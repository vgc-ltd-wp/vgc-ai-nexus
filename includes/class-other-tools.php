<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Other plugins' AI tools and MCP servers (AI Nexus → Other AI tools).
 *
 * Read-only mode and the tool switches of AI Nexus used to bind only AI Nexus's
 * own abilities. Other plugins' abilities reached AI clients past them:
 *   1. through the adapter's execute-ability tool on AI Nexus's own server (any
 *      ability with meta.mcp.public);
 *   2. as tools other plugins add to AI Nexus's server (mcp_adapter_default_server_config);
 *   3. on MCP servers other plugins create on the adapter (WooCommerce's woocommerce-mcp);
 *   4. through WordPress's Abilities REST run route (show_in_rest).
 * This class closes all four, in one place:
 *
 *   - wp_register_ability_args, priority 26 (after Connection_Approval's wrap at 25):
 *     the permission and execute callbacks of every ability that is not AI Nexus's
 *     own are wrapped. During an AI request they refuse a tool an administrator
 *     turned off, a new one while "keep new ones off" is chosen, and, while
 *     read-only mode is on, every tool that is not a read. A read is
 *     meta.annotations.readonly === true, or an unmarked tool the administrator
 *     counted as a read; false, null or missing is a change (deny by default).
 *   - AI Nexus's own = core, every extension and anything else built on
 *     MCP_Abilities\Ability (their callbacks close over one, as Tool_Errors
 *     recognises them; they enforce read-only mode in Ability::current_user_can()),
 *     plus the adapter's three mcp-adapter/* abilities. Name prefixes cannot tell:
 *     the WooCommerce extension registers woocommerce/... abilities of its own.
 *   - An AI request: Request_Context::is_mcp() (an adapter server route, a STDIO
 *     tool call, or the Abilities REST run route), except the Abilities REST
 *     route for a signed-in browser (cookie + nonce) or WP-CLI: a person in
 *     wp-admin is not an AI. Cron, wp-admin and a plugin using its own ability
 *     are never refused.
 *   - What the AI sees: tools/list, resources/list and prompts/list on every
 *     adapter server, discover-abilities and get-ability-info leave out what
 *     would be refused. tools/list runs Tool_Visibility first with read-only mode
 *     set aside; this filter runs after it with the mode in force, so other
 *     plugins' changing tools are hidden while read-only mode is on, as AI
 *     Nexus's own dispatchers hide their write actions.
 *   - mcp_adapter_pre_tool_call: a second guard for every tool call, which also
 *     covers tools other plugins add with a handler of their own (no ability
 *     behind them: id "<server id>:<tool name>", read = annotations readOnlyHint).
 *   - A server or an MCP-like address an administrator turned off: its routes are
 *     removed (rest_endpoints) and any request to it gets a 404 (rest_pre_dispatch,
 *     in any letter case, /wp-json/... and ?rest_route=, /batch/v1 included).
 *     AI Nexus's own server, /mcp/..., /mcp-abilities/... and /vgc-cc/... can never
 *     be turned off, whatever the option says.
 *
 * Every refusal goes to the activity log (type other_tool), once per request.
 *
 * Settings: one option, OPTION, which core's update_option ability refuses
 * (prefix mcp_abilities_), so an AI cannot switch anything back on:
 *   { v: 1, new: 'allow'|'off',
 *     off:   { tools: {id: 1}, servers: {id: 1}, endpoints: {route: 1} },
 *     reads: {id: 1},
 *     seen:  { tools: {id: 1}, servers: {id: 1}, endpoints: {route: 1} } }
 * A tool id is an ability name ("woocommerce/product-create") or, for a tool with
 * its own handler, "<server id>:<tool name>". An endpoint is its REST route as
 * registered. "seen" is what the administrator has been shown on the page.
 *
 * Not covered: MCP endpoints a plugin serves itself, without the adapter (AI Nexus
 * cannot see their tools; it can only switch the address off), and an ability a
 * plugin registers with an ability_class that overrides execute() and
 * check_permissions() so they never call their callbacks.
 */
final class Other_Tools {

    public const OPTION         = 'mcp_abilities_other_tools';
    public const CODE_READ_ONLY = 'mcp_abilities_read_only';
    public const CODE_OFF       = 'mcp_abilities_tool_off';
    public const DEFAULT_SERVER = 'mcp-adapter-default-server';
    public const LOG_TYPE       = 'other_tool';
    public const PAGE_SLUG      = 'mcp-abilities-other-tools';

    /** The adapter's own abilities: AI Nexus's server is built on them. */
    public const ADAPTER_ABILITIES = [
        'mcp-adapter/discover-abilities',
        'mcp-adapter/get-ability-info',
        'mcp-adapter/execute-ability',
    ];

    /** Most ids one list in the option may hold. */
    private const MAX_IDS = 3000;

    /**
     * What is known about each ability, from its registration.
     *
     * @var array<string, array{own:bool, readonly:mixed, file:string}>
     */
    private static array $abilities = [];

    /** @var array<string, bool> Refusals logged in this request. */
    private static array $logged = [];

    /** @var array<string, array|null> Tool classifications by object. */
    private static array $tool_cache = [];

    /** While true, rest_endpoints is left as it is (for the inventory). */
    private static bool $raw_routes = false;

    private static bool $booted = false;

    public static function init(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        // First: what each ability is, from its callbacks as the plugin gave them.
        add_filter( 'wp_register_ability_args', [ self::class, 'note_ability' ], 1, 2 );
        // After Connection_Approval (25), before Tool_Errors::contain_exceptions (30).
        add_filter( 'wp_register_ability_args', [ self::class, 'wrap_ability' ], 26, 2 );

        // After Tool_Visibility (20), which sets read-only mode aside; before
        // Connection_Approval (999), which then decides what a held connection sees.
        add_filter( 'mcp_adapter_tools_list', [ self::class, 'filter_tools_list' ], 30, 2 );
        add_filter( 'mcp_adapter_resources_list', [ self::class, 'filter_resources_list' ], 30, 2 );
        add_filter( 'mcp_adapter_prompts_list', [ self::class, 'filter_prompts_list' ], 30, 2 );

        // After Connection_Approval (5), before Request_Context opens a frame (PHP_INT_MAX).
        add_filter( 'mcp_adapter_pre_tool_call', [ self::class, 'pre_tool_call' ], 6, 4 );
        add_filter( 'mcp_adapter_pre_resource_read', [ self::class, 'pre_resource_read' ], 6, 4 );
        add_filter( 'mcp_adapter_pre_prompt_get', [ self::class, 'pre_prompt_get' ], 6, 4 );

        add_filter( 'rest_endpoints', [ self::class, 'remove_routes' ], 999 );
        // Before Request_Context::detect (0) and Connection_Approval::gate_rest (1).
        add_filter( 'rest_pre_dispatch', [ self::class, 'refuse_request' ], -10, 3 );
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    /** @return array{v:int,new:string,off:array,reads:array,seen:array} */
    public static function settings(): array {
        $raw = get_option( self::OPTION, [] );
        $raw = is_array( $raw ) ? $raw : [];
        $out = [
            'v'     => 1,
            'new'   => ( isset( $raw['new'] ) && 'off' === $raw['new'] ) ? 'off' : 'allow',
            'off'   => [],
            'reads' => self::id_map( $raw['reads'] ?? [] ),
            'seen'  => [],
        ];
        foreach ( [ 'off', 'seen' ] as $part ) {
            foreach ( [ 'tools', 'servers', 'endpoints' ] as $kind ) {
                $out[ $part ][ $kind ] = self::id_map( $raw[ $part ][ $kind ] ?? [] );
            }
        }
        return $out;
    }

    /**
     * @param mixed $list { id: truthy }.
     * @return array<string, int>
     */
    private static function id_map( $list ): array {
        $map = [];
        foreach ( is_array( $list ) ? $list : [] as $key => $value ) {
            if ( is_string( $key ) && '' !== $key && strlen( $key ) <= 300 && $value ) {
                $map[ $key ] = 1;
            }
            if ( count( $map ) >= self::MAX_IDS ) {
                break;
            }
        }
        return $map;
    }

    public static function save( array $settings ): void {
        $clean = [
            'v'     => 1,
            'new'   => 'off' === ( $settings['new'] ?? '' ) ? 'off' : 'allow',
            'off'   => [],
            'reads' => self::id_map( $settings['reads'] ?? [] ),
            'seen'  => [],
        ];
        foreach ( [ 'off', 'seen' ] as $part ) {
            foreach ( [ 'tools', 'servers', 'endpoints' ] as $kind ) {
                $clean[ $part ][ $kind ] = self::id_map( $settings[ $part ][ $kind ] ?? [] );
            }
        }
        // Never stored as off, whoever asks: AI Nexus's own server and addresses.
        unset( $clean['off']['servers'][ self::DEFAULT_SERVER ] );
        foreach ( array_keys( $clean['off']['endpoints'] ) as $route ) {
            if ( self::is_own_route( $route ) ) {
                unset( $clean['off']['endpoints'][ $route ] );
            }
        }
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, $clean, '', true );
        } else {
            update_option( self::OPTION, $clean, true );
        }
    }

    // ── What an ability is ───────────────────────────────────────────────────

    /**
     * wp_register_ability_args, priority 1: note what the ability is, from its
     * callbacks as the plugin gave them. Changes nothing.
     *
     * @param mixed  $args
     * @param string $name
     * @return mixed
     */
    public static function note_ability( $args, $name = '' ) {
        $name = (string) $name;
        if ( is_array( $args ) && '' !== $name ) {
            self::$abilities[ $name ] = self::describe( $name, $args['permission_callback'] ?? null, $args['execute_callback'] ?? null, $args['meta']['annotations']['readonly'] ?? null );
        }
        return $args;
    }

    /**
     * @param mixed $permission
     * @param mixed $execute
     * @param mixed $readonly
     * @return array{own:bool, readonly:mixed, file:string}
     */
    private static function describe( string $name, $permission, $execute, $readonly ): array {
        $own = in_array( $name, self::ADAPTER_ABILITIES, true )
            || self::closes_over_ability( $permission )
            || self::closes_over_ability( $execute );
        return [
            'own'      => $own,
            'readonly' => $readonly,
            'file'     => $own ? '' : self::file_of( $execute ),
        ];
    }

    /**
     * What is known about an ability, or null when there is none of that name.
     * One registered before AI Nexus hooked in is read from the ability itself.
     *
     * @return array{own:bool, readonly:mixed, file:string}|null
     */
    public static function ability( string $name ): ?array {
        if ( isset( self::$abilities[ $name ] ) ) {
            return self::$abilities[ $name ];
        }
        if ( '' === $name || ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $name ) ) {
            return null;
        }
        $ability = wp_get_ability( $name );
        if ( ! $ability ) {
            return null;
        }
        $meta = (array) $ability->get_meta();
        self::$abilities[ $name ] = self::describe(
            $name,
            self::property( $ability, 'permission_callback' ),
            self::property( $ability, 'execute_callback' ),
            $meta['annotations']['readonly'] ?? null
        );
        return self::$abilities[ $name ];
    }

    /** Whether an ability is AI Nexus's own (core, an extension, the adapter's). Unknown counts as not. */
    public static function is_own_ability( string $name ): bool {
        $info = self::ability( $name );
        return null !== $info && $info['own'];
    }

    /**
     * Whether a callback is a closure over an MCP_Abilities\Ability (or one of
     * its methods), looking through closures that wrap it.
     *
     * @param mixed $callback
     */
    public static function closes_over_ability( $callback, int $depth = 0 ): bool {
        if ( $depth > 8 ) {
            return false;
        }
        if ( is_array( $callback ) && isset( $callback[0] ) && $callback[0] instanceof Ability ) {
            return true;
        }
        if ( ! $callback instanceof \Closure ) {
            return false;
        }
        try {
            $vars = ( new \ReflectionFunction( $callback ) )->getStaticVariables();
        } catch ( \Throwable $e ) {
            return false;
        }
        foreach ( $vars as $value ) {
            if ( $value instanceof Ability ) {
                return true;
            }
            if ( ( $value instanceof \Closure || is_array( $value ) ) && self::closes_over_ability( $value, $depth + 1 ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A non-public property of an adapter object, or null.
     *
     * @param object $object
     * @return mixed
     */
    private static function property( $object, string $name ) {
        if ( ! is_object( $object ) ) {
            return null;
        }
        try {
            $class = new \ReflectionClass( $object );
            while ( $class && ! $class->hasProperty( $name ) ) {
                $class = $class->getParentClass();
            }
            if ( ! $class ) {
                return null;
            }
            $prop = $class->getProperty( $name );
            $prop->setAccessible( true );
            return $prop->getValue( $object );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * The file a callback is defined in ('' when unknown).
     *
     * @param mixed $callback
     */
    private static function file_of( $callback ): string {
        try {
            if ( $callback instanceof \Closure ) {
                return (string) ( new \ReflectionFunction( $callback ) )->getFileName();
            }
            if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_string( $callback[1] ) ) {
                return (string) ( new \ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
            }
            if ( is_string( $callback ) && '' !== $callback ) {
                if ( false !== strpos( $callback, '::' ) ) {
                    return (string) ( new \ReflectionMethod( $callback ) )->getFileName();
                }
                if ( function_exists( $callback ) ) {
                    return (string) ( new \ReflectionFunction( $callback ) )->getFileName();
                }
            }
        } catch ( \Throwable $e ) {
            return '';
        }
        return '';
    }

    /**
     * @param mixed $readonly An annotation value.
     * @return string 'read' (true), 'write' (false) or 'unmarked' (anything else).
     */
    public static function marked( $readonly ): string {
        if ( true === $readonly ) {
            return 'read';
        }
        return false === $readonly ? 'write' : 'unmarked';
    }

    /** Whether a tool counts as a read: marked so by its plugin, or unmarked and counted so by an administrator. */
    public static function counts_as_read( string $id, string $marked ): bool {
        if ( 'read' === $marked ) {
            return true;
        }
        return 'unmarked' === $marked && isset( self::settings()['reads'][ $id ] );
    }

    // ── The decision ─────────────────────────────────────────────────────────

    /**
     * Why a tool is refused to an AI: 'off', 'new' or 'read_only'; null when it is not.
     *
     * @param string $id     Ability name, or "<server id>:<tool name>".
     * @param string $marked marked() of its annotation.
     */
    public static function reason( string $id, string $marked ): ?string {
        $s = self::settings();
        if ( isset( $s['off']['tools'][ $id ] ) ) {
            return 'off';
        }
        if ( 'off' === $s['new'] && ! isset( $s['seen']['tools'][ $id ] ) ) {
            return 'new';
        }
        if ( Read_Only::active() && ! self::counts_as_read( $id, $marked ) ) {
            return 'read_only';
        }
        return null;
    }

    /** The reason for an ability, or null when it is AI Nexus's own, unknown, or allowed. */
    public static function ability_reason( string $name ): ?string {
        $info = self::ability( $name );
        if ( null === $info || $info['own'] ) {
            return null;
        }
        return self::reason( $name, self::marked( $info['readonly'] ) );
    }

    /**
     * Whether this request comes from an AI: an MCP request, except the Abilities
     * REST route for a signed-in browser (cookie + nonce) or WP-CLI.
     */
    public static function is_ai_request(): bool {
        if ( ! Request_Context::is_mcp() ) {
            return false;
        }
        if ( 'abilities-rest' !== Request_Context::transport() ) {
            return true;
        }
        $auth = (string) Request_Context::actor()['auth'];
        if ( 'cli' === $auth ) {
            return false;
        }
        return ! ( 'cookie' === $auth && self::browser_session() );
    }

    /** A signed-in browser with a valid REST nonce: a person, not a stored credential. */
    public static function browser_session(): bool {
        return true === ( $GLOBALS['wp_rest_auth_cookie'] ?? null ) && is_user_logged_in();
    }

    /** The refusal for an AI calling an ability now, or null. */
    public static function refusal( string $name ): ?\WP_Error {
        if ( ! self::is_ai_request() ) {
            return null;
        }
        $why = self::ability_reason( $name );
        return null === $why ? null : self::error( $name, $why );
    }

    /**
     * What the AI is told.
     *
     * @param string $marked marked() of the tool; '' to look it up (abilities).
     */
    public static function error( string $id, string $why, string $marked = '' ): \WP_Error {
        $code = 'read_only' === $why ? self::CODE_READ_ONLY : self::CODE_OFF;
        return new \WP_Error( $code, self::message( $id, $why, $marked ), [ 'status' => 403, 'reason' => $why ] );
    }

    public static function message( string $id, string $why, string $marked = '' ): string {
        $page = 'WordPress Admin → AI Nexus → Other AI tools';
        switch ( $why ) {
            case 'read_only':
                $unmarked = 'unmarked' === ( '' !== $marked ? $marked : self::marked_of( $id ) );
                return sprintf(
                    '%1$s. AI Nexus is in READ-ONLY mode on this site, so it is not allowed. Reading still works. A site administrator can switch read-only mode off under WordPress Admin → AI Nexus → Abilities%2$s.',
                    $unmarked
                        ? sprintf( '"%s" is not marked as read-only by the plugin that adds it, so it counts as a tool that changes the site', $id )
                        : sprintf( '"%s" changes the site', $id ),
                    Read_Only::forced() ? ' after removing the VGC_AI_NEXUS_READONLY constant from wp-config.php' : ''
                );
            case 'new':
                return sprintf( '"%1$s" is new on this site, and new AI tools are kept off until a site administrator turns them on (%2$s). Do not try to reach it another way.', $id, $page );
            case 'server_off':
                return sprintf( 'A site administrator has turned off the MCP server "%1$s" for AI assistants (%2$s).', $id, $page );
        }
        return sprintf( 'A site administrator has turned off "%1$s" for AI assistants on this site, so it cannot be used. Do not try to reach it another way; if it is needed, ask the user to have it turned on under %2$s.', $id, $page );
    }

    /** marked() of a tool id: an ability's annotation, or 'unmarked' when unknown. */
    private static function marked_of( string $id ): string {
        $info = false === strpos( $id, ':' ) ? self::ability( $id ) : null;
        return null === $info ? 'unmarked' : self::marked( $info['readonly'] );
    }

    /** Write a refusal to the activity log, once per tool and reason per request. */
    public static function log_refusal( string $id, \WP_Error $error ): void {
        $data = $error->get_error_data();
        $why  = is_array( $data ) ? (string) ( $data['reason'] ?? '' ) : '';
        $key  = $id . '|' . $why;
        if ( isset( self::$logged[ $key ] ) ) {
            return;
        }
        self::$logged[ $key ] = true;
        try {
            Logger::log( $id, $error, [ [ 'type' => self::LOG_TYPE, 'id' => 0, 'key' => $id, 'action' => $why ] ] );
        } catch ( \Throwable $e ) {
            // Never let logging break the refusal.
        }
    }

    /**
     * Whether a permission check is part of a call (and so its refusal is worth a
     * log row), not of a tool list.
     */
    private static function inside_a_call(): bool {
        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) {
            $function = (string) ( $frame['function'] ?? '' );
            if ( in_array( $function, [ 'call_tool', 'read_resource', 'get_prompt', 'check_ability_permissions', 'execute_ability' ], true ) ) {
                return true;
            }
            if ( 'execute' === $function && isset( $frame['class'] ) && is_a( (string) $frame['class'], 'WP_Ability', true ) ) {
                return true;
            }
        }
        return false;
    }

    // ── The gate on every other plugin's ability ─────────────────────────────

    /**
     * wp_register_ability_args, priority 26.
     *
     * @param mixed  $args
     * @param string $name
     * @return mixed
     */
    public static function wrap_ability( $args, $name = '' ) {
        $name = (string) $name;
        if ( ! is_array( $args ) || '' === $name ) {
            return $args;
        }
        if ( 'mcp-adapter/discover-abilities' === $name ) {
            return self::wrap_discover( $args );
        }
        if ( 'mcp-adapter/get-ability-info' === $name ) {
            return self::wrap_info( $args );
        }
        if ( ! isset( self::$abilities[ $name ] ) ) {
            self::note_ability( $args, $name );
        }
        if ( self::$abilities[ $name ]['own'] ) {
            return $args;
        }

        if ( isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ) {
            $permission                  = $args['permission_callback'];
            $args['permission_callback'] = static function ( ...$input ) use ( $permission, $name ) {
                $error = self::refusal( $name );
                if ( null !== $error ) {
                    if ( self::inside_a_call() ) {
                        self::log_refusal( $name, $error );
                    }
                    return $error;
                }
                return $permission( ...$input );
            };
        }
        if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
            $execute                  = $args['execute_callback'];
            $args['execute_callback'] = static function ( ...$input ) use ( $execute, $name ) {
                $error = self::refusal( $name );
                if ( null !== $error ) {
                    self::log_refusal( $name, $error );
                    return $error;
                }
                return $execute( ...$input );
            };
        }
        return $args;
    }

    /** discover-abilities lists nothing that would be refused. */
    private static function wrap_discover( array $args ): array {
        if ( ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
            return $args;
        }
        $execute                  = $args['execute_callback'];
        $args['execute_callback'] = static function ( ...$input ) use ( $execute ) {
            $result = $execute( ...$input );
            if ( is_array( $result ) && isset( $result['abilities'] ) && is_array( $result['abilities'] ) ) {
                $result['abilities'] = array_values( array_filter( $result['abilities'], static function ( $row ) {
                    $name = is_array( $row ) && isset( $row['name'] ) ? (string) $row['name'] : '';
                    return '' === $name || null === self::ability_reason( $name );
                } ) );
            }
            return $result;
        };
        return $args;
    }

    /** get-ability-info says nothing about a tool that would be refused, only why. */
    private static function wrap_info( array $args ): array {
        $target = static function ( array $input ): string {
            $first = $input[0] ?? null;
            return is_array( $first ) && isset( $first['ability_name'] ) && is_string( $first['ability_name'] ) ? $first['ability_name'] : '';
        };
        foreach ( [ 'permission_callback', 'execute_callback' ] as $key ) {
            if ( ! isset( $args[ $key ] ) || ! is_callable( $args[ $key ] ) ) {
                continue;
            }
            $callback     = $args[ $key ];
            $args[ $key ] = static function ( ...$input ) use ( $callback, $target ) {
                $name = $target( $input );
                $why  = '' !== $name ? self::ability_reason( $name ) : null;
                if ( null !== $why ) {
                    return self::error( $name, $why );
                }
                return $callback( ...$input );
            };
        }
        return $args;
    }

    // ── Adapter servers: lists and calls ─────────────────────────────────────

    /** @param mixed $server */
    public static function server_id_of( $server ): string {
        return is_object( $server ) && method_exists( $server, 'get_server_id' ) ? (string) $server->get_server_id() : '';
    }

    /**
     * The ability behind an adapter component (tool, resource, prompt), or ''.
     *
     * @param mixed $component
     */
    public static function ability_name_of( $component ): string {
        if ( ! is_object( $component ) ) {
            return '';
        }
        if ( method_exists( $component, 'get_observability_context' ) ) {
            $ctx = (array) $component->get_observability_context();
            if ( ! empty( $ctx['ability_name'] ) && is_string( $ctx['ability_name'] ) ) {
                return $ctx['ability_name'];
            }
        }
        $ability = self::property( $component, 'ability' );
        return is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
    }

    /**
     * What a tool on an adapter server is, for the gate and the page.
     *
     * @param mixed $mcp_tool The McpTool.
     * @return array{type:string, id:string, marked:string}|null Null: AI Nexus's own, or not known.
     */
    public static function classify_tool( $mcp_tool, string $server_id, string $name ): ?array {
        if ( ! is_object( $mcp_tool ) ) {
            return null;
        }
        $key = spl_object_hash( $mcp_tool ) . '|' . $server_id;
        if ( array_key_exists( $key, self::$tool_cache ) ) {
            return self::$tool_cache[ $key ];
        }
        $ability = self::ability_name_of( $mcp_tool );
        $info    = '' !== $ability ? self::ability( $ability ) : null;
        // A tool built from an ability that was never registered (a WP_Ability made
        // by hand) has no registration to judge it by: what it runs decides, as for a
        // tool with a handler of its own. Only a tool that really closes over an AI
        // Nexus ability is AI Nexus's own; anything else follows the switches.
        $wp_ability = '' !== $ability && null === $info ? self::property( $mcp_tool, 'ability' ) : null;
        if ( null !== $info ) {
            $out = $info['own'] ? null : [ 'type' => 'ability', 'id' => $ability, 'marked' => self::marked( $info['readonly'] ) ];
        } elseif ( '' === $ability && self::DEFAULT_SERVER === $server_id && Connection_Approval::STATUS_TOOL === $name ) {
            $out = null;
        } elseif ( self::closes_over_ability( self::property( $mcp_tool, 'handler' ) ) || self::closes_over_ability( self::property( $mcp_tool, 'permission_callback' ) )
            || ( is_object( $wp_ability ) && self::closes_over_ability( self::property( $wp_ability, 'execute_callback' ) ) ) ) {
            $out = null;
        } else {
            $readonly = null;
            if ( method_exists( $mcp_tool, 'get_protocol_dto' ) ) {
                $dto         = $mcp_tool->get_protocol_dto();
                $annotations = is_object( $dto ) && method_exists( $dto, 'getAnnotations' ) ? $dto->getAnnotations() : null;
                if ( is_object( $annotations ) && method_exists( $annotations, 'getReadOnlyHint' ) ) {
                    $readonly = $annotations->getReadOnlyHint();
                }
            }
            $runs = is_object( $wp_ability ) ? self::property( $wp_ability, 'execute_callback' ) : self::property( $mcp_tool, 'handler' );
            $out  = [ 'type' => 'handler', 'id' => $server_id . ':' . $name, 'marked' => self::marked( $readonly ), 'file' => self::file_of( $runs ) ];
        }
        self::$tool_cache[ $key ] = $out;
        return $out;
    }

    /**
     * Tool DTO name.
     *
     * @param mixed $tool
     */
    private static function dto_name( $tool ): string {
        if ( is_object( $tool ) && method_exists( $tool, 'getName' ) ) {
            return (string) $tool->getName();
        }
        return is_array( $tool ) ? (string) ( $tool['name'] ?? '' ) : '';
    }

    /**
     * mcp_adapter_tools_list, priority 30: nothing an AI would be refused.
     *
     * @param mixed $tools  Tool DTOs.
     * @param mixed $server McpServer.
     * @return mixed
     */
    public static function filter_tools_list( $tools, $server = null ) {
        if ( ! is_array( $tools ) ) {
            return $tools;
        }
        $sid = self::server_id_of( $server );
        if ( self::server_is_off( $sid ) ) {
            return [];
        }
        if ( ! is_object( $server ) || ! method_exists( $server, 'get_mcp_tool' ) ) {
            return $tools;
        }
        $out = [];
        foreach ( $tools as $tool ) {
            $name = self::dto_name( $tool );
            $info = '' !== $name ? self::classify_tool( $server->get_mcp_tool( $name ), $sid, $name ) : null;
            if ( null === $info || null === self::reason( $info['id'], $info['marked'] ) ) {
                $out[] = $tool;
            }
        }
        return $out;
    }

    /**
     * mcp_adapter_resources_list: other plugins' ability resources that would be refused are left out.
     *
     * @param mixed $resources Resource DTOs.
     * @param mixed $server
     * @return mixed
     */
    public static function filter_resources_list( $resources, $server = null ) {
        if ( ! is_array( $resources ) ) {
            return $resources;
        }
        if ( self::server_is_off( self::server_id_of( $server ) ) ) {
            return [];
        }
        if ( ! is_object( $server ) || ! method_exists( $server, 'get_mcp_resource' ) ) {
            return $resources;
        }
        return array_values( array_filter( $resources, static function ( $resource ) use ( $server ) {
            $uri = is_object( $resource ) && method_exists( $resource, 'getUri' ) ? (string) $resource->getUri() : '';
            return '' === $uri || null === self::ability_reason( self::ability_name_of( $server->get_mcp_resource( $uri ) ) );
        } ) );
    }

    /**
     * mcp_adapter_prompts_list: the same for prompts.
     *
     * @param mixed $prompts Prompt DTOs.
     * @param mixed $server
     * @return mixed
     */
    public static function filter_prompts_list( $prompts, $server = null ) {
        if ( ! is_array( $prompts ) ) {
            return $prompts;
        }
        if ( self::server_is_off( self::server_id_of( $server ) ) ) {
            return [];
        }
        if ( ! is_object( $server ) || ! method_exists( $server, 'get_mcp_prompt' ) ) {
            return $prompts;
        }
        return array_values( array_filter( $prompts, static function ( $prompt ) use ( $server ) {
            $name = self::dto_name( $prompt );
            return '' === $name || null === self::ability_reason( self::ability_name_of( $server->get_mcp_prompt( $name ) ) );
        } ) );
    }

    /**
     * mcp_adapter_pre_tool_call, priority 6: every tool call, whichever server and
     * transport (the STDIO bridge too). Covers tools with a handler of their own.
     *
     * @return mixed
     */
    public static function pre_tool_call( $args, $tool_name = '', $mcp_tool = null, $server = null ) {
        if ( is_wp_error( $args ) ) {
            return $args;
        }
        $sid = self::server_id_of( $server );
        if ( self::server_is_off( $sid ) ) {
            return self::refuse( $sid, 'server_off' );
        }
        $info = self::classify_tool( $mcp_tool, $sid, (string) $tool_name );
        if ( null !== $info ) {
            $why = self::reason( $info['id'], $info['marked'] );
            return null === $why ? $args : self::refuse( $info['id'], $why, $info['marked'] );
        }
        // The adapter's execute-ability: the ability it is asked to run.
        if ( 'mcp-adapter/execute-ability' === self::ability_name_of( $mcp_tool ) && is_array( $args ) && isset( $args['ability_name'] ) && is_string( $args['ability_name'] ) ) {
            $why = self::ability_reason( $args['ability_name'] );
            if ( null !== $why ) {
                return self::refuse( $args['ability_name'], $why );
            }
        }
        return $args;
    }

    /**
     * mcp_adapter_pre_resource_read.
     *
     * @return mixed
     */
    public static function pre_resource_read( $params, $uri = '', $resource = null, $server = null ) {
        return self::pre_component( $params, $resource, $server );
    }

    /**
     * mcp_adapter_pre_prompt_get.
     *
     * @return mixed
     */
    public static function pre_prompt_get( $arguments, $name = '', $prompt = null, $server = null ) {
        return self::pre_component( $arguments, $prompt, $server );
    }

    /**
     * @param mixed $args
     * @param mixed $component
     * @param mixed $server
     * @return mixed
     */
    private static function pre_component( $args, $component, $server ) {
        if ( is_wp_error( $args ) ) {
            return $args;
        }
        $sid = self::server_id_of( $server );
        if ( self::server_is_off( $sid ) ) {
            return self::refuse( $sid, 'server_off' );
        }
        $ability = self::ability_name_of( $component );
        $why     = '' !== $ability ? self::ability_reason( $ability ) : null;
        return null === $why ? $args : self::refuse( $ability, $why );
    }

    private static function refuse( string $id, string $why, string $marked = '' ): \WP_Error {
        $error = self::error( $id, $why, $marked );
        self::log_refusal( $id, $error );
        return $error;
    }

    // ── Servers and addresses ────────────────────────────────────────────────

    /** @return array<string, object> Server id => McpServer, as the adapter has them (none before it starts). */
    public static function servers(): array {
        if ( ! class_exists( '\WP\MCP\Core\McpAdapter', false ) || ! method_exists( '\WP\MCP\Core\McpAdapter', 'instance' ) ) {
            return [];
        }
        try {
            $adapter = \WP\MCP\Core\McpAdapter::instance();
            $servers = method_exists( $adapter, 'get_servers' ) ? (array) $adapter->get_servers() : [];
        } catch ( \Throwable $e ) {
            return [];
        }
        $out = [];
        foreach ( $servers as $key => $server ) {
            if ( is_object( $server ) ) {
                $id         = self::server_id_of( $server );
                $out[ '' !== $id ? $id : (string) $key ] = $server;
            }
        }
        return $out;
    }

    /** The adapter builds its servers on rest_api_init: start the REST server for them. Admin pages only. */
    public static function build_servers(): void {
        if ( ! did_action( 'rest_api_init' ) && function_exists( 'rest_get_server' ) ) {
            rest_get_server();
        }
    }

    /** @param mixed $server */
    public static function server_route( $server ): string {
        if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_route_namespace' ) || ! method_exists( $server, 'get_server_route' ) ) {
            return '';
        }
        return '/' . trim( (string) $server->get_server_route_namespace(), '/' ) . '/' . trim( (string) $server->get_server_route(), '/' );
    }

    /** @return array<string, string> Lower-case route prefix => server id, other plugins' servers only. */
    private static function other_server_routes(): array {
        $out = [];
        foreach ( self::servers() as $id => $server ) {
            $route = strtolower( self::server_route( $server ) );
            if ( self::DEFAULT_SERVER !== $id && '' !== $route && '/' !== $route ) {
                $out[ $route ] = (string) $id;
            }
        }
        return $out;
    }

    public static function server_is_off( string $id ): bool {
        if ( '' === $id || self::DEFAULT_SERVER === $id ) {
            return false;
        }
        $s = self::settings();
        return isset( $s['off']['servers'][ $id ] ) || ( 'off' === $s['new'] && ! isset( $s['seen']['servers'][ $id ] ) );
    }

    /** AI Nexus's own addresses: never listed, never turned off. */
    public static function is_own_route( string $route ): bool {
        $route = strtolower( '/' . ltrim( $route, '/' ) );
        foreach ( [ '/mcp', '/mcp-abilities', '/vgc-cc' ] as $prefix ) {
            if ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) {
                return true;
            }
        }
        return false;
    }

    /** A route with a segment "mcp", or "mcp-" / "-mcp" in a segment, in any letter case. */
    public static function is_mcp_like( string $route ): bool {
        foreach ( explode( '/', strtolower( $route ) ) as $segment ) {
            if ( 'mcp' === $segment || false !== strpos( $segment, 'mcp-' ) || false !== strpos( $segment, '-mcp' ) ) {
                return true;
            }
        }
        return false;
    }

    public static function endpoint_is_off( string $route ): bool {
        if ( self::is_own_route( $route ) ) {
            return false;
        }
        $s = self::settings();
        return isset( $s['off']['endpoints'][ $route ] ) || ( 'off' === $s['new'] && ! isset( $s['seen']['endpoints'][ $route ] ) );
    }

    /**
     * @param mixed $handlers
     * @return array<int, mixed> The callbacks of a route's handlers.
     */
    private static function route_callbacks( $handlers ): array {
        if ( ! is_array( $handlers ) ) {
            return [];
        }
        $out = [];
        foreach ( isset( $handlers['callback'] ) ? [ $handlers ] : $handlers as $handler ) {
            if ( is_array( $handler ) && isset( $handler['callback'] ) ) {
                $out[] = $handler['callback'];
            }
        }
        return $out;
    }

    /** @param mixed $handlers */
    private static function is_namespace_index( $handlers ): bool {
        foreach ( self::route_callbacks( $handlers ) as $callback ) {
            if ( is_array( $callback ) && isset( $callback[1] ) && 'get_namespace_index' === $callback[1] ) {
                return true;
            }
        }
        return false;
    }

    /** Whether a route belongs to an adapter server (given lower-case server prefixes). */
    private static function server_of_route( string $route, array $server_routes ): string {
        $route = strtolower( $route );
        foreach ( $server_routes as $prefix => $id ) {
            if ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) {
                return (string) $id;
            }
        }
        return '';
    }

    /** Whether a route is an MCP-like address of another plugin that the switches apply to. */
    private static function is_other_endpoint( string $route, $handlers, array $server_routes ): bool {
        return self::is_mcp_like( $route )
            && ! self::is_own_route( $route )
            && '' === self::server_of_route( $route, $server_routes )
            && ! self::is_namespace_index( $handlers );
    }

    /**
     * rest_endpoints: the routes of a server or an address that is turned off are gone.
     *
     * @param mixed $endpoints
     * @return mixed
     */
    public static function remove_routes( $endpoints ) {
        if ( self::$raw_routes || ! is_array( $endpoints ) ) {
            return $endpoints;
        }
        $server_routes = self::other_server_routes();
        foreach ( $endpoints as $route => $handlers ) {
            $route  = (string) $route;
            $server = self::server_of_route( $route, $server_routes );
            if ( '' !== $server ) {
                if ( self::server_is_off( $server ) ) {
                    unset( $endpoints[ $route ] );
                }
                continue;
            }
            if ( self::is_other_endpoint( $route, $handlers, $server_routes ) && self::endpoint_is_off( $route ) ) {
                unset( $endpoints[ $route ] );
            }
        }
        return $endpoints;
    }

    /** @return array<string, string> Other plugins' servers that are on: id => "name (address)". */
    public static function active_servers(): array {
        $out = [];
        foreach ( self::servers() as $id => $server ) {
            $id = (string) $id;
            if ( self::DEFAULT_SERVER === $id || self::server_is_off( $id ) ) {
                continue;
            }
            $name       = method_exists( $server, 'get_server_name' ) ? (string) $server->get_server_name() : $id;
            $route      = self::server_route( $server );
            $out[ $id ] = '' !== $route ? sprintf( '%s (%s)', $name, $route ) : $name;
        }
        return $out;
    }

    /** @return string[] Other plugins' MCP-like addresses that are on. */
    public static function active_endpoints(): array {
        $out           = [];
        $server_routes = self::other_server_routes();
        foreach ( self::raw_routes() as $route => $handlers ) {
            $route = (string) $route;
            if ( self::is_other_endpoint( $route, $handlers, $server_routes ) && ! self::endpoint_is_off( $route ) ) {
                $out[] = $route;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> Every REST route, as registered (no switch applied). */
    public static function raw_routes(): array {
        if ( ! function_exists( 'rest_get_server' ) ) {
            return [];
        }
        self::$raw_routes = true;
        try {
            return (array) rest_get_server()->get_routes();
        } finally {
            self::$raw_routes = false;
        }
    }

    /**
     * rest_pre_dispatch, priority -10: a request to a server or an address that is
     * turned off is answered 404, whichever form of the address it used.
     *
     * @param mixed            $result
     * @param mixed            $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function refuse_request( $result, $server = null, $request = null ) {
        if ( ! $request instanceof \WP_REST_Request ) {
            return $result;
        }
        // As WordPress matches it: any letter case, one trailing newline ignored.
        $path = Request_Context::normalize_route( (string) $request->get_route() );
        if ( self::is_own_route( $path ) ) {
            return $result;
        }
        $server_routes = self::other_server_routes();
        $owner         = self::server_of_route( $path, $server_routes );
        if ( '' !== $owner ) {
            return self::server_is_off( $owner ) ? self::no_route() : $result;
        }
        $s = self::settings();
        foreach ( array_keys( $s['off']['endpoints'] ) as $route ) {
            if ( ! self::is_own_route( $route ) && self::route_matches( $route, $path ) ) {
                return self::no_route();
            }
        }
        // "Keep new ones off": an MCP-like address the administrator has not seen.
        if ( 'off' === $s['new'] && self::is_mcp_like( $path ) ) {
            foreach ( self::raw_routes() as $route => $handlers ) {
                $route = (string) $route;
                if ( self::route_matches( $route, $path ) ) {
                    if ( self::is_other_endpoint( $route, $handlers, $server_routes ) && ! isset( $s['seen']['endpoints'][ $route ] ) ) {
                        return self::no_route();
                    }
                    break;
                }
            }
        }
        return $result;
    }

    /** Whether a request path matches a registered route, the way WordPress matches it. */
    private static function route_matches( string $route, string $path ): bool {
        if ( strtolower( $route ) === $path ) {
            return true;
        }
        return 1 === @preg_match( '@^' . $route . '$@i', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a stored pattern that does not compile matches nothing
    }

    /**
     * A response, not a WP_Error: on /batch/v1 WordPress hands what rest_pre_dispatch
     * returned straight to rest_post_dispatch, where WordPress 7.1 fatals on a WP_Error.
     */
    private static function no_route(): \WP_REST_Response {
        // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own message
        return rest_convert_error_to_response( new \WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.' ), [ 'status' => 404 ] ) );
    }

    // ── What the page shows ──────────────────────────────────────────────────

    /**
     * Everything an AI can reach besides AI Nexus's own tools. Builds the adapter's
     * servers first (admin pages only).
     *
     * @return array{servers: array, tools: array, endpoints: array}
     */
    public static function inventory(): array {
        self::build_servers();
        $s       = self::settings();
        $routes  = self::raw_routes();
        $servers = [];
        $tools   = [];
        $ways    = []; // ability name => [ way, … ]
        $execute = false;

        foreach ( self::servers() as $sid => $server ) {
            $sid        = (string) $sid;
            $is_default = self::DEFAULT_SERVER === $sid;
            $route      = self::server_route( $server );
            $name       = method_exists( $server, 'get_server_name' ) ? (string) $server->get_server_name() : $sid;
            $row        = [
                'id'          => $sid,
                'name'        => $name,
                'description' => method_exists( $server, 'get_server_description' ) ? (string) $server->get_server_description() : '',
                'route'       => $route,
                'address'     => '' !== $route ? rest_url( ltrim( $route, '/' ) ) : '',
                'transports'  => self::transports_of( $route, $routes ),
                'tools'       => [],
                'resources'   => [],
                'prompts'     => [],
                'own'         => $is_default,
                'on'          => ! self::server_is_off( $sid ),
                'new'         => ! $is_default && ! isset( $s['seen']['servers'][ $sid ] ),
                'source'      => '',
            ];
            $files = [];
            foreach ( array_keys( method_exists( $server, 'get_tools' ) ? (array) $server->get_tools() : [] ) as $tool_name ) {
                $tool_name     = (string) $tool_name;
                $row['tools'][] = $tool_name;
                $mcp           = method_exists( $server, 'get_mcp_tool' ) ? $server->get_mcp_tool( $tool_name ) : null;
                if ( $is_default && 'mcp-adapter/execute-ability' === self::ability_name_of( $mcp ) ) {
                    $execute = true;
                }
                $info = self::classify_tool( $mcp, $sid, $tool_name );
                if ( null === $info ) {
                    continue;
                }
                if ( 'ability' === $info['type'] ) {
                    $ways[ $info['id'] ][] = [ 'kind' => $is_default ? 'direct' : 'server', 'server' => $name ];
                    $files[]               = (string) ( self::ability( $info['id'] )['file'] ?? '' );
                    continue;
                }
                $files[]              = (string) ( $info['file'] ?? '' );
                $tools[ $info['id'] ] = self::tool_row( $info['id'], $tool_name, self::tool_label( $mcp ), 'server:' . $sid, $name, $info['marked'], [ [ 'kind' => $is_default ? 'direct' : 'server', 'server' => $name ] ], (string) ( $info['file'] ?? '' ), $s );
            }
            foreach ( array_keys( method_exists( $server, 'get_resources' ) ? (array) $server->get_resources() : [] ) as $uri ) {
                $row['resources'][] = (string) $uri;
                $ability            = self::ability_name_of( method_exists( $server, 'get_mcp_resource' ) ? $server->get_mcp_resource( (string) $uri ) : null );
                if ( '' !== $ability && ! self::is_own_ability( $ability ) ) {
                    $ways[ $ability ][] = [ 'kind' => 'resource', 'server' => $name ];
                }
            }
            foreach ( array_keys( method_exists( $server, 'get_prompts' ) ? (array) $server->get_prompts() : [] ) as $prompt ) {
                $row['prompts'][] = (string) $prompt;
                $ability          = self::ability_name_of( method_exists( $server, 'get_mcp_prompt' ) ? $server->get_mcp_prompt( (string) $prompt ) : null );
                if ( '' !== $ability && ! self::is_own_ability( $ability ) ) {
                    $ways[ $ability ][] = [ 'kind' => 'prompt', 'server' => $name ];
                }
            }
            $row['source'] = $is_default ? 'VGC AI Nexus' : self::server_source( $row['transports'], $files );
            $servers[]     = $row;
        }
        // AI Nexus's own server first.
        usort( $servers, static function ( $a, $b ) {
            if ( $a['own'] !== $b['own'] ) {
                return $a['own'] ? -1 : 1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        foreach ( function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : [] as $ability ) {
            $name = (string) $ability->get_name();
            $info = self::ability( $name );
            if ( null === $info || $info['own'] ) {
                continue;
            }
            $meta  = (array) $ability->get_meta();
            $found = $ways[ $name ] ?? [];
            if ( $execute && ! empty( $meta['mcp']['public'] ) && 'tool' === ( $meta['mcp']['type'] ?? 'tool' ) ) {
                array_unshift( $found, [ 'kind' => 'execute', 'server' => '' ] );
            }
            if ( $ability->get_meta_item( 'show_in_rest' ) ) {
                $found[] = [ 'kind' => 'rest', 'server' => '' ];
            }
            if ( ! $found ) {
                continue; // No way in for an AI.
            }
            $slash          = strpos( $name, '/' );
            $tools[ $name ] = self::tool_row( $name, $name, (string) $ability->get_label(), false === $slash ? $name : substr( $name, 0, $slash ), '', self::marked( $info['readonly'] ), $found, $info['file'], $s );
        }
        uasort( $tools, static function ( $a, $b ) {
            return strcmp( $a['group'] . '|' . $a['id'], $b['group'] . '|' . $b['id'] );
        } );

        $endpoints     = [];
        $server_routes = self::other_server_routes();
        foreach ( $routes as $route => $handlers ) {
            $route = (string) $route;
            if ( ! self::is_other_endpoint( $route, $handlers, $server_routes ) ) {
                continue;
            }
            $methods = [];
            $file    = '';
            foreach ( is_array( $handlers ) ? ( isset( $handlers['callback'] ) ? [ $handlers ] : $handlers ) : [] as $handler ) {
                if ( is_array( $handler ) ) {
                    $methods = array_merge( $methods, array_keys( array_filter( (array) ( $handler['methods'] ?? [] ) ) ) );
                    $file    = '' !== $file ? $file : self::file_of( $handler['callback'] ?? null );
                }
            }
            $endpoints[] = [
                'route'   => $route,
                'address' => rest_url( ltrim( $route, '/' ) ),
                'methods' => array_values( array_unique( $methods ) ),
                'source'  => self::source_label( $file ),
                'on'      => ! self::endpoint_is_off( $route ),
                'new'     => ! isset( $s['seen']['endpoints'][ $route ] ),
            ];
        }

        return [ 'servers' => $servers, 'tools' => array_values( $tools ), 'endpoints' => $endpoints ];
    }

    /**
     * @param array $ways  [ { kind, server }, … ]
     * @param array $s     settings()
     */
    private static function tool_row( string $id, string $name, string $label, string $group, string $group_label, string $marked, array $ways, string $file, array $s ): array {
        return [
            'id'          => $id,
            'name'        => $name,
            'label'       => $label,
            'group'       => $group,
            'group_label' => $group_label,
            'marked'      => $marked,
            'admin_read'  => 'unmarked' === $marked && isset( $s['reads'][ $id ] ),
            'ways'        => $ways,
            'source'      => self::source_label( $file ),
            'on'          => ! isset( $s['off']['tools'][ $id ] ) && ! ( 'off' === $s['new'] && ! isset( $s['seen']['tools'][ $id ] ) ),
            'new'         => ! isset( $s['seen']['tools'][ $id ] ),
        ];
    }

    /** @param mixed $mcp_tool */
    private static function tool_label( $mcp_tool ): string {
        if ( is_object( $mcp_tool ) && method_exists( $mcp_tool, 'get_protocol_dto' ) ) {
            $dto = $mcp_tool->get_protocol_dto();
            if ( is_object( $dto ) && method_exists( $dto, 'getTitle' ) && is_string( $dto->getTitle() ) ) {
                return (string) $dto->getTitle();
            }
        }
        return '';
    }

    /**
     * The transport classes serving a server's address.
     *
     * @return string[]
     */
    private static function transports_of( string $route, array $routes ): array {
        $out    = [];
        $prefix = strtolower( $route );
        foreach ( $routes as $key => $handlers ) {
            $key = strtolower( (string) $key );
            if ( '' === $prefix || ( $key !== $prefix && 0 !== strpos( $key, $prefix . '/' ) ) ) {
                continue;
            }
            foreach ( self::route_callbacks( $handlers ) as $callback ) {
                if ( is_array( $callback ) && isset( $callback[0] ) ) {
                    $out[] = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
                }
            }
        }
        return array_values( array_unique( $out ) );
    }

    /** Which plugin a server comes from, as best as can be told: its own transport, else its tools. */
    private static function server_source( array $transports, array $files ): string {
        foreach ( $transports as $class ) {
            if ( 0 !== strpos( ltrim( $class, '\\' ), 'WP\\MCP\\' ) && class_exists( $class ) ) {
                try {
                    $label = self::source_label( (string) ( new \ReflectionClass( $class ) )->getFileName() );
                } catch ( \Throwable $e ) {
                    $label = '';
                }
                if ( '' !== $label ) {
                    return $label;
                }
            }
        }
        $labels = array_values( array_unique( array_filter( array_map( [ self::class, 'source_label' ], $files ) ) ) );
        return implode( ', ', $labels );
    }

    /** "WooCommerce", "must-use plugin x.php", "WordPress" or '' for a file. */
    public static function source_label( string $file ): string {
        if ( '' === $file ) {
            return '';
        }
        $rest = self::path_under( $file, WP_PLUGIN_DIR );
        if ( null !== $rest ) {
            return self::plugin_name( explode( '/', $rest )[0] );
        }
        $rest = self::path_under( $file, WPMU_PLUGIN_DIR );
        if ( null !== $rest ) {
            /* translators: %s: file name */
            return sprintf( __( 'must-use plugin %s', 'mcp-abilities' ), explode( '/', $rest )[0] );
        }
        if ( null !== self::path_under( $file, ABSPATH . WPINC ) ) {
            return 'WordPress';
        }
        $rest = self::path_under( $file, get_theme_root() );
        if ( null !== $rest ) {
            /* translators: %s: theme folder */
            return sprintf( __( 'theme %s', 'mcp-abilities' ), explode( '/', $rest )[0] );
        }
        return '';
    }

    /**
     * The part of $file below $dir, or null when it is not below it. Compares the
     * real paths too (a short 8.3 name on Windows, a symlinked folder).
     */
    private static function path_under( string $file, string $dir ): ?string {
        $files = array_unique( array_filter( [ wp_normalize_path( $file ), wp_normalize_path( (string) realpath( $file ) ) ] ) );
        $dirs  = array_unique( array_filter( [ wp_normalize_path( $dir ), wp_normalize_path( (string) realpath( $dir ) ) ] ) );
        $fold  = '\\' === DIRECTORY_SEPARATOR;
        foreach ( $dirs as $base ) {
            $base = trailingslashit( $base );
            foreach ( $files as $path ) {
                $match = $fold ? 0 === stripos( $path, $base ) : 0 === strpos( $path, $base );
                if ( $match ) {
                    return (string) substr( $path, strlen( $base ) );
                }
            }
        }
        return null;
    }

    private static function plugin_name( string $folder ): string {
        static $names = null;
        if ( null === $names ) {
            $names = [];
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            foreach ( (array) get_plugins() as $file => $data ) {
                $names[ explode( '/', (string) $file )[0] ] = (string) ( $data['Name'] ?? '' );
            }
        }
        return '' !== ( $names[ $folder ] ?? '' ) ? $names[ $folder ] : $folder;
    }

    /**
     * The page was viewed: everything on it is "seen". With "keep new ones off",
     * what the administrator sees for the first time is stored as off, so that
     * seeing it never switches it on.
     */
    public static function mark_seen( array $inventory ): void {
        $s       = self::settings();
        $changed = false;
        $lists   = [
            'tools'     => array_column( $inventory['tools'], 'id' ),
            'servers'   => array_values( array_filter( array_column( $inventory['servers'], 'id' ), static fn( $id ) => self::DEFAULT_SERVER !== $id ) ),
            'endpoints' => array_column( $inventory['endpoints'], 'route' ),
        ];
        foreach ( $lists as $kind => $ids ) {
            foreach ( $ids as $id ) {
                $id = (string) $id;
                if ( isset( $s['seen'][ $kind ][ $id ] ) ) {
                    continue;
                }
                if ( 'off' === $s['new'] ) {
                    $s['off'][ $kind ][ $id ] = 1;
                }
                $s['seen'][ $kind ][ $id ] = 1;
                $changed                   = true;
            }
        }
        if ( $changed ) {
            self::save( $s );
        }
    }

    /**
     * Apply what the administrator saved on the page. Only what the inventory
     * holds is taken; "counts as a read" only for tools their plugin left unmarked.
     *
     * @param array $raw       { tools: {id: {on, read}}, servers: {id: on}, endpoints: {route: on}, new: allow|off }
     * @param array $inventory inventory()
     * @return array The settings saved.
     */
    public static function apply_admin_changes( array $raw, array $inventory ): array {
        $s     = self::settings();
        $tools = [];
        foreach ( $inventory['tools'] as $row ) {
            $tools[ $row['id'] ] = $row;
        }
        foreach ( is_array( $raw['tools'] ?? null ) ? $raw['tools'] : [] as $id => $state ) {
            $id = (string) $id;
            if ( ! isset( $tools[ $id ] ) || ! is_array( $state ) ) {
                continue;
            }
            if ( ! empty( $state['on'] ) ) {
                unset( $s['off']['tools'][ $id ] );
            } else {
                $s['off']['tools'][ $id ] = 1;
            }
            if ( 'unmarked' === $tools[ $id ]['marked'] && ! empty( $state['read'] ) ) {
                $s['reads'][ $id ] = 1;
            } else {
                unset( $s['reads'][ $id ] );
            }
            $s['seen']['tools'][ $id ] = 1;
        }
        $servers = [];
        foreach ( $inventory['servers'] as $row ) {
            if ( empty( $row['own'] ) ) {
                $servers[ $row['id'] ] = true;
            }
        }
        foreach ( is_array( $raw['servers'] ?? null ) ? $raw['servers'] : [] as $id => $on ) {
            $id = (string) $id;
            if ( ! isset( $servers[ $id ] ) ) {
                continue; // AI Nexus's own, or unknown.
            }
            if ( $on ) {
                unset( $s['off']['servers'][ $id ] );
            } else {
                $s['off']['servers'][ $id ] = 1;
            }
            $s['seen']['servers'][ $id ] = 1;
        }
        $endpoints = array_flip( array_column( $inventory['endpoints'], 'route' ) );
        foreach ( is_array( $raw['endpoints'] ?? null ) ? $raw['endpoints'] : [] as $route => $on ) {
            $route = (string) $route;
            if ( ! isset( $endpoints[ $route ] ) || self::is_own_route( $route ) ) {
                continue;
            }
            if ( $on ) {
                unset( $s['off']['endpoints'][ $route ] );
            } else {
                $s['off']['endpoints'][ $route ] = 1;
            }
            $s['seen']['endpoints'][ $route ] = 1;
        }
        if ( isset( $raw['new'] ) && in_array( $raw['new'], [ 'allow', 'off' ], true ) ) {
            $s['new'] = $raw['new'];
        }
        self::save( $s );
        return self::settings();
    }

    /** Back to a fresh request: for tests that run several requests in one process. */
    public static function reset(): void {
        self::$logged     = [];
        self::$tool_cache = [];
    }
}
