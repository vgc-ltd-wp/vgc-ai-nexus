<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Delete_Post_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_post';
        $this->label        = __( 'Delete Post', 'mcp-abilities' );
        $this->description  = 'Move a post to the trash, or permanently delete it.';
        $this->required_cap = 'delete_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'        => [ 'type' => 'integer', 'description' => 'ID of the post to delete.' ],
                'force'     => [ 'type' => 'boolean', 'description' => 'If true, permanently delete (bypass trash). Default: false.', 'default' => false ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $post_id = absint( $params['id'] );
        $force   = ! empty( $params['force'] );

        if ( ! get_post( $post_id ) ) {
            return $this->error( "Post {$post_id} not found." );
        }
        if ( ! current_user_can( 'delete_post', $post_id ) ) {
            return $this->error( 'You do not have permission to delete this post.' );
        }

        $result = wp_delete_post( $post_id, $force );
        if ( ! $result ) {
            return $this->error( 'Failed to delete the post.' );
        }

        $action = $force ? 'permanently deleted' : 'moved to trash';
        return $this->success( "Post {$post_id} has been {$action}." );
    }
}
