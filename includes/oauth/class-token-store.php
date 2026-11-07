<?php
/**
 * Durable storage for OAuth codes and tokens.
 *
 * @package mcp-abilities
 */

namespace MCP_Abilities\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Codes and tokens live in wp_options rows (autoload off), not transients.
 *
 * With a persistent object cache (Memcached on SiteGround, Redis elsewhere)
 * transients exist ONLY in the cache. Any flush or eviction then deletes a
 * refresh token, and the client is silently signed out. Options are
 * cached the same way but always persisted to the database, so a cache wipe
 * just means the next read comes from the DB.
 *
 * Rows are keyed by the SHA-256 of the raw value; the raw token is never stored.
 */
final class Token_Store {

	const PREFIX = 'vgc_cc_t_';

	/** Transient prefixes used before 1.3.4, read once and migrated. */
	private const LEGACY = array(
		'token'   => 'vgc_cc_token_',
		'refresh' => 'vgc_cc_refresh_',
		'code'    => 'vgc_cc_code_',
	);

	private static function key( string $type, string $raw ): string {
		return self::PREFIX . $type . '_' . hash( 'sha256', $raw );
	}

	/**
	 * @param string               $type 'token' | 'refresh' | 'code' | 'tomb'
	 * @param array<string, mixed> $data Must include 'expires' (unix time).
	 *                                   Since 2.39.0 also 'fp' (Lifecycle::fingerprint() of
	 *                                   the user's password when issued); refresh rows 'conf'
	 *                                   (issued after the client proved its secret) and 'since'.
	 */
	public static function put( string $type, string $raw, array $data ): void {
		$key = self::key( $type, $raw );
		if ( false === add_option( $key, $data, '', false ) ) {
			update_option( $key, $data, false );
		}
	}

	/**
	 * The stored data, or null when missing or expired (expired rows are removed).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $type, string $raw ): ?array {
		$key  = self::key( $type, $raw );
		$data = get_option( $key, null );

		if ( ! is_array( $data ) && isset( self::LEGACY[ $type ] ) ) {
			// Pre-1.3.4 token still alive in the cache: move it to the database so
			// updating the plugin does not sign anyone out.
			$legacy = get_transient( self::LEGACY[ $type ] . hash( 'sha256', $raw ) );
			if ( is_array( $legacy ) && isset( $legacy['expires'] ) ) {
				self::put( $type, $raw, $legacy );
				delete_transient( self::LEGACY[ $type ] . hash( 'sha256', $raw ) );
				$data = $legacy;
			}
		}

		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( (int) ( $data['expires'] ?? 0 ) < time() ) {
			delete_option( $key );
			return null;
		}
		return $data;
	}

	public static function delete( string $type, string $raw ): void {
		delete_option( self::key( $type, $raw ) );
	}

	/**
	 * Remove expired rows, oldest first. Called whenever tokens are issued, so the
	 * table never grows past roughly one row per live token.
	 */
	public static function cleanup( int $limit = 100 ): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
			$wpdb->esc_like( self::PREFIX ) . '%',
			$limit
		) );
		$now     = time();
		$removed = 0;
		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row->option_value );
			if ( ! is_array( $data ) || (int) ( $data['expires'] ?? 0 ) < $now ) {
				delete_option( $row->option_name );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Live (unexpired, unrotated, still valid for their user) refresh tokens per client: who signed in and
	 * how long the connection can keep refreshing.
	 *
	 * @return array<string, array{users: int[], refresh_until: int}>
	 */
	public static function summary_by_client(): array {
		$out = array();
		foreach ( self::rows( 'refresh' ) as $data ) {
			// Since 2.39.0 also not a token whose user is gone or changed password.
			if ( ! empty( $data['rotated_at'] ) || ! Lifecycle::row_is_live( $data ) ) {
				continue;
			}
			$cid = (string) ( $data['client_id'] ?? '' );
			$out[ $cid ]['users'][]       = (int) ( $data['user_id'] ?? 0 );
			$out[ $cid ]['refresh_until'] = max( (int) ( $out[ $cid ]['refresh_until'] ?? 0 ), (int) $data['expires'] );
		}
		foreach ( $out as &$o ) {
			$o['users'] = array_values( array_unique( $o['users'] ) );
		}
		return $out;
	}

	/** Remove every code and token issued to one client. */
	public static function delete_for_client( string $client_id ): int {
		return self::delete_where( static fn( string $type, array $data ): bool => (string) ( $data['client_id'] ?? '' ) === $client_id );
	}

	/**
	 * Remove every code and token of one user: of one client, or of all
	 * (2.39.0: user deleted, removed from the site, role changed, Disconnect).
	 */
	public static function delete_for_user( int $user_id, ?string $client_id = null ): int {
		return self::delete_where( static function ( string $type, array $data ) use ( $user_id, $client_id ): bool {
			return (int) ( $data['user_id'] ?? 0 ) === $user_id
				&& ( null === $client_id || (string) ( $data['client_id'] ?? '' ) === $client_id );
		} );
	}

	/**
	 * Remove every code, token and reuse marker that $match picks.
	 *
	 * @param callable(string, array<string, mixed>): bool $match Type ('code', 'token', 'refresh', 'tomb') and data.
	 * @return int How many rows went.
	 */
	public static function delete_where( callable $match ): int {
		$removed = 0;
		foreach ( self::TYPES as $type ) {
			foreach ( self::rows( $type ) as $name => $data ) {
				if ( $match( $type, $data ) ) {
					delete_option( $name );
					++$removed;
				}
			}
		}
		return $removed;
	}

	/**
	 * Every stored row of one type, expired ones included.
	 *
	 * @return array<string, array<string, mixed>> option_name => data
	 */
	public static function all_of( string $type ): array {
		return in_array( $type, self::TYPES, true ) ? self::rows( $type ) : array();
	}

	/**
	 * Shorten every refresh token that runs longer than $until to $until
	 * (2.39.0 upgrade: 30 days became 14 days unused).
	 *
	 * @return int How many were shortened.
	 */
	public static function cap_refresh( int $until ): int {
		$capped = 0;
		foreach ( self::rows( 'refresh' ) as $name => $data ) {
			if ( (int) ( $data['expires'] ?? 0 ) > $until ) {
				$data['expires'] = $until;
				update_option( $name, $data, false );
				++$capped;
			}
		}
		return $capped;
	}

	/** Row types: authorization codes, access tokens, refresh tokens, rotated refresh tokens (reuse markers). */
	const TYPES = array( 'code', 'token', 'refresh', 'tomb' );

	/** @return array<string, array<string, mixed>> option_name => data */
	private static function rows( string $type ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( self::PREFIX . $type . '_' ) . '%'
		) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row->option_value );
			if ( is_array( $data ) ) {
				$out[ $row->option_name ] = $data;
			}
		}
		return $out;
	}

	/** Used by uninstall. */
	public static function delete_all(): void {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( self::PREFIX ) . '%'
		) );
		foreach ( (array) $names as $name ) {
			delete_option( $name );
		}
	}
}
