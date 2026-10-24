<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Media_Import;

defined( 'ABSPATH' ) || exit;

/**
 * Upload_Image_Ability
 *
 * Adds an image to the Media Library from a public URL or from inline base64,
 * optionally attaching it to a post and making it the featured image.
 *
 * Security measures:
 *  - MIME type is read from the file's bytes (Media_Import), never trusted from a URL, header or name.
 *  - SSRF prevention for URLs: the resolved IP of the host must not be in private or reserved ranges.
 *  - Size capped at 10 MB (3 MB for inline base64, which travels through the model's context).
 */
class Upload_Image_Ability extends Ability {

    /** Inline base64 passes through the AI's context window; keep it small. */
    private const MAX_INLINE_BYTES = 3 * 1024 * 1024;

    protected function define_meta(): void {
        $this->key          = 'upload_image';
        $this->label        = __( 'Upload Image', 'mcp-abilities' );
        $this->description  = 'Add an image to the WordPress Media Library — JPEG, PNG, GIF, WebP or AVIF, max 10 MB. Give EITHER "url" (a publicly reachable image, downloaded by the site) OR "data_base64" (the image bytes inline, max 3 MB — every byte passes through your context, so only for small images). For an image that is NOT on the web (a file you created or have in your workspace), prefer create_upload_link: it returns a one-time link you send the file to with curl, so the bytes never pass through the conversation. Optionally attach it to a post with "post_id" and make it that post\'s featured image with "set_featured". Returns the attachment id and URL.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'url'          => [ 'type' => 'string', 'description' => 'Publicly accessible URL of the image to import. Use this OR data_base64.' ],
                'data_base64'  => [ 'type' => 'string', 'description' => 'The image file itself, base64-encoded (a "data:image/...;base64," prefix is accepted). Max 3 MB decoded. Use this OR url.' ],
                'filename'     => [ 'type' => 'string', 'description' => 'File name to store, e.g. "red-fox-coloring-page.png". The extension is corrected to the real image type. Defaults to the URL\'s file name, or "image".' ],
                'title'        => [ 'type' => 'string', 'description' => 'Attachment title. Defaults to the file name.' ],
                'alt_text'     => [ 'type' => 'string', 'description' => 'Alt text (important for accessibility and image SEO).' ],
                'caption'      => [ 'type' => 'string', 'description' => 'Caption for the image.' ],
                'description'  => [ 'type' => 'string', 'description' => 'Long description for the image attachment.' ],
                'post_id'      => [ 'type' => 'integer', 'description' => 'Attach the image to this post/page/product (it appears in that item\'s media).' ],
                'set_featured' => [ 'type' => 'boolean', 'description' => 'With post_id: also make it the featured image. Default false.', 'default' => false ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $url  = trim( (string) ( $params['url'] ?? '' ) );
        $data = trim( (string) ( $params['data_base64'] ?? '' ) );

        if ( ( '' === $url ) === ( '' === $data ) ) {
            return $this->error( 'Give exactly one of "url" (a public image URL) or "data_base64" (the image bytes). For a file in your workspace, use create_upload_link instead.' );
        }

        if ( '' !== $data ) {
            $tmp      = $this->decode_inline( $data );
            $filename = (string) ( $params['filename'] ?? '' );
            if ( is_string( $tmp ) && ! is_file( $tmp ) ) {
                return $this->error( $tmp );
            }
        } else {
            $url = esc_url_raw( $url );
            if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                return $this->error( 'A valid URL is required.' );
            }
            $ssrf_error = $this->check_ssrf( $url );
            if ( null !== $ssrf_error ) {
                return $this->error( $ssrf_error );
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $tmp = download_url( $url, 30 );
            if ( is_wp_error( $tmp ) ) {
                return $this->error( 'Failed to download image: ' . $tmp->get_error_message() );
            }
            $filename = (string) ( $params['filename'] ?? '' ) ?: ( basename( (string) parse_url( $url, PHP_URL_PATH ) ) ?: 'image' );
        }

        $result = Media_Import::import( $tmp, $filename, $params );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }
        return $this->json_result( $result );
    }

    /**
     * Decode inline base64 into a temp file.
     *
     * @return string Temp file path on success, or an error message.
     */
    private function decode_inline( string $data ): string {
        if ( str_starts_with( $data, 'data:' ) ) {
            $comma = strpos( $data, ',' );
            $data  = false === $comma ? '' : substr( $data, $comma + 1 );
        }
        $data = preg_replace( '/\s+/', '', $data );
        if ( strlen( $data ) > (int) ceil( self::MAX_INLINE_BYTES * 4 / 3 ) + 4 ) {
            return 'Inline images are limited to 3 MB. Use create_upload_link to send a larger file without passing it through the conversation.';
        }
        $bytes = base64_decode( strtr( $data, '-_', '+/' ), true );
        if ( false === $bytes || '' === $bytes ) {
            return '"data_base64" is not valid base64.';
        }
        $tmp = Media_Import::temp_file_from_bytes( $bytes );
        return $tmp ?? 'Could not write a temporary file on the server.';
    }

    /**
     * Returns a non-null string if the URL resolves to a private/reserved IP (SSRF risk).
     */
    private function check_ssrf( string $url ): ?string {
        $host = parse_url( $url, PHP_URL_HOST );

        if ( ! $host ) {
            return 'Could not parse host from URL.';
        }

        $scheme = strtolower( parse_url( $url, PHP_URL_SCHEME ) ?? '' );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return 'Only http and https URLs are supported.';
        }

        $ip = gethostbyname( $host );

        if ( $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return 'Could not resolve host: ' . $host;
        }

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return 'Requests to private or reserved IP ranges are not allowed.';
        }

        return null;
    }
}
