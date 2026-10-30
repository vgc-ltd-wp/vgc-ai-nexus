<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Who is acting, through which app, in which tool call — for the whole request.
 *
 * One place answers three questions for every part of AI Nexus that records a
 * change (revisions now; WooCommerce order notes and comments next):
 *
 *   1. Is this an MCP request? Decided on rest_pre_dispatch (priority 0) from the
 *      route: an MCP adapter server route (namespace "mcp", or any server the
 *      adapter registered) or the WordPress Abilities ".../run" route. Nested
 *      rest_do_request() calls inherit it. A tool call through the adapter's
 *      ToolsHandler (the STDIO transport included) also marks the request.
 *   2. Who is acting? actor() — the user, how they signed in (oauth,
 *      app_password, cookie), the OAuth app or Application Password, the MCP
 *      session's clientInfo, and the ability being run. label() turns it into
 *      "Vergona — via MCP (Claude)".
 *   3. Which call is running? Every ability call is a "call" with frames:
 *      the adapter's tool call (mcp_adapter_pre_tool_call / _result), every WP
 *      ability execution (its execute_callback is wrapped at registration) and
 *      every AI Nexus ability run (Plugin::run_ability). Frames nest; the
 *      outermost one closing ends the call. Then:
 *        - do_action( 'mcp_abilities_call_end', $call_id ) — recorders add the
 *          objects they touched with add_object() (and may create revisions);
 *        - one activity-log row is written for the call, objects included;
 *        - do_action( 'mcp_abilities_call_logged', $log_id, $call_id, $objects )
 *          — recorders link their objects back to the log row.
 *      So EVERY ability call is logged once: core tools, extension tools (their
 *      handlers call $ability->execute() directly), the adapter's
 *      execute-ability tool and the Abilities REST route.
 *
 * Never stores a token or secret: the OAuth client_id is a public identifier,
 * an Application Password is kept by name and UUID, the session by its first
 * 12 characters.
 */
final class Request_Context {

    /** Version of the actor array stored in revision and post meta. */
    const ACTOR_SCHEMA = 1;

    /** How specific a call name is: a more specific one replaces a vaguer one. */
    const RANK_META       = 0; // the adapter's own execute/discover/info tools
    const RANK_TOOL       = 1; // an MCP tool name
    const RANK_WP_ABILITY = 2; // a WordPress ability name
    const RANK_ABILITY    = 3; // an AI Nexus ability key
    const RANK_ACTION     = 4; // the action a dispatcher (Crud_Ability) runs

    /** Adapter-provided tools/abilities that only wrap another ability. */
    private const META_NAMES = [
        'mcp-adapter/execute-ability', 'mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info',
        'mcp-adapter-execute-ability', 'mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info',
    ];

    private static bool   $booted     = false;
    private static bool   $is_mcp     = false;
    private static string $transport  = '';   // 'http' | 'abilities-rest' | 'stdio'
    private static string $session_id = '';   // full id, memory only; stored as a 12-char prefix
    private static ?array $actor      = null; // resolved once per user
    private static ?array $app_pass   = null; // [ 'uuid', 'name', 'user_id' ] from the auth hook

    /** @var int[] Open frame tokens, outermost first. */
    private static array  $stack = [];
    private static ?array $call  = null;
    private static int    $seq   = 0;
    private static int    $calls = 0;

