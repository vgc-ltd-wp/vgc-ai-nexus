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

        // Title slashed: wp_update_nav_menu_item() saves it with wp_insert_post(), which
        // unslashes, and compares it unslashed with the linked page's title (2.51.0).
        $item_args = [
            'menu-item-title'     => wp_slash( $title ),
            'menu-item-type'      => $type,
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => absint( $params['parent_id'] ?? 0 ),
        ];

        if ( ! empty( $params['url'] ) ) {
            $item_args['menu-item-url'] = esc_url_raw( $params['url'] );
        }
        // A custom link needs an address WordPress keeps (2.52.0): esc_url_raw()
        // turns one it does not allow ("javascript:…", an unknown scheme) into "",
        // and an item with no address at all was made just the same.
        if ( 'custom' === $type && empty( $item_args['menu-item-url'] ) ) {
            return $this->error( ! empty( $params['url'] )
                ? sprintf( '"%s" is not an address WordPress allows in a menu, so no item was added. Send an http(s) address, a path such as "/contact", or "#" for an item without a link.', sanitize_text_field( (string) $params['url'] ) )
                : 'A custom link needs "url" (an http(s) address, a path such as "/contact", or "#" for an item without a link). No item was added.' );
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
            // The linked item must exist (2.52.0): an item for a post or term that is
            // not there was made, but WordPress hides it on the site and in
            // get_menu_items, so it was invisible everywhere.
            $object_error = self::object_error( $type, $item_args['menu-item-object'], (int) $item_args['menu-item-object-id'] );
            if ( null !== $object_error ) {
                return $this->error( $object_error . ' No item was added.' );
            }
        }

        $item_id = wp_update_nav_menu_item( $menu_id, 0, $item_args );

        if ( is_wp_error( $item_id ) ) {
            return $this->error( $item_id->get_error_message() );
        }

        return $this->json_result( [ 'id' => (int) $item_id, 'item_id' => $item_id, 'menu_id' => $menu_id ] );
    }

    /**
     * Why a post_type or taxonomy menu item cannot link to this object, or null.
     * Shared with update_menu_item.
     */
    public static function object_error( string $type, string $object, int $object_id ): ?string {
        if ( 'post_type' === $type ) {
            if ( ! post_type_exists( $object ) ) {
                return sprintf( 'Post type "%s" is not registered. Use the type of the item to link, e.g. "page" or "post" (list_post_types lists them).', $object );
            }
            $post = get_post( $object_id );
            if ( ! $post ) {
                return sprintf( 'There is no item %d to link to.', $object_id );
            }
            if ( $post->post_type !== $object ) {
                return sprintf( 'Item %d is a "%s", not a "%s": send object "%s".', $object_id, $post->post_type, $object, $post->post_type );
            }
            if ( 'trash' === $post->post_status ) {
                return sprintf( 'Item %d is in the trash; WordPress hides menu items that link to it.', $object_id );
            }
            return null;
        }
        if ( 'taxonomy' === $type ) {
            if ( ! taxonomy_exists( $object ) ) {
                return sprintf( 'Taxonomy "%s" is not registered. Use the taxonomy of the term to link, e.g. "category" or "post_tag".', $object );
            }
            $term = get_term( $object_id, $object );
            if ( ! $term instanceof \WP_Term ) {
                return sprintf( 'There is no term %d in the taxonomy "%s" (list_terms lists them).', $object_id, $object );
            }
        }
        return null;
    }
}
