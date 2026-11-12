<?php
/**
 * Option names shared with the VGC MCP Claude Connector (OAuth) plugin.
 *
 * @package mcp-abilities
 */

namespace MCP_Abilities\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * The names are deliberately the connector's: a site moving from the connector
 * to the built-in server keeps its registered clients, policy and tokens, so
 * claude.ai connections made before the switch keep working after it.
 */
final class Options {

	const CLIENTS         = 'vgc_cc_oauth_clients';
	const REG_POLICY      = 'vgc_cc_dyn_reg_policy';
	const ALLOWED_ORIGINS = 'vgc_cc_allowed_origins';

	/**
	 * The host list both plugins stored by default. Since 2.39.0 these entries
	 * no longer allow a host on their own: Claude's addresses are checked by
	 * Client_Manager::is_claude_redirect() (https, claude.ai or claude.com, a
	 * path under /api/mcp/). They stay in the stored list so that an older
	 * connector reading the same option still lets claude.ai register.
	 */
	const DEFAULT_ORIGINS = array( '*.claude.ai', '*.claude.com', 'claude.ai', 'claude.com', '*.anthropic.com' );

	/** At most this many hosts an administrator adds. */
	const MAX_EXTRA_HOSTS = 20;

	/** 'restricted' (Claude's own addresses, loopback with PKCE, hosts an administrator added), 'open', or 'closed'. */
	public static function policy(): string {
		$p = get_option( self::REG_POLICY, 'restricted' );
		// 2.42.0: anything unexpected (also not a string) is "restricted", without a PHP warning.
		return is_string( $p ) && in_array( $p, array( 'restricted', 'open', 'closed' ), true ) ? $p : 'restricted';
	}

	public static function set_policy( string $policy ): bool {
		if ( ! in_array( $policy, array( 'restricted', 'open', 'closed' ), true ) ) {
			return false;
		}
		update_option( self::REG_POLICY, $policy, false );
		return true;
	}

	/**
	 * Hosts an administrator allowed besides Claude's (the stored list minus the
	 * old defaults). '*.example.com' allows example.com and every sub-domain.
	 *
	 * @return string[]
	 */
	public static function extra_hosts(): array {
		$stored = get_option( self::ALLOWED_ORIGINS, self::DEFAULT_ORIGINS );
		$out    = array();
		foreach ( (array) $stored as $entry ) {
			$entry = self::clean_host( (string) $entry );
			if ( '' !== $entry && ! in_array( $entry, self::DEFAULT_ORIGINS, true ) ) {
				$out[] = $entry;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Store the hosts an administrator allows (the old defaults are kept in the
	 * stored list for an older connector).
	 *
	 * @param string[] $hosts As typed; each is cleaned, invalid ones dropped.
	 * @return string[] The hosts kept.
	 */
	public static function set_extra_hosts( array $hosts ): array {
		$clean = array();
		foreach ( $hosts as $host ) {
			$host = self::clean_host( (string) $host );
			if ( '' !== $host && ! in_array( $host, self::DEFAULT_ORIGINS, true ) && ! in_array( $host, $clean, true ) ) {
				$clean[] = $host;
			}
			if ( count( $clean ) >= self::MAX_EXTRA_HOSTS ) {
				break;
			}
		}
		update_option( self::ALLOWED_ORIGINS, array_merge( self::DEFAULT_ORIGINS, $clean ), false );
		return $clean;
	}

	/**
	 * A host as an administrator may enter it: "example.com" or "*.example.com",
	 * lower case, without scheme, port, path or user. '' when it is not one.
	 */
	public static function clean_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		if ( preg_match( '#^https?://#', $host ) ) {
			$host = (string) wp_parse_url( $host, PHP_URL_HOST );
		}
		$host = rtrim( $host, '/.' );
		if ( strlen( $host ) > 253 || ! preg_match( '/^(\*\.)?([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$|^(\*\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host ) ) {
			return '';
		}
		return $host;
	}
}
