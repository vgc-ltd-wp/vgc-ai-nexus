<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Reports which MCP adapter is actually serving this site.
 *
 * Several plugins bundle the same MCP adapter library (WooCommerce 10.9+ ships
 * it as a Composer dependency; AIOSEO can install it). Whichever copy loads
 * first defines the WP\MCP\* classes for the entire request, and WordPress
 * offers no arbitration. When a foreign copy wins:
 *
 *   - it may predate filters we rely on (e.g. mcp_adapter_initialize_response),
 *   - its class names may differ from the ones we check for, which used to make
 *     core silently contribute ZERO tools.
 *
 * None of that produced an error anywhere, so it went unnoticed for weeks. This
 * class turns the situation into a reported fact.
 *
 * The globals only say whose WP_MCP_VERSION won. Classes load lazily, so the
 * server can still be a MIX of two copies (it was, on WooCommerce 10.9 sites,
 * until 2.23.1) — which is why the class files themselves are checked too.
 */
class Adapter_Status {

    /** The classes that decide how a tool is listed, called and answered. */
    private const TOOL_PATH = [
        'WP\\MCP\\Core\\McpAdapter',
        'WP\\MCP\\Core\\McpServer',
        'WP\\MCP\\Domain\\Tools\\McpTool',
        'WP\\MCP\\Handlers\\Tools\\ToolsHandler',
    ];

    /** @return array<string,mixed> */
    public static function get(): array {
        $flags = isset( $GLOBALS['mcp_abilities_adapter'] ) && is_array( $GLOBALS['mcp_abilities_adapter'] )
            ? $GLOBALS['mcp_abilities_adapter']
            : [ 'ours' => false, 'preloaded_by' => null, 'autoload_failed' => false ];

        $loaded      = defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '';
        $has_mcptool = class_exists( '\WP\MCP\Domain\Tools\McpTool' )
            && method_exists( '\WP\MCP\Domain\Tools\McpTool', 'fromArray' );
        $origins     = self::class_origins();

        $status = [
            'adapter_loaded'     => '' !== $loaded,
            'adapter_version'    => $loaded,
            'is_bundled_adapter' => (bool) $flags['ours'],
            'preloaded_by'       => $flags['preloaded_by'],
            'bundled_autoload_failed' => (bool) $flags['autoload_failed'],
            'mcptool_api'        => $has_mcptool,
            'tool_injection'     => $has_mcptool ? 'object' : 'ability-name fallback',
            // bundled | mixed | foreign | none — where the WP\MCP classes came from.
            'class_origin'       => $origins['state'],
            'foreign_classes'    => array_keys( $origins['foreign'] ),
            'foreign_sources'    => $origins['sources'],
            'tool_errors_as'     => self::tool_errors_need_wp_error() ? 'WP_Error' : 'error array',
        ];

        $status['healthy'] = $status['adapter_loaded'] && ( $status['is_bundled_adapter']
            ? 'bundled' === $origins['state']
            : $has_mcptool );

        return $status;
    }

    /**
     * Where the adapter classes of this request were defined from.
     *
     * Every WP\MCP class of our copy that is already loaded is checked, and the
     * tool-path classes are loaded if they are not yet (resolving them is what
     * the server itself would do).
     *
     * @return array{state:string, foreign:array<string,string>, sources:string[]}
     */
    public static function class_origins(): array {
        static $origins = null;
        if ( null !== $origins ) {
            return $origins;
        }

        $names = [];
        foreach ( array_keys( Adapter_Loader::classmap() ) as $class ) {
            if ( str_starts_with( $class, 'WP\\MCP\\' ) ) {
                $names[ $class ] = false;
            }
        }
        foreach ( self::TOOL_PATH as $class ) {
            $names[ $class ] = true;
        }

        $bundled = 0;
        $foreign = [];
        foreach ( $names as $class => $autoload ) {
            if ( ! class_exists( $class, $autoload ) && ! interface_exists( $class, false ) && ! trait_exists( $class, false ) ) {
                continue;
            }
            $file = (string) ( new \ReflectionClass( $class ) )->getFileName();
            if ( self::is_bundled_file( $file ) ) {
                ++$bundled;
            } else {
                $foreign[ $class ] = $file;
            }
        }

        $sources = array_values( array_unique( array_map( [ self::class, 'source_of' ], $foreign ) ) );

        if ( ! $bundled && ! $foreign ) {
            $state = 'none';
        } elseif ( ! $foreign ) {
            $state = 'bundled';
        } elseif ( ! $bundled ) {
            $state = 'foreign';
        } else {
            $state = 'mixed';
        }

        $origins = [ 'state' => $state, 'foreign' => $foreign, 'sources' => $sources ];
        return $origins;
    }

