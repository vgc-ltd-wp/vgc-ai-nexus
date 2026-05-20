<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

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
        $this->description  = 'Add a widget to a widget area. Use list_widgets on an existing area to see what settings a widget type expects, then replicate the structure for "settings". Common widget types: text, html (Custom HTML), search, recent-posts, archives, categories, tag-cloud, navigation-menu, pages, calendar, meta.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'area_id'     => [ 'type' => 'string', 'description' => 'Widget area ID to add the widget to.' ],
                'widget_type' => [ 'type' => 'string', 'description' => 'Widget base ID (e.g. "text", "search", "recent-posts", "navigation-menu").' ],
                'settings'    => [ 'type' => 'object', 'description' => 'Widget-specific settings object (e.g. {"title": "My Text", "text": "Hello world"} for a text widget).' ],
                'position'    => [ 'type' => 'integer', 'description' => 'Position index within the area (0-based). Appends to end if omitted.' ],
            ],
            'required' => [ 'area_id', 'widget_type' ],
        ];
    }

    public function execute( array $params ): array {
        global $wp_registered_sidebars, $wp_registered_widget_updates;

        $area_id     = sanitize_key( $params['area_id'] );
        $widget_type = sanitize_text_field( $params['widget_type'] );

        if ( ! isset( $wp_registered_sidebars[ $area_id ] ) ) {
            return $this->error( "Widget area \"{$area_id}\" not found." );
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

        // Sanitise settings: only allow scalar and array values.
        $raw_settings = is_array( $params['settings'] ?? null ) ? $params['settings'] : [];
        $settings     = $this->sanitise_widget_settings( $raw_settings );

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

        return $this->json_result( [
            'widget_id'    => $widget_id,
            'area_id'      => $area_id,
            'instance_num' => $next_num,
        ] );
    }

    private function sanitise_widget_settings( array $settings ): array {
        $clean = [];
        foreach ( $settings as $key => $value ) {
            $key = sanitize_key( $key );
            if ( is_string( $value ) ) {
                $clean[ $key ] = sanitize_textarea_field( $value );
            } elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
                $clean[ $key ] = $value;
            } elseif ( is_array( $value ) ) {
                $clean[ $key ] = $this->sanitise_widget_settings( $value );
            }
        }
        return $clean;
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
                'settings'  => [ 'type' => 'object', 'description' => 'Complete new settings object for the widget. Replaces the existing settings.' ],
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
        $option_key   = 'widget_' . $widget_type;
        $instances    = get_option( $option_key, [] );

        if ( ! is_array( $instances ) || ! isset( $instances[ $instance_num ] ) ) {
            return $this->error( "Widget \"{$widget_id}\" not found." );
        }

        $raw_settings = is_array( $params['settings'] ) ? $params['settings'] : [];
        $settings     = $this->sanitise_widget_settings( $raw_settings );

        $instances[ $instance_num ] = $settings;
        update_option( $option_key, $instances );

        return $this->success( "Widget \"{$widget_id}\" updated." );
    }

    private function sanitise_widget_settings( array $settings ): array {
        $clean = [];
        foreach ( $settings as $key => $value ) {
            $key = sanitize_key( $key );
            if ( is_string( $value ) ) {
                $clean[ $key ] = sanitize_textarea_field( $value );
            } elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
                $clean[ $key ] = $value;
            } elseif ( is_array( $value ) ) {
                $clean[ $key ] = $this->sanitise_widget_settings( $value );
            }
        }
        return $clean;
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
