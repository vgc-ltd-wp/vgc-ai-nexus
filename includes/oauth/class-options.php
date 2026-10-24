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
	const DEFAULT_ORIGINS = array( '*.claude.ai', '*.claude.com', 'claude.ai', 'claude.com', '*.anthropic.com' );

	/** 'restricted' (claude.ai / anthropic.com redirect addresses only), 'open', or 'closed'. */
	public static function policy(): string {
		$p = (string) get_option( self::REG_POLICY, 'restricted' );
		return in_array( $p, array( 'restricted', 'open', 'closed' ), true ) ? $p : 'restricted';
	}

	public static function set_policy( string $policy ): bool {
		if ( ! in_array( $policy, array( 'restricted', 'open', 'closed' ), true ) ) {
			return false;
		}
		update_option( self::REG_POLICY, $policy, false );
		return true;
	}
}
