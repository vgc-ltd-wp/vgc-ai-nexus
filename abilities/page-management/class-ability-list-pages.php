<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Query_Support;

defined( 'ABSPATH' ) || exit;

class List_Pages_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_pages';
        $this->label        = __( 'List Pages', 'mcp-abilities' );
        $this->description  = 'List WordPress pages with hierarchy info. Supports pagination (offset or page + per_page), WPML language scoping, and inline meta fields for bulk reads. Returns items, total and total_pages.';
        $this->required_cap = 'edit_pages';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'status'    => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ], 'default' => 'publish' ],
                'per_page'  => [ 'type' => 'integer', 'description' => 'Items per page (1-100).', 'default' => 20, 'maximum' => 100 ],
                'page'      => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'offset'    => [ 'type' => 'integer', 'description' => 'Result offset; overrides page when set.', 'minimum' => 0 ],
                'parent_id' => [ 'type' => 'integer', 'description' => 'Filter by parent page ID. Use 0 for top-level.' ],
                'search'    => [ 'type' => 'string' ],
                'language'  => [ 'type' => 'string', 'description' => "WPML language code (e.g. 'de', 'en') to scope results, or 'all' for every language. Defaults to the current language. Ignored if WPML is inactive." ],
                'fields'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => "Extra meta keys to include per item, e.g. ['_yoast_wpseo_metadesc','_yoast_wpseo_title']." ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $args = [
            'post_type'   => 'page',
            'post_status' => sanitize_text_field( $params['status'] ?? 'publish' ),
            'orderby'     => 'menu_order title',
            'order'       => 'ASC',
        ];
        if ( isset( $params['parent_id'] ) ) {
            $args['post_parent'] = absint( $params['parent_id'] );
        }
        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }

        return $this->json_result( Query_Support::run( $args, $params, function ( \WP_Post $page, array $meta_keys ): array {
            $item = [
                'id'         => $page->ID,
                'title'      => $page->post_title,
                'slug'       => $page->post_name,
                'status'     => $page->post_status,
                'parent_id'  => (int) $page->post_parent,
                'menu_order' => (int) $page->menu_order,
                'permalink'  => get_permalink( $page->ID ),
                'template'   => get_page_template_slug( $page->ID ) ?: 'default',
            ];
            $item = Query_Support::with_wpml( $item, $page );
            if ( $meta_keys ) {
                $item['meta'] = Query_Support::meta_block( $page->ID, $meta_keys );
            }
            return $item;
        } ) );
    }
}
