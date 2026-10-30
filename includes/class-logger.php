<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Activity logger — one row per ability call, in the mcp_ability_log table.
 *
 * Request_Context writes the row when a call ends, so every call is logged
 * once, whichever path ran it (core tools, extension tools, the adapter's
 * execute-ability tool, the Abilities REST route).
 *
 * Data captured per call:
 *   - Timestamp (UTC)
 *   - Tool (the most specific ability name: "update_post", not the dispatcher)
 *   - WP user ID
 *   - Sign-in method: oauth | app_password | cookie | cli        (v2)
 *   - The app as the revision label shows it ("Claude")          (v2)
 *   - OAuth client_id (a public identifier, never a token)       (v2)
 *   - App-password name + UUID
 *   - MCP session id, first 12 characters                        (v2)
 *   - Client name/version (the session's clientInfo, else the User-Agent)
 *   - Caller IP
 *   - Status (success / error) + error message
 *   - Objects changed: JSON [ { type:"post", id, revision } ]    (v2)
 */
class Logger {

    const TABLE_VERSION_OPTION = 'mcp_abilities_log_table_version';
    const TABLE_VERSION        = 2;
    const LOG_RETENTION_DAYS   = 90;

    // ── Bootstrap ───────────────────────────────────────────────────────────

    public static function init(): void {
        // A site updated in place never runs the activation hook: upgrade here.
        self::maybe_upgrade();

        // Daily pruning via WP Cron.
        if ( ! wp_next_scheduled( 'mcp_abilities_prune_log' ) ) {
            wp_schedule_event( time(), 'daily', 'mcp_abilities_prune_log' );
        }
        add_action( 'mcp_abilities_prune_log', [ __CLASS__, 'prune_old_entries' ] );
    }

    /** Bring the table to TABLE_VERSION when the stored version is lower. */
    public static function maybe_upgrade(): void {
        if ( (int) get_option( self::TABLE_VERSION_OPTION, 0 ) < self::TABLE_VERSION ) {
            self::create_table();
        }
    }

    // ── Data capture ────────────────────────────────────────────────────────

    /**
     * Kept for callers of the old API: Request_Context now captures the
     * Application Password on wp_application_password_did_authenticate.
     *
     * @param \WP_User $user
     * @param array    $item
     */
    public static function capture_app_pass( \WP_User $user, array $item ): void {
        Request_Context::capture_app_pass( $user, $item );
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
     * @param string     $tool    Ability key (e.g. 'update_post').
     * @param mixed      $result  What the call returned: an array (an error is
     *                            {success:false, error}), or a WP_Error.
     * @param array|null $objects What the call changed: [ { type, id, revision? }, … ].
     * @return int The new row's id (0 when the insert failed).
     */
    public static function log( string $tool, $result, ?array $objects = null ): int {
        global $wpdb;

        [ $status, $error ] = self::status_of( $result );

        $ctx    = Request_Context::log_fields();
        $client = '' !== $ctx['client_name']
            ? [ 'name' => $ctx['client_name'], 'version' => $ctx['client_version'] ]
            : self::detect_client();

        $objects_json = null;
        if ( $objects ) {
            $encoded      = wp_json_encode( array_values( $objects ), JSON_UNESCAPED_SLASHES );
            $objects_json = false === $encoded ? null : $encoded;
        }

        $ok = $wpdb->insert(
            $wpdb->prefix . 'mcp_ability_log',
            [
                'logged_at'       => current_time( 'mysql', true ),   // UTC
                'tool'            => substr( $tool, 0, 100 ),
                'user_id'         => $ctx['user_id'] ?: null,
                'auth_method'     => '' !== $ctx['auth_method'] ? substr( $ctx['auth_method'], 0, 20 ) : null,
                'client_label'    => '' !== $ctx['client_label'] ? substr( $ctx['client_label'], 0, 200 ) : null,
                'oauth_client_id' => '' !== $ctx['oauth_client_id'] ? substr( $ctx['oauth_client_id'], 0, 64 ) : null,
                'app_pass_name'   => '' !== $ctx['app_pass_name'] ? substr( $ctx['app_pass_name'], 0, 200 ) : null,
                'app_pass_uuid'   => '' !== $ctx['app_pass_uuid'] ? substr( $ctx['app_pass_uuid'], 0, 36 ) : null,
                'session_id'      => '' !== $ctx['session_id'] ? substr( $ctx['session_id'], 0, 12 ) : null,
                'client_name'     => $client['name']    !== '' ? substr( $client['name'],    0, 100 ) : null,
                'client_version'  => $client['version'] !== '' ? substr( $client['version'], 0, 50  ) : null,
                'ip'              => self::get_ip(),
                'status'          => $status,
                'error_message'   => $error,
                'objects'         => $objects_json,
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * @param mixed $result
     * @return array{0:string,1:?string} [ status, error message ]
     */
    private static function status_of( $result ): array {
        if ( is_wp_error( $result ) ) {
            return [ 'error', substr( $result->get_error_message(), 0, 500 ) ];
        }
        if ( is_array( $result ) && array_key_exists( 'success', $result ) && false === $result['success'] ) {
            $error = $result['error'] ?? '';
            if ( is_array( $error ) ) {
                $error = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : (string) wp_json_encode( $error );
            }
            return [ 'error', substr( (string) $error, 0, 500 ) ];
        }
        return [ 'success', null ];
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Return paginated log rows plus a total count.
     *
     * @param array $args {
     *   @type int    $per_page  Rows per page. Default 50.
     *   @type int    $page      1-based page number. Default 1.
     *   @type int    $id        One row by id (from a revision's link).
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
            'id'        => 0,
            'tool'      => '',
            'user_id'   => 0,
            'status'    => '',
            'date_from' => '',
            'date_to'   => '',
        ] );

        $conditions    = [ '1=1' ];
        $filter_values = [];

        if ( $args['id'] ) {
            $conditions[]    = 'id = %d';
            $filter_values[] = (int) $args['id'];
        }
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
        $main_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY logged_at DESC, id DESC LIMIT %d OFFSET %d";
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
     * The objects a row lists, decoded.
     *
     * @param object $row A row from get_log().
     * @return array<int, array{type:string,id:int,revision?:int|null,deleted?:bool}>
     */
    public static function row_objects( $row ): array {
        $raw = is_object( $row ) && isset( $row->objects ) ? (string) $row->objects : '';
        if ( '' === $raw ) {
            return [];
        }
        $data = json_decode( $raw, true );
        return is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : [];
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
     * Revisions keep their own copy of who made them (_mcp_abilities_actor).
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
     * Safe to call on every activation — dbDelta is idempotent, and adds the
     * v2 columns to a v1 table without touching its rows.
     *
     * No SQL comments inside the CREATE TABLE: dbDelta reads every line as a
     * column and turns a comment into a broken ALTER. One space between a
     * column's name and type, or dbDelta re-issues CHANGE COLUMN every run.
     * v2 columns: auth_method, client_label, oauth_client_id, session_id, objects.
     */
    public static function create_table(): void {
        global $wpdb;
        $table           = $wpdb->prefix . 'mcp_ability_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
logged_at datetime NOT NULL,
tool varchar(100) NOT NULL DEFAULT '',
user_id bigint(20) unsigned DEFAULT NULL,
auth_method varchar(20) DEFAULT NULL,
client_label varchar(200) DEFAULT NULL,
oauth_client_id varchar(64) DEFAULT NULL,
app_pass_name varchar(200) DEFAULT NULL,
app_pass_uuid varchar(36) DEFAULT NULL,
session_id varchar(12) DEFAULT NULL,
client_name varchar(100) DEFAULT NULL,
client_version varchar(50) DEFAULT NULL,
ip varchar(45) DEFAULT NULL,
status varchar(10) NOT NULL DEFAULT 'success',
error_message text DEFAULT NULL,
objects text DEFAULT NULL,
PRIMARY KEY  (id),
KEY idx_logged_at (logged_at),
KEY idx_user_id (user_id),
KEY idx_tool (tool)
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
