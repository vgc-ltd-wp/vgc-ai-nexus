<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Create_Menu_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_menu';
        $this->label        = __( 'Create Menu', 'mcp-abilities' );
        $this->description  = 'Create a new WordPress navigation menu. Use assign_menu_location to attach it to a theme location after creation.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name' => [ 'type' => 'string', 'description' => 'Menu name (displayed in admin).' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    public function execute( array $params ): array {
        $name = sanitize_text_field( $params['name'] ?? '' );
        if ( '' === $name ) {
            return $this->error( 'Menu name cannot be empty.' );
        }

        $menu_id = wp_create_nav_menu( $name );

        if ( is_wp_error( $menu_id ) ) {
            return $this->error( $menu_id->get_error_message() );
        }

        return $this->json_result( [ 'id' => $menu_id, 'name' => $name ] );
    }
}
