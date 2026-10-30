<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Post_Access;

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
                'status'     => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft', 'description' => '"publish" and "private" need the permission to publish this post type; without it the post is created as "pending" (see status_note).' ],
                'post_type'  => [ 'type' => 'string', 'description' => 'Post type slug. Defaults to "post". Use list_post_types to see all available types.' ],
                'author_id'  => [ 'type' => 'integer','description' => 'Author user ID. Defaults to current user.' ],
                'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of category IDs (post type "post" only).' ],
                'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of tag IDs (post type "post" only).' ],
                'date'       => [ 'type' => 'string', 'description' => 'Publish (created) date in site local time, "Y-m-d H:i:s". create_post: the date of the new post. update_post / update_page: changes the date of an EXISTING post (also "Y-m-d\TH:i:s" or "Y-m-d"); a future date on a published post is refused unless status "future" is sent too; a past date on a scheduled post publishes it. The modified date cannot be set (WordPress updates it itself).' ],
                'meta'       => [ 'type' => 'object', 'description' => 'Key/value post meta to set. Protected keys (e.g. starting with "_") need permission to edit them; refused keys are listed in meta_skipped.' ],
                'slug'       => [ 'type' => 'string', 'description' => 'Post slug (post_name). Auto-generated from title if omitted.' ],
                'parent_id'  => [ 'type' => 'integer', 'description' => 'Parent post ID for hierarchical post types (e.g. pages).' ],
            ],
            'required' => [ 'title' ],
        ];
    }

    public function execute( array $params ): array {
        $allowed_statuses = [ 'publish', 'draft', 'pending', 'private' ];
        $status           = in_array( $params['status'] ?? 'draft', $allowed_statuses, true )
            ? ( $params['status'] ?? 'draft' )
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

        // Attributing a post to a different user requires the type's edit_others_posts.
        if ( $author_id !== get_current_user_id() && ! current_user_can( $type_obj->cap->edit_others_posts ) ) {
            return $this->error( 'You do not have permission to create posts attributed to other users.' );
        }

        // Publishing, scheduling or a private post needs the type's own
        // publish capability; without it the post is created as pending.
        $status_note = null;
        if ( in_array( $status, Post_Access::PUBLISH_STATUSES, true ) && ! current_user_can( $type_obj->cap->publish_posts ) ) {
            $status_note = sprintf( 'Status "%s" needs the permission to publish (%s), which this account does not have, so the item was created as "pending" (Pending Review) for an editor to publish.', $status, $type_obj->cap->publish_posts );
            $status      = 'pending';
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

        // Set custom meta: protected keys only with permission to edit them.
        $meta_skipped = [];
        if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
            [ $meta, $meta_skipped ] = Post_Access::writable_meta( $post_id, $params['meta'] );
            foreach ( $meta as $key => $value ) {
                update_post_meta( $post_id, $key, $value );
            }
        }

        $result = [
            'id'        => $post_id,
            'permalink' => get_permalink( $post_id ),
            'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
            'status'    => get_post_status( $post_id ),
        ];
        if ( null !== $status_note ) {
            $result['status_note'] = $status_note;
        }
        if ( $meta_skipped ) {
            $result['meta_skipped'] = $meta_skipped;
        }
        return $this->json_result( $result );
    }
}