    public static function init(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        add_action( 'wp_application_password_did_authenticate', [ self::class, 'capture_app_pass' ], 10, 2 );
        add_filter( 'rest_pre_dispatch', [ self::class, 'detect' ], 0, 3 );

        // Frames around every MCP tool call (adapter 0.5+). Late, so a filter
        // that refuses the call first never leaves a frame open.
        add_filter( 'mcp_adapter_pre_tool_call', [ self::class, 'on_pre_tool_call' ], PHP_INT_MAX, 4 );
        add_filter( 'mcp_adapter_tool_call_result', [ self::class, 'on_tool_call_result' ], PHP_INT_MAX, 5 );

        // Frames around every WP ability execution: the execute-ability tool,
        // the Abilities REST run route, and adapters that call tools by ability
        // name. After Tool_Errors (10), which recognises AI Nexus callbacks by
        // the Ability they close over.
        add_filter( 'wp_register_ability_args', [ self::class, 'wrap_ability' ], 20, 2 );

        // A call left open (exception, fatal error, a refused pre-filter) is
        // still logged and its changes still recorded.
        add_action( 'shutdown', [ self::class, 'shutdown' ], 0 );
    }

    // ── 1. Is this an MCP request? ───────────────────────────────────────────

    /**
     * rest_pre_dispatch, priority 0: runs after authentication, before any
     * route callback.
     *
     * @param mixed            $result
     * @param mixed            $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function detect( $result, $server, $request ) {
        if ( self::$is_mcp || ! $request instanceof \WP_REST_Request ) {
            return $result;
        }
        $kind = self::route_kind( (string) $request->get_route() );
        if ( '' === $kind ) {
            return $result;
        }
        self::$is_mcp    = true;
        self::$transport = $kind;
        $sid             = $request->get_header( 'mcp_session_id' );
        self::$session_id = is_string( $sid ) ? substr( preg_replace( '/[^A-Za-z0-9._:-]/', '', $sid ), 0, 128 ) : '';
        self::$actor     = null;
        return $result;
    }

    /** 'http' for an MCP server route, 'abilities-rest' for the run route, '' otherwise. */
    private static function route_kind( string $route ): string {
        // WordPress matches REST routes in any letter case.
        $route = strtolower( '/' . ltrim( $route, '/' ) );
        if ( preg_match( '#^/wp-abilities/v\d+/abilities/.+/run/?$#', $route ) ) {
            return 'abilities-rest';
        }
        foreach ( self::server_routes() as $prefix ) {
            if ( $route === $prefix || 0 === strpos( $route, rtrim( $prefix, '/' ) . '/' ) ) {
                return 'http';
            }
        }
        return '';
    }

    /** @return string[] Lower-case route prefixes of the MCP servers. */
    private static function server_routes(): array {
        $routes = [ '/mcp' ];
        if ( class_exists( '\WP\MCP\Core\McpAdapter', false ) && method_exists( '\WP\MCP\Core\McpAdapter', 'instance' ) ) {
            try {
                $adapter = \WP\MCP\Core\McpAdapter::instance();
                $servers = method_exists( $adapter, 'get_servers' ) ? (array) $adapter->get_servers() : [];
                foreach ( $servers as $server ) {
                    if ( is_object( $server ) && method_exists( $server, 'get_server_route_namespace' ) && method_exists( $server, 'get_server_route' ) ) {
                        $routes[] = strtolower( '/' . trim( (string) $server->get_server_route_namespace(), '/' ) . '/' . trim( (string) $server->get_server_route(), '/' ) );
                    }
                }
            } catch ( \Throwable $e ) {
                // The namespace prefix above still covers the default server.
            }
        }
        return array_values( array_unique( $routes ) );
    }

    public static function is_mcp(): bool {
        return self::$is_mcp;
    }

    /** For tests and for a caller that runs an MCP-like flow outside REST. */
    public static function mark_mcp( string $transport = 'http', string $session_id = '' ): void {
        self::$is_mcp     = true;
        self::$transport  = $transport;
        self::$session_id = $session_id;
        self::$actor      = null;
    }

    // ── 2. Who is acting? ────────────────────────────────────────────────────

    /**
     * wp_application_password_did_authenticate.
     *
     * @param \WP_User $user
     * @param array    $item App-password item (name, uuid, …).
     */
    public static function capture_app_pass( $user, $item ): void {
        if ( ! $user instanceof \WP_User || ! is_array( $item ) ) {
            return;
        }
        self::$app_pass = [
            'uuid'    => isset( $item['uuid'] ) ? (string) $item['uuid'] : '',
            'name'    => isset( $item['name'] ) ? (string) $item['name'] : '',
            'user_id' => (int) $user->ID,
        ];
        self::$actor = null;
    }

