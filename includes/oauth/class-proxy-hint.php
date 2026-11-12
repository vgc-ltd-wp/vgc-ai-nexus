<?php
/**
 * A site behind a proxy or CDN that it has not named (2.42.0).
 *
 * @package mcp-abilities
 */

namespace MCP_Abilities\OAuth;

use MCP_Abilities\Client_Ip;

defined( 'ABSPATH' ) || exit;

/**
 * The OAuth limits (Rate_Limit) count by REMOTE_ADDR, because a caller can
 * write any X-Forwarded-For. Behind Cloudflare or another proxy that the site
 * has not named with mcp_abilities_trusted_proxies, REMOTE_ADDR is the
 * proxy's, so every caller shares one counter: one busy caller makes sign-in
 * wait for everyone, and the activity log shows the proxy's address.
 *
 * AI Nexus does not trust those headers by itself. It only notices them: when
 * one address has brought requests that carried a forwarding header for
 * CALLERS different callers, administrators see a notice on AI Nexus's pages
 * that explains the filter, until they dismiss it or name that proxy.
 *
 * Watched on the requests Rate_Limit counts (registration, token, unknown
 * bearer tokens). Stored in the non-autoloaded option OPTION, written only when
 * something new is seen (at most CALLERS short hashes for each of MAX_SEEN
 * addresses), and never again once found:
 *   { seen: { <address>: { header, callers: [hash…], at } }, found: { remote, header, at }, dismissed: time }
 */
final class Proxy_Hint {

	const OPTION   = 'mcp_abilities_proxy_hint';
	const ACTION   = 'mcp_abilities_dismiss_proxy_hint';
	const CALLERS  = 3;
	const MAX_SEEN = 20;

	/** $_SERVER key => the header's name, in the order they are read. */
	const HEADERS = array(
		'HTTP_CF_CONNECTING_IP'  => 'CF-Connecting-IP',
		'HTTP_TRUE_CLIENT_IP'    => 'True-Client-IP',
		'HTTP_FASTLY_CLIENT_IP'  => 'Fastly-Client-IP',
		'HTTP_X_REAL_IP'         => 'X-Real-IP',
		'HTTP_X_FORWARDED_FOR'   => 'X-Forwarded-For',
		'HTTP_FORWARDED'         => 'Forwarded',
	);

	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'dismiss' ) );
	}

	/** @return array<string, mixed> */
	private static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * A request about to be counted: if it carries a forwarding header and its
	 * address is counted as it is (not a named proxy), remember which caller the
	 * header named. Nothing is written for a caller seen before.
	 */
	public static function note(): void {
		$remote = Client_Ip::remote();
		if ( '' === $remote || Client_Ip::is_trusted_proxy( $remote ) || Rate_Limit::ip() !== $remote ) {
			return; // No address, a proxy the site named, or the site counts by another address.
		}
		$header = '';
		$value  = '';
		foreach ( self::HEADERS as $key => $name ) {
			$v = isset( $_SERVER[ $key ] ) ? trim( (string) $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only hashed
			if ( '' !== $v ) {
				$header = $name;
				$value  = substr( $v, 0, 200 );
				break;
			}
		}
		if ( '' === $header ) {
			return;
		}
		$state = self::state();
		if ( ! empty( $state['found'] ) ) {
			return;
		}
		$seen   = isset( $state['seen'] ) && is_array( $state['seen'] ) ? $state['seen'] : array();
		$entry  = isset( $seen[ $remote ] ) && is_array( $seen[ $remote ] ) ? $seen[ $remote ] : array( 'callers' => array() );
		$caller = substr( hash( 'sha256', $value ), 0, 12 );
		$known  = array_values( array_filter( (array) ( $entry['callers'] ?? array() ), 'is_string' ) );
		if ( in_array( $caller, $known, true ) ) {
			return;
		}
		$known[] = $caller;
		if ( count( $known ) >= self::CALLERS ) {
			update_option( self::OPTION, array( 'found' => array( 'remote' => $remote, 'header' => $header, 'at' => time() ) ), false );
			Server::log( sprintf( 'requests from %s carry a %s header for %d different callers: the site seems to be behind a proxy it has not named (filter mcp_abilities_trusted_proxies); administrators are told on AI Nexus pages', $remote, $header, count( $known ) ) );
			return;
		}
		// The address seen most recently goes last; the oldest go past MAX_SEEN.
		unset( $seen[ $remote ] );
		$seen[ $remote ] = array( 'header' => $header, 'callers' => $known, 'at' => time() );
		$state['seen']   = array_slice( $seen, -self::MAX_SEEN, null, true );
		update_option( self::OPTION, $state, false );
	}

	/**
	 * What was found, or null: an address that forwarded for several callers,
	 * not dismissed and still not named as a proxy.
	 *
	 * @return array{remote:string,header:string,at:int}|null
	 */
	public static function found(): ?array {
		$state = self::state();
		$found = isset( $state['found'] ) && is_array( $state['found'] ) ? $state['found'] : null;
		if ( null === $found || ! empty( $state['dismissed'] ) ) {
			return null;
		}
		$remote = (string) ( $found['remote'] ?? '' );
		if ( '' === $remote || Client_Ip::is_trusted_proxy( $remote ) ) {
			return null;
		}
		return array( 'remote' => $remote, 'header' => (string) ( $found['header'] ?? '' ), 'at' => (int) ( $found['at'] ?? 0 ) );
	}

	/** On AI Nexus's pages, for administrators, until dismissed or the proxy is named. */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'mcp-abilities' !== $page && 0 !== strpos( $page, 'mcp-abilities-' ) ) {
			return;
		}
		$found = self::found();
		if ( null === $found ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		echo '<div class="notice notice-warning"><p><strong>VGC AI Nexus:</strong> '
			. esc_html( sprintf(
				/* translators: 1: an IP address, 2: a header name such as CF-Connecting-IP */
				__( 'This site seems to be behind a proxy or CDN: requests from %1$s carry a %2$s header for different visitors. AI Nexus counts sign-in attempts per address, so behind it every visitor shares one counter (one busy caller can make sign-in wait for everyone), and the activity log shows the proxy\'s address. If you trust that proxy, list its addresses with the filter mcp_abilities_trusted_proxies (for Cloudflare, its published IP ranges). AI Nexus does not trust these headers by itself, because anyone can send them.', 'mcp-abilities' ),
				$found['remote'],
				$found['header']
			) )
			. ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'mcp-abilities' ) . '</a></p></div>';
	}

	/** admin-post: dismiss the notice (nonce + manage_options). */
	public static function dismiss(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mcp-abilities' ), 403 );
		}
		$state              = self::state();
		$state['dismissed'] = time();
		update_option( self::OPTION, $state, false );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=mcp-abilities-connect' ) );
		exit;
	}
}
