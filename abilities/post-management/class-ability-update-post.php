<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Custom_Code;
use MCP_Abilities\Post_Access;

defined( 'ABSPATH' ) || exit;

class Update_Post_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_post';
        $this->label        = __( 'Update Post', 'mcp-abilities' );
        $this->description  = 'Update an existing post, page or custom post type item by ID. Only provided fields are changed. "date" changes the publish (created) date of the existing post; the result echoes the stored date, date_gmt and status.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'         => [ 'type' => 'integer', 'description' => 'ID of the post to update.' ],
                'title'      => [ 'type' => 'string' ],
                'content'    => [ 'type' => 'string', 'description' => 'New content (HTML or blocks). ' . self::CONTENT_FILTER_DESCRIPTION ],
                'excerpt'    => [ 'type' => 'string' ],
                'status'     => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ], 'description' => '"future" schedules the post; it needs a future date. "publish", "future" and "private" need the permission to publish this post; without it the post is saved as "pending" (see status_note in the result).' ],
                'date'       => [ 'type' => 'string', 'description' => self::POST_DATE_DESCRIPTION ],
                'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'meta'       => [ 'type' => 'object', 'description' => 'Meta key/value pairs to update. Protected keys (e.g. starting with "_") need permission to edit them; refused keys are listed in meta_skipped.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $post_id = absint( $params['id'] );
        $post    = get_post( $post_id );
        if ( ! $post ) {
            return $this->error( "Post {$post_id} not found." );
        }
        $internal = Post_Access::internal_type_error( $post->post_type );
        if ( null !== $internal ) {
            return $this->error( $internal );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return $this->error( 'You do not have permission to edit this post.' );
        }

        $update = [ 'ID' => $post_id ];
        $date_error = $this->prepare_post_date( $post_id, $params, $update );
        if ( null !== $date_error ) {
            return $this->error( $date_error );
        }
        $post = get_post( $post_id );
        $old_status = $post->post_status;
        $old_date   = $post->post_date;

        // Publish, schedule and private need publish_post; without it the post
        // is saved as pending, as the WordPress editor does.
        $status = Post_Access::update_status(
            $post,
            isset( $params['status'] ) ? sanitize_key( $params['status'] ) : null,
            isset( $update['post_date'] )
        );
        if ( null !== $status['error'] ) {
            return $this->error( $status['error'] );
        }

        [ $meta, $meta_skipped ] = ! empty( $params['meta'] ) && is_array( $params['meta'] )
            ? Post_Access::writable_meta( $post_id, $params['meta'] )
            : [ [], [] ];

        if ( isset( $params['title'] ) )   $update['post_title']   = sanitize_text_field( $params['title'] );
        // As sent for an account that may post unfiltered HTML, unless raw code is
        // locked; otherwise filtered as WordPress filters posts, with a note (2.49.0).
        $content_note = null;
        if ( isset( $params['content'] ) ) {
            [ $update['post_content'], $content_note ] = Custom_Code::filter_with_note( (string) $params['content'] );
        }
        if ( isset( $params['excerpt'] ) ) $update['post_excerpt']  = sanitize_textarea_field( $params['excerpt'] );
        if ( null !== $status['status'] )  $update['post_status']   = $status['status'];
        if ( isset( $params['categories'] ) ) $update['post_category'] = array_map( 'absint', (array) $params['categories'] );
        if ( isset( $params['tags'] ) )    $update['tags_input']    = array_map( 'absint', (array) $params['tags'] );

        // wp_update_post() takes slashed data: unslashed, every backslash was lost.
        $result = wp_update_post( wp_slash( $update ), true );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        foreach ( $meta as $key => $value ) {
            // Slashed: update_post_meta() unslashes, so backslashes were lost (2.50.0).
            update_post_meta( $post_id, $key, Post_Access::slash_meta( $value ) );
        }

        $extra = array_merge( [
            'post_id'   => $post_id,
            'permalink' => get_permalink( $post_id ),
        ], self::post_date_readback( $post_id, $old_status, $old_date ) );
        if ( null !== $status['note'] ) {
            $extra['status_note'] = $status['note'];
        }
        if ( null !== $content_note ) {
            $extra['note'] = $content_note;
        }
        if ( $meta_skipped ) {
            $extra['meta_skipped'] = $meta_skipped;
        }
        return $this->success( "Post {$post_id} updated successfully.", $extra );
    }
}
