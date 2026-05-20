<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Delete_Menu_Item_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_menu_item';
        $this->label        = __( 'Delete Menu Item', 'mcp-abilities' );
        $this->description  = 'Remove an item from a navigation menu. Use get_menu_items to find item IDs.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'item_id' => [ 'type' => 'integer', 'description' => 'Menu item post ID (from get_menu_items).' ],
            ],
            'required' => [ 'item_id' ],
        ];
    }

    public function execute( array $params ): array {
        $item_id = absint( $params['item_id'] );

        $post = get_post( $item_id );
        if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
            return $this->error( "Menu item {$item_id} not found." );
        }

        $result = wp_delete_post( $item_id, true );

        if ( ! $result ) {
            return $this->error( "Failed to delete menu item {$item_id}." );
        }

        return $this->success( "Menu item {$item_id} deleted." );
    }
}
