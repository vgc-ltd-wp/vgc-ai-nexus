<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Update_Page_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_page';
        $this->label        = __( 'Update Page', 'mcp-abilities' );
        $this->description  = 'Update an existing WordPress page. "date" changes its publish (created) date; the result echoes the stored date, date_gmt and status.';
        $this->required_cap = 'edit_pages';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'         => [ 'type' => 'integer' ],
                'title'      => [ 'type' => 'string' ],
                'slug'       => [ 'type' => 'string', 'description' => 'New page slug (post_name).' ],
                'content'    => [ 'type' => 'string' ],
                'status'     => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ], 'description' => '"future" schedules the page; it needs a future date.' ],
                'date'       => [ 'type' => 'string', 'description' => self::POST_DATE_DESCRIPTION ],
                'parent_id'  => [ 'type' => 'integer' ],
                'template'   => [ 'type' => 'string' ],
                'menu_order' => [ 'type' => 'integer' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $page_id = absint( $params['id'] );
        $page    = get_post( $page_id );
        if ( ! $page ) {
            return $this->error( "Page {$page_id} not found." );
        }
        if ( 'page' !== $page->post_type ) {
            return $this->error( "Item {$page_id} is a {$page->post_type}, not a page. Use update_post for other post types." );
        }
        if ( ! current_user_can( 'edit_post', $page_id ) ) {
            return $this->error( 'You do not have permission to edit this page.' );
        }

        $update = [ 'ID' => $page_id ];
        $date_error = $this->prepare_post_date( $page_id, $params, $update );
        if ( null !== $date_error ) {
            return $this->error( $date_error );
        }
        $page       = get_post( $page_id );
        $old_status = $page->post_status;
        $old_date   = $page->post_date;
        if ( isset( $params['title'] ) )      $update['post_title']   = sanitize_text_field( $params['title'] );
        if ( ! empty( $params['slug'] ) )     $update['post_name']    = sanitize_title( $params['slug'] );
        if ( isset( $params['content'] ) )    $update['post_content']  = wp_kses_post( $params['content'] );
        if ( isset( $params['status'] ) )     $update['post_status']   = sanitize_key( $params['status'] );
        if ( isset( $params['parent_id'] ) )  $update['post_parent']   = absint( $params['parent_id'] );
        if ( isset( $params['menu_order'] ) ) $update['menu_order']    = (int) $params['menu_order'];

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        if ( isset( $params['template'] ) ) {
            update_post_meta( $page_id, '_wp_page_template', sanitize_file_name( $params['template'] ) );
        }

        return $this->success( "Page {$page_id} updated.", array_merge(
            [ 'page_id' => $page_id ],
            self::post_date_readback( $page_id, $old_status, $old_date )
        ) );
    }
}
