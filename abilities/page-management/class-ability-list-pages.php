<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Pages_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_pages';
        $this->label        = __( 'List Pages', 'mcp-abilities' );
        $this->description  = 'List WordPress pages with hierarchy info.';
        $this->required_cap = 'edit_pages';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'status'    => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ], 'default' => 'publish' ],
                'per_page'  => [ 'type' => 'integer', 'default' => 20 ],
                'parent_id' => [ 'type' => 'integer', 'description' => 'Filter by parent page ID. Use 0 for top-level.' ],
                'search'    => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $args = [
            'post_type'      => 'page',
            'post_status'    => sanitize_text_field( $params['status']   ?? 'publish' ),
            'posts_per_page' => min( absint( $params['per_page'] ?? 20 ), 100 ),
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ];

        if ( isset( $params['parent_id'] ) ) {
            $args['post_parent'] = absint( $params['parent_id'] );
        }
        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }

        $query = new \WP_Query( $args );
        $pages = [];
        foreach ( $query->posts as $page ) {
            $pages[] = [
                'id'         => $page->ID,
                'title'      => $page->post_title,
                'slug'       => $page->post_name,
                'status'     => $page->post_status,
                'parent_id'  => (int) $page->post_parent,
                'menu_order' => (int) $page->menu_order,
                'permalink'  => get_permalink( $page->ID ),
                'template'   => get_page_template_slug( $page->ID ) ?: 'default',
            ];
        }

        return $this->json_result( [ 'pages' => $pages, 'total' => $query->found_posts ] );
    }
}
