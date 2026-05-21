<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

class Installer {

    public static function activate(): void {
        // Ensure option exists with empty defaults.
        if ( ! get_option( MCP_ABILITIES_OPTION_KEY ) ) {
            add_option( MCP_ABILITIES_OPTION_KEY, [] );
        }

        // Create (or upgrade) the activity log table.
        Logger::create_table();

        // Flush rewrite rules so REST routes register.
        flush_rewrite_rules();

        do_action( 'mcp_abilities_activated' );
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
        do_action( 'mcp_abilities_deactivated' );
    }

    /**
     * Full cleanup on uninstall — called from uninstall.php.
     */
    public static function uninstall(): void {
        Logger::drop_table();
        delete_option( MCP_ABILITIES_OPTION_KEY );
    }
}
