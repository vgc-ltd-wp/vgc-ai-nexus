<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Add_Menu_Item_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'add_menu_item';
        $this->label        = __( 'Add Menu Item', 'mcp-abilities' );
        $this->description  = 'Add an item to a navigation menu. Supports custom URLs, posts/pages (post_type), and taxonomy terms. Use get_menu_items to inspect the current menu first.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'menu_id'   => [ 'type' => 'integer', 'description' => 'Menu term ID (from list_menus).' ],
                'title'     => [ 'type' => 'string',  'description' => 'Label displayed in the menu.' ],
                'type'      => [
                    'type'        => 'string',
                    'enum'        => [ 'custom', 'post_type', 'taxonomy' ],
                    'default'     => 'custom',
                    'description' => '"custom" for a URL link; "post_type" for a post/page; "taxonomy" for a category/tag.',
                ],
                'url'       => [ 'type' => 'string',  'description' => 'URL for custom type items.' ],
                'object'    => [ 'type' => 'string',  'description' => 'Post type slug (e.g. "post", "page") or taxonomy slug (e.g. "category") for non-custom items.' ],
                'object_id' => [ 'type' => 'integer', 'description' => 'Post ID or term ID for post_type/taxonomy items.' ],
                'parent_id' => [ 'type' => 'integer', 'description' => 'Parent menu item ID for sub-menus (0 = top level).', 'default' => 0 ],
                'position'  => [ 'type' => 'integer', 'description' => 'Sort order within the menu.' ],
                'target'    => [ 'type' => 'string',  'enum' => [ '', '_blank' ], 'description' => 'Link target. Use "_blank" to open in a new tab.' ],
                'classes'   => [ 'type' => 'string',  'description' => 'Space-separated CSS classes.' ],
            ],
            'required' => [ 'menu_id', 'title' ],
        ];
    }

    public function execute( array $params ): array {
        $menu_id = absint( $params['menu_id'] );
        if ( ! wp_get_nav_menu_object( $menu_id ) ) {
            return $this->error( "Menu {$menu_id} not found." );
        }

        $type   = sanitize_key( $params['type'] ?? 'custom' );
        $title  = sanitize_text_field( $params['title'] );

        if ( '' === $title ) {
            return $this->error( 'Menu item title cannot be empty.' );
        }

        $item_args = [
            'menu-item-title'     => $title,
            'menu-item-type'      => $type,
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => absint( $params['parent_id'] ?? 0 ),
        ];

        if ( ! empty( $params['url'] ) ) {
            $item_args['menu-item-url'] = esc_url_raw( $params['url'] );
        }
        if ( ! empty( $params['object'] ) ) {
            $item_args['menu-item-object'] = sanitize_key( $params['object'] );
        }
        if ( ! empty( $params['object_id'] ) ) {
            $item_args['menu-item-object-id'] = absint( $params['object_id'] );
        }
        if ( isset( $params['position'] ) ) {
            $item_args['menu-item-position'] = absint( $params['position'] );
        }
        if ( ! empty( $params['target'] ) ) {
            $item_args['menu-item-target'] = sanitize_key( $params['target'] );
        }
        if ( ! empty( $params['classes'] ) ) {
            $item_args['menu-item-classes'] = sanitize_text_field( $params['classes'] );
        }

        // Validate non-custom items have required fields.
        if ( 'custom' !== $type ) {
            if ( empty( $item_args['menu-item-object'] ) ) {
                return $this->error( '"object" is required for post_type and taxonomy items (e.g. "page", "category").' );
            }
            if ( empty( $item_args['menu-item-object-id'] ) ) {
                return $this->error( '"object_id" is required for post_type and taxonomy items.' );
            }
        }

        $item_id = wp_update_nav_menu_item( $menu_id, 0, $item_args );

        if ( is_wp_error( $item_id ) ) {
            return $this->error( $item_id->get_error_message() );
        }

        return $this->json_result( [ 'item_id' => $item_id, 'menu_id' => $menu_id ] );
    }
}
