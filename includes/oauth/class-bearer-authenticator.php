<?php
/**
 * Bearer token authenticator for WordPress REST API requests.
 *
 * Uses request-scoped detection (Server::authenticated_client(), set when the
 * token of THIS request was accepted) instead of a flag that outlives the
 * request, avoiding the PHP-FPM cross-request auth-bypass vulnerability.
 *
 * @package mcp-abilities
 */

declare( strict_types=1 );

namespace MCP_Abilities\OAuth;

final class Bearer_Authenticator {

	/**
	 * REST route prefixes a token signs in on: the MCP servers and the OAuth
	 * server's own routes (userinfo). Every MCP server the adapter registers,
	 * also under another namespace, counts as well.
	 */
	const ROUTES = array( '/mcp', '/vgc-cc' );

	public static function init(): void {
		add_filter( 'determine_current_user',    array( self::class, 'authenticate' ), 20 );
		add_filter( 'rest_authentication_errors', array( self::class, 'suppress_auth_error' ), 20 );
	}

	public static function authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		// Only run for REST API requests — avoids a transient lookup on every
		// front-end page load. WP_REST_Server::serve_request() asks again once
		// REST_REQUEST is set, so a token is still seen on REST requests.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $user_id;
		}
		// A token is issued for MCP. On /wp/v2, WooCommerce and other routes it
		// would be a full REST login that read-only mode and the tool switches do
		// not bind (audit M8), so there the request stays signed out.
		$route = self::current_route();
		if ( ! self::route_allowed( $route ) ) {
			if ( Server::debugging() && null !== Server::extract_bearer_token() ) {
				Server::log( sprintf( 'bearer token ignored on REST route %s (tokens work on MCP and /vgc-cc/ only)', substr( $route, 0, 120 ) ) );
			}
			return $user_id;
		}

		$bearer_user_id = Server::validate_bearer_token();
		return null !== $bearer_user_id ? $bearer_user_id : $user_id;
	}

	/**
	 * Core's cookie check would otherwise sign out a token user who also sends a
	 * login cookie. Only a request whose token was accepted skips it: a bare
	 * "Authorization: Bearer" header next to a login cookie must not turn off
	 * the nonce check for that cookie (audit L1).
	 */
	public static function suppress_auth_error( $result ) {
		if ( ! is_null( $result ) ) {
			return $result;
		}
		$auth = Server::authenticated_client();
		if ( null !== $auth && is_user_logged_in() && get_current_user_id() === (int) $auth['user_id'] ) {
			return true;
		}
		return $result;
	}

	/**
	 * The REST route of this request, lower case (WordPress matches routes in
	 * any letter case). The same value WP_REST_Server dispatches on.
	 */
	public static function current_route(): string {
		$route = '';
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP && isset( $GLOBALS['wp']->query_vars['rest_route'] ) && is_string( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security
		} else {
			$path   = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security
			$prefix = '/' . trim( (string) rest_get_url_prefix(), '/' ) . '/';
			$pos    = strpos( $path, $prefix );
			if ( false !== $pos ) {
				$route = substr( $path, $pos + strlen( $prefix ) - 1 );
			}
		}
		return strtolower( '/' . ltrim( (string) $route, '/' ) );
	}

	public static function route_allowed( string $route ): bool {
		$route = strtolower( '/' . ltrim( $route, '/' ) );
		if ( class_exists( \MCP_Abilities\Request_Context::class ) && 'http' === \MCP_Abilities\Request_Context::route_kind( $route ) ) {
			return true;
		}
		/**
		 * Filters the REST route prefixes on which an AI Nexus OAuth token signs
		 * the request in. Add one only if an MCP client really needs it there:
		 * read-only mode and the tool switches do not apply outside MCP.
		 *
		 * @param string[] $prefixes Default '/mcp', '/vgc-cc'.
		 */
		foreach ( (array) apply_filters( 'mcp_abilities_oauth_bearer_routes', self::ROUTES ) as $prefix ) {
			$prefix = strtolower( '/' . trim( (string) $prefix, '/' ) );
			if ( '/' !== $prefix && ( $route === $prefix || str_starts_with( $route, $prefix . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
