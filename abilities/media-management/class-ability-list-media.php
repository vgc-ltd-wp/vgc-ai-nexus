<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Media_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_media';
        $this->label        = __( 'List Media', 'mcp-abilities' );
        $this->description  = 'List media library items with optional type filter.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'mime_type' => [ 'type' => 'string', 'description' => 'Filter by mime type prefix, e.g. image, video, audio, application/pdf.' ],
                'per_page'  => [ 'type' => 'integer', 'default' => 20 ],
                'page'      => [ 'type' => 'integer', 'default' => 1 ],
                'search'    => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => min( absint( $params['per_page'] ?? 20 ), 100 ),
            'paged'          => max( 1, absint( $params['page'] ?? 1 ) ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ( ! empty( $params['mime_type'] ) ) {
            $args['post_mime_type'] = sanitize_text_field( $params['mime_type'] );
        }
        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }

        $query = new \WP_Query( $args );
        $items = [];
        foreach ( $query->posts as $att ) {
            $meta    = wp_get_attachment_metadata( $att->ID );
            $items[] = [
                'id'        => $att->ID,
                'title'     => $att->post_title,
                'filename'  => basename( get_attached_file( $att->ID ) ),
                'url'       => wp_get_attachment_url( $att->ID ),
                'mime_type' => $att->post_mime_type,
                'alt'       => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
                'date'      => $att->post_date,
                'width'     => $meta['width']    ?? null,
                'height'    => $meta['height']   ?? null,
                'filesize'  => $meta['filesize'] ?? null,
            ];
        }

        return $this->json_result( [ 'items' => $items, 'total' => $query->found_posts ] );
    }
}
