<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── Get Theme Mods ─────────────────────────────────────────────────────────────

class Get_Theme_Mods_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_theme_mods';
        $this->label        = __( 'Get Theme Mods', 'mcp-abilities' );
        $this->description  = 'Retrieve all Customizer (theme_mods) settings for the currently active theme. Returns key/value pairs that can be updated with update_theme_mod.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [],
        ];
    }

    public function execute( array $params ): array {
        $theme  = get_option( 'stylesheet' );
        $mods   = get_theme_mods();

        if ( empty( $mods ) ) {
            $mods = new \stdClass(); // Return {} instead of [] for JSON clarity.
        }

        return $this->json_result( [
            'theme' => $theme,
            'mods'  => $mods,
        ] );
    }
}

// ── Update Theme Mod ───────────────────────────────────────────────────────────

class Update_Theme_Mod_Ability extends Ability {

    /**
     * Keys that must never be modified via MCP because they are structural
     * and could break the site or expose internal paths.
     */
    private const DENYLIST = [
        'nav_menu_locations',  // menu assignments — complex objects, use menu tools
        'custom_css_post_id',  // managed by WP core, not a user setting
        'background_image_thumb', // derived value, set via media uploader
        'sidebars_widgets',    // widget layout — not a theme_mod but sometimes stored here
    ];

    protected function define_meta(): void {
        $this->key          = 'update_theme_mod';
        $this->label        = __( 'Update Theme Mod', 'mcp-abilities' );
        $this->description  = 'Update a single Customizer (theme_mods) setting for the active theme. Use get_theme_mods first to discover available keys.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'key'   => [
                    'type'        => 'string',
                    'description' => 'The theme_mod key to update (e.g. "blogname", "header_textcolor", "background_color"). Use get_theme_mods to list available keys.',
                ],
                'value' => [
                    'type'        => [ 'string', 'number', 'boolean', 'null' ],
                    'description' => 'New value for the theme mod. Strings are sanitised; booleans and numbers are accepted as-is.',
                ],
            ],
            'required' => [ 'key', 'value' ],
        ];
    }

    public function execute( array $params ): array {
        $key = sanitize_key( $params['key'] );

        if ( '' === $key ) {
            return $this->error( 'The "key" parameter must not be empty.' );
        }

        if ( in_array( $key, self::DENYLIST, true ) ) {
            return $this->error( "The theme mod \"{$key}\" cannot be updated through this tool." );
        }

        $value = $params['value'] ?? null;

        // Sanitise string values; pass numeric/boolean through unchanged.
        if ( is_string( $value ) ) {
            // Allow hex colours (#rrggbb / #rgb), simple slugs, short text.
            // Use wp_kses_post only if the string looks like HTML, otherwise strip.
            if ( str_contains( $value, '<' ) ) {
                $value = wp_kses_post( $value );
            } else {
                $value = sanitize_text_field( $value );
            }
        }

        set_theme_mod( $key, $value );

        return $this->success( "Theme mod \"{$key}\" updated successfully." );
    }
}
