<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Query_Support;
use MCP_Abilities\Wpml_Support;

defined( 'ABSPATH' ) || exit;

class List_Pages_Ability extends Ability {

    /** The fields "fields" may name (2.47.0); featured_image only when named. */
    public const ITEM_FIELDS = [ 'id', 'title', 'slug', 'status', 'parent_id', 'menu_order', 'permalink', 'template', 'featured_image' ];

    protected function define_meta(): void {
        $this->key          = 'list_pages';
        $this->label        = __( 'List Pages', 'mcp-abilities' );
        $this->description  = 'List WordPress pages with hierarchy info. Supports pagination (offset or page + per_page) and WPML language scoping. For long lists name the fields you need, e.g. "fields": ["id", "title", "parent_id"]: each item then has only those (meta keys under "meta"). Returns items, total and total_pages. Drafts, pending and private pages of other authors are listed only for accounts that may edit them; anyone else gets their own (the result says so in "note").';
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
                'fields'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Item fields and meta keys to return. Naming an item field (' . implode( ', ', self::ITEM_FIELDS ) . ') cuts each item down to id and the fields named: the smallest answer. Any other name is a meta key, given under "meta" ("meta.<key>" is always one), e.g. ["title", "_yoast_wpseo_metadesc"]. Meta keys alone: id, title and "meta".' ],
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

        return $this->json_result( Query_Support::run( $args, $params, function ( \WP_Post $page, array $meta_keys, ?array $keep = null ): array {
            // Closures: worked out only for the fields kept.
            $item = [
                'id'         => $page->ID,
                'title'      => $page->post_title,
                'slug'       => $page->post_name,
                'status'     => $page->post_status,
                'parent_id'  => (int) $page->post_parent,
                'menu_order' => (int) $page->menu_order,
                'permalink'  => static fn() => get_permalink( $page->ID ),
                'template'   => static fn() => get_page_template_slug( $page->ID ) ?: 'default',
            ];
            if ( null !== $keep && in_array( 'featured_image', $keep, true ) ) {
                $item['featured_image'] = static fn() => get_the_post_thumbnail_url( $page->ID, 'full' ) ?: null;
            }
            $item = Query_Support::with_wpml( $item, $page );
            if ( $meta_keys ) {
                $item = Query_Support::with_meta( $item, $page->ID, $meta_keys );
            }
            return $item;
        }, Wpml_Support::active() ? array_merge( self::ITEM_FIELDS, [ 'language', 'trid' ] ) : self::ITEM_FIELDS ) );
    }
}
