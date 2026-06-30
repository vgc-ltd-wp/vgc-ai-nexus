<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Theme_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'theme-management';
        $this->label       = __( 'Theme Management', 'mcp-abilities' );
        $this->description = __( 'Read and update active theme customizer (theme_mods) settings.', 'mcp-abilities' );
        $this->icon        = 'dashicons-admin-appearance';
        $this->guide       = __( "Read and tweak the active theme's Customizer settings (theme mods) - logo, colours and other options exposed through the Customizer.", 'mcp-abilities' );
        $this->examples    = [
            __( "What theme mods are currently set?", 'mcp-abilities' ),
            __( "Update the custom logo setting", 'mcp-abilities' ),
            __( "Show the current header background colour", 'mcp-abilities' ),
        ];
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-theme-mods.php';
        foreach ( [
            'MCP_Abilities\\Abilities\\Get_Theme_Mods_Ability',
            'MCP_Abilities\\Abilities\\Update_Theme_Mod_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
