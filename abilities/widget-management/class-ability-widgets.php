<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── Widget types ───────────────────────────────────────────────────────────────

final class Widget_Types {

    /** How settings are stored (2.50.0); appended to the "settings" descriptions. */
    public const SETTINGS_DESCRIPTION = 'HTML is kept only in a custom_html widget\'s "content", a text widget\'s "text" and a block widget\'s "content": as sent (script included) for an account that may post unfiltered HTML, such as an administrator; otherwise, or while raw code is locked on the site, filtered as WordPress filters posts. Every other text setting is plain text. The result has a "note" when something sent was removed.';

    /** Why this is not a widget type on this site, or null when it is. */
    public static function error( string $type ): ?string {
        global $wp_registered_widget_updates, $wp_widget_factory;

        $registered = array_keys( (array) $wp_registered_widget_updates );
        if ( $wp_widget_factory instanceof \WP_Widget_Factory ) {
            foreach ( $wp_widget_factory->widgets as $widget ) {
                $registered[] = (string) $widget->id_base;
            }
        }
        $registered = array_values( array_unique( $registered ) );
        if ( in_array( $type, $registered, true ) ) {
            return null;
        }
        sort( $registered );
        return sprintf( 'Widget type "%s" is not registered on this site. Registered types: %s.', $type, implode( ', ', $registered ) );
    }

    /**
     * The settings that hold HTML, per widget type (2.50.0): the ones WordPress's
     * own widgets keep as HTML in their update(). Custom HTML "content", Text
     * "text" and Block "content". The 'mcp_abilities_widget_html_fields' filter
     * can name a third-party widget's HTML settings (type => [ setting, … ]).
     *
     * @return array<string,string[]>
     */
    public static function html_fields(): array {
        $fields = apply_filters( 'mcp_abilities_widget_html_fields', [
            'custom_html' => [ 'content' ],
            'text'        => [ 'text' ],
            'block'       => [ 'content' ],
        ] );
        return is_array( $fields ) ? $fields : [];
    }

    /**
     * Widget settings as they may be stored, and a note when something sent was
     * taken out (2.50.0).
     *
     * An HTML setting (html_fields()) is kept as sent for an account that may post
     * unfiltered HTML, unless raw code is locked; otherwise it is filtered as
     * WordPress filters a post, as WordPress's own widgets do. Every other text
     * setting stays plain text (sanitize_textarea_field(), as before 2.50.0); numbers
     * and true/false are kept; nested lists are cleaned the same way, as plain text.
     * Before 2.50.0 every text setting was plain text, so a Custom HTML or Text
     * widget lost all its markup, and the answer said nothing.
     *
     * @return array{0:array,1:?string} [ settings, note or null ]
     */
    public static function clean_settings( string $type, array $settings ): array {
        $html     = array_map( 'strval', (array) ( self::html_fields()[ $type ] ?? [] ) );
        $filtered = false;
        $plain    = [];
        $clean    = self::clean_level( $settings, $html, '', $filtered, $plain );

        $notes = [];
        if ( $filtered ) {
            $notes[] = \MCP_Abilities\Custom_Code::filtered_note();
        }
        if ( $plain ) {
            $notes[] = sprintf(
                'Plain text only, so the HTML tags were removed from: "%s". %s',
                implode( '", "', $plain ),
                $html
                    ? sprintf( 'In a "%s" widget only "%s" keeps HTML.', $type, implode( '", "', $html ) )
                    : 'HTML is kept only in a custom_html widget\'s "content", a text widget\'s "text" and a block widget\'s "content".'
            );
        }
        return [ $clean, $notes ? implode( ' ', $notes ) : null ];
    }