    /**
     * The acting user and app, plus the ability being run.
     *
     * @return array{v:int,user_id:int,user_name:string,app:string,auth:string,client_id:string,app_pass_uuid:string,app_pass_name:string,client_name:string,client_version:string,session:string,transport:string,ability:string,operator:string}
     */
    public static function actor(): array {
        $user_id = get_current_user_id();
        if ( null === self::$actor || self::$actor['user_id'] !== $user_id ) {
            self::$actor = self::resolve_actor( $user_id );
        }
        $actor            = self::$actor;
        $actor['ability'] = self::current_ability();
        return $actor;
    }

    private static function resolve_actor( int $user_id ): array {
        $user  = $user_id ? get_userdata( $user_id ) : null;
        $actor = [
            'v'              => self::ACTOR_SCHEMA,
            'user_id'        => $user_id,
            'user_name'      => $user ? (string) $user->display_name : '',
            'app'            => '',
            'auth'           => '',
            'client_id'      => '',
            'app_pass_uuid'  => '',
            'app_pass_name'  => '',
            'client_name'    => '',
            'client_version' => '',
            'session'        => '' !== self::$session_id ? substr( self::$session_id, 0, 12 ) : '',
            'transport'      => self::$transport,
            'ability'        => '',
            'operator'       => '',
        ];
        $oauth_name = '';

        // Application Password: the auth hook, else WordPress's own record.
        $pass = ( self::$app_pass && self::$app_pass['user_id'] === $user_id ) ? self::$app_pass : null;
        if ( ! $pass && $user_id && function_exists( 'rest_get_authenticated_app_password' ) ) {
            $uuid = rest_get_authenticated_app_password();
            if ( is_string( $uuid ) && '' !== $uuid && class_exists( '\WP_Application_Passwords' ) ) {
                $item = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
                $pass = [ 'uuid' => $uuid, 'name' => is_array( $item ) ? (string) ( $item['name'] ?? '' ) : '', 'user_id' => $user_id ];
            }
        }

        if ( $pass ) {
            $actor['auth']          = 'app_password';
            $actor['app_pass_uuid'] = $pass['uuid'];
            $actor['app_pass_name'] = $pass['name'];
        } elseif ( $user_id && null !== ( $client_id = self::oauth_client_id( $user_id ) ) ) {
            $actor['auth']      = 'oauth';
            $actor['client_id'] = $client_id;
            if ( '' !== $client_id && class_exists( OAuth\Client_Manager::class ) ) {
                $client     = OAuth\Client_Manager::get( $client_id );
                $oauth_name = is_array( $client ) ? (string) ( $client['name'] ?? '' ) : '';
            }
            // Who said they are connecting, on the consent page or as set by an
            // administrator (OAuth\Operators). Not checked; it only labels.
            if ( '' !== $client_id && class_exists( OAuth\Operators::class ) ) {
                $actor['operator'] = OAuth\Operators::get( $client_id, $user_id );
            }
        } elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
            $actor['auth'] = 'cli';
        } elseif ( $user_id ) {
            $actor['auth'] = 'cookie';
        }

        // The MCP session's clientInfo (from the initialize call).
        if ( $user_id && '' !== self::$session_id ) {
            $session = self::session( $user_id, self::$session_id );
            if ( $session ) {
                $name    = $session['client_name'] ?? ( $session['client_params']['clientInfo']['name'] ?? '' );
                $version = $session['client_version'] ?? ( $session['client_params']['clientInfo']['version'] ?? '' );
                $actor['client_name']    = is_string( $name ) ? sanitize_text_field( $name ) : '';
                $actor['client_version'] = is_string( $version ) ? sanitize_text_field( $version ) : '';
            }
        }

