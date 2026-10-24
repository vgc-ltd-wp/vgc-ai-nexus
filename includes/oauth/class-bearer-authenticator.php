<?php
/**
 * Bearer token authenticator for WordPress REST API requests.
 *
 * Uses request-scoped detection (is_user_logged_in + Authorization header
 * prefix) instead of a static flag, avoiding the PHP-FPM cross-request
 * auth-bypass vulnerability.
 *
 * @package mcp-abilities
 */

declare( strict_types=1 );

namespace MCP_Abilities\OAuth;

final class Bearer_Authenticator {

	public static function init(): void {
		add_filter( 'determine_current_user',    array( self::class, 'authenticate' ), 20 );
		add_filter( 'rest_authentication_errors', array( self::class, 'suppress_auth_error' ), 20 );
	}

	public static function authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		// Only run for REST API requests — avoids a transient lookup on every
		// front-end page load.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $user_id;
		}

		$bearer_user_id = Server::validate_bearer_token();
		return null !== $bearer_user_id ? $bearer_user_id : $user_id;
	}

	public static function suppress_auth_error( $result ) {
		if ( ! is_null( $result ) ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $auth ) && str_starts_with( $auth, 'Bearer ' ) ) {
				return true;
			}
		}
		return $result;
	}
}
