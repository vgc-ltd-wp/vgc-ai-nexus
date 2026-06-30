<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Template_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'template-management';
        $this->label       = __( 'Template Management', 'mcp-abilities' );
        $this->description = __( 'List, read, create and edit Full Site Editing templates, template parts, global styles and block patterns. Block themes only.', 'mcp-abilities' );
        $this->icon        = 'dashicons-layout';
        $this->guide       = __( "Edit your block (FSE) theme: read and modify site templates and template parts, adjust global styles (colours, typography), and manage block patterns. Block themes only.", 'mcp-abilities' );
        $this->examples    = [
            __( "Show me the header template part", 'mcp-abilities' ),
            __( "Change the global link colour to #6C63FF", 'mcp-abilities' ),
            __( "List all available templates in my theme", 'mcp-abilities' ),
        ];
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-templates.php';
        require_once __DIR__ . '/class-ability-template-parts.php';
        require_once __DIR__ . '/class-ability-global-styles.php';

        foreach ( [
            // wp_template
            'MCP_Abilities\\Abilities\\List_Templates_Ability',
            'MCP_Abilities\\Abilities\\Get_Template_Ability',
            'MCP_Abilities\\Abilities\\Update_Template_Ability',
            'MCP_Abilities\\Abilities\\Create_Template_Ability',
            'MCP_Abilities\\Abilities\\Delete_Template_Ability',
            // wp_template_part
            'MCP_Abilities\\Abilities\\List_Template_Parts_Ability',
            'MCP_Abilities\\Abilities\\Get_Template_Part_Ability',
            'MCP_Abilities\\Abilities\\Update_Template_Part_Ability',
            'MCP_Abilities\\Abilities\\Create_Template_Part_Ability',
            'MCP_Abilities\\Abilities\\Delete_Template_Part_Ability',
            'MCP_Abilities\\Abilities\\Set_Template_Part_Area_Ability',
            // Global styles & patterns
            'MCP_Abilities\\Abilities\\Get_Global_Styles_Ability',
            'MCP_Abilities\\Abilities\\Update_Global_Styles_Ability',
            'MCP_Abilities\\Abilities\\List_Block_Patterns_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
