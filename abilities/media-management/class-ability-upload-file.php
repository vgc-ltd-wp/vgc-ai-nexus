<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Upload_File_Ability
 *
 * Downloads a non-image file from a URL and imports it into the WordPress Media Library.
 * Images must be imported via upload_image instead.
 *
 * Security measures:
 *  - Strict MIME allowlist — no executables, scripts, archives, or images.
 *  - MIME is verified AFTER download via finfo / mime_content_type(), not from HTTP headers.
 *  - SSRF prevention: the resolved IP must not be in private or reserved ranges.
 *  - File size is capped at 50 MB.
 */
class Upload_File_Ability extends Ability {

    /**
     * Allowed MIME types and their canonical file extensions.
     * Excludes images (use upload_image), archives (.zip, .tar), scripts (.php, .js, .html).
     */
    private const ALLOWED_MIMES = [
        // Documents
        'application/pdf'                                                      => 'pdf',
        'text/plain'                                                           => 'txt',
        'text/csv'                                                             => 'csv',
        'application/msword'                                                   => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel'                                             => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'   => 'xlsx',
        'application/vnd.ms-powerpoint'                                        => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        // Audio
        'audio/mpeg'                                                           => 'mp3',
        'audio/wav'                                                            => 'wav',
        'audio/ogg'                                                            => 'ogg',
        'audio/mp4'                                                            => 'm4a',
        // Video
        'video/mp4'                                                            => 'mp4',
        'video/webm'                                                           => 'webm',
        'video/quicktime'                                                      => 'mov',
    ];

    /** Maximum file size accepted (bytes). */
    private const MAX_BYTES = 50 * 1024 * 1024; // 50 MB

    protected function define_meta(): void {
        $this->key          = 'upload_file';
        $this->label        = __( 'Upload File', 'mcp-abilities' );
        $this->description  = 'Download a document, audio, or video file from a URL and add it to the WordPress Media Library. Supported types: PDF, TXT, CSV, DOC/DOCX, XLS/XLSX, PPT/PPTX, MP3, WAV, OGG, M4A, MP4, WebM, MOV. Maximum 50 MB. Use upload_image for images.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'url'      => [
                    'type'        => 'string',
                    'description' => 'Publicly accessible URL of the file to import.',
                ],
                'title'    => [
                    'type'        => 'string',
                    'description' => 'Attachment title shown in the Media Library. Defaults to the filename.',
                ],
                'filename' => [
                    'type'        => 'string',
                    'description' => 'Override the stored filename (e.g. "report-2024.pdf"). Must include the correct extension.',
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

        $tmp = download_url( $url, 60 );

        if ( is_wp_error( $tmp ) ) {
            return $this->error( 'Failed to download file: ' . $tmp->get_error_message() );
        }

        // ── File size check ───────────────────────────────────────────────────
        if ( filesize( $tmp ) > self::MAX_BYTES ) {
            @unlink( $tmp );
            return $this->error( 'File exceeds the 50 MB size limit.' );
        }

        // ── MIME verification (post-download) ─────────────────────────────────
        $mime = $this->detect_mime( $tmp );

        if ( null === $mime || ! array_key_exists( $mime, self::ALLOWED_MIMES ) ) {
            @unlink( $tmp );
            $allowed = implode( ', ', array_values( self::ALLOWED_MIMES ) );
            return $this->error(
                ( null === $mime )
                    ? 'Could not determine the file type of the downloaded content.'
                    : "File type \"{$mime}\" is not allowed. Accepted extensions: {$allowed}."
            );
        }

        // ── Determine filename ────────────────────────────────────────────────
        if ( ! empty( $params['filename'] ) ) {
            $filename = sanitize_file_name( $params['filename'] );
        } else {
            $filename = basename( parse_url( $url, PHP_URL_PATH ) ) ?: 'upload';
            $filename = sanitize_file_name( $filename );
        }

        // Enforce correct extension for the verified MIME.
        $filename = $this->enforce_extension( $filename, $mime );

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];

        // ── Attachment post data ──────────────────────────────────────────────
        $post_data = [];
        if ( ! empty( $params['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $params['title'] );
        }

        $attachment_id = media_handle_sideload( $file_array, 0, null, $post_data );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return $this->error( 'Failed to import file: ' . $attachment_id->get_error_message() );
        }

        return $this->json_result( [
            'id'        => $attachment_id,
            'url'       => wp_get_attachment_url( $attachment_id ),
            'filename'  => $filename,
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

    /**
     * Detect the real MIME type of a downloaded file using PHP's finfo extension,
     * with a fallback to mime_content_type().
     */
    private function detect_mime( string $path ): ?string {
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            $mime  = $finfo ? finfo_file( $finfo, $path ) : false;
            if ( $finfo ) {
                finfo_close( $finfo );
            }
            if ( $mime && '' !== $mime ) {
                return $mime;
            }
        }

        if ( function_exists( 'mime_content_type' ) ) {
            $mime = mime_content_type( $path );
            return ( $mime && '' !== $mime ) ? $mime : null;
        }

        // Last resort: WordPress's own type checker (extension-based, less reliable).
        $wp_type = wp_check_filetype( $path );
        return $wp_type['type'] ?: null;
    }

    /**
     * Ensure the filename's extension matches the verified MIME type.
     * Replaces a wrong/missing extension with the canonical one.
     */
    private function enforce_extension( string $filename, string $mime ): string {
        $expected = self::ALLOWED_MIMES[ $mime ] ?? '';
        if ( '' === $expected ) {
            return $filename;
        }

        $current = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        // Some MIMEs map to multiple valid extensions (e.g. audio/wav → wav/wave).
        // Keep the current extension if it's a known alias for the MIME.
        $aliases = $this->get_extension_aliases( $mime );
        if ( in_array( $current, $aliases, true ) ) {
            return $filename;
        }

        $base = '' !== $current
            ? substr( $filename, 0, -( strlen( $current ) + 1 ) )
            : $filename;

        return $base . '.' . $expected;
    }

    /** Returns all valid extensions for a MIME type (canonical + known aliases). */
    private function get_extension_aliases( string $mime ): array {
        $canonical = self::ALLOWED_MIMES[ $mime ] ?? '';
        $aliases   = [
            'audio/wav' => [ 'wav', 'wave' ],
            'audio/ogg' => [ 'ogg', 'oga' ],
            'video/mp4' => [ 'mp4', 'm4v' ],
        ];

        return $aliases[ $mime ] ?? ( $canonical ? [ $canonical ] : [] );
    }
}