    /**
     * @param string[] $html     HTML setting names (top level only).
     * @param string[] $plain    Plain-text settings that lost tags (dotted paths), filled in.
     */
    private static function clean_level( array $settings, array $html, string $path, bool &$filtered, array &$plain ): array {
        $clean = [];
        foreach ( $settings as $key => $value ) {
            $key  = sanitize_key( (string) $key );
            $name = '' === $path ? $key : $path . '.' . $key;
            if ( is_string( $value ) ) {
                if ( '' === $path && in_array( $key, $html, true ) ) {
                    [ $clean[ $key ], $note ] = \MCP_Abilities\Custom_Code::filter_with_note( $value );
                    $filtered = $filtered || null !== $note;
                } else {
                    $clean[ $key ] = sanitize_textarea_field( $value );
                    if ( \MCP_Abilities\Custom_Code::filtered_away( $value, $clean[ $key ] ) ) {
                        $plain[] = $name;
                    }
                }
            } elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
                $clean[ $key ] = $value;
            } elseif ( is_array( $value ) ) {
                $clean[ $key ] = self::clean_level( $value, $html, $name, $filtered, $plain );
            }
        }
        return $clean;
    }
}

// ── List Widget Areas ──────────────────────────────────────────────────────────

class List_Widget_Areas_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_widget_areas';
        $this->label        = __( 'List Widget Areas', 'mcp-abilities' );
        $this->description  = 'List all registered widget areas (sidebars) and how many widgets are currently in each. Use list_widgets to see the actual widgets inside a specific area.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [ 'type' => 'object', 'properties' => [] ];
    }

    public function execute( array $params ): array {
        global $wp_registered_sidebars;

        $sidebars_widgets = wp_get_sidebars_widgets();
        $areas            = [];

        foreach ( $wp_registered_sidebars as $id => $sidebar ) {
            $widgets       = $sidebars_widgets[ $id ] ?? [];
            $widget_count  = count( array_filter( $widgets, fn( $w ) => is_string( $w ) ) );
            $areas[] = [
                'id'           => $id,
                'name'         => $sidebar['name'],
                'description'  => $sidebar['description'] ?? '',
                'widget_count' => $widget_count,
            ];
        }

        return $this->json_result( [ 'widget_areas' => $areas ] );
    }
}

// ── List Widgets ───────────────────────────────────────────────────────────────

class List_Widgets_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_widgets';
        $this->label        = __( 'List Widgets', 'mcp-abilities' );
        $this->description  = 'List all widgets currently placed in a specific widget area, including their type, instance ID and settings.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'area_id' => [ 'type' => 'string', 'description' => 'Widget area ID (from list_widget_areas).' ],
            ],
            'required' => [ 'area_id' ],
        ];
    }

    public function execute( array $params ): array {
        global $wp_registered_sidebars, $wp_registered_widgets;

        $area_id = sanitize_key( $params['area_id'] );

        if ( ! isset( $wp_registered_sidebars[ $area_id ] ) ) {
            return $this->error( "Widget area \"{$area_id}\" not found." );
        }

        $sidebars_widgets = wp_get_sidebars_widgets();
        $widget_ids       = $sidebars_widgets[ $area_id ] ?? [];
        $widgets          = [];

        foreach ( $widget_ids as $widget_id ) {
            if ( ! is_string( $widget_id ) ) {
                continue;
            }

            // Parse "text-2" → type = "text", instance = 2.
            $parsed = $this->parse_widget_id( $widget_id );
            if ( null === $parsed ) {
                continue;
            }

            [ $type, $instance_num ] = $parsed;
            $instances = get_option( 'widget_' . $type, [] );
            $settings  = is_array( $instances[ $instance_num ] ?? null )
                ? $instances[ $instance_num ]
                : [];

            // Get display name from registered widgets if available.
            $display_name = $wp_registered_widgets[ $widget_id ]['name'] ?? $type;

            $widgets[] = [
                'widget_id'    => $widget_id,
                'type'         => $type,
                'instance_num' => $instance_num,
                'name'         => $display_name,
                'settings'     => $settings,
            ];
        }

        return $this->json_result( [
            'area_id' => $area_id,
            'widgets' => $widgets,
        ] );
    }

    private function parse_widget_id( string $widget_id ): ?array {
        // Widget IDs follow the pattern "{base_id}-{number}".
        // The base_id may itself contain hyphens (e.g. "recent-posts-3").
        if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
            return null;
        }
        return [ $m[1], (int) $m[2] ];
    }
}

