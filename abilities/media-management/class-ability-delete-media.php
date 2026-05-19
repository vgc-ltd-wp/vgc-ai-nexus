<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Delete_Media_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_media';
        $this->label        = __( 'Delete Media', 'mcp-abilities' );
        $this->description  = 'Permanently delete a media attachment and its files.';
        $this->required_cap = 'delete_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $id = absint( $params['id'] );
        if ( ! get_post( $id ) ) {
            return $this->error( "Attachment {$id} not found." );
        }

        $result = wp_delete_attachment( $id, true );
        if ( ! $result ) {
            return $this->error( "Failed to delete attachment {$id}." );
        }

        return $this->success( "Attachment {$id} permanently deleted." );
    }
}
