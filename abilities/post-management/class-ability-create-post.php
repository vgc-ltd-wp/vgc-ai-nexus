<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Create_Post_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_post';
        $this->label        = __( 'Create Post', 'mcp-abilities' );
        $this->description  = 'Create a new WordPress post with content, excerpt, categories and tags.';
        $this->required_cap = 'publish_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'title'      => [ 'type' => 'string', 'description' => 'Post title.' ],
                'content'    => [ 'type' => 'string', 'description' => 'Post content (HTML or blocks).' ],
                'excerpt'    => [ 'type' => 'string', 'description' => 'Post excerpt.' ],
                'status'     => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft' ],
                'author_id'  => [ 'type' => 'integer','description' => 'Author user ID. Defaults to current user.' ],
                'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of category IDs.' ],
                'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of tag IDs.' ],
                'date'       => [ 'type' => 'string', 'description' => 'Publish date in Y-m-d H:i:s format.' ],
                'meta'       => [ 'type' => 'object', 'description' => 'Key/value post meta to set.' ],
            ],
            'required' => [ 'title' ],
        ];
    }

    public function execute( array $params ): array {
        $allowed_statuses = [ 'publish', 'draft', 'pending', 'private' ];
        $status           = in_array( $params['status'] ?? 'draft', $allowed_statuses, true )
            ? $params['status']
            : 'draft';

        $author_id = isset( $params['author_id'] ) ? absint( $params['author_id'] ) : get_current_user_id();

        // Attributing a post to a different user requires edit_others_posts.
        if ( $author_id !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
            return $this->error( 'You do not have permission to create posts attributed to other users.' );
        }

        $post_data = [
            'post_title'   => sanitize_text_field( $params['title'] ),
            'post_content' => wp_kses_post( $params['content'] ?? '' ),
            'post_excerpt' => sanitize_textarea_field( $params['excerpt'] ?? '' ),
            'post_status'  => $status,
            'post_type'    => 'post',
            'post_author'  => $author_id,
        ];

        if ( ! empty( $params['date'] ) ) {
            $post_data['post_date'] = sanitize_text_field( $params['date'] );
        }

        if ( ! empty( $params['categories'] ) ) {
            $post_data['post_category'] = array_map( 'absint', (array) $params['categories'] );
        }

        if ( ! empty( $params['tags'] ) ) {
            $post_data['tags_input'] = array_map( 'absint', (array) $params['tags'] );
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return $this->error( $post_id->get_error_message() );
        }

        // Set custom meta
        if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
            foreach ( $params['meta'] as $key => $value ) {
                update_post_meta( $post_id, sanitize_key( $key ), $value );
            }
        }

        return $this->json_result( [
            'id'        => $post_id,
            'permalink' => get_permalink( $post_id ),
            'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
            'status'    => $status,
        ] );
    }
}