// ── Add Widget ─────────────────────────────────────────────────────────────────

class Add_Widget_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'add_widget';
        $this->label        = __( 'Add Widget', 'mcp-abilities' );
        $this->description  = 'Add a widget to a widget area. Use list_widgets on an existing area to see what settings a widget type expects, then replicate the structure for "settings". Common widget types: text, custom_html, search, recent-posts, archives, categories, tag_cloud, nav_menu, pages, calendar, meta, block. A type that is not registered on this site is refused, and the error lists the ones that are.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'area_id'     => [ 'type' => 'string', 'description' => 'Widget area ID to add the widget to.' ],
                'widget_type' => [ 'type' => 'string', 'description' => 'Widget base ID (e.g. "text", "search", "recent-posts", "nav_menu").' ],
                'settings'    => [ 'type' => 'object', 'description' => 'Widget-specific settings object (e.g. {"title": "My Text", "text": "Hello world"} for a text widget). ' . Widget_Types::SETTINGS_DESCRIPTION ],
                'position'    => [ 'type' => 'integer', 'description' => 'Position index within the area (0-based). Appends to end if omitted.' ],
            ],
            'required' => [ 'area_id', 'widget_type' ],
        ];
    }

    public function execute( array $params ): array {
        global $wp_registered_sidebars;

        $area_id     = sanitize_key( $params['area_id'] );
        $widget_type = sanitize_text_field( $params['widget_type'] );

        if ( ! isset( $wp_registered_sidebars[ $area_id ] ) ) {
            return $this->error( "Widget area \"{$area_id}\" not found." );
        }

        $type_error = Widget_Types::error( $widget_type );
        if ( null !== $type_error ) {
            return $this->error( $type_error );
        }

        // Determine the next instance number.
        $option_key = 'widget_' . $widget_type;
        $instances  = get_option( $option_key, [] );

        if ( ! is_array( $instances ) ) {
            $instances = [];
        }

        // Instance numbers are integers; skip _multiwidget key.
        $existing_nums = array_filter( array_keys( $instances ), 'is_int' );
        $next_num      = $existing_nums ? ( max( $existing_nums ) + 1 ) : 2;

        // Text, numbers, true/false and lists only; HTML only where the widget type keeps it.
        $raw_settings        = is_array( $params['settings'] ?? null ) ? $params['settings'] : [];
        [ $settings, $note ] = Widget_Types::clean_settings( $widget_type, $raw_settings );

        // Store the new instance.
        $instances[ $next_num ]     = $settings;
        $instances['_multiwidget']  = 1;
        update_option( $option_key, $instances );

        // Add to the sidebar.
        $widget_id        = $widget_type . '-' . $next_num;
        $sidebars_widgets = wp_get_sidebars_widgets();

        if ( ! isset( $sidebars_widgets[ $area_id ] ) ) {
            $sidebars_widgets[ $area_id ] = [];
        }

        if ( isset( $params['position'] ) ) {
            array_splice( $sidebars_widgets[ $area_id ], absint( $params['position'] ), 0, [ $widget_id ] );
        } else {
            $sidebars_widgets[ $area_id ][] = $widget_id;
        }

        update_option( 'sidebars_widgets', $sidebars_widgets );

        $result = [
            'widget_id'    => $widget_id,
            'area_id'      => $area_id,
            'instance_num' => $next_num,
        ];
        if ( null !== $note ) {
            $result['note'] = $note;
        }
        return $this->json_result( $result );
    }
}

// ── Update Widget ──────────────────────────────────────────────────────────────

