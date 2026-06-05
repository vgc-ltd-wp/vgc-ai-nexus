<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Query_Support;

defined( 'ABSPATH' ) || exit;

class List_Posts_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_posts';
        $this->label        = __( 'List Posts', 'mcp-abilities' );
        $this->description  = 'List WordPress posts with filtering by status, category, author or date. Supports pagination (offset or page + per_page), WPML language scoping, and inline meta fields for bulk reads. Returns items, total and total_pages.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'status'   => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ], 'default' => 'publish' ],
                'per_page' => [ 'type' => 'integer', 'description' => 'Items per page (1-100).', 'default' => 10, 'maximum' => 100 ],
                'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'offset'   => [ 'type' => 'integer', 'description' => 'Result offset; overrides page when set.', 'minimum' => 0 ],
                'search'   => [ 'type' => 'string',  'description' => 'Search term.' ],
                'author'   => [ 'type' => 'integer', 'description' => 'Filter by author user ID.' ],
                'category' => [ 'type' => 'integer', 'description' => 'Filter by category ID.' ],
                'language' => [ 'type' => 'string',  'description' => "WPML language code (e.g. 'de', 'en') to scope results, or 'all' for every language. Defaults to the current language. Ignored if WPML is inactive." ],
                'fields'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => "Extra meta keys to include per item, e.g. ['_yoast_wpseo_metadesc','_yoast_wpseo_title']." ],
                'orderby'  => [ 'type' => 'string',  'enum' => [ 'date', 'title', 'ID', 'modified', 'rand' ], 'default' => 'date' ],
                'order'    => [ 'type' => 'string',  'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $args = [
            'post_type'   => 'post',
            'post_status' => sanitize_text_field( $params['status'] ?? 'publish' ),
            'orderby'     => sanitize_key( $params['orderby'] ?? 'date' ),
            'order'       => strtoupper( $params['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
        ];
        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }
        if ( ! empty( $params['author'] ) ) {
            $args['author'] = absint( $params['author'] );
        }
        if ( ! empty( $params['category'] ) ) {
            $args['cat'] = absint( $params['category'] );
        }

        return $this->json_result( Query_Support::run( $args, $params, function ( \WP_Post $post, array $meta_keys ): array {
            $item = [
                'id'            => $post->ID,
                'title'         => $post->post_title,
                'slug'          => $post->post_name,
                'status'        => $post->post_status,
                'date'          => $post->post_date,
                'modified'      => $post->post_modified,
                'author_id'     => (int) $post->post_author,
                'excerpt'       => get_the_excerpt( $post ),
                'permalink'     => get_permalink( $post->ID ),
                'comment_count' => (int) $post->comment_count,
            ];
            $item = Query_Support::with_wpml( $item, $post );
            if ( $meta_keys ) {
                $item['meta'] = Query_Support::meta_block( $post->ID, $meta_keys );
            }
            return $item;
        } ) );
    }
}
