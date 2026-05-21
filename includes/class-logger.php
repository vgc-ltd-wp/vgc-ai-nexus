<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Activity logger — records every ability execution to the mcp_ability_log table.
 *
 * Data captured per call:
 *   - Timestamp (UTC)
 *   - Tool (ability key)
 *   - WP user ID (from app-password auth or current_user)
 *   - App-password name + UUID (from wp_application_password_did_authenticate)
 *   - Client name/version (parsed from User-Agent)
 *   - Caller IP
 *   - Status (success / error) + error message
 */
class Logger {

    const TABLE_VERSION_OPTION = 'mcp_abilities_log_table_version';
    const TABLE_VERSION        = 1;
    const LOG_RETENTION_DAYS   = 90;

    // Stored per-request by the app-password hook.
    private static ?int    $req_user_id     = null;
    private static ?string $req_pass_name   = null;
    private static ?string $req_pass_uuid   = null;

    // ── Bootstrap ───────────────────────────────────────────────────────────

    public static function init(): void {
        // Capture app-password data as early in the request lifecycle as possible.
        add_action( 'wp_application_password_did_authenticate', [ __CLASS__, 'capture_app_pass' ], 10, 2 );

        // Daily pruning via WP Cron.
        if ( ! wp_next_scheduled( 'mcp_abilities_prune_log' ) ) {
            wp_schedule_event( time(), 'daily', 'mcp_abilities_prune_log' );
        }
        add_action( 'mcp_abilities_prune_log', [ __CLASS__, 'prune_old_entries' ] );
    }

    // ── Data capture ────────────────────────────────────────────────────────

    /**
     * Fired by WP after a successful application-password authentication.
     *
     * @param \WP_User $user Authenticated user.
     * @param array    $item App-password item (name, uuid, last_used, last_ip …).
     */
    public static function capture_app_pass( \WP_User $user, array $item ): void {
        self::$req_user_id   = $user->ID;
        self::$req_pass_name = $item['name'] ?? null;
        self::$req_pass_uuid = $item['uuid'] ?? null;
    }

    /**
     * Parse client name and version from the HTTP User-Agent header.
     * Returns ['name' => '', 'version' => ''] if nothing recognisable is found.
     */
    private static function detect_client(): array {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        $patterns = [
            '/\bclaude[-_]desktop[\/\s]+([\d.]+)/i' => 'Claude Desktop',
            '/\bclaude[-_]code[\/\s]+([\d.]+)/i'    => 'Claude Code',
            '/\bclaude[\/\s]+([\d.]+)/i'            => 'Claude',
            '/\bcursor[\/\s]+([\d.]+)/i'            => 'Cursor',
            '/\bwindsurf[\/\s]+([\d.]+)/i'          => 'Windsurf',
            '/\bcline[\/\s]+([\d.]+)/i'             => 'Cline',
            '/\bcontinue[\/\s]+([\d.]+)/i'          => 'Continue',
            '/\bcopilot[\/\s]+([\d.]+)/i'           => 'GitHub Copilot',
            '/\bmcp[-_]client[\/\s]+([\d.]+)/i'     => 'MCP Client',
        ];

        foreach ( $patterns as $regex => $name ) {
            if ( preg_match( $regex, $ua, $m ) ) {
                return [ 'name' => $name, 'version' => $m[1] ];
            }
        }

        // Store a truncated UA even if we didn't recognise the pattern —
        // better than losing the information entirely.
        if ( $ua !== '' ) {
            return [ 'name' => substr( $ua, 0, 80 ), 'version' => '' ];
        }

        return [ 'name' => '', 'version' => '' ];
    }

