<?php
/**
 * Per-address request limits for the OAuth endpoints anyone can call.
 *
 * @package mcp-abilities
 */

namespace MCP_Abilities\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * /register and /token need no sign-in, so a loop from one address could fill
 * the app list, the options table and the error log. Each address gets a fixed
 * number of requests per window; the limits are far above what claude.ai sends
 * (one registration per "Connect", about one token request per connection per
 * hour, all from a few Anthropic addresses).
 *
 * Counters are transients: losing one (a cache flush) only resets a window.
 * The address is REMOTE_ADDR; X-Forwarded-For can be set by the caller.
 */
final class Rate_Limit {

	const PREFIX = 'mcp_oauth_rl_';

	/** bucket => [ requests, window in seconds ] */
	const LIMITS = array(
		'register' => array( 30, 3600 ),
		'token'    => array( 60, 60 ),
	);

	/**
	 * Count one request from this address.
	 *
	 * @return int 0 when allowed, otherwise the seconds until the window ends.
	 */
	public static function hit( string $bucket ): int {
		$limit = self::LIMITS[ $bucket ] ?? null;
		/**
		 * Filters an OAuth endpoint's limit per address.
		 *
		 * @param array{0:int,1:int}|null $limit  [ requests, window in seconds ]; 0 requests turns the limit off.
		 * @param string                  $bucket 'register' or 'token'.
		 */
		$limit = apply_filters( 'mcp_abilities_oauth_rate_limit', $limit, $bucket );
		if ( ! is_array( $limit ) || (int) ( $limit[0] ?? 0 ) <= 0 || (int) ( $limit[1] ?? 0 ) <= 0 ) {
			return 0;
		}
		[ $max, $window ] = array( (int) $limit[0], (int) $limit[1] );

		$ip  = self::ip();
		$key = self::PREFIX . $bucket . '_' . md5( $ip );
		$now = time();
		$hit = get_transient( $key );
		if ( ! is_array( $hit ) || (int) ( $hit['until'] ?? 0 ) <= $now ) {
			$hit = array( 'n' => 0, 'until' => $now + $window );
		}
		++$hit['n'];
		$wait = max( 1, (int) $hit['until'] - $now );
		set_transient( $key, $hit, $wait );

		if ( $hit['n'] <= $max ) {
			return 0;
		}
		if ( $hit['n'] === $max + 1 ) {
			// Once per window, so a flood does not flood the log.
			Server::log( sprintf( '/%s: more than %d requests in %ds from %s, refused for %ds', $bucket, $max, $window, $ip, $wait ) );
		}
		return $wait;
	}

	/** The caller's address as the web server saw it. */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
		/**
		 * Filters the address the limits count by, for a site behind a proxy
		 * that does not set REMOTE_ADDR to the visitor's address.
		 *
		 * @param string $ip REMOTE_ADDR, or 'unknown'.
		 */
		return (string) apply_filters( 'mcp_abilities_oauth_client_ip', $ip );
	}

	/** The OAuth error response for a refused request. */
	public static function response( int $wait ): \WP_REST_Response {
		$r = new \WP_REST_Response( array(
			'error'             => 'temporarily_unavailable',
			/* translators: %d: seconds */
			'error_description' => sprintf( __( 'Too many requests from this address. Try again in %d seconds.', 'mcp-abilities' ), $wait ),
		), 429 );
		$r->header( 'Retry-After', (string) $wait );
		return $r;
	}
}
