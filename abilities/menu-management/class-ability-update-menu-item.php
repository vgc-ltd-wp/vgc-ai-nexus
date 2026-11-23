<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Update_Menu_Item_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_menu_item';
        $this->label        = __( 'Update Menu Item', 'mcp-abilities' );
        $this->description  = 'Update an existing navigation menu item. Use get_menu_items to find item IDs. Only the fields you provide will be changed.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'item_id'   => [ 'type' => 'integer', 'description' => 'Menu item post ID (from get_menu_items).' ],
                'menu_id'   => [ 'type' => 'integer', 'description' => 'Menu term ID the item belongs to.' ],
                'title'     => [ 'type' => 'string',  'description' => 'New label.' ],
                'url'       => [ 'type' => 'string',  'description' => 'New URL (custom links only; a page, post or term item keeps linking to it).' ],
                'parent_id' => [ 'type' => 'integer', 'description' => 'New parent item ID (0 to move to top level).' ],
                'position'  => [ 'type' => 'integer', 'description' => 'New sort order.' ],
                'target'    => [ 'type' => 'string',  'enum' => [ '', '_blank' ], 'description' => 'Link target.' ],
                'classes'   => [ 'type' => 'string',  'description' => 'Space-separated CSS classes.' ],
            ],
            'required' => [ 'item_id', 'menu_id' ],
        ];
    }

    public function execute( array $params ): array {
        $item_id = absint( $params['item_id'] );
        $menu_id = absint( $params['menu_id'] );

        $post = get_post( $item_id );
        if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
            return $this->error( "Menu item {$item_id} not found." );
        }
        if ( ! wp_get_nav_menu_object( $menu_id ) ) {
            return $this->error( "Menu {$menu_id} not found." );
        }

        // The fields sent.
        $sent = [];

        if ( isset( $params['title'] ) ) {
            // Slashed: saved with wp_update_post(), which unslashes (2.51.0).
            $sent['menu-item-title'] = wp_slash( sanitize_text_field( $params['title'] ) );
        }
        if ( isset( $params['url'] ) ) {
            $sent['menu-item-url'] = esc_url_raw( $params['url'] );
        }
        if ( isset( $params['parent_id'] ) ) {
            $sent['menu-item-parent-id'] = absint( $params['parent_id'] );
        }
        if ( isset( $params['position'] ) ) {
            $sent['menu-item-position'] = absint( $params['position'] );
        }
        if ( isset( $params['target'] ) ) {
            $sent['menu-item-target'] = sanitize_key( $params['target'] );
        }
        if ( isset( $params['classes'] ) ) {
            $sent['menu-item-classes'] = sanitize_text_field( $params['classes'] );
        }

        if ( empty( $sent ) ) {
            return $this->error( 'No fields provided to update.' );
        }

        // wp_update_nav_menu_item() does not read the item: every field it is not given
        // takes its default (a custom link with no URL, target, classes or parent, moved to
        // the end of the menu, published). So start from the item as stored, as WordPress's
        // own menu screen and REST API do, and lay the fields sent over it (2.51.1).
        $result = wp_update_nav_menu_item( $menu_id, $item_id, array_merge( self::stored_args( $post ), $sent ) );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        // Order 0 (the first item added to an empty menu) cannot be handed over: WordPress
        // reads it as "put it at the end". Put it back when the order was not sent.
        if ( ! isset( $sent['menu-item-position'] ) && 0 === (int) $post->menu_order ) {
            global $wpdb;
            $wpdb->update( $wpdb->posts, [ 'menu_order' => 0 ], [ 'ID' => $item_id ] );
            clean_post_cache( $item_id );
        }

        return $this->success( "Menu item {$item_id} updated." );
    }

    /**
     * The item's fields as wp_update_nav_menu_item() takes them, read raw from the post and
     * its meta (wp_setup_nav_menu_item() gives them filtered for display: a description cut
     * to 200 words without tags, a page item's title borrowed from the page). Title,
     * description and title attribute slashed: wp_update_post() unslashes them. A page or
     * term item's empty title stays empty, so it keeps following its page or term.
     */
    private static function stored_args( \WP_Post $post ): array {
        $id   = (int) $post->ID;
        $text = static function ( string $key ) use ( $id ): string {
            $v = get_post_meta( $id, $key, true );
            return is_scalar( $v ) ? (string) $v : '';
        };
        $type    = $text( '_menu_item_type' );
        $classes = array_filter( (array) get_post_meta( $id, '_menu_item_classes', true ), 'is_scalar' );

        return [
            'menu-item-db-id'       => $id,
            'menu-item-object-id'   => (int) $text( '_menu_item_object_id' ),
            'menu-item-object'      => $text( '_menu_item_object' ),
            'menu-item-parent-id'   => (int) $text( '_menu_item_menu_item_parent' ),
            'menu-item-position'    => (int) $post->menu_order,
            'menu-item-type'        => '' !== $type ? $type : 'custom',
            'menu-item-title'       => wp_slash( (string) $post->post_title ),
            'menu-item-url'         => $text( '_menu_item_url' ),
            'menu-item-description' => wp_slash( (string) $post->post_content ),
            'menu-item-attr-title'  => wp_slash( (string) $post->post_excerpt ),
            'menu-item-target'      => $text( '_menu_item_target' ),
            'menu-item-classes'     => implode( ' ', $classes ),
            'menu-item-xfn'         => $text( '_menu_item_xfn' ),
            'menu-item-status'      => (string) $post->post_status,
        ];
    }
}