    /**
     * Resolve the best available caller IP address.
     */
    private static function get_ip(): string {
        $forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
            ? (string) $_SERVER['HTTP_X_FORWARDED_FOR']
            : '';

        if ( $forwarded !== '' ) {
            $ip = trim( explode( ',', $forwarded )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }

        return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    // ── Write ────────────────────────────────────────────────────────────────

    /**
     * Write one log row after an ability call completes.
     *
     * @param string $tool   Ability key (e.g. 'update_post').
     * @param array  $result The array returned by the ability's execute() method.
     */
    public static function log( string $tool, array $result ): void {
        global $wpdb;

        $is_error = isset( $result['success'] ) && false === $result['success'];
        $status   = $is_error ? 'error' : 'success';
        $error    = $is_error ? substr( (string) ( $result['error'] ?? '' ), 0, 500 ) : null;

        $user_id  = self::$req_user_id ?? get_current_user_id() ?: null;
        $client   = self::detect_client();

        $wpdb->insert(
            $wpdb->prefix . 'mcp_ability_log',
            [
                'logged_at'      => current_time( 'mysql', true ),   // UTC
                'tool'           => substr( $tool, 0, 100 ),
                'user_id'        => $user_id,
                'app_pass_name'  => self::$req_pass_name ? substr( self::$req_pass_name, 0, 200 ) : null,
                'app_pass_uuid'  => self::$req_pass_uuid,
                'client_name'    => $client['name']    !== '' ? substr( $client['name'],    0, 100 ) : null,
                'client_version' => $client['version'] !== '' ? substr( $client['version'], 0, 50  ) : null,
                'ip'             => self::get_ip(),
                'status'         => $status,
                'error_message'  => $error,
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Return paginated log rows plus a total count.
     *
     * @param array $args {
     *   @type int    $per_page  Rows per page. Default 50.
     *   @type int    $page      1-based page number. Default 1.
     *   @type string $tool      Filter by exact tool name.
     *   @type int    $user_id   Filter by WP user ID.
     *   @type string $status    'success' | 'error'
     *   @type string $date_from Y-m-d start date (inclusive, UTC).
     *   @type string $date_to   Y-m-d end date (inclusive, UTC).
     * }
     * @return array { rows: object[], total: int, per_page: int, page: int, pages: int }
     */
    public static function get_log( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ability_log';

        $args = wp_parse_args( $args, [
            'per_page'  => 50,
            'page'      => 1,
            'tool'      => '',
            'user_id'   => 0,
            'status'    => '',
            'date_from' => '',
            'date_to'   => '',
        ] );

        $conditions    = [ '1=1' ];
        $filter_values = [];

        if ( $args['tool'] ) {
            $conditions[]    = 'tool = %s';
            $filter_values[] = $args['tool'];
        }
        if ( $args['user_id'] ) {
            $conditions[]    = 'user_id = %d';
            $filter_values[] = (int) $args['user_id'];
        }
        if ( $args['status'] ) {
            $conditions[]    = 'status = %s';
            $filter_values[] = $args['status'];
        }
        if ( $args['date_from'] ) {
            $conditions[]    = 'logged_at >= %s';
            $filter_values[] = $args['date_from'] . ' 00:00:00';
        }
        if ( $args['date_to'] ) {
            $conditions[]    = 'logged_at <= %s';
            $filter_values[] = $args['date_to'] . ' 23:59:59';
        }

        $where = implode( ' AND ', $conditions );
        $limit  = max( 1, (int) $args['per_page'] );
        $offset = max( 0, ( (int) $args['page'] - 1 ) ) * $limit;

        // Main query with pagination.
        $main_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY logged_at DESC LIMIT %d OFFSET %d";
        $all_values = array_merge( $filter_values, [ $limit, $offset ] );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $main_sql, ...$all_values ) );

        // Count query (no LIMIT/OFFSET).
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if ( ! empty( $filter_values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$filter_values ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var( $count_sql );
        }

        return [
            'rows'     => $rows ?: [],
            'total'    => $total,
            'per_page' => $limit,
            'page'     => (int) $args['page'],
            'pages'    => (int) ceil( $total / $limit ) ?: 1,
        ];
    }

    /**
     * Summary stats for the dashboard header.
     */
    public static function get_summary(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ability_log';

        $today_start = gmdate( 'Y-m-d' ) . ' 00:00:00';
        $week_start  = gmdate( 'Y-m-d', strtotime( '-6 days' ) ) . ' 00:00:00';

        $today_calls = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE logged_at >= %s", $today_start )
        );

        $week_calls = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE logged_at >= %s", $week_start )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_calls = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $unique_users_today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE logged_at >= %s AND user_id IS NOT NULL",
            $today_start
        ) );

        $top_tool = $wpdb->get_row( $wpdb->prepare(
            "SELECT tool, COUNT(*) AS cnt FROM {$table} WHERE logged_at >= %s GROUP BY tool ORDER BY cnt DESC LIMIT 1",
            $week_start
        ) );

        $clients = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT client_name FROM {$table} WHERE logged_at >= %s AND client_name IS NOT NULL AND client_name != '' ORDER BY client_name",
            $week_start
        ) );

        return [
            'today_calls'        => $today_calls,
            'week_calls'         => $week_calls,
            'total_calls'        => $total_calls,
            'unique_users_today' => $unique_users_today,
            'top_tool'           => $top_tool ? $top_tool->tool : null,
            'top_tool_count'     => $top_tool ? (int) $top_tool->cnt : 0,
            'distinct_clients'   => $clients ?: [],
        ];
    }

    /**
     * Return distinct tool names for the filter dropdown.
     */
    public static function get_all_tools(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ability_log';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_col( "SELECT DISTINCT tool FROM {$table} ORDER BY tool ASC" ) ?: [];
    }

    // ── Maintenance ──────────────────────────────────────────────────────────

    /**
     * Remove log entries older than LOG_RETENTION_DAYS. Called by WP Cron.
     */
    public static function prune_old_entries(): void {
        global $wpdb;
        $table     = $wpdb->prefix . 'mcp_ability_log';
        $threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::LOG_RETENTION_DAYS . ' days' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $threshold ) );
    }

    // ── Table DDL ────────────────────────────────────────────────────────────

    /**
     * Create (or update) the log table using dbDelta.
     * Safe to call on every activation — dbDelta is idempotent.
     */
    public static function create_table(): void {
        global $wpdb;
        $table           = $wpdb->prefix . 'mcp_ability_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            logged_at      datetime             NOT NULL,
            tool           varchar(100)         NOT NULL DEFAULT '',
            user_id        bigint(20) UNSIGNED  DEFAULT NULL,
            app_pass_name  varchar(200)         DEFAULT NULL,
            app_pass_uuid  varchar(36)          DEFAULT NULL,
            client_name    varchar(100)         DEFAULT NULL,
            client_version varchar(50)          DEFAULT NULL,
            ip             varchar(45)          DEFAULT NULL,
            status         varchar(10)          NOT NULL DEFAULT 'success',
            error_message  text                 DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_logged_at (logged_at),
            KEY idx_user_id   (user_id),
            KEY idx_tool      (tool)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
    }

    /**
     * Drop the log table entirely. Called on plugin uninstall.
     */
    public static function drop_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ability_log';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::TABLE_VERSION_OPTION );
        wp_clear_scheduled_hook( 'mcp_abilities_prune_log' );
    }
}
