<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Template_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'template-management';
        $this->label       = __( 'Template Management', 'mcp-abilities' );
        $this->description = __( 'List, read, create and edit Full Site Editing templates (wp_template) and template parts (wp_template_part). Block themes only.', 'mcp-abilities' );
        $this->icon        = 'dashicons-layout';
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-templates.php';
        require_once __DIR__ . '/class-ability-template-parts.php';

        foreach ( [
            'MCP_Abilities\\Abilities\\List_Templates_Ability',
            'MCP_Abilities\\Abilities\\Get_Template_Ability',
            'MCP_Abilities\\Abilities\\Update_Template_Ability',
            'MCP_Abilities\\Abilities\\Create_Template_Ability',
            'MCP_Abilities\\Abilities\\List_Template_Parts_Ability',
            'MCP_Abilities\\Abilities\\Get_Template_Part_Ability',
            'MCP_Abilities\\Abilities\\Update_Template_Part_Ability',
            'MCP_Abilities\\Abilities\\Create_Template_Part_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
