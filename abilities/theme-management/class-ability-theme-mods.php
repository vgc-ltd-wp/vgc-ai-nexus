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

        // Sanitise string values; pass numeric/boolean through unchanged. What the
        // cleaning changed is said in the answer (2.52.0): a URL with %20 or a
        // percent-encoded Cyrillic path lost those characters, line breaks went,
        // and removed markup was not mentioned.
        $note = null;
        if ( is_string( $value ) ) {
            $sent = $value;
            if ( str_contains( $value, '<' ) ) {
                $value = wp_kses_post( $value );
                if ( \MCP_Abilities\Custom_Code::filtered_away( $sent, $value ) ) {
                    $note = 'Markup WordPress does not allow in posts (script, iframe, style and the like) was removed.';
                }
            } elseif ( preg_match( '#^(https?:)?//\S+$#i', trim( $value ) ) ) {
                // An address: cleaned as one, which keeps %20 and %D0%B0.
                $value = esc_url_raw( trim( $value ) );
                if ( '' === $value ) {
                    return $this->error( "The theme mod \"{$key}\" was not changed: \"" . sanitize_text_field( $sent ) . '" is not an address WordPress accepts.' );
                }
            } else {
                // Plain text: line breaks kept (sanitize_text_field() joined the lines).
                $value = sanitize_textarea_field( $value );
                if ( $value !== trim( $sent ) ) {
                    $note = preg_match( '/%[0-9a-f]{2}/i', $sent ) && ! preg_match( '/%[0-9a-f]{2}/i', $value )
                        ? 'WordPress removes percent-encoded characters (such as %20) from plain text, so they were taken out; send the characters themselves (a space, the letters) instead.'
                        : 'WordPress cleaned the text (tags and stray characters removed).';
                }
            }
        }

        set_theme_mod( $key, $value );

        $extra = [];
        if ( null !== $note ) {
            $extra['stored_value'] = get_theme_mod( $key );
            $extra['note']         = $note . ' "stored_value" is what was saved.';
        }
        return $this->success( "Theme mod \"{$key}\" updated successfully.", $extra );
    }
}
