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
            $menu = is_numeric( $params['id'] ) ? absint( $params['id'] ) : 0;
        } elseif ( ! empty( $params['slug'] ) ) {
            $menu = sanitize_title( $params['slug'] );
        } else {
            return $this->error( 'Provide id or slug.' );
        }

        $menu_obj = wp_get_nav_menu_object( $menu );
        if ( ! $menu_obj ) {
            return $this->error( 'Menu not found. list_menus lists the menus.' );
        }
        $items = (array) wp_get_nav_menu_items( $menu_obj->term_id );

        // Items whose page, post or term is gone or in the trash (2.52.0): outside
        // wp-admin WordPress leaves them out of the list, so a broken link in a
        // menu could not be seen. They are listed with "invalid": true.
        $shown   = array_map( static fn( $i ) => (int) $i->ID, $items );
        $invalid = [];
        foreach ( array_map( 'intval', (array) get_objects_in_term( $menu_obj->term_id, 'nav_menu' ) ) as $item_id ) {
            if ( in_array( $item_id, $shown, true ) ) {
                continue;
            }
            $p = get_post( $item_id );
            if ( ! $p || 'nav_menu_item' !== $p->post_type || 'publish' !== $p->post_status ) {
                continue;
            }
            $broken           = wp_setup_nav_menu_item( $p );
            $broken->_invalid = true;
            $items[]          = $broken;
            $invalid[]        = $item_id;
        }
        usort( $items, static fn( $a, $b ) => (int) $a->menu_order <=> (int) $b->menu_order );

        if ( ! $items ) {
            return $this->json_result( [ 'items' => [], 'note' => 'The menu has no items.' ] );
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
        ] + ( ! empty( $item->_invalid ) ? [ 'invalid' => true ] : [] ), $items );

        $out = [ 'items' => $result ];
        if ( $invalid ) {
            $out['note'] = sprintf( '%d item(s) (%s) link to content that is gone or in the trash: WordPress hides them on the site. Point them elsewhere with update_menu_item, or remove them with delete_menu_item.', count( $invalid ), implode( ', ', $invalid ) );
        }
        return $this->json_result( $out );
    }
}
