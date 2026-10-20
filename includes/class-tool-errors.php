<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the text of every AI Nexus tool error, whichever MCP adapter serves.
 *
 * AI Nexus abilities — core's and every extension's — report a failure as
 * {success:false, error:"..."}. Our ToolsHandler turns that into a tool error
 * with that text. Older ones read $result['error']['message'] instead and
 * answer "An error occurred while executing the tool." (0.1.0, the copy
 * WooCommerce 10.9 bundles and the standalone MCP Adapter plugin ships). Every
 * version passes on a WP_Error's message, so while another copy's ToolsHandler
 * serves, the error arrays go out as WP_Error.
 *
 * It is done once, for all of them, at registration: the execute callback of
 * every AI Nexus ability is wrapped (wp_register_ability_args, WordPress 6.9+;
 * the wp_ability_execute_result filter only exists from 7.1). An AI Nexus
 * ability is one whose execute callback is a closure over an
 * MCP_Abilities\Ability. Name prefixes cannot tell: WooCommerce registers
 * woocommerce/... abilities of its own.
 *
 * This only covers calls that go through WP_Ability::execute(). An adapter
 * without McpTool::fromArray() (0.1.0) calls every tool that way, because
 * AI Nexus then hands it ability names.
 */
final class Tool_Errors {

    public static function init(): void {
        add_filter( 'wp_register_ability_args', [ self::class, 'wrap_execute_callback' ], 10, 2 );
    }

    /**
     * @param mixed  $args Ability arguments.
     * @param string $name Ability name.
     * @return mixed
     */
    public static function wrap_execute_callback( $args, $name = '' ) {
        if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! self::is_ai_nexus_callback( $args['execute_callback'] ) ) {
            return $args;
        }
        $callback = $args['execute_callback'];
        $args['execute_callback'] = static function ( ...$input ) use ( $callback ) {
            return self::for_adapter( $callback( ...$input ) );
        };
        return $args;
    }

    /**
     * Hand a tool result to the serving adapter in a form it can read.
     *
     * Not while our own ToolsHandler serves: it reads the array, and it would
     * write every WP_Error to the PHP error log.
     *
     * Not inside the adapter's execute-ability tool either: it returns an
     * ability's array as data, which every version shows in full, while a
     * WP_Error it turns into its own {success:false, error} — which the old
     * ToolsHandler reduces to the generic message again.
     *
     * @param mixed $result
     * @return mixed
     */
    public static function for_adapter( $result ) {
        if ( is_array( $result )
            && isset( $result['success'], $result['error'] )
            && false === $result['success']
            && is_string( $result['error'] )
            && '' !== trim( $result['error'] )
            && Adapter_Status::tool_errors_need_wp_error()
            && ! self::inside_execute_ability() ) {
            return new \WP_Error( 'mcp_abilities_tool_error', $result['error'], [ 'status' => 400 ] );
        }
        return $result;
    }

    /** @param mixed $callback */
    private static function is_ai_nexus_callback( $callback ): bool {
        if ( ! $callback instanceof \Closure ) {
            return false;
        }
        // A closure's static variables include the ones it captured (use, or
        // what an arrow function reads from its scope).
        foreach ( ( new \ReflectionFunction( $callback ) )->getStaticVariables() as $value ) {
            if ( $value instanceof Ability ) {
                return true;
            }
        }
        return false;
    }

    private static function inside_execute_ability(): bool {
        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ) as $frame ) {
            if ( isset( $frame['class'] ) && str_ends_with( $frame['class'], '\\ExecuteAbilityAbility' ) ) {
                return true;
            }
        }
        return false;
    }
}
