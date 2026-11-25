<?php
/**
 * Back to the consent page after signing in (2.53.1).
 *
 * The consent page sends a signed-out person, or one whose sign-in is older
 * than Server::reauth_after(), to wp_login_url( <the consent page> ). That
 * relies on the login keeping its redirect_to all the way through. Some sites
 * lose it: a security plugin's "after login go to …" rule, a two-factor step
 * that ends on the dashboard, a custom login page. WordPress then lands the
 * person in wp-admin, the Approve button is never shown, and the AI app waits
 * for a code that never comes. Seen 2026-11-25 on a site with WP Cerber (its
 * own login address and reCAPTCHA): the login form still carried redirect_to,
 * the browser still ended on the dashboard.
 *
 * So before sending someone to sign in, the consent page is remembered in a
 * short-lived cookie (signed, HttpOnly, SameSite=Lax, 10 minutes). After a
 * sign-in:
 *
 * - login_redirect, at the last priority, sends the person to it when the
 *   login asked for nothing else (no redirect_to, the consent page itself, or
 *   the dashboard);
 * - when another plugin redirected on its own and WordPress's filter never
 *   ran, the first wp-admin or front-end page view sends them there, once,
 *   while the sign-in is fresh and the account may connect apps.
 *
 * The consent page forgets the cookie when it shows. Only this site's consent
 * page is ever accepted from the cookie, so it cannot send anyone elsewhere,
 * and the consent page keeps every rule of its own (capability, fresh sign-in,
 * nonce, Approve).
 *
 * @package mcp-abilities
 */

declare( strict_types=1 );

namespace MCP_Abilities\OAuth;

final class Login_Return {

	const COOKIE = 'mcp_abilities_oauth_return';
	const TTL    = 600;
	// A cookie holds about 4 KB; the address goes in base64 (4/3 longer).
	const MAX_URL = 2800;

	public static function init(): void {
		add_filter( 'login_redirect', array( self::class, 'filter_login_redirect' ), PHP_INT_MAX, 3 );
		add_action( 'admin_init', array( self::class, 'maybe_send_back' ), 0 );
		add_action( 'template_redirect', array( self::class, 'maybe_send_back' ), 0 );
	}

	/**
	 * Remember the consent page before sending the person to sign in. Any other
	 * address, or one too long for a cookie, is not remembered.
	 */
	public static function remember( string $url ): void {
		if ( headers_sent() || strlen( $url ) > self::MAX_URL || ! Server::is_consent_url( $url ) ) {
			return;
		}
		$expires = time() + self::TTL;
		$value   = self::seal( $url, $expires );
		self::set_cookie( $value, $expires );
		$_COOKIE[ self::COOKIE ] = $value;
		// The redirect carries a cookie for this browser only: no cache may keep it.
		nocache_headers();
	}

	/** Drop the cookie (the consent page showed, or it was used). */
	public static function forget(): void {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		unset( $_COOKIE[ self::COOKIE ] );
		if ( ! headers_sent() ) {
			self::set_cookie( '', time() - YEAR_IN_SECONDS );
		}
	}

	/** The remembered consent page, or '' (none, expired, altered, or not this site's consent page). */
	public static function pending(): string {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $raw || strlen( $raw ) > 4096 ) {
			return '';
		}
		$parts = explode( '.', $raw );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[1] ) ) {
			return '';
		}
		[ $encoded, $expires, $mac ] = $parts;
		if ( ! hash_equals( self::mac( $encoded . '.' . $expires ), $mac ) ) {
			return '';
		}
		$expires = (int) $expires;
		if ( $expires < time() || $expires > time() + self::TTL + 60 ) {
			return '';
		}
		$url = base64_decode( strtr( $encoded, '-_', '+/' ), true );
		if ( ! is_string( $url ) || '' === $url || ! Server::is_consent_url( $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * After a sign-in on wp-login.php (or a login page built on it): back to the
	 * consent page, unless the login asked to go somewhere else.
	 *
	 * @param string|mixed           $redirect_to Where WordPress (and earlier filters) would go.
	 * @param string|mixed           $requested   The redirect_to the login form sent.
	 * @param \WP_User|\WP_Error|mixed $user      The account that signed in.
	 * @return string|mixed
	 */
	public static function filter_login_redirect( $redirect_to, $requested = '', $user = null ) {
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return $redirect_to;
		}
		$url = self::pending();
		if ( '' === $url || ! user_can( $user, Server::consent_capability() ) ) {
			return $redirect_to;
		}
		$requested = is_string( $requested ) ? trim( $requested ) : '';
		if ( '' !== $requested && $requested !== $url && ! self::is_dashboard( $requested ) ) {
			// The person signed in from another link: theirs wins.
			return $redirect_to;
		}
		if ( $redirect_to !== $url ) {
			Server::log( sprintf(
				'sign-in of user_id=%d: back to the consent page (the login would have gone to %s)',
				(int) $user->ID,
				is_string( $redirect_to ) && '' !== $redirect_to ? self::path_of( $redirect_to ) : '(nothing)'
			) );
		}
		return $url;
	}

	/**
	 * The first page view after a sign-in that ended somewhere else (another
	 * plugin redirected on its own): back to the consent page, once.
	 */
	public static function maybe_send_back(): void {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( doing_action( 'template_redirect' ) && ( is_feed() || is_robots() || is_trackback() ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		$url = self::pending();
		if ( '' === $url || ! Server::may_consent() ) {
			self::forget();
			return;
		}
		// Not signed in just now: this is not the end of that sign-in (the consent
		// page would only ask again). The cookie runs out by itself.
		if ( ! Server::login_is_fresh() ) {
			return;
		}
		self::forget();
		Server::log( sprintf(
			'user_id=%d signed in and landed on %s: back to the consent page',
			get_current_user_id(),
			self::path_of( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		) );
		wp_safe_redirect( $url );
		exit;
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private static function seal( string $url, int $expires ): string {
		$encoded = rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
		return $encoded . '.' . $expires . '.' . self::mac( $encoded . '.' . $expires );
	}

	private static function mac( string $data ): string {
		return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) . '|mcp-abilities-oauth-return' );
	}

	private static function set_cookie( string $value, int $expires ): void {
		// Path "/": the login page, wp-admin and the consent page may sit under
		// different paths. The cookie is signed and only this site's consent page
		// is accepted from it, so another site on the same host gains nothing.
		setcookie( self::COOKIE, $value, array(
			'expires'  => $expires,
			'path'     => '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}

	/** The dashboard WordPress goes to when a login names no destination. */
	private static function is_dashboard( string $url ): bool {
		$url = untrailingslashit( $url );
		return in_array( $url, array( 'wp-admin', untrailingslashit( admin_url() ), untrailingslashit( admin_url( 'index.php' ) ) ), true );
	}

	/** Only the path, for the log (never a query with codes or states). */
	private static function path_of( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}
}
