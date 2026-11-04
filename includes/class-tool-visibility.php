<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * What a signed-in user is told exists.
 *
 * The MCP endpoint lets in anyone with `read`, and the adapter listed every tool
 * to all of them. Every call was (and is) checked, so this was never a way to
 * run anything, but a Subscriber could enumerate the whole tool surface. Now
 * tools/list shows a user the tools they could call, and discover-abilities and
 * get-ability-info need more than `read`.
 *
 * Not a security boundary on its own: the checks on each call stay as they are.
 * It works through the adapter's filters, so mcp/ is not patched; another plugin's
 * copy of the adapter older than 0.5.0 has no such filter and lists everything,
 * which still runs nothing the user may not.
 */
final class Tool_Visibility {

    /** What discover-abilities and get-ability-info need. The capability every content role has. */
    public const DISCOVERY_CAP = 'edit_posts';

    /** Error code of a permission check that means a plain no, with a message. */
    public const REFUSED_CODE = 'mcp_abilities_refused';

    public static function init(): void {
        // Before Connection_Approval (999), which then decides what a held connection sees.
        add_filter( 'mcp_adapter_tools_list', [ self::class, 'filter_tools_list' ], 20, 2 );
        add_filter( 'mcp_adapter_discover_abilities_capability', [ self::class, 'discovery_cap' ] );
        add_filter( 'mcp_adapter_get_ability_info_capability', [ self::class, 'discovery_cap' ] );
    }

    /**
     * Raise the adapter's default only; a site's own capability is left alone.
     *
     * @param mixed $cap
     * @return mixed
     */
    public static function discovery_cap( $cap ) {
        return 'read' === $cap ? self::DISCOVERY_CAP : $cap;
    }

    /**
     * mcp_adapter_tools_list: only the tools this user could call.
     *
     * @param mixed $tools  Tool DTOs.
     * @param mixed $server McpServer.
     * @return mixed
     */
    public static function filter_tools_list( $tools, $server = null ) {
        if ( ! is_array( $tools ) || ! is_object( $server ) || ! method_exists( $server, 'get_mcp_tool' ) ) {
            return $tools;
        }
        // A held connection may call nothing; Connection_Approval's filter leaves it the status tool.
        if ( Connection_Approval::is_held() ) {
            return $tools;
        }

        // Read-only mode is set aside: a write tool someone holds the capability
        // for stays listed and is refused when called, as it always was. That
        // way switching the mode off needs no reconnect, and the mode's own
        // refusal stays the message the AI sees.
        return Read_Only::aside( static function () use ( $tools, $server ) {
            $out = [];
            foreach ( $tools as $tool ) {
                $name = is_object( $tool ) && method_exists( $tool, 'getName' ) ? (string) $tool->getName() : ( is_array( $tool ) ? (string) ( $tool['name'] ?? '' ) : '' );
                $mcp  = '' !== $name ? $server->get_mcp_tool( $name ) : null;
                // No tool behind the entry: somebody's filter added it; not ours to judge.
                if ( null === $mcp || self::may_call( $mcp ) ) {
                    $out[] = $tool;
                }
            }
            return $out;
        } );
    }

    /**
     * Whether the current user could call a tool.
     *
     * @param \WP\MCP\Domain\Tools\McpTool $tool
     */
    private static function may_call( $tool ): bool {
        // Another copy of the adapter with another tool class: its own check at call time decides.
        if ( ! method_exists( $tool, 'check_permission' ) || ! method_exists( $tool, 'get_adapter_meta' ) ) {
            return true;
        }

        // The adapter's own two tools that need an argument to check at all.
        switch ( (string) ( $tool->get_adapter_meta()['ability'] ?? '' ) ) {
            case 'mcp-adapter/get-ability-info':
                return current_user_can( (string) apply_filters( 'mcp_adapter_get_ability_info_capability', 'read' ) );
            case 'mcp-adapter/execute-ability':
                return current_user_can( (string) apply_filters( 'mcp_adapter_execute_ability_capability', 'read' ) )
                    && self::can_run_some_ability();
        }

        $permission = $tool->check_permission( [] );
        if ( true === $permission ) {
            return true;
        }
        // Someone else's tool may answer with an error only because its check
        // wants the call's arguments. An administrator keeps seeing such a tool.
        return is_wp_error( $permission )
            && self::REFUSED_CODE !== $permission->get_error_code()
            && current_user_can( 'manage_options' );
    }

    /** Whether execute-ability could do anything for this user: some public ability lets them in. */
    private static function can_run_some_ability(): bool {
        if ( ! function_exists( 'wp_get_abilities' ) ) {
            return true;
        }
        foreach ( wp_get_abilities() as $ability ) {
            $meta = (array) $ability->get_meta();
            if ( empty( $meta['mcp']['public'] ) || 'tool' !== ( $meta['mcp']['type'] ?? 'tool' ) ) {
                continue;
            }
            try {
                if ( true === $ability->check_permissions( null ) ) {
                    return true;
                }
            } catch ( \Throwable $e ) {
                continue; // A check that cannot run without arguments says nothing.
            }
        }
        return false;
    }
}
