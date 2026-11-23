<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Query_Support;

defined( 'ABSPATH' ) || exit;

class List_Media_Ability extends Ability {

    /** The fields "fields" may name (2.47.0). */
    public const ITEM_FIELDS = [ 'id', 'title', 'filename', 'url', 'mime_type', 'alt', 'date', 'width', 'height', 'filesize' ];

    protected function define_meta(): void {
        $this->key          = 'list_media';
        $this->label        = __( 'List Media', 'mcp-abilities' );
        $this->description  = 'List media library items with optional type filter.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'mime_type' => [ 'type' => 'string', 'description' => 'Filter by mime type prefix, e.g. image, video, audio, application/pdf.' ],
                'per_page'  => [ 'type' => 'integer', 'default' => 20, 'description' => 'Items per page (1-100). The result has total and has_more.' ],
                'page'      => [ 'type' => 'integer', 'default' => 1 ],
                'search'    => [ 'type' => 'string' ],
                'fields'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Only these fields of each item (id is always kept), e.g. ["id", "url", "alt"]: a smaller answer. Fields: ' . implode( ', ', self::ITEM_FIELDS ) . '.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        // per_page and page are echoed (2.52.0): the cap at 100 was not.
        $per_page = isset( $params['per_page'] ) && (int) $params['per_page'] > 0 ? min( (int) $params['per_page'], 100 ) : 20;
        $page     = max( 1, (int) ( $params['page'] ?? 1 ) );
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ( ! empty( $params['mime_type'] ) ) {
            $args['post_mime_type'] = sanitize_text_field( $params['mime_type'] );
        }
        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }

        // "fields" (2.47.0): only the fields named; costly values only when kept.
        $split = Query_Support::split_fields( Query_Support::meta_keys( $params ), self::ITEM_FIELDS, false );

        $query = new \WP_Query( $args );
        $items = [];
        foreach ( $query->posts as $att ) {
            $meta    = null;
            $dims    = static function () use ( $att, &$meta ): array {
                return $meta ?? ( $meta = (array) wp_get_attachment_metadata( $att->ID ) );
            };
            $items[] = Query_Support::finish_item( [
                'id'        => $att->ID,
                'title'     => $att->post_title,
                'filename'  => static fn() => basename( (string) get_attached_file( $att->ID ) ),
                'url'       => static fn() => wp_get_attachment_url( $att->ID ),
                'mime_type' => $att->post_mime_type,
                'alt'       => static fn() => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
                'date'      => $att->post_date,
                'width'     => static fn() => $dims()['width'] ?? null,
                'height'    => static fn() => $dims()['height'] ?? null,
                'filesize'  => static fn() => $dims()['filesize'] ?? null,
            ], $split['keep'] );
        }

        $out = [
            'items'    => $items,
            'total'    => $query->found_posts,
            'per_page' => $per_page,
            'page'     => $page,
            'has_more' => $page * $per_page < (int) $query->found_posts,
        ];
        // A MIME value no file has ("jpg", "image/jpg", "pdf") gave an empty list:
        // the answer names the types the library has (2.52.0).
        if ( ! empty( $params['mime_type'] ) && ! $items && 1 === $page ) {
            $have = array_keys( array_filter( (array) wp_count_attachments() ) );
            $have = array_values( array_filter( $have, static fn( $t ) => is_string( $t ) && str_contains( $t, '/' ) ) );
            $out['note'] = sprintf(
                'No media file has the type "%s". Send a type prefix such as "image" or a full type%s.',
                $args['post_mime_type'],
                $have ? ' this library has: ' . implode( ', ', $have ) : ''
            );
        }
        $note = Query_Support::fields_note( $items, [], $split['unknown'], self::ITEM_FIELDS );
        if ( null !== $note ) {
            $out['fields_note'] = $note;
        }
        if ( ! Query_Support::meta_keys( $params ) ) {
            // 2.48.0: a big list of whole items says how small it is with "fields".
            $out = Query_Support::with_size_note( $out, $items, Query_Support::compact_fields( self::ITEM_FIELDS ) );
        }
        return $this->json_result( $out );
    }
}
