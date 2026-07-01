<?php
namespace MCP_Abilities\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Self-service "Connect to Claude" flow.
 *
 * AI Nexus bundles the MCP server, so connecting is just: create an Application
 * Password for the current user and hand back a ready Claude Desktop config that
 * points the npx bridge at this site's MCP endpoint.
 */
class Connect {

    const APP_PASS_NAME      = 'Claude (VGC AI Nexus)';
    const NPX_PACKAGE        = '@automattic/mcp-wordpress-remote@0.3.1';
    const OPTION_CONNECTIONS = 'mcp_abilities_connections';

    public function init(): void {
        add_action( 'wp_ajax_mcp_generate_app_password', [ $this, 'ajax_generate' ] );
        add_action( 'wp_ajax_mcp_revoke_connection',     [ $this, 'ajax_revoke' ] );
    }

    /** The bundled MCP server's REST URL. */
    public static function server_url(): string {
        return rest_url( 'mcp/mcp-adapter-default-server' );
    }

    /** A stable per-site key for the Claude config entry. */
    public static function server_key(): string {
        $host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'wordpress';
        return trim( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $host ) ), '-' ) ?: 'wordpress';
    }

    /** Full Claude Desktop config block for a username + application password. */
    public static function config_snippet( string $username, string $app_pass ): array {
        return [
            'mcpServers' => [
                self::server_key() => [
                    'command' => 'npx',
                    'args'    => [ '-y', self::NPX_PACKAGE ],
                    'env'     => [
                        'WP_API_URL'      => self::server_url(),
                        'WP_API_USERNAME' => $username,
                        'WP_API_PASSWORD' => $app_pass,
                    ],
                ],
            ],
        ];
    }

    public static function status(): string {
        return empty( self::list_connections() ) ? 'not_configured' : 'connected';
    }

    /**
     * Stored connections, reconciled against the live WordPress Application
     * Passwords so the list always reflects reality:
     *   - drops entries whose app password was revoked elsewhere (prunes the option),
     *   - adopts app passwords created by this plugin (our APP_PASS_NAME) that are
     *     missing from the option (e.g. created on an older build that only showed
     *     them once and never recorded them — the "not stored" symptom).
     *
     * @return array<int, array{user_id:int,user_login:string,display_name:string,uuid:string,created:int,last_used:?int,last_ip:?string}>
     */
    public static function list_connections(): array {
        if ( ! class_exists( '\WP_Application_Passwords' ) ) {
            return [];
        }

        $stored  = (array) get_option( self::OPTION_CONNECTIONS, [] );
        $changed = false;
        $rows    = [];

        // Consider every user that either has a stored connection or owns an app
        // password named like ours. Start from stored users, then scan for adoptees.
        $user_ids = array_map( 'absint', array_keys( $stored ) );

        // Adopt: find users who own an app password with our name but aren't recorded.
        $adopt_query = new \WP_User_Query( [
            'meta_key' => '_application_passwords', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'fields'   => 'ID',
            'number'   => 500,
        ] );
        foreach ( (array) $adopt_query->get_results() as $uid ) {
            $user_ids[] = (int) $uid;
        }
        $user_ids = array_values( array_unique( array_filter( $user_ids ) ) );

        foreach ( $user_ids as $uid ) {
            $user = get_user_by( 'id', $uid );
            if ( ! $user instanceof \WP_User ) {
                if ( isset( $stored[ $uid ] ) ) { unset( $stored[ $uid ] ); $changed = true; }
                continue;
            }

            $passwords = \WP_Application_Passwords::get_user_application_passwords( $uid );
            $by_uuid   = [];
            foreach ( (array) $passwords as $pw ) {
                if ( ! empty( $pw['uuid'] ) ) {
                    $by_uuid[ $pw['uuid'] ] = $pw;
                }
            }

            // Resolve which app password backs this connection.
            $uuid = isset( $stored[ $uid ] ) ? (string) $stored[ $uid ] : '';
            $item = $uuid && isset( $by_uuid[ $uuid ] ) ? $by_uuid[ $uuid ] : null;

            // Stored UUID no longer exists → try to adopt one of ours, else prune.
            if ( ! $item ) {
                foreach ( (array) $passwords as $pw ) {
                    if ( isset( $pw['name'] ) && self::APP_PASS_NAME === $pw['name'] ) {
                        $item = $pw;
                        $uuid = (string) $pw['uuid'];
                        break;
                    }
                }
                if ( $item ) {
                    if ( ! isset( $stored[ $uid ] ) || $stored[ $uid ] !== $uuid ) {
                        $stored[ $uid ] = $uuid;
                        $changed        = true;
                    }
                } elseif ( isset( $stored[ $uid ] ) ) {
                    unset( $stored[ $uid ] );
                    $changed = true;
                    continue;
                } else {
                    continue; // nothing of ours for this user
                }
            }

            $rows[] = [
                'user_id'      => $uid,
                'user_login'   => $user->user_login,
                'display_name' => $user->display_name,
                'uuid'         => $uuid,
                'created'      => isset( $item['created'] ) ? (int) $item['created'] : 0,
                'last_used'    => isset( $item['last_used'] ) && $item['last_used'] ? (int) $item['last_used'] : null,
                'last_ip'      => isset( $item['last_ip'] ) ? (string) $item['last_ip'] : null,
            ];
        }

        if ( $changed ) {
            update_option( self::OPTION_CONNECTIONS, $stored, false );
        }

        usort( $rows, static fn( $a, $b ) => strcasecmp( $a['display_name'], $b['display_name'] ) );
        return $rows;
    }

    /**
     * Roles never offered as connection users (customers, plain subscribers).
     * Filterable via 'mcp_abilities_excluded_user_roles'.
     *
     * @return string[]
     */
    public static function excluded_roles(): array {
        return array_values( array_filter( array_map(
            'sanitize_key',
            (array) apply_filters( 'mcp_abilities_excluded_user_roles', [ 'customer', 'subscriber' ] )
        ) ) );
    }

    /**
     * Users eligible to be a connection account (excludes low-privilege roles).
     *
     * @return \WP_User[]
     */
    public static function eligible_users( int $limit = 100 ): array {
        $query = new \WP_User_Query( [
            'role__not_in' => self::excluded_roles(),
            'orderby'      => 'display_name',
            'order'        => 'ASC',
            'number'       => max( 1, $limit ),
        ] );
        return $query->get_results();
    }

    public static function adapter_active(): bool {
        return defined( 'WP_MCP_VERSION' );
    }

    /** AJAX: create an Application Password for the current user, return config. */
    public function ajax_generate(): void {
        check_ajax_referer( 'mcp_abilities_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'mcp-abilities' ) ] );
        }

        // Target user: the selected one, or the current user by default.
        $target_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : get_current_user_id();
        $user      = get_user_by( 'id', $target_id );
        if ( ! $user instanceof \WP_User ) {
            wp_send_json_error( [ 'message' => __( 'Select a valid user for the connection.', 'mcp-abilities' ) ] );
        }
        // Creating credentials for another account requires the capability to edit it.
        if ( $user->ID !== get_current_user_id() && ! current_user_can( 'edit_user', $user->ID ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to create a connection for that user.', 'mcp-abilities' ) ] );
        }
        if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
            wp_send_json_error( [ 'message' => __( 'Application Passwords are unavailable. The site must be served over HTTPS and the feature enabled.', 'mcp-abilities' ) ] );
        }

        $conns = (array) get_option( self::OPTION_CONNECTIONS, [] );
        if ( isset( $conns[ $user->ID ] ) ) {
            \WP_Application_Passwords::delete_application_password( $user->ID, $conns[ $user->ID ] );
        }

        $result = \WP_Application_Passwords::create_new_application_password( $user->ID, [ 'name' => self::APP_PASS_NAME ] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        [ $password, $item ]  = $result;
        $conns[ $user->ID ]   = $item['uuid'];
        update_option( self::OPTION_CONNECTIONS, $conns, false );

        wp_send_json_success( [
            'username'     => $user->user_login,
            'display_name' => $user->display_name,
            'user_id'      => $user->ID,
            'uuid'         => $item['uuid'],
            'password'     => $password,
            'config'       => wp_json_encode( self::config_snippet( $user->user_login, $password ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
        ] );
    }

    /** AJAX: revoke a stored connection (delete its Application Password). */
    public function ajax_revoke(): void {
        check_ajax_referer( 'mcp_abilities_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'mcp-abilities' ) ] );
        }

        $target_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $uuid      = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
        $user      = get_user_by( 'id', $target_id );
        if ( ! $user instanceof \WP_User || '' === $uuid ) {
            wp_send_json_error( [ 'message' => __( 'Invalid connection.', 'mcp-abilities' ) ] );
        }
        if ( $user->ID !== get_current_user_id() && ! current_user_can( 'edit_user', $user->ID ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to revoke that connection.', 'mcp-abilities' ) ] );
        }

        \WP_Application_Passwords::delete_application_password( $user->ID, $uuid );

        $conns = (array) get_option( self::OPTION_CONNECTIONS, [] );
        if ( isset( $conns[ $user->ID ] ) && $conns[ $user->ID ] === $uuid ) {
            unset( $conns[ $user->ID ] );
            update_option( self::OPTION_CONNECTIONS, $conns, false );
        }

        wp_send_json_success( [ 'message' => __( 'Connection revoked.', 'mcp-abilities' ) ] );
    }
}
