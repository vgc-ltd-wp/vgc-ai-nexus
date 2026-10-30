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
     *
     * Deliberately KEPT: the MCP attribution meta, which is the audit trail of
     * what an AI changed — _mcp_abilities_actor and _mcp_abilities_last_change
     * on revisions and posts (Revision_Attribution), on comments and order
     * notes (Comment_Attribution) and on WooCommerce orders
     * (Order_Attribution), plus the older _vgc_mcp* revision tags. Revisions,
     * comments and orders outlive the plugin; so does the record of who made
     * them and through which app.
     */
    public static function uninstall(): void {
        Logger::drop_table();
        delete_option( MCP_ABILITIES_OPTION_KEY );
        delete_option( 'mcp_abilities_oauth_rules' );
        // OAuth clients and tokens are shared with the VGC MCP Claude Connector;
        // keep them while that plugin is active and serving the connections.
        if ( null === OAuth\Bootstrap::connector_version() ) {
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
