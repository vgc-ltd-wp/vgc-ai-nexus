<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Get_Menu_Items_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_menu_items';
        $this->label        = __( 'Get Menu Items', 'mcp-abilities' );
        $this->description  = 'Get items of a navigation menu. Required: id (integer menu ID from list-menus) or slug (string). Example: {id: 19}';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'   => [ 'type' => 'integer', 'description' => 'Menu term ID.' ],
                'slug' => [ 'type' => 'string',  'description' => 'Menu slug (used if id is omitted).' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! empty( $params['id'] ) ) {
            $menu = $params['id'];
        } elseif ( ! empty( $params['slug'] ) ) {
            $menu = sanitize_title( $params['slug'] );
        } else {
            return $this->error( 'Provide id or slug.' );
        }

        $items = wp_get_nav_menu_items( $menu );
        if ( ! $items ) {
            return $this->error( 'Menu not found or has no items.' );
        }

        $result = array_map( fn( $item ) => [
            'id'        => $item->ID,
            'title'     => $item->title,
            'url'       => $item->url,
            'parent_id' => (int) $item->menu_item_parent,
            'order'     => (int) $item->menu_order,
            'type'      => $item->type,
            'object'    => $item->object,
            'object_id' => (int) $item->object_id,
            'target'    => $item->target,
            'classes'   => implode( ' ', (array) $item->classes ),
        ], $items );

        return $this->json_result( [ 'items' => $result ] );
    }
}
