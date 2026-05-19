<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── List Revisions ────────────────────────────────────────────────────────────

class List_Revisions_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_revisions';
        $this->label        = __( 'List Revisions', 'mcp-abilities' );
        $this->description  = 'List all revisions for a post. Returns revision ID, author, date, and title for each revision.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'description' => 'ID of the parent post.' ],
            ],
            'required' => [ 'post_id' ],
        ];
    }

    public function execute( array $params ): array {
        $post_id = absint( $params['post_id'] );
        $post    = get_post( $post_id );

        if ( ! $post ) {
            return $this->error( "Post {$post_id} not found." );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return $this->error( 'You do not have permission to view revisions for this post.' );
        }

        $revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );

        if ( empty( $revisions ) ) {
            return $this->json_result( [ 'post_id' => $post_id, 'total' => 0, 'revisions' => [] ] );
        }

        $result = array_values( array_map( function ( $rev ) {
            $author = get_userdata( $rev->post_author );
            return [
                'id'          => $rev->ID,
                'date'        => $rev->post_modified,
                'title'       => $rev->post_title,
                'author_id'   => (int) $rev->post_author,
                'author_name' => $author ? $author->display_name : '',
            ];
        }, $revisions ) );

        return $this->json_result( [
            'post_id'   => $post_id,
            'total'     => count( $result ),
            'revisions' => $result,
        ] );
    }
}

// ── Get Revision ──────────────────────────────────────────────────────────────

class Get_Revision_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_revision';
        $this->label        = __( 'Get Revision', 'mcp-abilities' );
        $this->description  = 'Retrieve the full content of a specific post revision by its revision ID.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'The revision ID (from list_revisions).' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $revision = wp_get_post_revision( absint( $params['id'] ) );

        if ( ! $revision ) {
            return $this->error( 'Revision not found.' );
        }
        if ( ! current_user_can( 'edit_post', $revision->post_parent ) ) {
            return $this->error( 'You do not have permission to view this revision.' );
        }

        $author = get_userdata( $revision->post_author );

        return $this->json_result( [
            'id'          => $revision->ID,
            'post_id'     => (int) $revision->post_parent,
            'date'        => $revision->post_modified,
            'title'       => $revision->post_title,
            'content'     => $revision->post_content,
            'excerpt'     => $revision->post_excerpt,
            'author_id'   => (int) $revision->post_author,
            'author_name' => $author ? $author->display_name : '',
        ] );
    }
}
