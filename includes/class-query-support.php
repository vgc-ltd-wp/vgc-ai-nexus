<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared list-query helper for ability list endpoints.
 *
 * Centralises pagination (offset / page + per_page cap), WPML language scoping
 * and the standard response envelope so list_posts, list_pages and the SEO
 * audit action behave identically.
 */
final class Query_Support {

    /**
     * Run a paginated, optionally language-scoped WP_Query and format each post.
     *
     * The query is first limited to what the caller may see
     * (Post_Access::scope_list_query()); a refusal comes back as an error result.
     *
     * @param array<string,mixed> $args      Base WP_Query args (post_type, post_status, …).
     * @param array<string,mixed> $params    Raw ability input (per_page, page, offset, language, fields).
     * @param callable            $formatter fn( \WP_Post $post, string[] $meta_keys ): array
     * @return array{items:array,total:int,total_pages:int,page:int,per_page:int,offset:int,note?:string}|array{success:false,error:string}
     */
    public static function run( array $args, array $params, callable $formatter ): array {
        $scope = Post_Access::scope_list_query( $args );
        if ( null !== $scope['error'] ) {
            return [ 'success' => false, 'error' => $scope['error'] ];
        }

        $per_page = min( max( 1, absint( $params['per_page'] ?? 10 ) ), 100 );
        $args['posts_per_page'] = $per_page;

        $page = max( 1, absint( $params['page'] ?? 1 ) );
        if ( isset( $params['offset'] ) && '' !== $params['offset'] ) {
            $offset         = max( 0, absint( $params['offset'] ) );
            $args['offset'] = $offset;            // WP_Query: offset overrides paged
        } else {
            $offset        = ( $page - 1 ) * $per_page;
            $args['paged'] = $page;
        }

        $meta_keys = self::meta_keys( $params );

        // WPML language scoping (no-op when WPML is inactive or no language given).
        $language = isset( $params['language'] ) ? sanitize_text_field( (string) $params['language'] ) : '';
        $previous = '' !== $language ? Wpml_Support::switch_to( $language ) : null;

        $query = new \WP_Query( $args );
        $items = [];
        foreach ( $query->posts as $post ) {
            $items[] = $formatter( $post, $meta_keys );
        }
        $total       = (int) $query->found_posts;
        $total_pages = (int) ceil( $total / $per_page );

        Wpml_Support::restore( $previous );

        $out = [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => $page,
            'per_page'    => $per_page,
            'offset'      => $offset,
        ];
        if ( null !== $scope['note'] ) {
            $out['note'] = $scope['note'];
        }
        return $out;
    }

    /** Sanitised list of requested meta keys from the `fields` param. */
    public static function meta_keys( array $params ): array {
        if ( empty( $params['fields'] ) || ! is_array( $params['fields'] ) ) {
            return [];
        }
        return array_values( array_filter( array_map( 'sanitize_text_field', $params['fields'] ) ) );
    }

    /**
     * Build a meta map for the requested keys (single values). Keys the caller
     * may not read (Post_Access) are left out and listed in `$hidden`; a post
     * the caller may not read gives no meta at all.
     *
     * @param string[] $hidden Keys left out, appended in place.
     */
    public static function meta_block( int $post_id, array $meta_keys, array &$hidden = [] ): array {
        $post = get_post( $post_id );
        if ( ! $post || ! Post_Access::can_read( $post ) ) {
            $hidden = array_merge( $hidden, $meta_keys );
            return [];
        }
        $meta = [];
        foreach ( $meta_keys as $key ) {
            if ( ! Post_Access::can_use_meta_key( $post_id, $key ) ) {
                $hidden[] = $key;
                continue;
            }
            $meta[ $key ] = get_post_meta( $post_id, $key, true );
        }
        return $meta;
    }

    /**
     * Meta for a list item: the readable keys under `meta`, the withheld ones
     * under `meta_hidden` (only when there are any).
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function with_meta( array $item, int $post_id, array $meta_keys ): array {
        $hidden       = [];
        $item['meta'] = self::meta_block( $post_id, $meta_keys, $hidden );
        if ( $hidden ) {
            $item['meta_hidden'] = $hidden;
        }
        return $item;
    }

    /**
     * Add WPML language + trid to an item array when WPML is active.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function with_wpml( array $item, \WP_Post $post ): array {
        if ( ! Wpml_Support::active() ) {
            return $item;
        }
        $item['language'] = Wpml_Support::post_language( $post->ID );
        $item['trid']     = Wpml_Support::post_trid( $post->ID, $post->post_type );
        return $item;
    }
}
