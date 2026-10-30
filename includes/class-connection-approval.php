<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * "Require admin approval for new connections" — off by default.
 *
 * When on, a new AI connection can connect but gets no abilities until a site
 * administrator approves it on AI Nexus → Connect. One switch: the toggle on
 * the Connect page, or the VGC_AI_NEXUS_REQUIRE_APPROVAL constant in
 * wp-config.php (forces it on and locks the toggle) — the read-only pattern.
 *
 * A connection is one sign-in route of one user:
 *   - OAuth (claude.ai):        "oauth:<client_id>:<user_id>"  (as Operators)
 *   - Application Password:     "app:<uuid>:<user_id>"
 * Never held: WP-CLI / STDIO, a signed-in browser (cookie), and — filter
 * mcp_abilities_approval_exempt_admins, default true — users who can
 * manage_options.
 *
 * A held connection (pending, or rejected) sees exactly one tool,
 * mcp-abilities-connection-status, and an initialize message that says it is
 * waiting. Everything else is refused with a clear message and logged with
 * status "pending_approval". It is enforced in several places, so it holds for
 * core, every extension, the adapter's execute-ability tool, the Abilities
 * REST API and other plugins' abilities:
 *   - rest_pre_dispatch: MCP tools/call (answered before any tool runs), the
 *     /wp-abilities/ routes, and every other REST route for a held OAuth token;
 *   - mcp_adapter_tools_list / mcp_adapter_initialize_response;
 *   - the base Ability::current_user_can() (every AI Nexus ability, core and
 *     extensions, whatever adapter serves it);
 *   - wp_register_ability_args: every WordPress ability's permission and
 *     execute callbacks, any plugin's.
 *
 * Records: non-autoloaded option RECORDS, key → { status, user_id, auth,
 * client_id | app_pass_uuid, app, operator, first_seen, last_seen, ip,
 * decided_by, decided_at, note, notified }. Created at OAuth consent (Approve)
 * and on the first MCP request of an unknown connection; last_seen is written
 * at most once a minute. A new pending record emails the site admin (filter
 * mcp_abilities_approval_notify_to), at most once per connection and 10 times
 * an hour site-wide.
 *
 * Switching on approves every existing live connection at that moment
 * (unless the admin asks otherwise), so scheduled routines keep working.
 * Switching off lets everyone through again; records are kept and ignored.
 */
final class Connection_Approval {

    public const OPTION        = 'mcp_abilities_require_approval';
    public const CONSTANT      = 'VGC_AI_NEXUS_REQUIRE_APPROVAL';
    public const RECORDS       = 'mcp_abilities_connection_approvals';
    public const MAIL_LOG      = 'mcp_abilities_approval_mail_times';
    public const STATUS_TOOL   = 'mcp-abilities-connection-status';
    public const ERROR_CODE    = 'mcp_abilities_pending_approval';
    public const LOG_STATUS    = 'pending_approval';
    private const MAIL_PER_HOUR = 10;
    private const TOUCH_EVERY   = 60;

    /** Per-request decision: [ 'key' => string, 'held' => array|null ]. */
    private static ?array $state = null;
    private static bool $booted  = false;

    public static function init(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        add_filter( 'rest_pre_dispatch', [ self::class, 'gate_rest' ], 1, 3 );
        add_filter( 'mcp_adapter_tools_list', [ self::class, 'filter_tools_list' ], 999, 2 );
        add_filter( 'mcp_adapter_initialize_response', [ self::class, 'held_instructions' ], 99, 1 );
        add_filter( 'mcp_adapter_pre_tool_call', [ self::class, 'pre_tool_call' ], 5, 4 );
        // After Tool_Errors (10) and Request_Context (20): the outermost wrap.
        add_filter( 'wp_register_ability_args', [ self::class, 'wrap_ability' ], 25, 2 );

        add_action( 'admin_notices', [ self::class, 'admin_notice' ] );
        add_action( 'admin_menu', [ self::class, 'menu_bubble' ], 999 );
    }

    // ── The setting ──────────────────────────────────────────────────────────

    public static function enabled(): bool {
        if ( self::forced() ) {
            return true;
        }
        /**
         * Whether new MCP connections need an administrator's approval.
         *
         * @param bool $on The Connect-page setting.
         */
        return (bool) apply_filters( 'mcp_abilities_require_approval', (bool) get_option( self::OPTION, false ) );
    }

