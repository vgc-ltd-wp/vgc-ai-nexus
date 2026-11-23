<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Post_Access;

defined( 'ABSPATH' ) || exit;

class Delete_Post_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_post';
        $this->label        = __( 'Delete Post', 'mcp-abilities' );
        $this->description  = 'Move a post, page or custom post type item to the trash, or permanently delete it (force: true). An item already in the trash, or a site with the trash switched off, needs force: true; the result says in "outcome" whether the item was "trashed" or "deleted".';
        $this->required_cap = 'delete_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'        => [ 'type' => 'integer', 'description' => 'ID of the post to delete.' ],
                'force'     => [ 'type' => 'boolean', 'description' => 'If true, permanently delete (bypass trash). Default: false: moved to the trash, whatever its type.', 'default' => false ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $post_id = absint( $params['id'] );
        $force   = ! empty( $params['force'] );

        $post = get_post( $post_id );
        if ( ! $post ) {
            return $this->error( "Post {$post_id} not found." );
        }
        $internal = Post_Access::internal_type_error( $post->post_type );
        if ( null !== $internal ) {
            return $this->error( $internal );
        }
        if ( ! current_user_can( 'delete_post', $post_id ) ) {
            return $this->error( 'You do not have permission to delete this post.' );
        }

        // Every type goes to the trash, not only posts and pages; where WordPress
        // has no trash for it, nothing is changed and the error says so (2.52.0).
        $done = Post_Access::trash_or_delete( $post, $force );
        if ( null !== $done['error'] ) {
            return $this->error( $done['error'] );
        }

        $action = 'deleted' === $done['outcome'] ? 'permanently deleted' : 'moved to trash';
        $extra  = [ 'id' => $post_id, 'outcome' => $done['outcome'] ];
        if ( null !== $done['note'] ) {
            $extra['note'] = $done['note'];
        }
        return $this->success( "Post {$post_id} has been {$action}.", $extra );
    }
}
