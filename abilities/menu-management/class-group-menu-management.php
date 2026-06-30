<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Menu_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'menu-management';
        $this->label       = __( 'Menu Management', 'mcp-abilities' );
        $this->description = __( 'Create, update and delete navigation menus, menu items, and theme location assignments.', 'mcp-abilities' );
        $this->icon        = 'dashicons-menu';
        $this->guide       = __( "Build and reorganise navigation menus: create menus, add or remove items (pages, custom links), reorder them, and assign menus to theme locations.", 'mcp-abilities' );
        $this->examples    = [
            __( "Add my new Returns page to the main menu", 'mcp-abilities' ),
            __( "Create a footer menu with Privacy, Terms and Contact", 'mcp-abilities' ),
            __( "Assign the Primary menu to the header location", 'mcp-abilities' ),
        ];
    }
}