class Update_Widget_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_widget';
        $this->label        = __( 'Update Widget', 'mcp-abilities' );
        $this->description  = 'Update the settings of an existing widget instance. Use list_widgets to find the widget_id and its current settings, then provide the full updated settings object.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'widget_id' => [ 'type' => 'string', 'description' => 'Widget instance ID (e.g. "text-2") from list_widgets.' ],
                'settings'  => [ 'type' => 'object', 'description' => 'Complete new settings object for the widget. Replaces the existing settings. ' . Widget_Types::SETTINGS_DESCRIPTION ],
            ],
            'required' => [ 'widget_id', 'settings' ],
        ];
    }

    public function execute( array $params ): array {
        $widget_id = sanitize_text_field( $params['widget_id'] );

        if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
            return $this->error( "Invalid widget ID \"{$widget_id}\". Expected format: \"type-number\" (e.g. \"text-2\")." );
        }

        $widget_type  = $m[1];
        $instance_num = (int) $m[2];

        // The option name is built from the caller's text, and update_option refuses
        // widget_* because this tool is the way in: only a real widget type gets here.
        $type_error = Widget_Types::error( $widget_type );
        if ( null !== $type_error ) {
            return $this->error( $type_error );
        }

        $option_key   = 'widget_' . $widget_type;
        $instances    = get_option( $option_key, [] );

        if ( ! is_array( $instances ) || ! isset( $instances[ $instance_num ] ) ) {
            return $this->error( "Widget \"{$widget_id}\" not found." );
        }

        $raw_settings        = is_array( $params['settings'] ) ? $params['settings'] : [];
        [ $settings, $note ] = Widget_Types::clean_settings( $widget_type, $raw_settings );

        $instances[ $instance_num ] = $settings;
        update_option( $option_key, $instances );

        return $this->success( "Widget \"{$widget_id}\" updated.", null !== $note ? [ 'note' => $note ] : [] );
    }
}

// ── Remove Widget ──────────────────────────────────────────────────────────────

class Remove_Widget_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'remove_widget';
        $this->label        = __( 'Remove Widget', 'mcp-abilities' );
        $this->description  = 'Remove a widget from a widget area and delete its settings. Use list_widgets to find the widget_id.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'widget_id' => [ 'type' => 'string',  'description' => 'Widget instance ID (e.g. "text-2") from list_widgets.' ],
                'area_id'   => [ 'type' => 'string',  'description' => 'Widget area ID the widget is in.' ],
                'keep_settings' => [ 'type' => 'boolean', 'description' => 'If true, preserves the widget instance settings (moves to inactive). Default: false (deletes settings).', 'default' => false ],
            ],
            'required' => [ 'widget_id', 'area_id' ],
        ];
    }

    public function execute( array $params ): array {
        $widget_id = sanitize_text_field( $params['widget_id'] );
        $area_id   = sanitize_key( $params['area_id'] );

        if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
            return $this->error( "Invalid widget ID \"{$widget_id}\". Expected format: \"type-number\" (e.g. \"text-2\")." );
        }

        $widget_type  = $m[1];
        $instance_num = (int) $m[2];

        $sidebars_widgets = wp_get_sidebars_widgets();

        if ( ! isset( $sidebars_widgets[ $area_id ] ) ) {
            return $this->error( "Widget area \"{$area_id}\" not found." );
        }

        $pos = array_search( $widget_id, $sidebars_widgets[ $area_id ], true );
        if ( false === $pos ) {
            return $this->error( "Widget \"{$widget_id}\" not found in area \"{$area_id}\"." );
        }

        // Remove from sidebar.
        array_splice( $sidebars_widgets[ $area_id ], $pos, 1 );
        update_option( 'sidebars_widgets', $sidebars_widgets );

        // Delete instance settings unless keep_settings is set.
        if ( empty( $params['keep_settings'] ) ) {
            $option_key = 'widget_' . $widget_type;
            $instances  = get_option( $option_key, [] );
            if ( is_array( $instances ) && isset( $instances[ $instance_num ] ) ) {
                unset( $instances[ $instance_num ] );
                update_option( $option_key, $instances );
            }
        }

        return $this->success( "Widget \"{$widget_id}\" removed from \"{$area_id}\"." );
    }
}
