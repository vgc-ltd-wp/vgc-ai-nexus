<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Get_Media_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_media';
        $this->label        = __( 'Get Media Item', 'mcp-abilities' );
        $this->description  = 'Retrieve full details of a single media attachment.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'Attachment post ID.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $id  = absint( $params['id'] );
        $att = get_post( $id );
        if ( ! $att || 'attachment' !== $att->post_type ) {
            return $this->error( "Attachment {$id} not found." );
        }

        $meta  = wp_get_attachment_metadata( $id );
        $sizes = [];
        if ( ! empty( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size_name => $size_data ) {
                $sizes[ $size_name ] = wp_get_attachment_image_src( $id, $size_name );
            }
        }

        return $this->json_result( [
            'id'          => $id,
            'title'       => $att->post_title,
            'caption'     => $att->post_excerpt,
            'description' => $att->post_content,
            'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'url'         => wp_get_attachment_url( $id ),
            'mime_type'   => $att->post_mime_type,
            'date'        => $att->post_date,
            'meta'        => $meta,
            'sizes'       => $sizes,
        ] );
    }
}
