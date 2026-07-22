<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Query_Support;

defined( 'ABSPATH' ) || exit;

class List_Posts_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_posts';
        $this->label        = __( 'List Posts', 'mcp-abilities' );
        $this->description  = 'List WordPress content of ANY registered post type (default "post" — use list_post_types to discover slugs like "product", "avada_portfolio", "event"). Filter by status, author, built-in category, or ANY taxonomy via taxonomy+terms (e.g. taxonomy "portfolio_category", terms ["automotive"]). Unknown post types or taxonomies return an explicit error listing the valid options. Supports pagination (offset or page + per_page), WPML language scoping, and inline meta fields for bulk reads. Returns items, total and total_pages.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'post_type' => [ 'type' => 'string', 'description' => 'Post type slug (any registered type, e.g. "post", "page", "product", "avada_portfolio"). Default "post". Use list_post_types to discover.', 'default' => 'post' ],
                'status'   => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ], 'default' => 'publish' ],
                'per_page' => [ 'type' => 'integer', 'description' => 'Items per page (1-100).', 'default' => 10, 'maximum' => 100 ],
                'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'offset'   => [ 'type' => 'integer', 'description' => 'Result offset; overrides page when set.', 'minimum' => 0 ],
                'search'   => [ 'type' => 'string',  'description' => 'Search term.' ],
                'author'   => [ 'type' => 'integer', 'description' => 'Filter by author user ID.' ],
                'category' => [ 'type' => 'integer', 'description' => 'Filter by built-in category ID (blog posts). For custom taxonomies use taxonomy + terms.' ],
                'taxonomy' => [ 'type' => 'string',  'description' => 'Any taxonomy slug to filter by, e.g. "portfolio_category", "product_cat". Use with "terms".' ],
                'terms'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Term slugs (or numeric IDs as strings) within "taxonomy". Items matching ANY listed term are returned.' ],
                'language' => [ 'type' => 'string',  'description' => "WPML language code (e.g. 'de', 'en') to scope results, or 'all' for every language. Defaults to the current language. Ignored if WPML is inactive." ],
                'fields'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => "Extra meta keys to include per item, e.g. ['_yoast_wpseo_metadesc','_yoast_wpseo_title']." ],
                'orderby'  => [ 'type' => 'string',  'enum' => [ 'date', 'title', 'ID', 'modified', 'rand' ], 'default' => 'date' ],
                'order'    => [ 'type' => 'string',  'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        // Post type: any registered type, with a self-correcting error on unknowns
        // (a silent fallback to "post" cost a customer several blind retries).
        $post_type = sanitize_key( $params['post_type'] ?? 'post' );
        if ( ! post_type_exists( $post_type ) ) {
            $available = array_keys( get_post_types( [ 'show_ui' => true ] ) );
            return $this->error( sprintf(
                'Post type "%s" is not registered on this site. Registered types include: %s. Use list_post_types for full details.',
                $post_type,
                implode( ', ', array_slice( $available, 0, 30 ) )
            ) );
        }

        $args = [
            'post_type'   => $post_type,
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

        // Generic taxonomy filter (works for any CPT taxonomy, e.g. portfolio_category).
        $taxonomy = isset( $params['taxonomy'] ) ? sanitize_key( $params['taxonomy'] ) : '';
        $terms    = isset( $params['terms'] ) && is_array( $params['terms'] ) ? array_filter( array_map( 'strval', $params['terms'] ) ) : [];
        if ( '' !== $taxonomy || [] !== $terms ) {
            if ( '' === $taxonomy || [] === $terms ) {
                return $this->error( 'Provide BOTH "taxonomy" and "terms" to filter by a taxonomy.' );
            }
            if ( ! taxonomy_exists( $taxonomy ) ) {
                $for_type = get_object_taxonomies( $post_type );
                return $this->error( sprintf(
                    'Taxonomy "%s" is not registered. Taxonomies available for post type "%s": %s.',
                    $taxonomy,
                    $post_type,
                    $for_type ? implode( ', ', $for_type ) : '(none)'
                ) );
            }
            $numeric = array_filter( $terms, 'is_numeric' );
            $args['tax_query'] = [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'taxonomy' => $taxonomy,
                'field'    => count( $numeric ) === count( $terms ) ? 'term_id' : 'slug',
                'terms'    => count( $numeric ) === count( $terms ) ? array_map( 'intval', $terms ) : $terms,
            ] ];
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
