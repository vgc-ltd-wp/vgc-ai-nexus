<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Create_Post_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_post';
        $this->label        = __( 'Create Post', 'mcp-abilities' );
        $this->description  = 'Create a new WordPress post of any registered post type. Defaults to post_type=post. Use list_post_types to discover available types (e.g. page, product, event). Categories and tags only apply to the built-in post type.';
        $this->required_cap = 'publish_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'title'      => [ 'type' => 'string', 'description' => 'Post title.' ],
                'content'    => [ 'type' => 'string', 'description' => 'Post content (HTML or blocks).' ],
                'excerpt'    => [ 'type' => 'string', 'description' => 'Post excerpt.' ],
                'status'     => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft' ],
                'post_type'  => [ 'type' => 'string', 'description' => 'Post type slug. Defaults to "post". Use list_post_types to see all available types.' ],
                'author_id'  => [ 'type' => 'integer','description' => 'Author user ID. Defaults to current user.' ],
                'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of category IDs (post type "post" only).' ],
                'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of tag IDs (post type "post" only).' ],
                'date'       => [ 'type' => 'string', 'description' => 'Publish date in Y-m-d H:i:s format.' ],
                'meta'       => [ 'type' => 'object', 'description' => 'Key/value post meta to set.' ],
                'slug'       => [ 'type' => 'string', 'description' => 'Post slug (post_name). Auto-generated from title if omitted.' ],
                'parent_id'  => [ 'type' => 'integer', 'description' => 'Parent post ID for hierarchical post types (e.g. pages).' ],
            ],
            'required' => [ 'title' ],
        ];
    }

    public function execute( array $params ): array {
        $allowed_statuses = [ 'publish', 'draft', 'pending', 'private' ];
        $status           = in_array( $params['status'] ?? 'draft', $allowed_statuses, true )
            ? $params['status']
            : 'draft';

        // Resolve and validate post type.
        $post_type = sanitize_key( $params['post_type'] ?? 'post' );
        if ( ! post_type_exists( $post_type ) ) {
            return $this->error( "Post type \"{$post_type}\" is not registered. Use list_post_types to see available types." );
        }

        // Check the caller can create posts of this type.
        $type_obj   = get_post_type_object( $post_type );
        $create_cap = $type_obj->cap->create_posts ?? 'edit_posts';
        if ( ! current_user_can( $create_cap ) ) {
            return $this->error( "You do not have permission to create \"{$post_type}\" posts (requires {$create_cap})." );
        }

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
            'post_type'    => $post_type,
            'post_author'  => $author_id,
        ];

        if ( ! empty( $params['slug'] ) ) {
            $post_data['post_name'] = sanitize_title( $params['slug'] );
        }

        if ( ! empty( $params['parent_id'] ) ) {
            $post_data['post_parent'] = absint( $params['parent_id'] );
        }

        if ( ! empty( $params['date'] ) ) {
            $post_data['post_date'] = sanitize_text_field( $params['date'] );
        }

        // Categories and tags only apply to the built-in 'post' type.
        if ( 'post' === $post_type ) {
            if ( ! empty( $params['categories'] ) ) {
                $post_data['post_category'] = array_map( 'absint', (array) $params['categories'] );
            }

            if ( ! empty( $params['tags'] ) ) {
                $post_data['tags_input'] = array_map( 'absint', (array) $params['tags'] );
            }
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
