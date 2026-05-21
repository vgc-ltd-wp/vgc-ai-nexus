<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Core plugin singleton. Bootstraps all subsystems.
 */
final class Plugin {

    private static ?Plugin $instance = null;

    /** @var Registry */
    public Registry $registry;

    private function __construct() {}

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        $this->load_dependencies();
        $this->load_textdomain();

        $this->registry = new Registry();
        $this->registry->auto_discover();

        $this->register_with_adapter();

        if ( is_admin() ) {
            $admin = new Admin\Settings_Page( $this->registry );
            $admin->init();

            $connections = new Admin\Connections_Page();
            $connections->init();
        }

        do_action( 'mcp_abilities_loaded', $this );
    }

    private function load_dependencies(): void {
        $files = [
            'class-installer.php',
            'class-logger.php',
            'class-registry.php',
            'class-ability-group.php',
            'class-ability.php',
            'class-custom-code.php',
            'admin/class-settings-page.php',
            'admin/class-connections-page.php',
        ];
        foreach ( $files as $file ) {
            require_once MCP_ABILITIES_DIR . 'includes/' . $file;
        }

        // Register frontend CSS/JS output hooks as early as possible.
        Custom_Code::init();

        // Start the activity logger (hooks app-password capture + cron pruning).
        Logger::init();
    }

    private function load_textdomain(): void {
        load_plugin_textdomain(
            'mcp-abilities',
            false,
            dirname( plugin_basename( MCP_ABILITIES_FILE ) ) . '/languages'
        );
    }

    // ── MCP Adapter integration ─────────────────────────────────────────────

    private function register_with_adapter(): void {
        // Register WP Abilities on the correct hooks (WP 6.9 strict enforcement).
        // These handle the discover-abilities / execute-ability paths.
        add_action( 'wp_abilities_api_categories_init', [ $this, 'register_ability_category' ] );
        add_action( 'wp_abilities_api_init',            [ $this, 'register_wp_abilities' ] );

        // Inject McpTool instances directly — bypasses wp_get_ability() so timing is not an issue.
        add_filter( 'mcp_adapter_default_server_config', [ $this, 'add_tools_to_default_server' ] );
    }

    public function register_ability_category(): void {
        wp_register_ability_category(
            'mcp-abilities',
            [
                'label'       => __( 'MCP Abilities', 'mcp-abilities' ),
                'description' => __( 'WordPress site management abilities for MCP.', 'mcp-abilities' ),
            ]
        );
    }

    public function register_wp_abilities(): void {
        foreach ( $this->registry->get_groups() as $group ) {
            foreach ( $group->get_abilities() as $ability ) {
                // WP 6.9 ability names only allow [a-z0-9-/] — convert underscores to dashes.
                $name = 'mcp-abilities/' . str_replace( '_', '-', $ability->get_key() );

                // Strip the top-level 'type' from the WP-registered schema.
                // WP REST validation uses rest_is_object() which returns false for PHP []
                // (empty array decoded from JSON {}), causing all no-param calls to fail.
                // Without 'type', WP only enforces 'required' fields and validates provided
                // properties — empty {} passes for optional-only abilities, required fields
                // still fail correctly for abilities that need them.
                $wp_schema = $ability->get_input_schema();
                unset( $wp_schema['type'] );

                wp_register_ability(
                    $name,
                    [
                        'category'            => 'mcp-abilities',
                        'label'               => $ability->get_label(),
                        'description'         => $ability->get_description(),
                        'input_schema'        => $wp_schema,
                        'permission_callback' => function() use ( $ability, $group ) {
                            // Disabled tools are still reachable by logged-in users so
                            // the execute callback can explain why the tool is unavailable.
                            if ( ! $group->is_enabled() || ! $ability->is_enabled() ) {
                                return is_user_logged_in();
                            }
                            return $ability->current_user_can();
                        },
                        'execute_callback'    => function( $params = [] ) use ( $ability, $group ) {
                            return $this->run_ability( $ability, $group, (array) $params );
                        },
                        'meta'                => [
                            'mcp' => [ 'public' => true ],
                        ],
                    ]
                );
            }
        }
    }

    /**
     * Add enabled abilities as McpTool instances to the default server.
     * Using McpTool instances instead of string names avoids wp_get_ability()
     * at server-creation time, which fires before wp_abilities_api_init on WP 6.9.
     */
    public function add_tools_to_default_server( array $config ): array {
        foreach ( $this->registry->get_groups() as $group ) {
            foreach ( $group->get_abilities() as $ability ) {
                $tool = $this->make_mcp_tool( $ability, $group );
                if ( null !== $tool ) {
                    $config['tools'][] = $tool;
                }
            }
        }
        return $config;
    }

    private function make_mcp_tool( Ability $ability, Ability_Group $group ): ?\WP\MCP\Domain\Tools\McpTool {
        if ( ! class_exists( '\WP\MCP\Domain\Tools\McpTool' )
            || ! method_exists( '\WP\MCP\Domain\Tools\McpTool', 'fromArray' ) ) {
            return null;
        }

        // McpNameSanitizer replaces '/' with '-', so pre-sanitize to get a predictable name.
        $tool_name = str_replace( '/', '-', 'mcp-abilities/' . str_replace( '_', '-', $ability->get_key() ) );

        $tool = \WP\MCP\Domain\Tools\McpTool::fromArray( [
            'name'        => $tool_name,
            'description' => $ability->get_description(),
            'inputSchema' => $ability->get_input_schema(),
            'handler'     => function( $params = [] ) use ( $ability, $group ) {
                return $this->run_ability( $ability, $group, (array) $params );
            },
            'permission'  => function() use ( $ability, $group ) {
                if ( ! $group->is_enabled() || ! $ability->is_enabled() ) {
                    return is_user_logged_in();
                }
                return $ability->current_user_can();
            },
        ] );

        return $tool instanceof \WP_Error ? null : $tool;
    }

    /**
     * Execute an ability and log the call to the activity log.
     */
    private function run_ability( Ability $ability, Ability_Group $group, array $params ): array {
        if ( ! $group->is_enabled() || ! $ability->is_enabled() ) {
            return $this->disabled_tool_response( $ability );
        }
        $result = $ability->execute( $params );
        Logger::log( $ability->get_key(), $result );
        return $result;
    }

    /**
     * Consistent error response returned when a tool is called while disabled.
     * Uses the same format as Ability::error() so the MCP adapter marks it as
     * a tool error, giving the AI context to advise the user.
     */
    private function disabled_tool_response( Ability $ability ): array {
        return [
            'success' => false,
            'error'   => sprintf(
                'The "%s" tool is currently disabled. To use it, go to WordPress Admin → AI Nexus → Abilities and enable it.',
                $ability->get_label()
            ),
        ];
    }
}