    /** True when wp-config.php forces approval on (the toggle is then locked). */
    public static function forced(): bool {
        return defined( self::CONSTANT ) && constant( self::CONSTANT );
    }

    /**
     * Switch on. Existing live connections are approved as of now unless
     * $existing_pending: OAuth connections with a live refresh token, and
     * Application Passwords seen in the activity log in the last 90 days.
     *
     * @return int How many connections were approved this way.
     */
    public static function enable( bool $existing_pending, int $admin_id ): int {
        // Approve first, then switch on: a request arriving in between must
        // not find a live connection without its record.
        $count = $existing_pending ? 0 : self::grandfather( $admin_id );
        update_option( self::OPTION, true, true );
        self::$state = null;
        Logger::log( 'connection_approval_enabled', [ 'success' => true ], [ [ 'type' => 'setting', 'id' => 0, 'action' => $existing_pending ? 'existing connections held' : "existing connections approved: {$count}" ] ] );
        return $count;
    }

    public static function disable(): void {
        update_option( self::OPTION, false, true );
        self::$state = null;
        Logger::log( 'connection_approval_disabled', [ 'success' => true ], [ [ 'type' => 'setting', 'id' => 0, 'action' => 'off' ] ] );
    }

    private static function grandfather( int $admin_id ): int {
        $found = [];
        if ( class_exists( OAuth\Token_Store::class ) ) {
            foreach ( OAuth\Token_Store::summary_by_client() as $client_id => $usage ) {
                foreach ( (array) ( $usage['users'] ?? [] ) as $user_id ) {
                    $client  = OAuth\Client_Manager::get( (string) $client_id );
                    $found[] = [
                        'key'           => self::key( 'oauth', (string) $client_id, (int) $user_id ),
                        'auth'          => 'oauth',
                        'user_id'       => (int) $user_id,
                        'client_id'     => (string) $client_id,
                        'app_pass_uuid' => '',
                        'app'           => is_array( $client ) ? (string) ( $client['name'] ?? '' ) : '',
                        'operator'      => OAuth\Operators::get( (string) $client_id, (int) $user_id ),
                    ];
                }
            }
        }
        global $wpdb;
        $since = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT app_pass_uuid, user_id, MAX(app_pass_name) AS name FROM {$wpdb->prefix}mcp_ability_log WHERE app_pass_uuid IS NOT NULL AND app_pass_uuid <> '' AND user_id IS NOT NULL AND logged_at >= %s GROUP BY app_pass_uuid, user_id",
            $since
        ) );
        foreach ( (array) $rows as $row ) {
            $found[] = [
                'key'           => self::key( 'app_password', (string) $row->app_pass_uuid, (int) $row->user_id ),
                'auth'          => 'app_password',
                'user_id'       => (int) $row->user_id,
                'client_id'     => '',
                'app_pass_uuid' => (string) $row->app_pass_uuid,
                'app'           => (string) $row->name,
                'operator'      => '',
            ];
        }

        $records = self::records();
        $count   = 0;
        foreach ( $found as $conn ) {
            if ( self::is_exempt_user( $conn['user_id'] ) ) {
                continue;
            }
            $existing = $records[ $conn['key'] ] ?? null;
            // An administrator's explicit rejection stands.
            if ( is_array( $existing ) && in_array( $existing['status'] ?? '', [ 'approved', 'rejected' ], true ) ) {
                continue;
            }
            $records[ $conn['key'] ] = self::new_record( $conn, 'approved' ) + [];
            $records[ $conn['key'] ]['decided_by'] = $admin_id;
            $records[ $conn['key'] ]['decided_at'] = time();
            $records[ $conn['key'] ]['note']       = 'approved when approval was switched on';
            $records[ $conn['key'] ]['ip']         = '';
            ++$count;
        }
        self::save( $records );
        return $count;
    }

    // ── Connections ──────────────────────────────────────────────────────────

    public static function key( string $auth, string $id, int $user_id ): string {
        return ( 'oauth' === $auth ? 'oauth:' : 'app:' ) . $id . ':' . $user_id;
    }

    private static function is_exempt_user( int $user_id ): bool {
        /**
         * Whether administrators (manage_options) are never held.
         *
         * @param bool $exempt Default true.
         */
        return $user_id > 0 && user_can( $user_id, 'manage_options' ) && (bool) apply_filters( 'mcp_abilities_approval_exempt_admins', true );
    }

    /**
     * The current request's connection, or null when it is never held (no
     * user, browser cookie, WP-CLI/STDIO, or an exempt administrator).
     *
     * @return array{key:string,auth:string,user_id:int,client_id:string,app_pass_uuid:string,app:string,operator:string}|null
     */
    public static function current_connection(): ?array {
        $actor   = Request_Context::actor();
        $user_id = (int) $actor['user_id'];
        $auth    = (string) $actor['auth'];
        if ( ! $user_id || ! in_array( $auth, [ 'oauth', 'app_password' ], true ) || self::is_exempt_user( $user_id ) ) {
            return null;
        }
        $id = 'oauth' === $auth ? (string) $actor['client_id'] : (string) $actor['app_pass_uuid'];
        return [
            'key'           => self::key( $auth, $id, $user_id ),
            'auth'          => $auth,
            'user_id'       => $user_id,
            'client_id'     => 'oauth' === $auth ? $id : '',
            'app_pass_uuid' => 'app_password' === $auth ? $id : '',
            'app'           => (string) $actor['app'],
            'operator'      => (string) ( $actor['operator'] ?? '' ),
        ];
    }

    /**
     * Whether the current request's connection is held, and why. Creates the
     * pending record (and emails the admin) the first time a connection is seen.
     *
     * @return array{status:string,record:array,key:string}|null Null = allowed.
     */
    public static function held(): ?array {
        if ( ! self::enabled() ) {
            return null;
        }
        $conn = self::current_connection();
        if ( null === $conn ) {
            return null;
        }
        if ( null !== self::$state && self::$state['key'] === $conn['key'] ) {
            return self::$state['held'];
        }
        $records = self::records();
        $record  = $records[ $conn['key'] ] ?? null;
        if ( ! is_array( $record ) ) {
            $record = self::create_pending( $conn );
        } elseif ( time() - (int) ( $record['last_seen'] ?? 0 ) >= self::TOUCH_EVERY ) {
            $record['last_seen']        = time();
            $record['ip']               = self::ip();
            $records[ $conn['key'] ]    = $record;
            self::save( $records );
        }
        $held = 'approved' === ( $record['status'] ?? '' )
            ? null
            : [ 'status' => 'rejected' === ( $record['status'] ?? '' ) ? 'rejected' : 'pending', 'record' => $record, 'key' => $conn['key'] ];
        self::$state = [ 'key' => $conn['key'], 'held' => $held ];
        return $held;
    }

    public static function is_held(): bool {
        return null !== self::held();
    }

    /** What a held connection is told. */
    public static function message( ?array $held = null ): string {
        $held = $held ?? self::held();
        if ( $held && 'rejected' === $held['status'] ) {
            return 'A site administrator has rejected this connection, so it cannot use this site\'s tools. Ask an administrator of the site to approve it (WordPress Admin → AI Nexus → Connect).';
        }
        return 'This connection is waiting for an administrator to approve it. Until then it cannot use this site\'s tools. Ask an administrator of the site to approve it (WordPress Admin → AI Nexus → Connect).';
    }

    public static function error(): \WP_Error {
        return new \WP_Error( self::ERROR_CODE, self::message(), [ 'status' => 403 ] );
    }

    // ── Records ──────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    public static function records(): array {
        $records = get_option( self::RECORDS, [] );
        return is_array( $records ) ? $records : [];
    }

    private static function save( array $records ): void {
        if ( false === get_option( self::RECORDS, false ) ) {
            add_option( self::RECORDS, $records, '', false );
        } else {
            update_option( self::RECORDS, $records, false );
        }
    }

    private static function new_record( array $conn, string $status ): array {
        return [
            'status'        => $status,
            'user_id'       => (int) $conn['user_id'],
            'auth'          => (string) $conn['auth'],
            'client_id'     => (string) $conn['client_id'],
            'app_pass_uuid' => (string) $conn['app_pass_uuid'],
            'app'           => OAuth\Operators::sanitize( (string) $conn['app'] ),
            'operator'      => OAuth\Operators::sanitize( (string) $conn['operator'] ),
            'first_seen'    => time(),
            'last_seen'     => time(),
            'ip'            => self::ip(),
            'decided_by'    => 0,
            'decided_at'    => 0,
            'note'          => '',
            'notified'      => false,
        ];
    }

    private static function create_pending( array $conn ): array {
        $record   = self::new_record( $conn, 'pending' );
        $records  = self::records();
        // Another request may have created it a moment ago.
        if ( isset( $records[ $conn['key'] ] ) && is_array( $records[ $conn['key'] ] ) ) {
            return $records[ $conn['key'] ];
        }
        $record['notified']        = self::notify( $conn['key'], $record );
        $records[ $conn['key'] ]   = $record;
        self::save( $records );
        return $record;
    }

    /**
     * OAuth consent page, Approve: the connection is known from that moment,
     * with the name the person typed. Nothing happens while approval is off.
     */
    public static function on_oauth_consent( string $client_id, int $user_id, string $operator ): void {
        if ( ! self::enabled() || '' === $client_id || $user_id <= 0 || self::is_exempt_user( $user_id ) ) {
            return;
        }
        $key     = self::key( 'oauth', $client_id, $user_id );
        $records = self::records();
        if ( isset( $records[ $key ] ) && is_array( $records[ $key ] ) ) {
            if ( '' !== $operator ) {
                $records[ $key ]['operator'] = OAuth\Operators::sanitize( $operator );
                self::save( $records );
            }
            return;
        }
        $client = OAuth\Client_Manager::get( $client_id );
        self::create_pending( [
            'key'           => $key,
            'auth'          => 'oauth',
            'user_id'       => $user_id,
            'client_id'     => $client_id,
            'app_pass_uuid' => '',
            'app'           => is_array( $client ) ? (string) ( $client['name'] ?? '' ) : '',
            'operator'      => $operator,
        ] );
    }

    /**
     * An administrator's decision: approve, reject, revoke (back to pending)
     * or remove (forget the record). Logged in the activity log.
     */
    public static function decide( string $key, string $decision, int $admin_id ): bool {
        $records = self::records();
        if ( ! isset( $records[ $key ] ) || ! in_array( $decision, [ 'approve', 'reject', 'revoke', 'remove' ], true ) ) {
            return false;
        }
        $tools = [
            'approve' => 'connection_approved',
            'reject'  => 'connection_rejected',
            'revoke'  => 'connection_approval_revoked',
            'remove'  => 'connection_record_removed',
        ];
        if ( 'remove' === $decision ) {
            unset( $records[ $key ] );
        } else {
            $records[ $key ]['status']     = [ 'approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'pending' ][ $decision ];
            $records[ $key ]['decided_by'] = $admin_id;
            $records[ $key ]['decided_at'] = time();
            $records[ $key ]['note']       = 'revoke' === $decision ? 'approval revoked' : '';
        }
        self::save( $records );
        self::$state = null;
        Logger::log( $tools[ $decision ], [ 'success' => true ], [ [ 'type' => 'connection', 'id' => 0, 'key' => $key, 'action' => $decision ] ] );
        return true;
    }

    public static function pending_count(): int {
        return count( array_filter( self::records(), static fn( $r ) => is_array( $r ) && 'pending' === ( $r['status'] ?? '' ) ) );
    }

    /** The name shown for a record's "who is connecting": the current one for OAuth. */
    public static function operator_of( array $record ): string {
        if ( 'oauth' === ( $record['auth'] ?? '' ) && ! empty( $record['client_id'] ) ) {
            $now = OAuth\Operators::get( (string) $record['client_id'], (int) ( $record['user_id'] ?? 0 ) );
            if ( '' !== $now ) {
                return $now;
            }
        }
        return (string) ( $record['operator'] ?? '' );
    }

    private static function ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    // ── Notification ─────────────────────────────────────────────────────────

    /** Email the admin about a new pending connection. True when sent. */
    private static function notify( string $key, array $record ): bool {
        $times = array_values( array_filter( (array) get_option( self::MAIL_LOG, [] ), static fn( $t ) => (int) $t > time() - HOUR_IN_SECONDS ) );
        if ( count( $times ) >= self::MAIL_PER_HOUR ) {
            return false;
        }
        $times[] = time();
        if ( false === get_option( self::MAIL_LOG, false ) ) {
            add_option( self::MAIL_LOG, $times, '', false );
        } else {
            update_option( self::MAIL_LOG, $times, false );
        }

        /**
         * Who is told about a new connection waiting for approval.
         *
         * @param string[] $to     Default: the site admin email.
         * @param array    $record The pending record.
         */
        $to = array_values( array_filter( array_map( 'sanitize_email', (array) apply_filters( 'mcp_abilities_approval_notify_to', [ (string) get_option( 'admin_email' ) ], $record ) ) ) );
        if ( ! $to ) {
            return false;
        }
        $user  = get_userdata( (int) $record['user_id'] );
        $plain = static function ( $text ): string {
            // Plain text, one line, no markup: some sites wrap every email in HTML.
            $text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
            return trim( str_replace( [ '<', '>', "\r", "\n" ], [ '', '', ' ', ' ' ], $text ) );
        };
        $site  = $plain( get_bloginfo( 'name' ) );
        $lines = [
            sprintf( 'A new AI connection to %s is waiting for your approval. It cannot use any of the site\'s tools until an administrator approves it.', $site ),
            '',
            'Who: ' . ( $user ? $plain( $user->display_name ) . ' (' . $plain( $user->user_login ) . ', ' . $plain( implode( ', ', (array) $user->roles ) ) . ')' : '#' . (int) $record['user_id'] ),
            'Signed in with: ' . ( 'oauth' === $record['auth'] ? 'claude.ai sign-in (OAuth)' : 'an Application Password' ),
            'App: ' . ( '' !== $plain( $record['app'] ) ? $plain( $record['app'] ) : '—' ),
            'Says they are: ' . ( '' !== $plain( $record['operator'] ) ? $plain( $record['operator'] ) . ' (not checked)' : '—' ),
            'From: ' . ( '' !== $record['ip'] ? $record['ip'] : '—' ),
            '',
            'Approve or reject it here: ' . admin_url( 'admin.php?page=mcp-abilities-connect#mcp-approval' ),
        ];
        return (bool) wp_mail( $to, sprintf( '[%s] An AI connection is waiting for your approval', $site ), implode( "\n", $lines ) );
    }

    // ── Enforcement ──────────────────────────────────────────────────────────

    /**
     * rest_pre_dispatch, priority 1 (after Request_Context::detect at 0).
     *
     * @param mixed            $result
     * @param mixed            $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function gate_rest( $result, $server, $request ) {
        if ( null !== $result || ! $request instanceof \WP_REST_Request || ! self::enabled() ) {
            return $result;
        }
        $route = strtolower( '/' . ltrim( (string) $request->get_route(), '/' ) );
        $kind  = Request_Context::route_kind( $route );

        // The WordPress Abilities API (list, run): nothing for a held connection.
        if ( 0 === strpos( $route, '/wp-abilities/' ) ) {
            $held = self::held();
            if ( null !== $held ) {
                self::log_refusal( 'abilities-rest:' . $route, $held );
                return new \WP_Error( self::ERROR_CODE, self::message( $held ), [ 'status' => 403 ] );
            }
            return $result;
        }

        // An MCP server route: the transport answers initialize and tools/list
        // (both filtered below); a tool call is answered here, before any tool
        // or permission check runs, so every tool gets the same clear refusal.
        if ( 'http' === $kind ) {
            $held = self::held();
            if ( null === $held ) {
                return $result;
            }
            $body = $request->get_json_params();
            if ( is_array( $body ) && 'tools/call' === ( $body['method'] ?? '' ) ) {
                $tool = (string) ( $body['params']['name'] ?? '' );
                if ( self::STATUS_TOOL !== $tool ) {
                    self::log_refusal( $tool, $held );
                    return new \WP_REST_Response( [
                        'jsonrpc' => '2.0',
                        'id'      => $body['id'] ?? null,
                        'result'  => [
                            'content' => [ [ 'type' => 'text', 'text' => self::message( $held ) ] ],
                            'isError' => true,
                        ],
                    ], 200 );
                }
            }
            return $result;
        }

        // A claude.ai (OAuth) token exists for MCP only: while it is held, no
        // other REST route either (its own sign-in routes excepted).
        if ( 'oauth' === (string) Request_Context::actor()['auth'] && 0 !== strpos( $route, '/vgc-cc/' ) ) {
            $held = self::held();
            if ( null !== $held ) {
                return new \WP_Error( self::ERROR_CODE, self::message( $held ), [ 'status' => 403 ] );
            }
        }
        return $result;
    }

    private static function log_refusal( string $tool, array $held ): void {
        try {
            Logger::log( '' !== $tool ? $tool : 'tools/call', new \WP_Error( self::ERROR_CODE, self::message( $held ) ), [ [ 'type' => 'connection', 'id' => 0, 'key' => $held['key'], 'action' => $held['status'] ] ] );
        } catch ( \Throwable $e ) {
            // Never let logging break the refusal.
        }
    }

    /**
     * mcp_adapter_tools_list: a held connection sees only the status tool;
     * everyone else never sees it.
     *
     * @param array $tools Tool DTOs.
     * @return array
     */
    public static function filter_tools_list( $tools, $server = null ) {
        if ( ! is_array( $tools ) ) {
            return $tools;
        }
        $held = self::held();
        $out  = [];
        foreach ( $tools as $tool ) {
            $name = is_object( $tool ) && method_exists( $tool, 'getName' ) ? (string) $tool->getName() : ( is_array( $tool ) ? (string) ( $tool['name'] ?? '' ) : '' );
            if ( ( null !== $held ) === ( self::STATUS_TOOL === $name ) ) {
                $out[] = $tool;
            }
        }
        return $out;
    }

    /**
     * mcp_adapter_initialize_response: a held connection is told it waits —
     * and nothing about the site's tools or content.
     *
     * @param mixed $result InitializeResult DTO.
     * @return mixed
     */
    public static function held_instructions( $result ) {
        $held = self::held();
        if ( null === $held || ! is_object( $result ) || ! method_exists( $result, 'toArray' ) ) {
            return $result;
        }
        try {
            $data                 = $result->toArray();
            $data['instructions'] = ( 'rejected' === $held['status']
                ? 'A site administrator has REJECTED this connection.'
                : 'This connection is WAITING FOR AN ADMINISTRATOR\'S APPROVAL.' )
                . ' Until it is approved, none of the site\'s tools are available. The only tool is ' . self::STATUS_TOOL
                . ', which says whether the connection has been approved. Tell the user that a site administrator has to approve this connection under WordPress Admin → AI Nexus → Connect; once approved, reconnect or start a new chat to load the tools.';
            $class = get_class( $result );
            if ( method_exists( $class, 'fromArray' ) ) {
                return $class::fromArray( $data );
            }
        } catch ( \Throwable $e ) {
            return $result;
        }
        return $result;
    }

    /**
     * mcp_adapter_pre_tool_call: a second guard for a tool call that got past
     * the REST gate (e.g. the STDIO bridge never does; batches might).
     *
     * @return mixed
     */
    public static function pre_tool_call( $args, $tool_name = '', $mcp_tool = null, $server = null ) {
        if ( is_wp_error( $args ) || self::STATUS_TOOL === (string) $tool_name ) {
            return $args;
        }
        $held = self::held();
        if ( null !== $held ) {
            self::log_refusal( (string) $tool_name, $held );
            return new \WP_Error( self::ERROR_CODE, self::message( $held ) );
        }
        return $args;
    }

    /**
     * wp_register_ability_args: every WordPress ability, any plugin's — its
     * permission check fails and its execute callback refuses while held.
     *
     * @param mixed  $args
     * @param string $name
     * @return mixed
     */
    public static function wrap_ability( $args, $name = '' ) {
        if ( ! is_array( $args ) ) {
            return $args;
        }
        if ( isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ) {
            $permission                  = $args['permission_callback'];
            $args['permission_callback'] = static function ( ...$input ) use ( $permission ) {
                if ( self::is_held() ) {
                    return false;
                }
                return $permission( ...$input );
            };
        }
        if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
            $execute                  = $args['execute_callback'];
            $ability                  = (string) $name;
            $args['execute_callback'] = static function ( ...$input ) use ( $execute, $ability ) {
                $held = self::held();
                if ( null !== $held ) {
                    self::log_refusal( $ability, $held );
                    return new \WP_Error( self::ERROR_CODE, self::message( $held ) );
                }
                return $execute( ...$input );
            };
        }
        return $args;
    }

    /**
     * The status tool's answer. Reveals nothing about the site.
     *
     * @return array{status:string,message:string,requested_at:string}
     */
    public static function status_payload(): array {
        $held = self::held();
        if ( null === $held ) {
            return [
                'status'       => 'approved',
                'message'      => 'This connection may use the site\'s tools. If none are listed, reconnect or start a new chat to load them.',
                'requested_at' => '',
            ];
        }
        $first = (int) ( $held['record']['first_seen'] ?? 0 );
        return [
            'status'       => $held['status'],
            'message'      => self::message( $held ),
            'requested_at' => $first ? gmdate( 'c', $first ) : '',
        ];
    }

    /** The status tool for the default server (McpTool), or null on a foreign adapter. */
    public static function status_tool() {
        if ( ! class_exists( '\WP\MCP\Domain\Tools\McpTool' ) || ! method_exists( '\WP\MCP\Domain\Tools\McpTool', 'fromArray' ) ) {
            return null;
        }
        $tool = \WP\MCP\Domain\Tools\McpTool::fromArray( [
            'name'        => self::STATUS_TOOL,
            'description' => 'Says whether a site administrator has approved this connection: status "pending", "rejected" or "approved", with a message and when the connection was first seen. While a connection waits for approval, this is its only tool.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'verbose' => [ 'type' => 'boolean', 'description' => 'Reserved for future use. Has no effect.' ],
                ],
            ],
            'annotations' => [ 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ],
            'handler'     => static function () {
                return self::status_payload();
            },
            'permission'  => static function () {
                return is_user_logged_in();
            },
        ] );
        return $tool instanceof \WP_Error ? null : $tool;
    }

    // ── Admin notice + menu count ────────────────────────────────────────────

    public static function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) || ! self::enabled() ) {
            return;
        }
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( 'mcp-abilities' !== $page && 0 !== strpos( $page, 'mcp-abilities-' ) ) {
            return;
        }
        $count = self::pending_count();
        if ( ! $count ) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>VGC AI Nexus:</strong> '
            . esc_html( sprintf(
                /* translators: %d: number of connections */
                _n( '%d AI connection is waiting for your approval. It has no access to the site until you approve it.', '%d AI connections are waiting for your approval. They have no access to the site until you approve them.', $count, 'mcp-abilities' ),
                $count
            ) )
            . ' <a href="' . esc_url( admin_url( 'admin.php?page=mcp-abilities-connect#mcp-approval' ) ) . '">' . esc_html__( 'Review', 'mcp-abilities' ) . '</a></p></div>';
    }

    /** A count bubble on the AI Nexus menu and its Connect item. */
    public static function menu_bubble(): void {
        global $menu, $submenu;
        if ( ! current_user_can( 'manage_options' ) || ! self::enabled() ) {
            return;
        }
        $count = self::pending_count();
        if ( ! $count ) {
            return;
        }
        $bubble = ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>';
        foreach ( (array) $menu as $i => $item ) {
            if ( isset( $item[2] ) && 'mcp-abilities' === $item[2] ) {
                $menu[ $i ][0] .= $bubble; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            }
        }
        foreach ( (array) ( $submenu['mcp-abilities'] ?? [] ) as $i => $item ) {
            if ( isset( $item[2] ) && 'mcp-abilities-connect' === $item[2] ) {
                $submenu['mcp-abilities'][ $i ][0] .= $bubble; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            }
        }
    }

    /** One line for the usage guide. */
    public static function guide_line(): string {
        return self::enabled()
            ? 'ON — a new connection gets no tools until a site administrator approves it on AI Nexus → Connect; until then it sees only ' . self::STATUS_TOOL . ' (status pending/rejected) and every other call is refused.'
            : 'off — every signed-in connection can use the tools its user is allowed to.';
    }

    /** Tests that simulate several requests in one process. */
    public static function reset(): void {
        self::$state = null;
    }
}
