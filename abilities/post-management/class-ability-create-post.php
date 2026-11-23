<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Custom_Code;
use MCP_Abilities\Post_Access;

defined( 'ABSPATH' ) || exit;

class Create_Post_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_post';
        $this->label        = __( 'Create Post', 'mcp-abilities' );
        $this->description  = 'Create a new WordPress post of any registered post type. Defaults to post_type=post. Use list_post_types to discover available types (e.g. page, product, event). Categories and tags apply to types that have them (the built-in post type does); an unknown ID is refused. The result echoes the stored slug, date, status and terms.';
        $this->required_cap = 'publish_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'title'      => [ 'type' => 'string', 'description' => 'Post title.' ],
                'content'    => [ 'type' => 'string', 'description' => 'Post content (HTML or blocks). ' . self::CONTENT_FILTER_DESCRIPTION ],
                'excerpt'    => [ 'type' => 'string', 'description' => 'Post excerpt. ' . self::TEXT_RULE_DESCRIPTIONS['post'] ],
                'status'     => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft', 'description' => '"publish" and "private" need the permission to publish this post type; without it the post is created as "pending" (see status_note).' ],
                'post_type'  => [ 'type' => 'string', 'description' => 'Post type slug. Defaults to "post". Use list_post_types to see all available types.' ],
                'author_id'  => [ 'type' => 'integer','description' => 'Author user ID. Defaults to current user.' ],
                'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of category IDs (types with categories, such as "post"). An unknown ID is refused.' ],
                'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of tag IDs (types with tags, such as "post"). An unknown ID is refused.' ],
                'date'       => [ 'type' => 'string', 'description' => 'Publish (created) date in site local time, "Y-m-d H:i:s" (also "Y-m-d\TH:i:s" or "Y-m-d"). create_post: the date of the new post; with a future date WordPress schedules it instead of publishing (the result says so). update_post / update_page: changes the date of an EXISTING post; a future date on a published post is refused unless status "future" is sent too; a past date on a scheduled post publishes it. The modified date cannot be set (WordPress updates it itself).' ],
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
        // Revisions, menu items, privacy requests and the like: not through this tool.
        $internal = Post_Access::internal_type_error( $post_type );
        if ( null !== $internal ) {
            return $this->error( $internal );
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

        // As sent for an account that may post unfiltered HTML, unless raw code is
        // locked; otherwise filtered as WordPress filters posts, with a note (2.49.0).
        [ $content, $content_note ] = Custom_Code::filter_with_note( (string) ( $params['content'] ?? '' ) );
        // The excerpt follows the same rule (2.53.0; it was plain text). What was
        // removed is said from what was stored.
        $excerpt_sent = isset( $params['excerpt'] ) && is_scalar( $params['excerpt'] ) ? (string) $params['excerpt'] : null;
        $excerpt      = self::rich_text( $excerpt_sent ?? '', self::TEXT_RULE_POST );

        $post_data = [
            'post_title'   => sanitize_text_field( $params['title'] ),
            'post_content' => $content,
            'post_excerpt' => $excerpt,
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

        // The date with its GMT twin (2.52.0): without it a draft's date was
        // "floating", and the next update reset it to the time of that update.
        if ( isset( $params['date'] ) && '' !== $params['date'] ) {
            $local = self::parse_post_date( $params['date'] );
            if ( null === $local ) {
                return $this->error( 'Invalid date "' . sanitize_text_field( (string) $params['date'] ) . '". Send a real calendar date in site local time as "Y-m-d H:i:s" (e.g. 2024-03-15 09:30:00), "Y-m-d\TH:i:s" or "Y-m-d". Nothing was created.' );
            }
            $post_data['post_date']     = $local;
            $post_data['post_date_gmt'] = get_gmt_from_date( $local );
            $post_data['edit_date']     = true;
        }

        // Categories and tags: for every type that has them, checked before
        // anything is written (2.52.0; until then only "post", unknown IDs skipped).
        $terms = Post_Access::term_params( $params, $post_type );
        if ( null !== $terms['error'] ) {
            return $this->error( $terms['error'] );
        }

        // wp_insert_post() takes slashed data: unslashed, every backslash was lost.
        $post_id = wp_insert_post( wp_slash( $post_data ), true );

        if ( is_wp_error( $post_id ) ) {
            return $this->error( $post_id->get_error_message() );
        }
        $terms_done = Post_Access::apply_terms( (int) $post_id, $terms['set'] );

        // Set custom meta: protected keys only with permission to edit them.
        $meta_skipped = [];
        if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
            [ $meta, $meta_skipped ] = Post_Access::writable_meta( $post_id, $params['meta'] );
            foreach ( $meta as $key => $value ) {
                // Slashed: update_post_meta() unslashes, so backslashes were lost (2.50.0).
                update_post_meta( $post_id, $key, Post_Access::slash_meta( $value ) );
            }
        }

        clean_post_cache( $post_id );
        $result = [
            'id'        => $post_id,
            'permalink' => get_permalink( $post_id ),
            'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
            'status'    => get_post_status( $post_id ),
            'date'      => get_post_field( 'post_date', $post_id ),
        ];
        $result = array_merge( $result, self::slug_readback( (int) $post_id, $params['slug'] ?? null ), $terms_done['fields'] );
        if ( null !== $status_note ) {
            $result['status_note'] = $status_note;
        }
        $result = self::with_note( $result, $content_note );
        // A future date makes WordPress schedule the post instead of publishing it.
        if ( 'future' === $result['status'] && 'future' !== $status ) {
            $result = self::with_note( $result, sprintf( 'The date is in the future, so WordPress scheduled the post instead of publishing it: it is off the site until %s (site time), when WordPress publishes it.', $result['date'] ) );
        }
        $result = self::with_note( $result, $terms_done['note'] );
        $result = self::with_text_note( $result, $excerpt_sent, null !== $excerpt_sent ? (string) get_post_field( 'post_excerpt', $post_id, 'raw' ) : null, self::TEXT_RULE_POST, 'excerpt', 'excerpt' );
        if ( $meta_skipped ) {
            $result['meta_skipped'] = $meta_skipped;
        }
        return $this->json_result( $result );
    }
}
