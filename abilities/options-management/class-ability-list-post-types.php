<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Post_Types_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_post_types';
        $this->label        = __( 'List Post Types', 'mcp-abilities' );
        $this->description  = 'List all registered post types on the site — built-in (post, page, attachment) and custom (e.g. product, avada_portfolio, tribe_events). Returns the name, label, capabilities and feature support for each type. Use it to discover the exact slug before calling list_posts/create_post with a custom post_type. NOTE: these abilities run server-side and work with EVERY post type here that has an admin screen (has_admin_screen=true) — a type not being exposed via the WordPress REST API (rest_exposed=false) does NOT limit what these tools can read or write. Types without an admin screen (revisions, menu items, templates, WooCommerce variations and the like) are internal: the general post tools refuse them, and they have their own tools.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'public_only' => [ 'type' => 'boolean', 'description' => 'If true, only return publicly visible post types. Default: true.', 'default' => true ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $public_only = $params['public_only'] ?? true;

        $args  = $public_only ? [ 'public' => true ] : [];
        $types = get_post_types( $args, 'objects' );

        $result = [];
        foreach ( $types as $type ) {
            $result[] = [
                'name'             => $type->name,
                'label'            => $type->label,
                'singular_label'   => $type->labels->singular_name ?? $type->label,
                'public'           => $type->public,
                'hierarchical'     => $type->hierarchical,
                'has_archive'      => (bool) $type->has_archive,
                'supports_editor'  => post_type_supports( $type->name, 'editor' ),
                'supports_title'   => post_type_supports( $type->name, 'title' ),
                'supports_author'  => post_type_supports( $type->name, 'author' ),
                'supports_revisions' => post_type_supports( $type->name, 'revisions' ),
                'rest_base'        => $type->rest_base ?? $type->name,
                'rest_exposed'     => (bool) ( $type->show_in_rest ?? false ),
                'has_admin_screen' => (bool) $type->show_ui,
                'cap_create'       => $type->cap->create_posts ?? 'edit_posts',
            ];
        }

        return $this->json_result( [
            'total'      => count( $result ),
            'post_types' => $result,
            'note'       => 'rest_base / rest_exposed are informational ONLY. These abilities run server-side (WP_Query, wp_insert_post) and work with EVERY post type listed here that has an admin screen (has_admin_screen=true), including ones with rest_exposed=false such as avada_portfolio, fusion_template or tribe_events. A post type not being exposed via the REST API never prevents listing, reading, creating or updating it here. A type with has_admin_screen=false is internal to WordPress or a plugin: the general post tools refuse it.',
        ] );
    }
}
