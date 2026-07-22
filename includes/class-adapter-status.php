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
 */
class Adapter_Status {

    /** @return array<string,mixed> */
    public static function get(): array {
        $flags = isset( $GLOBALS['mcp_abilities_adapter'] ) && is_array( $GLOBALS['mcp_abilities_adapter'] )
            ? $GLOBALS['mcp_abilities_adapter']
            : [ 'ours' => false, 'preloaded_by' => null, 'autoload_failed' => false ];

        $loaded      = defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '';
        $has_mcptool = class_exists( '\WP\MCP\Domain\Tools\McpTool' )
            && method_exists( '\WP\MCP\Domain\Tools\McpTool', 'fromArray' );

        $status = [
            'adapter_loaded'     => '' !== $loaded,
            'adapter_version'    => $loaded,
            'is_bundled_adapter' => (bool) $flags['ours'],
            'preloaded_by'       => $flags['preloaded_by'],
            'bundled_autoload_failed' => (bool) $flags['autoload_failed'],
            'mcptool_api'        => $has_mcptool,
            'tool_injection'     => $has_mcptool ? 'object' : 'ability-name fallback',
        ];

        $status['healthy'] = $status['adapter_loaded'] && ( $status['is_bundled_adapter'] || $has_mcptool );

        return $status;
    }

    /** One-line human summary, used in the guide and the admin notice. */
    public static function summary(): string {
        $s = self::get();
        if ( ! $s['adapter_loaded'] ) {
            return 'No MCP adapter is loaded — the MCP endpoint will not work.';
        }
        if ( $s['is_bundled_adapter'] ) {
            return sprintf( 'Serving via the adapter bundled with AI Nexus (%s).', $s['adapter_version'] );
        }
        return sprintf(
            'Serving via an adapter provided by ANOTHER plugin (%s)%s. AI Nexus is using the %s path for tool registration.',
            $s['adapter_version'] ?: 'unknown version',
            $s['bundled_autoload_failed'] ? ', because the adapter bundled with AI Nexus could not be loaded' : '',
            $s['tool_injection']
        );
    }

    /**
     * Warn an administrator when a foreign adapter is serving, or when our own
     * bundled copy failed to load. Both are recoverable, but only if visible.
     */
    public static function maybe_admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = self::get();
        if ( $s['is_bundled_adapter'] && ! $s['bundled_autoload_failed'] ) {
            return;
        }

        $class = $s['adapter_loaded'] ? 'notice-warning' : 'notice-error';
        echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>VGC AI Nexus:</strong> '
            . esc_html( self::summary() ) . ' '
            . esc_html__( 'Tools still work, but features that depend on the bundled adapter (such as the connection orientation sent to AI assistants) may be unavailable. If this is unexpected, deactivate other MCP adapter plugins or reinstall AI Nexus.', 'mcp-abilities' )
            . '</p></div>';
    }
}
