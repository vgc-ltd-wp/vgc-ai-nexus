<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Query_Support;
use MCP_Abilities\Wpml_Support;

defined( 'ABSPATH' ) || exit;

class List_Posts_Ability extends Ability {

    /**
     * The fields "fields" may name (2.47.0). The last three are not in whole
     * items: they are worked out only when named, as get_post has them.
     */
    public const ITEM_FIELDS = [ 'id', 'title', 'slug', 'status', 'date', 'modified', 'author_id', 'excerpt', 'permalink', 'comment_count', 'featured_image', 'categories', 'tags' ];

    protected function define_meta(): void {
        $this->key          = 'list_posts';
        $this->label        = __( 'List Posts', 'mcp-abilities' );
        $this->description  = 'List WordPress content of ANY registered post type (default "post" — use list_post_types to discover slugs like "product", "avada_portfolio", "event"). Filter by status, author, built-in category, or ANY taxonomy via taxonomy+terms (e.g. taxonomy "portfolio_category", terms ["automotive"]). Unknown post types or taxonomies return an explicit error listing the valid options. Supports pagination (offset or page + per_page) and WPML language scoping. For long lists name the fields you need, e.g. "fields": ["id", "title", "_thumbnail_id"]: each item then has only those (meta keys under "meta"), a far smaller answer. Returns items, total and total_pages. Drafts, pending and private items of other authors are listed only for accounts that may edit them; anyone else gets their own (the result says so in "note").';
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
                'fields'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Item fields and meta keys to return. Naming an item field (' . implode( ', ', self::ITEM_FIELDS ) . ') cuts each item down to id and the fields named: the smallest answer. Any other name is a meta key, given under "meta" ("meta.<key>" is always one), e.g. ["title", "_thumbnail_id"]. Meta keys alone: id, title and "meta".' ],
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

        return $this->json_result( Query_Support::run( $args, $params, function ( \WP_Post $post, array $meta_keys, ?array $keep = null ): array {
            // Closures: worked out only for the fields kept.
            $item = [
                'id'            => $post->ID,
                'title'         => $post->post_title,
                'slug'          => $post->post_name,
                'status'        => $post->post_status,
                'date'          => $post->post_date,
                'modified'      => $post->post_modified,
                'author_id'     => (int) $post->post_author,
                'excerpt'       => static fn() => get_the_excerpt( $post ),
                'permalink'     => static fn() => get_permalink( $post->ID ),
                'comment_count' => (int) $post->comment_count,
            ];
            if ( null !== $keep ) {
                $item += self::named_extras( $post, $keep );
            }
            $item = Query_Support::with_wpml( $item, $post );
            if ( $meta_keys ) {
                $item = Query_Support::with_meta( $item, $post->ID, $meta_keys );
            }
            return $item;
        }, self::item_fields() ) );
    }

    /** ITEM_FIELDS, with WPML's language and trid when WPML is active. */
    public static function item_fields(): array {
        return Wpml_Support::active() ? array_merge( self::ITEM_FIELDS, [ 'language', 'trid' ] ) : self::ITEM_FIELDS;
    }

    /**
     * featured_image, categories and tags, the same as get_post gives them,
     * for the ones named.
     *
     * @param string[] $keep
     * @return array<string,\Closure>
     */
    public static function named_extras( \WP_Post $post, array $keep ): array {
        $extras = [
            'featured_image' => static fn() => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: null,
            'categories'     => static fn() => array_map( static fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], (array) wp_get_post_categories( $post->ID, [ 'fields' => 'all' ] ) ),
            'tags'           => static fn() => array_map( static fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], (array) wp_get_post_tags( $post->ID ) ),
        ];
        return array_intersect_key( $extras, array_flip( $keep ) );
    }
}
