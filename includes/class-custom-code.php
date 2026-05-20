<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Handles persistent storage and frontend output of custom CSS/JS injected
 * via the Custom Code ability group.
 *
 * CSS  — stored via WordPress's native Additional CSS (custom_css CPT), so it
 *         also appears in Customizer → Additional CSS.
 * JS   — stored in two WP options (head / footer) and output via wp_head / wp_footer.
 */
class Custom_Code {

    const JS_HEAD_OPTION   = 'mcp_custom_js_head';
    const JS_FOOTER_OPTION = 'mcp_custom_js_footer';

    /**
     * Register frontend output hooks. Called once from Plugin::load_dependencies().
     */
    public static function init(): void {
        add_action( 'wp_head',   [ __CLASS__, 'output_js_head' ],   999 );
        add_action( 'wp_footer', [ __CLASS__, 'output_js_footer' ], 999 );
    }

    // ── CSS (WordPress Additional CSS) ─────────────────────────────────────────

    public static function get_css(): string {
        return (string) wp_get_custom_css();
    }

    /**
     * @param string $css     The CSS to write.
     * @param string $mode    'append' or 'replace'.
     * @return true|\WP_Error
     */
    public static function set_css( string $css, string $mode = 'append' ) {
        if ( 'append' === $mode ) {
            $existing = self::get_css();
            $css      = '' !== trim( $existing )
                ? $existing . "\n\n/* === MCP injection === */\n" . $css
                : $css;
        }

        return wp_update_custom_css_post( $css );
    }

    // ── JS ─────────────────────────────────────────────────────────────────────

    public static function get_js( string $placement ): string {
        $option = ( 'header' === $placement ) ? self::JS_HEAD_OPTION : self::JS_FOOTER_OPTION;
        return (string) get_option( $option, '' );
    }

    /**
     * @param string $js        The JS to write.
     * @param string $placement 'header' or 'footer'.
     * @param string $mode      'append' or 'replace'.
     */
    public static function set_js( string $js, string $placement = 'footer', string $mode = 'append' ): void {
        $option   = ( 'header' === $placement ) ? self::JS_HEAD_OPTION : self::JS_FOOTER_OPTION;
        $existing = (string) get_option( $option, '' );

        if ( 'append' === $mode && '' !== trim( $existing ) ) {
            $js = $existing . "\n\n/* === MCP injection === */\n" . $js;
        }

        update_option( $option, $js, false ); // false = do not autoload
    }

    // ── Frontend output ────────────────────────────────────────────────────────

    public static function output_js_head(): void {
        $js = self::get_js( 'header' );
        if ( '' !== trim( $js ) ) {
            echo '<script id="mcp-custom-js-head">' . "\n" . $js . "\n" . '</script>' . "\n";
        }
    }

    public static function output_js_footer(): void {
        $js = self::get_js( 'footer' );
        if ( '' !== trim( $js ) ) {
            echo '<script id="mcp-custom-js-footer">' . "\n" . $js . "\n" . '</script>' . "\n";
        }
    }
}
