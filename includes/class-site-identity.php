<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Tells MCP clients which site they are connected to: its name, address and
 * icon, sent in the initialize response's serverInfo (MCP 2025-11-25:
 * title, websiteUrl, icons).
 *
 * Without it a client has to guess the icon. claude.ai shows a sub-domain's
 * connector (tasker.example.com) with the MAIN domain's favicon, so every site
 * on one domain looks the same. Each WordPress site sends its own Site Icon
 * (Appearance → Customize → Site Identity) instead, so a sub-domain shows its
 * own icon once it has one.
 */
final class Site_Identity {

    /** Sizes offered to the client; WordPress crops the Site Icon to these. */
    private const SIZES = [ 32, 192, 512 ];

    public static function init(): void {
        add_filter( 'mcp_adapter_initialize_response', [ self::class, 'add_to_initialize' ], 20, 1 );
    }

    /**
     * The Site Icon at each size, as MCP Icon objects. Empty when the site has
     * none (the client then falls back to its own guess).
     *
     * @return array<int, array{src:string, mimeType?:string, sizes:string[]}>
     */
    public static function icons(): array {
        $icons = [];
        $id    = (int) get_option( 'site_icon' );
        if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
            /** Filters the icons sent to MCP clients (none: no Site Icon set). */
            return (array) apply_filters( 'mcp_abilities_server_icons', $icons );
        }
        $mime = (string) get_post_mime_type( $id );
        $seen  = [];
        $meta  = (array) wp_get_attachment_metadata( $id );
        $files = [];
        foreach ( (array) ( $meta['sizes'] ?? [] ) as $s ) {
            if ( ! empty( $s['file'] ) ) {
                $files[ (string) $s['file'] ] = [ (int) ( $s['width'] ?? 0 ), (int) ( $s['height'] ?? 0 ) ];
            }
        }
        foreach ( self::SIZES as $size ) {
            // The nearest generated crop; label it with its REAL size, which
            // differs when the icon was not cropped through the Customizer.
            // (Core reports the requested size, not the file's, so the file's
            // own size is read from the attachment metadata.)
            $crop = image_get_intermediate_size( $id, [ $size, $size ] );
            if ( $crop && ! empty( $crop['url'] ) ) {
                $dims = $files[ (string) ( $crop['file'] ?? '' ) ] ?? [ 0, 0 ];
                $img  = [ (string) $crop['url'], $dims[0], $dims[1] ];
            } else {
                $img = [ (string) wp_get_attachment_url( $id ), (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) ];
            }
            if ( '' === $img[0] || isset( $seen[ $img[0] ] ) ) {
                continue;
            }
            $seen[ $img[0] ] = true;
            $icon            = [ 'src' => $img[0] ];
            if ( $img[1] > 0 && $img[2] > 0 ) {
                $icon['sizes'] = [ $img[1] . 'x' . $img[2] ];
            }
            if ( '' !== $mime ) {
                $icon['mimeType'] = $mime;
            }
            $icons[] = $icon;
        }
        return (array) apply_filters( 'mcp_abilities_server_icons', $icons );
    }

    /** The site's name as clients should show it. */
    public static function title(): string {
        $name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
        return '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
    }

    /**
     * Add title, websiteUrl and icons to serverInfo.
     *
     * @param mixed $result InitializeResult DTO.
     * @return mixed
     */
    public static function add_to_initialize( $result ) {
        if ( ! is_object( $result ) || ! method_exists( $result, 'toArray' ) ) {
            return $result;
        }
        try {
            $data = $result->toArray();
            if ( ! isset( $data['serverInfo'] ) || ! is_array( $data['serverInfo'] ) ) {
                return $result;
            }
            $data['serverInfo']['title']      = self::title();
            $data['serverInfo']['websiteUrl'] = home_url( '/' );
            $icons                            = self::icons();
            if ( $icons ) {
                $data['serverInfo']['icons'] = $icons;
            }
            $class = get_class( $result );
            if ( method_exists( $class, 'fromArray' ) ) {
                return $class::fromArray( $data );
            }
        } catch ( \Throwable $e ) {
            // Never let the site's identity break the handshake.
        }
        return $result;
    }
}