    /**
     * Whether tool errors must reach the adapter as WP_Error.
     *
     * Our ToolsHandler reads {success:false, error:"..."}. Older ones (0.1.0,
     * bundled with WooCommerce 10.9) read $result['error']['message'] and reduce
     * every such error to "An error occurred while executing the tool.". Every
     * version keeps a WP_Error's message, but ours also writes each WP_Error to
     * the PHP error log, so the conversion is kept to the handlers that need it.
     */
    public static function tool_errors_need_wp_error(): bool {
        static $need = null;
        if ( null === $need ) {
            $class = 'WP\\MCP\\Handlers\\Tools\\ToolsHandler';
            $need  = ! class_exists( $class )
                || ! self::is_bundled_file( (string) ( new \ReflectionClass( $class ) )->getFileName() );
        }
        return $need;
    }

    /** One-line human summary, used in the guide and the admin notice. */
    public static function summary(): string {
        $s = self::get();
        if ( ! $s['adapter_loaded'] ) {
            return 'No MCP adapter is loaded — the MCP endpoint will not work.';
        }
        if ( $s['is_bundled_adapter'] && 'bundled' === $s['class_origin'] ) {
            return sprintf( 'Serving via the adapter bundled with AI Nexus (%s).', $s['adapter_version'] );
        }
        if ( $s['is_bundled_adapter'] ) {
            return sprintf(
                'The adapter bundled with AI Nexus (%s) is loaded, but %d of its classes were loaded from another copy (%s): %s. Tool errors are handed over as WP_Error so their text still reaches the AI; tool annotations may be missing.',
                $s['adapter_version'],
                count( $s['foreign_classes'] ),
                implode( ', ', $s['foreign_sources'] ),
                self::short_list( $s['foreign_classes'] )
            );
        }
        return sprintf(
            'Serving via an adapter provided by ANOTHER plugin (%s)%s. AI Nexus is using the %s path for tool registration%s.%s',
            $s['adapter_version'] ?: 'unknown version',
            $s['bundled_autoload_failed'] ? ', because the adapter bundled with AI Nexus could not be loaded' : '',
            $s['tool_injection'],
            $s['foreign_sources'] ? ' (adapter classes from: ' . implode( ', ', $s['foreign_sources'] ) . ')' : '',
            'WP_Error' === $s['tool_errors_as'] ? ' Tool errors of AI Nexus and its extensions are handed over as WP_Error so their text still reaches the AI.' : ''
        );
    }

    /**
     * Warn an administrator when a foreign adapter is serving, when our own
     * bundled copy failed to load, or when the server is a mix of copies. All
     * are recoverable, but only if visible.
     */
    public static function maybe_admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = self::get();
        if ( $s['is_bundled_adapter'] && ! $s['bundled_autoload_failed'] && 'bundled' === $s['class_origin'] ) {
            return;
        }

        $advice = 'mixed' === $s['class_origin'] && $s['is_bundled_adapter']
            ? __( 'Another plugin loaded part of its own copy of the MCP adapter before AI Nexus could. Please tell VGC which plugin is named above.', 'mcp-abilities' )
            : __( 'Tools still work, but features that depend on the bundled adapter (such as the connection orientation sent to AI assistants) may be unavailable. If this is unexpected, deactivate other MCP adapter plugins or reinstall AI Nexus.', 'mcp-abilities' );

        $class = $s['adapter_loaded'] ? 'notice-warning' : 'notice-error';
        echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>VGC AI Nexus:</strong> '
            . esc_html( self::summary() ) . ' '
            . esc_html( $advice )
            . '</p></div>';
    }

    private static function is_bundled_file( string $file ): bool {
        if ( '' === $file ) {
            return false;
        }
        static $ours = null;
        if ( null === $ours ) {
            $ours = self::normalize( MCP_ABILITIES_DIR . 'mcp', true ) . '/';
        }
        return str_starts_with( self::normalize( $file ), $ours );
    }

    /**
     * Comparable form of a path. Class file names come from PHP already
     * resolved; directory constants may still go through a symlink.
     */
    private static function normalize( string $path, bool $resolve = false ): string {
        $real = $resolve ? realpath( $path ) : false;
        return strtolower( rtrim( wp_normalize_path( false !== $real ? $real : $path ), '/' ) );
    }

    /** The plugin folder a file belongs to, e.g. "woocommerce". */
    private static function source_of( string $file ): string {
        $file = self::normalize( $file );
        $dirs = [ 'mu-plugins/' => WPMU_PLUGIN_DIR, '' => WP_PLUGIN_DIR ];
        foreach ( $dirs as $prefix => $dir ) {
            $dir = self::normalize( $dir, true ) . '/';
            if ( str_starts_with( $file, $dir ) ) {
                return $prefix . strtok( substr( $file, strlen( $dir ) ), '/' );
            }
        }
        return '' === $file ? 'unknown' : 'outside the plugins folder';
    }

    /** @param string[] $classes */
    private static function short_list( array $classes ): string {
        $short = array_map( static function ( string $class ): string {
            $pos = strrpos( $class, '\\' );
            return false === $pos ? $class : substr( $class, $pos + 1 );
        }, $classes );
        $more = count( $short ) - 4;
        return implode( ', ', array_slice( $short, 0, 4 ) ) . ( $more > 0 ? sprintf( ' and %d more', $more ) : '' );
    }
}
