<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

class Installer {

    public static function activate(): void {
        // Ensure option exists with empty defaults
        if ( ! get_option( MCP_ABILITIES_OPTION_KEY ) ) {
            add_option( MCP_ABILITIES_OPTION_KEY, [] );
        }

        // Flush rewrite rules so REST routes register
        flush_rewrite_rules();

        do_action( 'mcp_abilities_activated' );
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
        do_action( 'mcp_abilities_deactivated' );
    }
}
