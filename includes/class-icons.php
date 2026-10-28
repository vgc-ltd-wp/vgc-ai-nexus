<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * AI Nexus's own icons: the core mark and one per VGC extension.
 *
 * The core draws every extension's icon by its extension ID or plugin slug,
 * so extensions that still name a dashicon get their icon without a release
 * of their own. Anything unknown (a third-party extension) keeps its dashicon.
 *
 * Drawn on a 24×24 grid with a 2 px round stroke in currentColor, the same
 * style as the VGC Toolbox icons. Each extension icon carries the family
 * badge: a small purple diamond in the top-right corner.
 */
final class Icons {

    /** Badge colour (and the core mark's hub). */
    public const ACCENT = '#7F77DD';

    /** 24×24 stroke paths. */
    private const PATHS = [
        'nexus'     => 'M12 5.2L18.8 12L12 18.8L5.2 12z',
        'analytics' => 'M3 21h17 M6 17v-4 M11 17V9 M16 17v-6 M5 9l5-4l4 3l3-3',
        'cpt'       => 'M7 7h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z M8 3h7 M9 12h6 M9 16h4',
        'woo'       => 'M4.5 8h13l-1 12.5H5.5z M8 11V7a3.5 3.5 0 0 1 6-2.4',
        'wpml'      => 'M11 4.5a8.5 8.5 0 1 0 0 17a8.5 8.5 0 1 0 0-17z M2.5 13h17 M11 4.5c-2.6 2.4-3.6 5.3-3.6 8.5s1 6.1 3.6 8.5 M11 4.5c2.6 2.4 3.6 5.3 3.6 8.5s-1 6.1-3.6 8.5',
        'elementor' => 'M3 5h11 M3 5v15h17v-8 M3 10h17 M10 10v10',
        'avada'     => 'M3 4h12v5H3z M15 6.5h2.5v5H9v3 M8 14.5h2v6.5H8z',
        'pin'       => 'M8 3h6 M9.5 3l-.8 6L5.5 12.5h11L13.3 9l-.8-6 M11 12.5V21',
        'tracker'   => 'M4 4h10 M4 4v16h16v-8 M9.3 4v16 M14.7 9v11 M6 8h1.5 M11.3 8h1.5 M11.3 12h1.5 M16.7 13h1.5',
    ];

    /** Extension ID (mcp_abilities_extensions) or plugin slug → icon key. */
    private const MAP = [
        'analytics'                => 'analytics',
        'vgc-ai-nexus-analytics'   => 'analytics',
        'custom-post-types'        => 'cpt',
        'vgc-ai-nexus-cpt'         => 'cpt',
        'woocommerce'              => 'woo',
        'vgc-ai-nexus-woocommerce' => 'woo',
        'wpml'                     => 'wpml',
        'vgc-ai-nexus-wpml'        => 'wpml',
        'elementor'                => 'elementor',
        'vgc-ai-nexus-elementor'   => 'elementor',
        'avada'                    => 'avada',
        'vgc-ai-nexus-avada'       => 'avada',
        'pinterest'                => 'pin',
        'vgc-ai-nexus-pinterest'   => 'pin',
        'vgc-tracker'              => 'tracker',
        'vgc-ai-nexus-tracker'     => 'tracker',
        'vgc-ai-nexus'             => 'nexus',
    ];

    /** The icon key for an extension ID or plugin slug, or '' when there is none. */
    public static function key_for( string $id ): string {
        return self::MAP[ $id ] ?? '';
    }

    /** Every plugin slug that has an icon (core and extensions). */
    public static function plugin_slugs(): array {
        return array_values( array_filter( array_keys( self::MAP ), static fn( $k ) => str_starts_with( $k, 'vgc-ai-nexus' ) ) );
    }

