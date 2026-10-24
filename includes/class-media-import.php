<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an image file on disk into a Media Library attachment.
 *
 * Shared by every way an image can arrive — downloaded from a URL, sent inline
 * as base64, or posted to a one-time upload link — so validation is identical:
 * the MIME type is read from the file's own bytes (never trusted from a URL,
 * header or filename), the size is capped, and the extension is forced to match
 * the detected type.
 */
final class Media_Import {

    public const ALLOWED_MIMES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ];
    public const MAX_BYTES     = 10 * 1024 * 1024; // 10 MB

    private const EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /**
     * Import a temporary file. The file is consumed (moved or deleted) either way.
     *
     * @param string $tmp      Path to a temporary file.
     * @param string $filename Wanted name; the extension is corrected to the real type.
     * @param array  $meta {
     *   @type string $title, $alt_text, $caption, $description
     *   @type int    $post_id       Attach to this post (0 = unattached).
     *   @type bool   $set_featured  Also make it the post's featured image.
     * }
     * @return array|\WP_Error Attachment summary.
     */
    public static function import( string $tmp, string $filename, array $meta ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $fail = static function ( string $code, string $message ) use ( $tmp ) {
            if ( is_file( $tmp ) ) {
                @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            return new \WP_Error( $code, $message );
        };

        $size = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
        if ( $size <= 0 ) {
            return $fail( 'empty', 'The image is empty.' );
        }
        if ( $size > self::MAX_BYTES ) {
            return $fail( 'too_large', sprintf( 'The image is %.1f MB; the limit is 10 MB.', $size / 1048576 ) );
        }

        $mime = wp_get_image_mime( $tmp );
        if ( false === $mime || ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
            return $fail( 'bad_type', false === $mime
                ? 'The file is not a recognised image. Accepted: JPEG, PNG, GIF, WebP, AVIF.'
                : sprintf( 'Image type "%s" is not allowed. Accepted: JPEG, PNG, GIF, WebP, AVIF.', $mime ) );
        }

        $post_id = (int) ( $meta['post_id'] ?? 0 );
        if ( $post_id > 0 ) {
            if ( ! get_post( $post_id ) ) {
                return $fail( 'no_post', "Post {$post_id} does not exist." );
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return $fail( 'forbidden', "You are not allowed to edit post {$post_id}, so the image cannot be attached to it." );
            }
        }

        $post_data = [];
        if ( ! empty( $meta['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $meta['title'] );
        }
        if ( ! empty( $meta['caption'] ) ) {
            $post_data['post_excerpt'] = sanitize_text_field( $meta['caption'] );
        }
        if ( ! empty( $meta['description'] ) ) {
            $post_data['post_content'] = sanitize_textarea_field( $meta['description'] );
        }

        $file_array = [
            'name'     => sanitize_file_name( self::with_extension( $filename, $mime ) ),
            'tmp_name' => $tmp,
        ];
        $id = media_handle_sideload( $file_array, $post_id, null, $post_data );
        if ( is_wp_error( $id ) ) {
            return $fail( 'import_failed', 'Could not add the image to the Media Library: ' . $id->get_error_message() );
        }

        if ( ! empty( $meta['alt_text'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $meta['alt_text'] ) );
        }

        $featured = false;
        if ( $post_id > 0 && ! empty( $meta['set_featured'] ) ) {
            $featured = (bool) set_post_thumbnail( $post_id, $id );
        }

        $src = wp_get_attachment_image_src( $id, 'full' );
        return [
            'id'          => (int) $id,
            'url'         => wp_get_attachment_url( $id ),
            'mime_type'   => $mime,
            'width'       => $src ? (int) $src[1] : null,
            'height'      => $src ? (int) $src[2] : null,
            'filesize'    => $size,
            'attached_to' => $post_id ?: null,
            'featured'    => $featured,
            'edit_link'   => get_edit_post_link( $id, 'raw' ),
        ];
    }

    /** Write bytes to a fresh temporary file; null on failure. */
    public static function temp_file_from_bytes( string $bytes ): ?string {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam( 'mcp-upload' );
        if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
            return null;
        }
        return $tmp;
    }

    /** Force the extension to match the detected type ("photo" + PNG → "photo.png"). */
    public static function with_extension( string $filename, string $mime ): string {
        $filename = '' !== trim( $filename ) ? basename( $filename ) : 'image';
        $want     = self::EXT[ $mime ] ?? '';
        if ( '' === $want ) {
            return $filename;
        }
        $have  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        $valid = 'image/jpeg' === $mime ? [ 'jpg', 'jpeg' ] : [ $want ];
        if ( in_array( $have, $valid, true ) ) {
            return $filename;
        }
        $base = '' !== $have ? substr( $filename, 0, -( strlen( $have ) + 1 ) ) : $filename;
        return ( '' !== $base ? $base : 'image' ) . '.' . $want;
    }
}
