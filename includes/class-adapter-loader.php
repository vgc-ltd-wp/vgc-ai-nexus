<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps every class of the bundled MCP adapter coming from AI Nexus's own copy.
 *
 * Defining WP_MCP_VERSION and registering our Composer autoloader first is not
 * enough. Classes load lazily, and a plugin that loads after us can put its own
 * autoloader IN FRONT of ours: WooCommerce 10.9+ prepends a (Jetpack)
 * autoloader that maps every WP\MCP\* class to the adapter 0.1.0 it bundles.
 * Every adapter class we had not touched yet then came from WooCommerce, and
 * our server ran as a mix of two versions: the old ToolsHandler turned every
 * tool error into "An error occurred while executing the tool.", McpTool had no
 * fromArray(), and ability annotations went out unmapped. Nothing reported it.
 *
 * So a loader for our two namespaces (WP\MCP, WP\McpSchema) goes to the FRONT
 * of the stack at file level, and is moved back to the front once every plugin
 * file has run (plugins_loaded) and again before the REST routes — and with
 * them the MCP servers — are built (rest_api_init). The classes still load
 * lazily: defining all of them up front would cost every request ~30 ms on a
 * host without OPcache.
 *
 * It is our own static method on purpose, not our Composer ClassLoader: the
 * Jetpack autoloader warns about a Composer loader that sits in front of it.
 */
final class Adapter_Loader {

    /** @var array<string,string>|null Class => file, from the bundled adapter's classmap. */
    private static ?array $classmap = null;

    /** @return array<string,string> */
    public static function classmap(): array {
        if ( null === self::$classmap ) {
            $file = MCP_ABILITIES_DIR . 'mcp/vendor/composer/autoload_classmap.php';
            $map  = is_readable( $file ) ? require $file : [];
            self::$classmap = is_array( $map ) ? $map : [];
        }
        return self::$classmap;
    }

    /** spl autoloader: our file for our namespaces, nothing else. */
    public static function load( string $class ): void {
        if ( ! str_starts_with( $class, 'WP\\MCP\\' ) && ! str_starts_with( $class, 'WP\\McpSchema\\' ) ) {
            return;
        }
        $map = self::classmap();
        if ( isset( $map[ $class ] ) && is_file( $map[ $class ] ) ) {
            require_once $map[ $class ];
        }
    }

    /** Put our loader in front of every autoloader registered so far. */
    public static function guard(): void {
        spl_autoload_unregister( [ self::class, 'load' ] );
        spl_autoload_register( [ self::class, 'load' ], true, true );
    }

    /** Called once, at file level, right after our adapter is set up. */
    public static function register(): void {
        self::guard();
        add_action( 'plugins_loaded', [ self::class, 'guard' ], PHP_INT_MIN );
        add_action( 'rest_api_init', [ self::class, 'guard' ], PHP_INT_MIN );
    }
}
