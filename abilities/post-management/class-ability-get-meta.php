<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Post_Access;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk meta read: fetch specific meta keys for many posts/pages in one call,
 * eliminating one full get_post per item during audits.
 */
class Get_Meta_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_meta';
        $this->label        = __( 'Get Meta (bulk)', 'mcp-abilities' );
        $this->description  = 'Read meta for many posts/pages at once. Provide an array of IDs and (optionally) the meta keys to return. Collapses hundreds of per-item reads into a few calls. Items this account may not read come back as forbidden; protected keys (e.g. starting with "_") it may not edit are left out and listed in meta_hidden.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'ids'  => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Post/page IDs (max 100).' ],
                'keys' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ],  'description' => "Meta keys to return, e.g. ['_yoast_wpseo_metadesc']. Omit to return all meta." ],
            ],
            'required'   => [ 'ids' ],
        ];
    }

    public function execute( array $params ): array {
        $ids = array_values( array_unique( array_map( 'absint', (array) ( $params['ids'] ?? [] ) ) ) );
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) {
            return $this->error( 'Provide at least one post ID in "ids".' );
        }
        if ( count( $ids ) > 100 ) {
            return $this->error( 'Too many IDs (max 100). Paginate the request.' );
        }

        $keys = [];
        if ( ! empty( $params['keys'] ) && is_array( $params['keys'] ) ) {
            $keys = array_values( array_filter( array_map( 'sanitize_text_field', $params['keys'] ) ) );
        }

        $items = [];
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) {
                $items[] = [ 'id' => $id, 'missing' => true ];
                continue;
            }
            if ( ! Post_Access::can_read( $post ) ) {
                $items[] = [ 'id' => $id, 'forbidden' => true, 'error' => 'You do not have permission to read this item.' ];
                continue;
            }
            $hidden = [];
            if ( $keys ) {
                $meta = [];
                foreach ( $keys as $key ) {
                    if ( ! Post_Access::can_use_meta_key( $id, $key ) ) {
                        $hidden[] = $key;
                        continue;
                    }
                    $meta[ $key ] = get_post_meta( $id, $key, true );
                }
            } else {
                $meta = Post_Access::readable_meta( $id, (array) get_post_meta( $id ), $hidden );
            }
            $item = [ 'id' => $id, 'meta' => $meta ];
            if ( $hidden ) {
                $item['meta_hidden'] = $hidden;
            }
            $items[] = $item;
        }

        return $this->json_result( [ 'items' => $items ] );
    }
}
