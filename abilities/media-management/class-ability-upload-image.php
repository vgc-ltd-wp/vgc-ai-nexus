<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Upload_Image_Ability
 *
 * Downloads an image from a URL and imports it into the WordPress Media Library.
 *
 * Security measures:
 *  - MIME type is verified AFTER download via wp_get_image_mime() — not trusted from URL/Content-Type.
 *  - SSRF prevention: the resolved IP of the host must not be in private or reserved ranges.
 *  - File size is capped at 10 MB.
 */
class Upload_Image_Ability extends Ability {

    /** Allowed image MIME types. */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    /** Maximum file size accepted (bytes). */
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    protected function define_meta(): void {
        $this->key          = 'upload_image';
        $this->label        = __( 'Upload Image', 'mcp-abilities' );
        $this->description  = 'Download an image from a URL and add it to the WordPress Media Library. Supports JPEG, PNG, GIF, WebP, and AVIF. Maximum 10 MB.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'url'         => [
                    'type'        => 'string',
                    'description' => 'Publicly accessible URL of the image to import.',
                ],
                'title'       => [
                    'type'        => 'string',
                    'description' => 'Attachment title. Defaults to the filename derived from the URL.',
                ],
                'alt_text'    => [
                    'type'        => 'string',
                    'description' => 'Alt text for the image (stored as _wp_attachment_image_alt).',
                ],
                'caption'     => [
                    'type'        => 'string',
                    'description' => 'Caption for the image.',
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => 'Long description for the image attachment.',
                ],
            ],
            'required' => [ 'url' ],
        ];
    }

    public function execute( array $params ): array {
        $url = esc_url_raw( trim( $params['url'] ?? '' ) );

        if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return $this->error( 'A valid URL is required.' );
        }

        // ── SSRF guard ────────────────────────────────────────────────────────
        $ssrf_error = $this->check_ssrf( $url );
        if ( null !== $ssrf_error ) {
            return $this->error( $ssrf_error );
        }

        // ── Download to temp file ─────────────────────────────────────────────
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 30 );

        if ( is_wp_error( $tmp ) ) {
            return $this->error( 'Failed to download image: ' . $tmp->get_error_message() );
        }

        // ── File size check ───────────────────────────────────────────────────
        if ( filesize( $tmp ) > self::MAX_BYTES ) {
            @unlink( $tmp );
            return $this->error( 'Image exceeds the 10 MB size limit.' );
        }

        // ── MIME verification (post-download, not Content-Type) ───────────────
        $mime = wp_get_image_mime( $tmp );

        if ( false === $mime || ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
            @unlink( $tmp );
            $allowed = implode( ', ', self::ALLOWED_MIMES );
            return $this->error(
                ( false === $mime )
                    ? "The downloaded file is not a recognised image."
                    : "MIME type \"{$mime}\" is not allowed. Accepted types: {$allowed}."
            );
        }

        // ── Build the file array for media_handle_sideload ────────────────────
        $filename = basename( parse_url( $url, PHP_URL_PATH ) ) ?: 'upload';
        // Ensure the filename carries the right extension for the verified MIME.
        $filename = $this->ensure_extension( $filename, $mime );

        $file_array = [
            'name'     => sanitize_file_name( $filename ),
            'tmp_name' => $tmp,
        ];

        // ── Attachment post data ──────────────────────────────────────────────
        $post_data = [];
        if ( ! empty( $params['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $params['title'] );
        }
        if ( ! empty( $params['caption'] ) ) {
            $post_data['post_excerpt'] = sanitize_text_field( $params['caption'] );
        }
        if ( ! empty( $params['description'] ) ) {
            $post_data['post_content'] = sanitize_textarea_field( $params['description'] );
        }

        $attachment_id = media_handle_sideload( $file_array, 0, null, $post_data );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return $this->error( 'Failed to import image: ' . $attachment_id->get_error_message() );
        }

        // ── Alt text ──────────────────────────────────────────────────────────
        if ( ! empty( $params['alt_text'] ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt_text'] ) );
        }

        return $this->json_result( [
            'id'  => $attachment_id,
            'url' => wp_get_attachment_url( $attachment_id ),
            'mime_type' => $mime,
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns a non-null string if the URL resolves to a private/reserved IP (SSRF risk).
     */
    private function check_ssrf( string $url ): ?string {
        $host = parse_url( $url, PHP_URL_HOST );

        if ( ! $host ) {
            return 'Could not parse host from URL.';
        }

        // Reject non-http(s) schemes.
        $scheme = strtolower( parse_url( $url, PHP_URL_SCHEME ) ?? '' );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return 'Only http and https URLs are supported.';
        }

        $ip = gethostbyname( $host );

        if ( $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            // gethostbyname() returns the original string on failure.
            return 'Could not resolve host: ' . $host;
        }

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return 'Requests to private or reserved IP ranges are not allowed.';
        }

        return null;
    }

    /**
     * Ensures $filename has an extension consistent with the verified $mime type.
     */
    private function ensure_extension( string $filename, string $mime ): string {
        $ext_map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ];

        $expected_ext = $ext_map[ $mime ] ?? '';
        if ( '' === $expected_ext ) {
            return $filename;
        }

        $current_ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        // For JPEG, accept both .jpg and .jpeg.
        $valid_exts = ( $mime === 'image/jpeg' ) ? [ 'jpg', 'jpeg' ] : [ $expected_ext ];

        if ( ! in_array( $current_ext, $valid_exts, true ) ) {
            // Strip existing extension if present and append the correct one.
            $base = '' !== $current_ext
                ? substr( $filename, 0, -( strlen( $current_ext ) + 1 ) )
                : $filename;
            return $base . '.' . $expected_ext;
        }

        return $filename;
    }
}
