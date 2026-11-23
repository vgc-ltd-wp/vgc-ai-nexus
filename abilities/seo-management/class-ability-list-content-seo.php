<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Query_Support;

defined( 'ABSPATH' ) || exit;

/**
 * One-query WPML- and Yoast-aware SEO audit.
 *
 * Example: "all published German pages missing a meta description" becomes a
 * single call: { post_type:"page", language:"de", missing_metadesc_only:true }.
 */
class List_Content_Seo_Ability extends Ability {

    private const META_DESC  = '_yoast_wpseo_metadesc';
    private const META_TITLE = '_yoast_wpseo_title';
    private const META_FOCUS = '_yoast_wpseo_focuskw';

    protected function define_meta(): void {
        $this->key          = 'list_content_seo';
        $this->label        = __( 'List Content SEO', 'mcp-abilities' );
        $this->description  = 'List content with its SEO meta (meta description, SEO title, focus keyword), scoped by WPML language, paginated. Works with ANY registered post type (post_type), including ones not exposed via the REST API. Filter by taxonomy + terms (e.g. taxonomy "portfolio_category", terms ["automotive"]), or pass "ids" to look up specific posts only — e.g. the SEO of one page. Set missing_metadesc_only=true to return only items whose Yoast meta description is empty/missing. Reads Yoast meta; returns empty SEO values if Yoast is not installed.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'ids'                   => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Restrict to these post IDs (any post type, any status unless "status" is sent). Use for single-item SEO lookups; other filters still apply. IDs not listed are named in "ids_not_listed".' ],
                'post_type'             => [ 'type' => 'string',  'description' => "Post type slug, or 'any'. Ignored when \"ids\" is given.", 'default' => 'page' ],
                'taxonomy'              => [ 'type' => 'string',  'description' => 'Taxonomy slug to filter by, e.g. "portfolio_category", "product_cat", "category". Use with "terms".' ],
                'terms'                 => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Term slugs (or numeric IDs as strings) within "taxonomy". Items matching ANY listed term are returned.' ],
                'language'              => [ 'type' => 'string',  'description' => "WPML language code (e.g. 'de'), or 'all'. Defaults to the current language. Ignored if WPML is inactive." ],
                'status'                => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ], 'default' => 'publish' ],
                'offset'                => [ 'type' => 'integer', 'minimum' => 0 ],
                'page'                  => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'per_page'              => [ 'type' => 'integer', 'default' => 100, 'maximum' => 100 ],
                'missing_metadesc_only' => [ 'type' => 'boolean', 'description' => 'Return only items whose Yoast meta description is empty or missing.', 'default' => false ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $post_type = sanitize_text_field( $params['post_type'] ?? 'page' );
        if ( 'any' !== $post_type && ! post_type_exists( $post_type ) ) {
            return $this->error( "Post type \"{$post_type}\" is not registered." );
        }

        $args = [
            'post_type'   => $post_type,
            'post_status' => sanitize_text_field( $params['status'] ?? 'publish' ),
            'orderby'     => 'title',
            'order'       => 'ASC',
        ];

        // Explicit ID lookup: the common "SEO of this one page" case. Post type is
        // widened to 'any' so callers don't have to know the item's type, and the
        // status filter is relaxed so an explicitly requested draft still returns.
        // Query_Support::run() keeps only the IDs this account may read, and
        // without "ids" it limits unpublished items to what the caller may list.
        $ids = [];
        if ( isset( $params['ids'] ) ) {
            $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $params['ids'] ) ) ) );
            if ( ! $ids ) {
                return $this->error( '"ids" was provided but contained no valid post IDs.' );
            }
            // The IDs' own types and statuses (2.52.0): "any" left out types kept
            // out of search (reusable blocks, Elementor templates) and trashed
            // items, so requested IDs vanished. A status that was sent applies.
            $types    = [];
            $statuses = [];
            foreach ( $ids as $id ) {
                $p = get_post( $id );
                if ( $p ) {
                    $types[]    = $p->post_type;
                    $statuses[] = $p->post_status;
                }
            }
            $args['post__in']      = $ids;
            $args['post_type']     = $types ? array_values( array_unique( $types ) ) : 'any';
            $args['post_status']   = isset( $params['status'] ) && '' !== $params['status'] && 'any' !== $params['status']
                ? sanitize_key( (string) $params['status'] )
                : ( $statuses ? array_values( array_unique( $statuses ) ) : 'any' );
            $args['orderby']       = 'post__in';
            $args['ignore_sticky_posts'] = true;
            unset( $args['order'] );
        }

        // Taxonomy filter (any taxonomy, e.g. portfolio_category) — mirrors list_posts
        // so "the SEO of the Automotive portfolio items" is one call. Terms are
        // resolved first; unknown ones are refused or named (2.52.0).
        $taxonomy = isset( $params['taxonomy'] ) ? sanitize_key( (string) $params['taxonomy'] ) : '';
        $terms    = isset( $params['terms'] ) && is_array( $params['terms'] ) ? array_values( array_filter( array_map( 'strval', $params['terms'] ), 'strlen' ) ) : [];
        $tax_note = null;
        if ( '' !== $taxonomy || [] !== $terms ) {
            if ( '' === $taxonomy || [] === $terms ) {
                return $this->error( 'Provide BOTH "taxonomy" and "terms" to filter by a taxonomy.' );
            }
            $tax = Query_Support::tax_filter( $taxonomy, $terms, $ids ? 'any' : $post_type );
            if ( null !== $tax['error'] ) {
                return $this->error( $tax['error'] );
            }
            $args['tax_query'] = $tax['tax_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            $tax_note          = $tax['note'];
        }

        // Server-side filter: meta description empty OR not set. Keeps pagination
        // totals accurate (filtering happens in the query, not after).
        if ( ! empty( $params['missing_metadesc_only'] ) ) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [ 'key' => self::META_DESC, 'compare' => 'NOT EXISTS' ],
                [ 'key' => self::META_DESC, 'value' => '', 'compare' => '=' ],
            ];
        }

        // per_page defaults to 100 here.
        if ( ! isset( $params['per_page'] ) ) {
            $params['per_page'] = 100;
        }

        $result = Query_Support::run( $args, $params, function ( \WP_Post $post ): array {
            $metadesc = (string) get_post_meta( $post->ID, self::META_DESC, true );
            $item = [
                'id'        => $post->ID,
                'title'     => $post->post_title,
                'type'      => $post->post_type,
                'status'    => $post->post_status,
                'permalink' => get_permalink( $post->ID ),
                'seo'       => [
                    'metadesc'        => $metadesc,
                    'title'           => (string) get_post_meta( $post->ID, self::META_TITLE, true ),
                    'focus_kw'        => (string) get_post_meta( $post->ID, self::META_FOCUS, true ),
                    'metadesc_length' => function_exists( 'mb_strlen' ) ? mb_strlen( $metadesc ) : strlen( $metadesc ),
                ],
            ];
            return Query_Support::with_wpml( $item, $post );
        } );
        if ( isset( $result['success'] ) ) {
            return $this->json_result( $result );
        }
        $result = self::with_note( $result, $tax_note );
        // Requested IDs that are not in the list, named (2.52.0).
        if ( $ids && count( $ids ) <= (int) $result['per_page'] && 0 === (int) $result['offset'] ) {
            $missing = array_values( array_diff( $ids, array_map( static fn( $i ) => (int) $i['id'], (array) $result['items'] ) ) );
            if ( $missing ) {
                $result['ids_not_listed'] = $missing;
                $result = self::with_note( $result, sprintf( 'Not in this list: %s (they do not exist, this account may not read them, or the other filters leave them out).', implode( ', ', $missing ) ) );
            }
        }
        // Without Yoast there is no SEO meta to read (2.52.0).
        if ( ! defined( 'WPSEO_VERSION' ) ) {
            $result = self::with_note( $result, ! empty( $params['missing_metadesc_only'] )
                ? 'Yoast SEO is not active on this site, so there are no Yoast meta descriptions: every item counts as missing one, and the SEO values are empty.'
                : 'Yoast SEO is not active on this site, so the SEO values (Yoast meta) are empty.' );
        }
        return $this->json_result( $result );
    }
}
