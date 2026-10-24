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
        delete_option( 'mcp_abilities_oauth_rules' );
        // OAuth clients and tokens are shared with the VGC MCP Claude Connector;
        // keep them while that plugin is active and serving the connections.
        if ( ! defined( 'VGC_CC_VERSION' ) ) {
            OAuth\Token_Store::delete_all();
            foreach ( [ OAuth\Options::CLIENTS, OAuth\Options::REG_POLICY, OAuth\Options::ALLOWED_ORIGINS, 'vgc_cc_dynamic_reg_user_id' ] as $opt ) {
                delete_option( $opt );
            }
        }
        global $wpdb;
        foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( Upload_Links::PREFIX ) . '%' ) ) as $name ) {
            delete_option( $name );
        }
    }
}
