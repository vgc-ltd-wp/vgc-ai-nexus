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
        // The daily switch-off of unused connections; scheduled again on the next load.
        wp_clear_scheduled_hook( 'mcp_abilities_idle_sweep' );
        // The daily licence check; scheduled again on the next admin page.
        wp_clear_scheduled_hook( Licence::CRON );
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
     *
     * Deliberately KEPT: read-only mode and "require admin approval" (with
     * "Also hold administrators' new connections", 2.39.0). Whoever
     * deletes the plugin and uploads it again must not find a read-only site
     * writable, or new connections let straight in, without anyone having
     * decided that. Two small options left behind are a fair price.
     *
     * Deliberately KEPT while the separate VGC MCP Claude Connector is on the
     * site, active or not: the OAuth apps and tokens, which that plugin serves
     * under the same names, the who-is-connecting names and approvals that go
     * with them, and the MCP sessions its copy of the adapter uses.
     *
     * Application Passwords: only the ones the Connect page recorded. One a person
     * made under Users → Profile is theirs, even if it has the same name.
     */
    public static function uninstall(): void {
        $connector = self::connector_present();

        if ( is_multisite() ) {
            foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::uninstall_site( $connector );
                restore_current_blog();
            }
        } else {
            self::uninstall_site( $connector );
        }

        // User meta is one table for the whole network. Sessions are only worth
        // keeping for another plugin's copy of the adapter that is running now.
        $other_adapter = defined( 'WP_MCP_VERSION' ) && empty( $GLOBALS['mcp_abilities_adapter']['ours'] );
        if ( ! $connector && ! $other_adapter ) {
            delete_metadata( 'user', 0, 'mcp_adapter_sessions', '', true );
        }
    }

    /** Everything AI Nexus keeps per site, for the current site. */
    private static function uninstall_site( bool $connector ): void {
        // First: it needs the option that goes below.
        self::revoke_connections();

        // Gives up this site's place on the licence key, then removes the key.
        Licence::uninstall();

        Logger::drop_table();
        wp_clear_scheduled_hook( Connections::CRON );

        foreach ( [
            MCP_ABILITIES_OPTION_KEY,
            'mcp_abilities_oauth_rules',
            'mcp_abilities_loaded_version',
            'mcp_abilities_consolidate',
            // Other plugins' tool and server switches: settings, not a safety switch
            // like read-only mode (2.38.0).
            Other_Tools::OPTION,
            Admin\Connect::OPTION_CONNECTIONS,
            Guide::CONVENTIONS_OPTION,
            Custom_Code::JS_HEAD_OPTION,
            Custom_Code::JS_FOOTER_OPTION,
            // 2.39.0: the list of connections that are on, the one-time upgrade
            // steps and their notice. ("Also hold administrators' new
            // connections" stays, with "require admin approval".)
            Connections::OPTION,
            OAuth\Lifecycle::UPGRADES,
            OAuth\Lifecycle::POLICY_NOTICE,
            // 2.42.0: the proxy notice. ("Also switch off other Application
            // Passwords" stays, like "require admin approval".)
            OAuth\Proxy_Hint::OPTION,
        ] as $opt ) {
            delete_option( $opt );
        }

        foreach ( [
            Admin\Extension_Installer::CACHE_KEY,
            Security_Status::PROBE_TRANSIENT,
            // This plugin's manifest cache only: every other VGC plugin has its own vgc_upd_*.
            'vgc_upd_' . md5( plugin_basename( MCP_ABILITIES_DIR . 'vgc-ai-nexus.php' ) ),
        ] as $transient ) {
            delete_transient( $transient );
        }

        // OAuth clients and tokens are shared with the VGC MCP Claude Connector.
        if ( ! $connector ) {
            OAuth\Token_Store::delete_all();
            // Including who-is-connecting names and connection approvals:
            // they belong to these connections.
            foreach ( [ OAuth\Options::CLIENTS, OAuth\Options::REG_POLICY, OAuth\Options::ALLOWED_ORIGINS, 'vgc_cc_dynamic_reg_user_id', OAuth\Operators::OPTION,
                Connection_Approval::RECORDS, Connection_Approval::MAIL_LOG ] as $opt ) {
                delete_option( $opt );
            }
        }

        global $wpdb;
        // One-time upload links, and the OAuth rate-limit counters (with an object
        // cache those live only in the cache and run out within the hour).
        foreach ( [ Upload_Links::PREFIX, '_transient_' . OAuth\Rate_Limit::PREFIX, '_transient_timeout_' . OAuth\Rate_Limit::PREFIX ] as $prefix ) {
            foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ) as $name ) {
                delete_option( $name );
            }
        }
    }

    /** Delete the Application Passwords the Connect page recorded for this site. */
    private static function revoke_connections(): void {
        if ( ! class_exists( '\WP_Application_Passwords' ) ) {
            return;
        }
        foreach ( (array) get_option( Admin\Connect::OPTION_CONNECTIONS, [] ) as $user_id => $uuid ) {
            if ( (int) $user_id > 0 && is_string( $uuid ) && '' !== $uuid ) {
                \WP_Application_Passwords::delete_application_password( (int) $user_id, $uuid );
            }
        }
    }

    /**
     * Whether the VGC MCP Claude Connector (OAuth) is installed, active or not: its
     * data must survive this plugin going. Recognised by its OAuth server file, as in
     * OAuth\Bootstrap::connector_version(), which only sees it while it is active.
     */
    private static function connector_present(): bool {
        if ( null !== OAuth\Bootstrap::connector_version() ) {
            return true;
        }
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ( array_keys( get_plugins() ) as $file ) {
            $folder = dirname( $file );
            if ( '.' !== $folder && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $folder . '/includes/class-oauth-server.php' ) ) {
                return true;
            }
        }
        return false;
    }
}
