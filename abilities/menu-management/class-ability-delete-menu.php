<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Delete_Menu_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_menu';
        $this->label        = __( 'Delete Menu', 'mcp-abilities' );
        $this->description  = 'Delete a navigation menu and all its items. This is permanent.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'Menu term ID (from list_menus).' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $menu_id = absint( $params['id'] );
        $menu    = wp_get_nav_menu_object( $menu_id );

        if ( ! $menu ) {
            return $this->error( "Menu {$menu_id} not found." );
        }

        $result = wp_delete_nav_menu( $menu_id );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "Menu \"{$menu->name}\" deleted." );
    }
}
