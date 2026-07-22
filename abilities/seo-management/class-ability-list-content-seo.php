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
        $this->description  = 'List content with its SEO meta (meta description, SEO title, focus keyword), scoped by WPML language, paginated. Pass "ids" to look up specific posts only — e.g. the SEO of one page. Set missing_metadesc_only=true to return only items whose Yoast meta description is empty/missing. Reads Yoast meta; returns empty SEO values if Yoast is not installed.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'ids'                   => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Restrict to these post IDs (any post type). Use for single-item SEO lookups; other filters still apply.' ],
                'post_type'             => [ 'type' => 'string',  'description' => "Post type slug, or 'any'. Ignored when \"ids\" is given.", 'default' => 'page' ],
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
        if ( isset( $params['ids'] ) ) {
            $ids = array_values( array_filter( array_map( 'absint', (array) $params['ids'] ) ) );
            if ( ! $ids ) {
                return $this->error( '"ids" was provided but contained no valid post IDs.' );
            }
            $args['post__in']      = $ids;
            $args['post_type']     = 'any';
            $args['post_status']   = 'any';
            $args['orderby']       = 'post__in';
            $args['ignore_sticky_posts'] = true;
            unset( $args['order'] );
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

        return $this->json_result( Query_Support::run( $args, $params, function ( \WP_Post $post ): array {
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
        } ) );
    }
}