    /**
     * The inner SVG elements of an icon (no <svg> wrapper).
     *
     * @param string $key    Icon key.
     * @param string $stroke Stroke colour.
     * @param string $knock  Colour the badge is cut out with (the background).
     */
    private static function body( string $key, string $stroke = 'currentColor', string $knock = '#fff' ): string {
        $path = '<path d="' . esc_attr( self::PATHS[ $key ] ) . '" fill="none" stroke="' . esc_attr( $stroke ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        if ( 'nexus' === $key ) {
            $out = $path;
            foreach ( [ [ 12, 3.6 ], [ 20.4, 12 ], [ 12, 20.4 ], [ 3.6, 12 ] ] as $p ) {
                $out .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="1.6" fill="none" stroke="' . esc_attr( $stroke ) . '" stroke-width="2"/>';
            }
            return $out . '<circle cx="12" cy="12" r="2.3" fill="' . self::ACCENT . '"/>';
        }
        return $path . '<path d="M19 1.5l3.5 3.5L19 8.5L15.5 5z" fill="' . self::ACCENT . '" style="stroke:' . esc_attr( $knock ) . '" stroke-width="1.6" stroke-linejoin="round"/>';
    }

    /**
     * Inline SVG for an icon key (decorative: hidden from screen readers).
     * The badge's cut-out uses the card background via CSS (.mcp-icon__badge).
     */
    public static function svg( string $key, int $size = 28, string $class = '' ): string {
        if ( ! isset( self::PATHS[ $key ] ) ) {
            return '';
        }
        return '<svg class="mcp-icon ' . esc_attr( $class ) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . self::body( $key, 'currentColor', 'var(--mcp-surface, #fff)' )
            . '</svg>';
    }

    /**
     * The icon for an extension: ours when we have one, else the extension's
     * own dashicon (a third-party extension).
     *
     * @param string $id       Extension ID or plugin slug.
     * @param string $dashicon The extension's declared dashicons class.
     * @param string $class    Extra class (the card / header icon class).
     * @param int    $size     Pixel size.
     */
    public static function render( string $id, string $dashicon, string $class, int $size = 28 ): string {
        $key = self::key_for( $id );
        if ( '' !== $key ) {
            return self::svg( $key, $size, $class );
        }
        $dashicon = '' !== $dashicon ? $dashicon : 'dashicons-admin-plugins';
        return '<span class="' . esc_attr( $class ) . ' dashicons ' . esc_attr( $dashicon ) . '"></span>';
    }

    /**
     * A standalone SVG file for an icon (used as an <img> on the WordPress
     * Updates screen): a rounded tile, dark strokes, the purple badge.
     */
    public static function file_svg( string $key ): string {
        if ( ! isset( self::PATHS[ $key ] ) ) {
            return '';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">'
            . '<rect width="128" height="128" rx="24" fill="#EEEDFE"/>'
            . '<g transform="translate(24 24) scale(3.333)">' . self::body( $key, '#3C3489', '#EEEDFE' ) . '</g>'
            . '</svg>';
    }

    /** The page-header logo: the nexus mark on a dark tile. */
    public static function logo( int $size = 32 ): string {
        return '<svg class="mcp-logo" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
            . '<rect width="32" height="32" rx="8" fill="#1a1a2e"/>'
            . '<g transform="translate(4 4)">' . self::body( 'nexus', '#ffffff' ) . '</g>'
            . '</svg>';
    }

    /**
     * The admin menu icon: the nexus mark, filled in black so WordPress
     * repaints it in the admin colour scheme (it repaints fills, not strokes).
     */
    public static function menu_icon(): string {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
             . '<path fill="black" fill-rule="evenodd" d="M10 2.6L17.4 10L10 17.4L2.6 10z M10 6.1L13.9 10L10 13.9L6.1 10z"/>'
             . '<circle fill="black" cx="10" cy="2.4" r="2.1"/><circle fill="black" cx="17.6" cy="10" r="2.1"/>'
             . '<circle fill="black" cx="10" cy="17.6" r="2.1"/><circle fill="black" cx="2.4" cy="10" r="2.1"/>'
             . '<circle fill="black" cx="10" cy="10" r="2"/>'
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Give AI Nexus and its extensions their icons on the WordPress Updates
     * screen. Runs when the update data is read, so every VGC updater copy
     * (core's and the one each extension bundles) benefits.
     *
     * @param mixed $transient update_plugins site transient.
     * @return mixed
     */
    public static function add_update_icons( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        foreach ( [ 'response', 'no_update' ] as $list ) {
            if ( empty( $transient->$list ) || ! is_array( $transient->$list ) ) {
                continue;
            }
            foreach ( $transient->$list as $basename => $item ) {
                // Only AI Nexus's own plugins: extension IDs such as "woocommerce"
                // or "elementor" are also the folders of those plugins.
                $folder = (string) strtok( (string) $basename, '/' );
                $key    = str_starts_with( $folder, 'vgc-ai-nexus' ) ? self::key_for( $folder ) : '';
                if ( '' === $key || ! is_object( $item ) ) {
                    continue;
                }
                $item->icons = [ 'svg' => MCP_ABILITIES_URL . 'assets/icons/' . $key . '.svg' ];
            }
        }
        return $transient;
    }
}