        // The app part of the label, most trustworthy first.
        foreach ( [ $oauth_name, $actor['app_pass_name'], $actor['client_name'] ] as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( '' !== $candidate ) {
                $actor['app'] = mb_substr( $candidate, 0, 100 );
                break;
            }
        }
        return $actor;
    }

    /**
     * The OAuth client of this request's bearer token: null when the request
     * did not sign in with one, '' when it did but the client is unknown.
     */
    private static function oauth_client_id( int $user_id ): ?string {
        if ( class_exists( OAuth\Server::class, false ) && method_exists( OAuth\Server::class, 'authenticated_client' ) ) {
            $auth = OAuth\Server::authenticated_client();
            if ( is_array( $auth ) && (int) $auth['user_id'] === $user_id ) {
                return (string) $auth['client_id'];
            }
        }
        // The connector plugin served the sign-in: same token store, same rows.
        if ( ! class_exists( OAuth\Server::class ) || ! class_exists( OAuth\Token_Store::class ) ) {
            return null;
        }
        $token = OAuth\Server::extract_bearer_token();
        if ( null === $token ) {
            return null;
        }
        $data = OAuth\Token_Store::get( 'token', $token );
        if ( is_array( $data ) && (int) ( $data['user_id'] ?? 0 ) === $user_id ) {
            return (string) ( $data['client_id'] ?? '' );
        }
        return null;
    }

    /** An MCP session as the adapter stored it, without touching it. */
    private static function session( int $user_id, string $session_id ): ?array {
        $sessions = get_user_meta( $user_id, 'mcp_adapter_sessions', true );
        return ( is_array( $sessions ) && isset( $sessions[ $session_id ] ) && is_array( $sessions[ $session_id ] ) ) ? $sessions[ $session_id ] : null;
    }

    /**
     * "Vergona — via MCP (Claude)" for an actor (stored or current).
     *
     * @param array|null $actor An actor() array, or a stored snapshot of one.
     */
    public static function label( ?array $actor = null ): string {
        $actor = $actor ?? self::actor();
        $name  = trim( (string) ( $actor['user_name'] ?? '' ) );
        if ( '' === $name && ! empty( $actor['user_id'] ) ) {
            $user = get_userdata( (int) $actor['user_id'] );
            $name = $user ? (string) $user->display_name : '';
        }
        if ( '' === $name ) {
            $name = __( 'Unknown user', 'mcp-abilities' );
        }
        $app = trim( (string) ( $actor['app'] ?? '' ) );
        if ( '' === $app ) {
            $app = __( 'AI assistant', 'mcp-abilities' );
        }
        $operator = trim( (string) ( $actor['operator'] ?? '' ) );
        if ( '' !== $operator && self::operator_is_user( $operator, $actor, $name ) ) {
            $operator = '';
        }
        if ( '' !== $operator ) {
            /* translators: 1: user display name, 2: who is connecting, as they typed it (e.g. an email), 3: the AI app, e.g. "Claude". */
            $label = sprintf( __( '%1$s (%2$s) — via MCP (%3$s)', 'mcp-abilities' ), $name, $operator, $app );
        } else {
            /* translators: 1: user display name, 2: the AI app, e.g. "Claude". */
            $label = sprintf( __( '%1$s — via MCP (%2$s)', 'mcp-abilities' ), $name, $app );
        }

        /**
         * Filters the label that marks a change made by an AI through MCP.
         *
         * @param string $label "Name — via MCP (App)".
         * @param array  $actor The actor (see Request_Context::actor()).
         */
        return (string) apply_filters( 'mcp_abilities_actor_label', $label, $actor );
    }

    /**
     * Whether a typed "who is connecting" just names the WordPress user again
     * (display name, login or email, any letter case): then no brackets.
     */
    private static function operator_is_user( string $operator, array $actor, string $display ): bool {
        $lower = static fn( string $s ): string => function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ) ) : strtolower( trim( $s ) );
        $same  = [ $display, (string) ( $actor['user_name'] ?? '' ) ];
        $user  = ! empty( $actor['user_id'] ) ? get_userdata( (int) $actor['user_id'] ) : null;
        if ( $user ) {
            $same[] = (string) $user->display_name;
            $same[] = (string) $user->user_login;
            $same[] = (string) $user->user_email;
        }
        $op = $lower( html_entity_decode( $operator, ENT_QUOTES, 'UTF-8' ) );
        foreach ( $same as $candidate ) {
            if ( '' !== trim( $candidate ) && $lower( $candidate ) === $op ) {
                return true;
            }
        }
        return false;
    }

    /**
     * What a recorder stores: the actor snapshot plus the time. Revisions,
     * comments and orders all store this shape (schema ACTOR_SCHEMA).
     *
     * @param array|null $actor An actor() array; the current one when null.
     */
    public static function record( ?array $actor = null ): array {
        $actor = $actor ?? self::actor();
        return [
            'v'              => self::ACTOR_SCHEMA,
            'user_id'        => (int) ( $actor['user_id'] ?? 0 ),
            'user_name'      => (string) ( $actor['user_name'] ?? '' ),
            'app'            => (string) ( $actor['app'] ?? '' ),
            'auth'           => (string) ( $actor['auth'] ?? '' ),
            'client_id'      => (string) ( $actor['client_id'] ?? '' ),
            'app_pass_uuid'  => (string) ( $actor['app_pass_uuid'] ?? '' ),
            'app_pass_name'  => (string) ( $actor['app_pass_name'] ?? '' ),
            'client_name'    => (string) ( $actor['client_name'] ?? '' ),
            'client_version' => (string) ( $actor['client_version'] ?? '' ),
            'session'        => (string) ( $actor['session'] ?? '' ),
            'transport'      => (string) ( $actor['transport'] ?? '' ),
            'ability'        => (string) ( $actor['ability'] ?? '' ),
            'operator'       => (string) ( $actor['operator'] ?? '' ),
            'time'           => gmdate( 'Y-m-d H:i:s' ),
            'log_id'         => 0,
        ];
    }

    /** The fields of a stored record that a REST response may show. */
    public static function public_record( array $record ): array {
        return [
            'label'     => self::label( $record ),
            'user_id'   => (int) ( $record['user_id'] ?? 0 ),
            'user_name' => (string) ( $record['user_name'] ?? '' ),
            'app'       => (string) ( $record['app'] ?? '' ),
            'operator'  => (string) ( $record['operator'] ?? '' ),
            'auth'      => (string) ( $record['auth'] ?? '' ),
            'ability'   => (string) ( $record['ability'] ?? '' ),
            'action'    => (string) ( $record['action'] ?? '' ),
            'time'      => (string) ( $record['time'] ?? '' ),
            'log_id'    => (int) ( $record['log_id'] ?? 0 ),
        ];
    }

    /**
     * Store a record as JSON metadata (post, comment, …). Slashed, because
     * metadata writes unslash their value. update_metadata(), not
     * update_post_meta(): that one moves a revision's meta to its parent.
     */
    public static function put_meta_json( string $meta_type, int $object_id, string $key, array $data ): void {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false !== $json && $object_id > 0 ) {
            update_metadata( $meta_type, $object_id, $key, wp_slash( $json ) );
        }
    }

    /** A record stored by put_meta_json(), or null. */
    public static function get_meta_json( string $meta_type, int $object_id, string $key ): ?array {
        $raw = $object_id > 0 ? get_metadata( $meta_type, $object_id, $key, true ) : '';
        if ( ! is_string( $raw ) || '' === $raw ) {
            return null;
        }
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : null;
    }

    /** Human words for a sign-in method. */
    public static function auth_label( string $auth ): string {
        switch ( $auth ) {
            case 'oauth':
                return __( 'OAuth', 'mcp-abilities' );
            case 'app_password':
                return __( 'Application Password', 'mcp-abilities' );
            case 'cookie':
                return __( 'Signed-in browser', 'mcp-abilities' );
            case 'cli':
                return __( 'WP-CLI', 'mcp-abilities' );
        }
        return '';
    }

    /**
     * Fields for an activity-log row.
     *
     * @return array{user_id:int,auth_method:string,client_label:string,oauth_client_id:string,session_id:string,app_pass_name:string,app_pass_uuid:string,client_name:string,client_version:string,operator:string}
     */
    public static function log_fields(): array {
        $a = self::actor();
        return [
            'user_id'         => (int) $a['user_id'],
            'auth_method'     => $a['auth'],
            'client_label'    => $a['app'],
            'oauth_client_id' => $a['client_id'],
            'session_id'      => $a['session'],
            'app_pass_name'   => $a['app_pass_name'],
            'app_pass_uuid'   => $a['app_pass_uuid'],
            'client_name'     => $a['client_name'],
            'client_version'  => $a['client_version'],
            'operator'        => $a['operator'],
        ];
    }

    // ── 3. Calls ─────────────────────────────────────────────────────────────

    /**
     * Open a frame. The outermost frame starts a call.
     *
     * @param string    $name   Tool / ability / action name.
     * @param int       $rank   One of the RANK_* constants.
     * @param bool|null $writes Whether the named ability writes (null = unknown).
     * @return int Token for end_call().
     */
    public static function begin_call( string $name, int $rank, ?bool $writes = null ): int {
        if ( ! self::$stack ) {
            self::$call = [
                'id'       => ++self::$calls,
                'name'     => '',
                'rank'     => -1,
                'writes'   => null,
                'ai_nexus' => false,
                'objects'  => [],
                'started'  => microtime( true ),
            ];
        }
        $token         = ++self::$seq;
        self::$stack[] = $token;
        self::note_ability( $name, $rank, $writes );
        return $token;
    }

    /**
     * Name the ability the current call is running, when it is more specific
     * than the name known so far. Crud_Ability calls it with the action.
     */
    public static function note_ability( string $name, int $rank = self::RANK_ACTION, ?bool $writes = null ): void {
        if ( null === self::$call || '' === $name ) {
            return;
        }
        if ( in_array( strtolower( $name ), self::META_NAMES, true ) ) {
            $rank = self::RANK_META;
        }
        if ( $rank >= self::RANK_ABILITY ) {
            self::$call['ai_nexus'] = true;
        }
        if ( $rank > self::$call['rank'] ) {
            self::$call['name']   = $name;
            self::$call['rank']   = $rank;
            self::$call['writes'] = $writes;
        }
    }

    /**
     * Close a frame (and every frame opened inside it). Closing the outermost
     * frame ends the call: recorders finish, the log row is written.
     *
     * @param int   $token  From begin_call().
     * @param mixed $result The call's result (array, WP_Error, …).
     */
    public static function end_call( int $token, $result = null ): void {
        $pos = array_search( $token, self::$stack, true );
        if ( false === $pos ) {
            return;
        }
        self::$stack = array_slice( self::$stack, 0, $pos );
        if ( self::$stack || null === self::$call ) {
            return;
        }
        self::finish( $result );
    }

    public static function in_call(): bool {
        return null !== self::$call;
    }

    /** The running call's id (0 when none). */
    public static function call_id(): int {
        return self::$call ? (int) self::$call['id'] : 0;
    }

    /** The most specific name of the running call ('' when none). */
    public static function current_ability(): string {
        return self::$call ? (string) self::$call['name'] : '';
    }

    /**
     * Whether the running call only reads. Unknown counts as a write: a
     * recorder then records rather than missing a change.
     */
    public static function is_read_call(): bool {
        if ( null === self::$call ) {
            return false;
        }
        if ( null !== self::$call['writes'] ) {
            return ! self::$call['writes'];
        }
        $name = (string) self::$call['name'];
        if ( '' === $name ) {
            return false;
        }
        // "mcp-abilities/list-posts" → "list_posts".
        $slash = strrpos( $name, '/' );
        $key   = str_replace( '-', '_', false === $slash ? $name : substr( $name, $slash + 1 ) );
        return Read_Only::is_read_key( $key );
    }

    /**
     * Record an object the running call changed, for its log row.
     *
     * @param string $type  'post' now; 'order', 'comment' next.
     * @param int    $id
     * @param array  $extra Extra fields, e.g. [ 'revision' => 123 ].
     */
    public static function add_object( string $type, int $id, array $extra = [] ): void {
        if ( null === self::$call || $id <= 0 ) {
            return;
        }
        $key    = $type . ':' . $id;
        $object = self::$call['objects'][ $key ] ?? [ 'type' => $type, 'id' => $id ];
        foreach ( $extra as $field => $value ) {
            // A later null never wipes a value an earlier call set.
            if ( null !== $value || ! array_key_exists( $field, $object ) ) {
                $object[ $field ] = $value;
            }
        }
        self::$call['objects'][ $key ] = $object;
    }

    /**
     * End the call: recorders finish, then one log row, then the back-links.
     *
     * @param mixed $result
     */
    private static function finish( $result ): void {
        $call_id = (int) self::$call['id'];

        try {
            /**
             * The call is ending. Recorders add their objects with add_object()
             * and may still change things (a revision for a meta-only change).
             *
             * @param int $call_id
             */
            do_action( 'mcp_abilities_call_end', $call_id );
        } catch ( \Throwable $e ) {
            // Recording must never break the call.
        }

        $call    = self::$call;
        $objects = array_values( $call['objects'] );
        $log_id  = 0;

        // Every MCP call is logged; outside MCP only AI Nexus's own abilities
        // are (as before), not every ability WordPress runs.
        if ( ( self::$is_mcp || $call['ai_nexus'] ) && '' !== $call['name'] ) {
            try {
                $log_id = Logger::log( $call['name'], $result, $objects );
            } catch ( \Throwable $e ) {
                $log_id = 0;
            }
        }

        try {
            /**
             * The call's log row is written (log id 0 when it was not logged).
             *
             * @param int   $log_id
             * @param int   $call_id
             * @param array $objects [ { type, id, revision? }, … ]
             */
            do_action( 'mcp_abilities_call_logged', $log_id, $call_id, $objects );
        } catch ( \Throwable $e ) {
            // As above.
        }

        self::$call = null;
    }

    // ── Frame sources ────────────────────────────────────────────────────────

    /**
     * mcp_adapter_pre_tool_call: a tool call through the adapter's ToolsHandler.
     *
     * @param mixed  $args
     * @param string $tool_name
     * @param mixed  $mcp_tool
     * @param mixed  $server
     * @return mixed
     */
    public static function on_pre_tool_call( $args, $tool_name = '', $mcp_tool = null, $server = null ) {
        if ( is_wp_error( $args ) ) {
            return $args;
        }
        if ( ! self::$is_mcp ) {
            // Only the adapter calls this filter: an MCP call by definition
            // (the STDIO transport runs under WP-CLI, outside REST).
            self::$is_mcp    = true;
            self::$transport = ( defined( 'WP_CLI' ) && WP_CLI ) ? 'stdio' : 'http';
            self::$actor     = null;
        }
        $token = self::begin_call( (string) $tool_name, self::RANK_TOOL );
        self::$call['tool_tokens'][] = $token;

        // Name the ability behind a handler-backed tool, so an extension tool
        // (which calls $ability->execute() itself) is logged by its key.
        $ability = self::ability_behind_tool( $mcp_tool );
        if ( $ability instanceof Ability ) {
            self::note_ability( $ability->get_key(), self::RANK_ABILITY, $ability->is_write() );
        } elseif ( is_string( $ability ) && '' !== $ability ) {
            self::note_ability( $ability, self::RANK_WP_ABILITY );
        }
        if ( in_array( strtolower( (string) $tool_name ), [ 'mcp-adapter-execute-ability', 'mcp-adapter/execute-ability' ], true )
            && is_array( $args ) && ! empty( $args['ability_name'] ) && is_string( $args['ability_name'] ) ) {
            self::note_ability( sanitize_text_field( $args['ability_name'] ), self::RANK_WP_ABILITY );
        }
        return $args;
    }

    /**
     * mcp_adapter_tool_call_result: closes the tool's frame.
     *
     * @param mixed $result
     * @return mixed Unchanged.
     */
    public static function on_tool_call_result( $result, $args = null, $tool_name = '', $mcp_tool = null, $server = null ) {
        if ( null !== self::$call && ! empty( self::$call['tool_tokens'] ) ) {
            $token = (int) array_pop( self::$call['tool_tokens'] );
            self::end_call( $token, $result );
        }
        return $result;
    }

    /**
     * The AI Nexus Ability (or WP ability name) a tool runs.
     *
     * @param mixed $mcp_tool
     * @return Ability|string|null
     */
    private static function ability_behind_tool( $mcp_tool ) {
        if ( ! is_object( $mcp_tool ) ) {
            return null;
        }
        if ( method_exists( $mcp_tool, 'get_observability_context' ) ) {
            $ctx = (array) $mcp_tool->get_observability_context();
            if ( ! empty( $ctx['ability_name'] ) && is_string( $ctx['ability_name'] ) ) {
                return $ctx['ability_name'];
            }
        }
        try {
            if ( ! property_exists( $mcp_tool, 'handler' ) ) {
                return null;
            }
            $prop = new \ReflectionProperty( $mcp_tool, 'handler' );
            $prop->setAccessible( true );
            $handler = $prop->getValue( $mcp_tool );
            if ( $handler instanceof \Closure ) {
                foreach ( ( new \ReflectionFunction( $handler ) )->getStaticVariables() as $value ) {
                    if ( $value instanceof Ability ) {
                        return $value;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            return null;
        }
        return null;
    }

    /**
     * wp_register_ability_args: wrap every ability's execute callback in a frame.
     *
     * @param mixed  $args
     * @param string $name
     * @return mixed
     */
    public static function wrap_ability( $args, $name = '' ) {
        if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
            return $args;
        }
        $callback = $args['execute_callback'];
        $name     = (string) $name;
        $args['execute_callback'] = static function ( ...$input ) use ( $callback, $name ) {
            $token  = self::begin_call( $name, self::RANK_WP_ABILITY );
            $done   = false;
            $result = null;
            try {
                $result = $callback( ...$input );
                $done   = true;
                return $result;
            } finally {
                self::end_call( $token, $done ? $result : new \WP_Error( 'mcp_abilities_exception', 'The ability stopped with an exception.' ) );
            }
        };
        return $args;
    }

    /** shutdown: finish a call that never closed. */
    public static function shutdown(): void {
        if ( null === self::$call ) {
            return;
        }
        self::$stack = [];
        $error       = error_get_last();
        $message     = ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) )
            ? 'The call stopped with a fatal error: ' . $error['message']
            : 'The call did not finish (it stopped with an exception or was refused).';
        self::finish( new \WP_Error( 'mcp_abilities_unfinished', $message ) );
    }

    /** Back to a fresh request: for tests that run several requests in one process. */
    public static function reset(): void {
        self::$is_mcp     = false;
        self::$transport  = '';
        self::$session_id = '';
        self::$actor      = null;
        self::$app_pass   = null;
        self::$stack      = [];
        self::$call       = null;
        if ( class_exists( OAuth\Server::class, false ) && method_exists( OAuth\Server::class, 'forget_authenticated_client' ) ) {
            OAuth\Server::forget_authenticated_client();
        }
    }
}
