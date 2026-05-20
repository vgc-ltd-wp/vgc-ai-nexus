<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Assign_Menu_Location_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'assign_menu_location';
        $this->label        = __( 'Assign Menu Location', 'mcp-abilities' );
        $this->description  = 'Assign a navigation menu to a theme location, or unassign it. Use list_menus to see registered locations and their current menu assignments.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'location' => [ 'type' => 'string',  'description' => 'Theme location slug (e.g. "primary", "footer"). Use list_menus to see available locations.' ],
                'menu_id'  => [ 'type' => 'integer', 'description' => 'Menu term ID to assign. Pass 0 to unassign the location.' ],
            ],
            'required' => [ 'location', 'menu_id' ],
        ];
    }

    public function execute( array $params ): array {
        $location = sanitize_key( $params['location'] );
        $menu_id  = absint( $params['menu_id'] );

        if ( '' === $location ) {
            return $this->error( 'Location cannot be empty.' );
        }

        // Validate the location is registered by the active theme.
        $registered = get_registered_nav_menus();
        if ( ! array_key_exists( $location, $registered ) ) {
            $available = implode( ', ', array_keys( $registered ) );
            return $this->error(
                empty( $registered )
                    ? 'The active theme does not register any menu locations.'
                    : "Location \"{$location}\" is not registered. Available locations: {$available}."
            );
        }

        // Validate the menu exists (unless unassigning).
        if ( $menu_id > 0 && ! wp_get_nav_menu_object( $menu_id ) ) {
            return $this->error( "Menu {$menu_id} not found." );
        }

        $locations             = get_nav_menu_locations();
        $locations[ $location ] = $menu_id;
        set_theme_mod( 'nav_menu_locations', $locations );

        if ( 0 === $menu_id ) {
            return $this->success( "Location \"{$location}\" unassigned." );
        }

        $menu = wp_get_nav_menu_object( $menu_id );
        return $this->success( "Menu \"{$menu->name}\" assigned to location \"{$location}\"." );
    }
}
