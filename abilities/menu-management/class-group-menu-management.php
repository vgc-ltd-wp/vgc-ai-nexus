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
    }
}
