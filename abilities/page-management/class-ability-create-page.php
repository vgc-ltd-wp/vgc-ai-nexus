<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Create_Page_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_page';
        $this->label        = __( 'Create Page', 'mcp-abilities' );
        $this->description  = 'Create a new WordPress page, optionally nested under a parent.';
        $this->required_cap = 'publish_pages';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'title'      => [ 'type' => 'string',  'description' => 'Page title.' ],
                'content'    => [ 'type' => 'string',  'description' => 'Page content.' ],
                'status'     => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft' ],
                'parent_id'  => [ 'type' => 'integer', 'description' => 'Parent page ID.' ],
                'template'   => [ 'type' => 'string',  'description' => 'Page template filename (e.g. template-full-width.php).' ],
                'menu_order' => [ 'type' => 'integer', 'default' => 0 ],
            ],
            'required' => [ 'title' ],
        ];
    }

    public function execute( array $params ): array {
        $data = [
            'post_type'    => 'page',
            'post_title'   => sanitize_text_field( $params['title'] ),
            'post_content' => wp_kses_post( $params['content'] ?? '' ),
            'post_status'  => sanitize_key( $params['status'] ?? 'draft' ),
            'post_parent'  => absint( $params['parent_id'] ?? 0 ),
            'menu_order'   => (int) ( $params['menu_order'] ?? 0 ),
        ];

        $page_id = wp_insert_post( $data, true );
        if ( is_wp_error( $page_id ) ) {
            return $this->error( $page_id->get_error_message() );
        }

        if ( ! empty( $params['template'] ) ) {
            update_post_meta( $page_id, '_wp_page_template', sanitize_file_name( $params['template'] ) );
        }

        return $this->json_result( [
            'id'        => $page_id,
            'permalink' => get_permalink( $page_id ),
            'edit_url'  => get_edit_post_link( $page_id, 'raw' ),
        ] );
    }
}
