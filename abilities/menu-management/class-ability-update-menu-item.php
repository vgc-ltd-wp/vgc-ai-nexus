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
                'url'       => [ 'type' => 'string',  'description' => 'New URL (for custom-type items).' ],
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

        // Build args from only the fields provided.
        $item_args = [];

        if ( isset( $params['title'] ) ) {
            $item_args['menu-item-title'] = sanitize_text_field( $params['title'] );
        }
        if ( isset( $params['url'] ) ) {
            $item_args['menu-item-url'] = esc_url_raw( $params['url'] );
        }
        if ( isset( $params['parent_id'] ) ) {
            $item_args['menu-item-parent-id'] = absint( $params['parent_id'] );
        }
        if ( isset( $params['position'] ) ) {
            $item_args['menu-item-position'] = absint( $params['position'] );
        }
        if ( isset( $params['target'] ) ) {
            $item_args['menu-item-target'] = sanitize_key( $params['target'] );
        }
        if ( isset( $params['classes'] ) ) {
            $item_args['menu-item-classes'] = sanitize_text_field( $params['classes'] );
        }

        if ( empty( $item_args ) ) {
            return $this->error( 'No fields provided to update.' );
        }

        $result = wp_update_nav_menu_item( $menu_id, $item_id, $item_args );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "Menu item {$item_id} updated." );
    }
}
