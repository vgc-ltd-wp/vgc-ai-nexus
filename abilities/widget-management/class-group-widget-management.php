<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Widget_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'widget-management';
        $this->label       = __( 'Widget Management', 'mcp-abilities' );
        $this->description = __( 'List widget areas (sidebars), inspect, add, update and remove widgets.', 'mcp-abilities' );
        $this->icon        = 'dashicons-welcome-widgets-menus';
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-widgets.php';
        foreach ( [
            'MCP_Abilities\\Abilities\\List_Widget_Areas_Ability',
            'MCP_Abilities\\Abilities\\List_Widgets_Ability',
            'MCP_Abilities\\Abilities\\Add_Widget_Ability',
            'MCP_Abilities\\Abilities\\Update_Widget_Ability',
            'MCP_Abilities\\Abilities\\Remove_Widget_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
