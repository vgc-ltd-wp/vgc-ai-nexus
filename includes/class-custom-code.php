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

    // ── Raw code: who may store it (2.41.0) ───────────────────────────────────

    /**
     * Whether an administrator locked raw code away from AI tools: the
     * MCP_ABILITIES_DISABLE_CUSTOM_CODE constant, or false from the
     * 'mcp_abilities_custom_code_enabled' filter. The custom-code group uses this,
     * and so does every other tool that stores markup or CSS for the whole site.
     */
    public static function locked(): bool {
        $enabled = ! ( defined( 'MCP_ABILITIES_DISABLE_CUSTOM_CODE' ) && MCP_ABILITIES_DISABLE_CUSTOM_CODE );
        $enabled = (bool) apply_filters( 'mcp_abilities_custom_code_enabled', $enabled );
        return ! $enabled;
    }

    /** Whether this request may store raw HTML/script: not locked, and the account has unfiltered_html. */
    public static function raw_allowed(): bool {
        return ! self::locked() && current_user_can( 'unfiltered_html' );
    }

    /** Whether this request may store raw CSS: not locked, and the account has edit_css. */
    public static function css_allowed(): bool {
        return ! self::locked() && current_user_can( 'edit_css' );
    }

    /**
     * Block markup as it may be stored for the whole site (templates, template
     * parts, navigation): as sent when raw code is allowed, otherwise filtered the
     * way WordPress filters a post for an account without unfiltered_html, so no
     * script reaches every page while raw code is locked.
     */
    public static function filter_markup( string $content ): string {
        return self::raw_allowed() ? $content : wp_kses_post( $content );
    }

    /** The note a tool adds when filter_markup() changed what was sent. */
    public static function filtered_note(): string {
        return self::locked()
            ? 'Raw code is locked on this site (MCP_ABILITIES_DISABLE_CUSTOM_CODE or the mcp_abilities_custom_code_enabled filter), so script and other markup WordPress does not allow in posts was removed before saving.'
            : 'This account does not have the "unfiltered_html" capability, so script and other markup WordPress does not allow in posts was removed before saving.';
    }

    /**
     * Global-styles data with every "css" key removed (top-level custom CSS and
     * per-block CSS), unless raw CSS is allowed.
     *
     * @param mixed $styles
     * @return mixed
     */
    public static function strip_style_css( $styles, bool &$stripped = false ) {
        if ( ! is_array( $styles ) || self::css_allowed() ) {
            return $styles;
        }
        foreach ( $styles as $key => $value ) {
            if ( 'css' === $key ) {
                unset( $styles[ $key ] );
                $stripped = true;
            } elseif ( is_array( $value ) ) {
                $styles[ $key ] = self::strip_style_css( $value, $stripped );
            }
        }
        return $styles;
    }

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
