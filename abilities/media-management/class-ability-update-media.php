<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Update_Media_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_media';
        $this->label        = __( 'Update Media', 'mcp-abilities' );
        $this->description  = 'Update title, alt text, caption, or description of a media item.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'            => [ 'type' => 'integer' ],
                'title'         => [ 'type' => 'string' ],
                'alt'           => [ 'type' => 'string' ],
                'caption'       => [ 'type' => 'string' ],
                'description'   => [ 'type' => 'string' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $id = absint( $params['id'] );
        if ( ! get_post( $id ) ) {
            return $this->error( "Attachment {$id} not found." );
        }

        $update = [ 'ID' => $id ];
        if ( isset( $params['title'] ) )       $update['post_title']   = sanitize_text_field( $params['title'] );
        if ( isset( $params['caption'] ) )     $update['post_excerpt']  = sanitize_textarea_field( $params['caption'] );
        if ( isset( $params['description'] ) ) $update['post_content']  = sanitize_textarea_field( $params['description'] );

        if ( count( $update ) > 1 ) {
            wp_update_post( $update );
        }

        if ( isset( $params['alt'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt'] ) );
        }

        return $this->success( "Attachment {$id} updated." );
    }
}
