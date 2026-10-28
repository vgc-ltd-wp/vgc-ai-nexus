<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

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
                'content'    => [ 'type' => 'string' ],
                'excerpt'    => [ 'type' => 'string' ],
                'status'     => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ], 'description' => '"future" schedules the post; it needs a future date.' ],
                'date'       => [ 'type' => 'string', 'description' => self::POST_DATE_DESCRIPTION ],
                'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'meta'       => [ 'type' => 'object', 'description' => 'Meta key/value pairs to update.' ],
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
        if ( isset( $params['title'] ) )   $update['post_title']   = sanitize_text_field( $params['title'] );
        if ( isset( $params['content'] ) ) $update['post_content']  = wp_kses_post( $params['content'] );
        if ( isset( $params['excerpt'] ) ) $update['post_excerpt']  = sanitize_textarea_field( $params['excerpt'] );
        if ( isset( $params['status'] ) )  $update['post_status']   = sanitize_key( $params['status'] );
        if ( isset( $params['categories'] ) ) $update['post_category'] = array_map( 'absint', (array) $params['categories'] );
        if ( isset( $params['tags'] ) )    $update['tags_input']    = array_map( 'absint', (array) $params['tags'] );

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
            foreach ( $params['meta'] as $key => $value ) {
                update_post_meta( $post_id, sanitize_key( $key ), $value );
            }
        }

        return $this->success( "Post {$post_id} updated successfully.", array_merge( [
            'post_id'   => $post_id,
            'permalink' => get_permalink( $post_id ),
        ], self::post_date_readback( $post_id, $old_status, $old_date ) ) );
    }
}
