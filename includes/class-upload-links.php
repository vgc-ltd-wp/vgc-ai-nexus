<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * One-time upload links for the Media Library.
 *
 * An AI caller with a workspace (Claude Code, scheduled routines) asks for a
 * link through the create_upload_link ability, then POSTs the image to it with
 * curl. The image bytes never pass through the model's context, which inline
 * base64 would (and which makes anything but tiny images impractical).
 *
 * The link IS the credential: 256 random bits, stored only as a SHA-256 hash,
 * valid for 15 minutes and for ONE request, and bound to the WordPress user
 * who asked for it — the upload runs with that user's capabilities. Stored in
 * wp_options, not transients: on sites with a persistent object cache
 * transients live only in the cache and can vanish before they expire.
 */
final class Upload_Links {

    public const PREFIX    = 'mcp_abilities_upl_';
    public const TTL       = 15 * MINUTE_IN_SECONDS;
    public const NAMESPACE = 'mcp-abilities/v1';

    /** @return array{url:string, expires_at:string} */
    public static function create( int $user_id, array $meta ): array {
        $token = bin2hex( random_bytes( 32 ) );
        add_option( self::PREFIX . hash( 'sha256', $token ), [
            'user_id' => $user_id,
            'meta'    => $meta,
            'expires' => time() + self::TTL,
        ], '', false );
        self::cleanup();
        return [
            'url'        => rest_url( self::NAMESPACE . '/upload/' . $token ),
            'expires_at' => gmdate( 'c', time() + self::TTL ),
        ];
    }

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/upload/(?P<token>[a-f0-9]{64})', [
            'methods'             => [ 'POST', 'PUT' ],
            'callback'            => [ self::class, 'handle' ],
            'permission_callback' => '__return_true', // the single-use token in the URL is the credential
        ] );
    }

    public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $key    = self::PREFIX . hash( 'sha256', (string) $request['token'] );
        $record = get_option( $key, null );

        if ( ! is_array( $record ) || (int) $record['expires'] < time() ) {
            delete_option( $key );
            return self::reply( 410, 'This upload link is invalid, already used or expired (links last 15 minutes and work once). Ask for a new one with create_upload_link.' );
        }
        delete_option( $key ); // single use, even if the upload below fails

        if ( Read_Only::active() ) {
            return self::reply( 403, Read_Only::error_message( 'create_upload_link' ) );
        }

        wp_set_current_user( (int) $record['user_id'] );
        if ( ! current_user_can( 'upload_files' ) ) {
            return self::reply( 403, 'The user who created this link can no longer upload files.' );
        }

        $meta     = (array) $record['meta'];
        $filename = (string) ( $meta['filename'] ?? '' );
        $files    = $request->get_file_params();

        if ( ! empty( $files['file']['tmp_name'] ) && UPLOAD_ERR_OK === (int) ( $files['file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
            // multipart/form-data:  curl -F "file=@photo.png" <url>
            if ( (int) $files['file']['size'] > Media_Import::MAX_BYTES ) {
                return self::reply( 413, 'The image is larger than 10 MB.' );
            }
            $tmp = Media_Import::temp_file_from_bytes( (string) file_get_contents( $files['file']['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            if ( '' === $filename ) {
                $filename = sanitize_file_name( (string) $files['file']['name'] );
            }
        } else {
            // Raw body:  curl --data-binary "@photo.png" -H "Content-Type: image/png" <url>
            $body = $request->get_body();
            if ( '' === $body ) {
                return self::reply( 400, 'No file received. Send the image as the request body (curl --data-binary @file) or as the multipart field "file" (curl -F file=@file).' );
            }
            if ( strlen( $body ) > Media_Import::MAX_BYTES ) {
                return self::reply( 413, 'The image is larger than 10 MB.' );
            }
            $tmp = Media_Import::temp_file_from_bytes( $body );
        }

        if ( null === $tmp ) {
            return self::reply( 500, 'Could not write a temporary file on the server.' );
        }

        $result = Media_Import::import( $tmp, $filename, $meta );
        if ( is_wp_error( $result ) ) {
            return self::reply( 400, $result->get_error_message() );
        }

        Logger::log( 'create_upload_link', $result );
        return new \WP_REST_Response( [ 'success' => true ] + $result, 201 );
    }

    /** Remove expired links (oldest first). */
    public static function cleanup( int $limit = 50 ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
            $wpdb->esc_like( self::PREFIX ) . '%',
            $limit
        ) );
        foreach ( (array) $rows as $row ) {
            $data = maybe_unserialize( $row->option_value );
            if ( ! is_array( $data ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
                delete_option( $row->option_name );
            }
        }
    }

    private static function reply( int $status, string $message ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => false, 'error' => $message ], $status );
    }
}
