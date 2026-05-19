<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Menus_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_menus';
        $this->label        = __( 'List Menus', 'mcp-abilities' );
        $this->description  = 'List all registered navigation menus and their theme locations.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [ 'type' => [ 'object', 'array' ] ];
    }

    public function execute( array $params ): array {
        $menus = wp_get_nav_menus();
        if ( is_wp_error( $menus ) || ! is_array( $menus ) ) {
            return $this->json_result( [ 'menus' => [] ] );
        }
        $locations = get_nav_menu_locations();
        $loc_names = get_registered_nav_menus();

        $result = array_map( function ( $menu ) use ( $locations, $loc_names ) {
            $assigned = [];
            foreach ( $locations as $loc => $menu_id ) {
                if ( $menu_id === $menu->term_id ) {
                    $assigned[] = [ 'location' => $loc, 'label' => $loc_names[ $loc ] ?? $loc ];
                }
            }
            return [
                'id'        => $menu->term_id,
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'count'     => $menu->count,
                'locations' => $assigned,
            ];
        }, $menus );

        return $this->json_result( [ 'menus' => $result ] );
    }
}
