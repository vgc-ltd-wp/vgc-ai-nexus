<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── Get Global Styles ──────────────────────────────────────────────────────────

class Get_Global_Styles_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_global_styles';
        $this->label        = __( 'Get Global Styles', 'mcp-abilities' );
        $this->description  = 'Read the active theme\'s global styles (theme.json) — colours, typography, spacing and element defaults. Returns both the full compiled styles (merged from theme + user customisations) and the user\'s own customisations separately. Use update_global_styles to write changes.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [ 'type' => 'object', 'properties' => [] ];
    }

    public function execute( array $params ): array {
        if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
            return $this->error( 'WP_Theme_JSON_Resolver is not available. Global Styles require WordPress 5.9+.' );
        }

        // Full compiled styles (theme defaults + user customisations merged).
        $merged     = \WP_Theme_JSON_Resolver::get_merged_data();
        $merged_raw = $merged->get_raw_data();

        // User customisations only (what differs from the theme defaults).
        $user_data = \WP_Theme_JSON_Resolver::get_user_data();
        $user_raw  = $user_data ? $user_data->get_raw_data() : [];

        return $this->json_result( [
            'theme'                => get_stylesheet(),
            'merged_settings'      => $merged_raw['settings'] ?? [],
            'merged_styles'        => $merged_raw['styles']   ?? [],
            'user_settings'        => $user_raw['settings']   ?? [],
            'user_styles'          => $user_raw['styles']     ?? [],
        ] );
    }
}

// ── Update Global Styles ───────────────────────────────────────────────────────

class Update_Global_Styles_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_global_styles';
        $this->label        = __( 'Update Global Styles', 'mcp-abilities' );
        $this->description  = 'Write to the active theme\'s global styles (theme.json user customisations). Provide a partial "settings" object, a partial "styles" object, or both — they are deep-merged with the existing user customisations. Call get_global_styles first to understand the current structure. Example: to change the body font, provide styles: {"typography": {"fontFamily": "var(--wp--preset--font-family--inter)"}}. Keys theme.json does not know have no effect; the result lists them in "ignored_paths".';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'settings' => [
                    'type'        => 'object',
                    'description' => 'Partial theme.json "settings" to merge (e.g. custom colour palette).',
                ],
                'styles' => [
                    'type'        => 'object',
                    'description' => 'Partial theme.json "styles" to merge (e.g. typography, color, spacing for body and elements).',
                ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
            return $this->error( 'WP_Theme_JSON_Resolver is not available. Global Styles require WordPress 5.9+.' );
        }

        if ( empty( $params['settings'] ) && empty( $params['styles'] ) ) {
            return $this->error( 'Provide at least one of "settings" or "styles" to update.' );
        }

        // Find the user global styles post for the active theme.
        $posts = get_posts( [
            'post_type'      => 'wp_global_styles',
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => 1,
            'tax_query'      => [ [
                'taxonomy' => 'wp_theme',
                'field'    => 'name',
                'terms'    => get_stylesheet(),
            ] ],
        ] );

        $was_ignored = false;
        if ( $posts ) {
            $post_id         = $posts[0]->ID;
            $current_content = json_decode( $posts[0]->post_content, true ) ?: [];
            // A styles post this tool made before 2.52.0 lacks the flag, so
            // WordPress has been ignoring everything in it.
            $was_ignored = empty( $current_content['isGlobalStylesUserThemeJSON'] );
        } else {
            // No user customisations yet — create the post.
            $post_id         = null;
            $current_content = [ 'version' => class_exists( 'WP_Theme_JSON' ) && defined( 'WP_Theme_JSON::LATEST_SCHEMA' ) ? \WP_Theme_JSON::LATEST_SCHEMA : 2 ];
        }
        // WordPress reads a user styles post only with this flag set (as the Site
        // Editor writes it); without it the styles were saved, reported as "live"
        // and ignored (2.52.0, audit of 2026-11-23).
        $current_content['isGlobalStylesUserThemeJSON'] = true;
        if ( empty( $current_content['version'] ) ) {
            $current_content['version'] = 2;
        }

        // Deep merge incoming changes into existing user data.
        if ( ! empty( $params['settings'] ) && is_array( $params['settings'] ) ) {
            $current_content['settings'] = $this->deep_merge(
                $current_content['settings'] ?? [],
                $params['settings']
            );
        }
        // Custom CSS in global styles ("css" at the top or per block) needs edit_css,
        // and nobody's is taken while raw code is locked (2.41.0).
        $css_stripped = false;
        if ( ! empty( $params['styles'] ) && is_array( $params['styles'] ) ) {
            $current_content['styles'] = $this->deep_merge(
                $current_content['styles'] ?? [],
                \MCP_Abilities\Custom_Code::strip_style_css( $params['styles'], $css_stripped )
            );
        }

        $json = wp_json_encode( $current_content );

        // wp_insert_post() and wp_update_post() take slashed data: unslashed, a
        // quote inside a value ("\"Inter\", sans-serif") broke the JSON (2.49.0).
        if ( $post_id ) {
            $result = wp_update_post( wp_slash( [ 'ID' => $post_id, 'post_content' => $json ] ), true );
        } else {
            $result = wp_insert_post( wp_slash( [
                'post_title'   => 'Global Styles and Settings',
                'post_status'  => 'publish',
                'post_type'    => 'wp_global_styles',
                'post_content' => $json,
            ] ), true );
            if ( ! is_wp_error( $result ) ) {
                wp_set_object_terms( $result, get_stylesheet(), 'wp_theme' );
            }
        }

        if ( is_wp_error( $result ) ) {
            return $this->error( 'Failed to save global styles: ' . $result->get_error_message() );
        }

        // Clear the theme JSON cache so changes are visible immediately.
        if ( function_exists( 'wp_clean_themes_cache' ) ) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        $extra = [];
        if ( $css_stripped ) {
            $extra['note'] = \MCP_Abilities\Custom_Code::locked()
                ? 'Raw code is locked on this site, so the custom CSS ("css") in the styles was left out.'
                : 'This account does not have the "edit_css" capability, so the custom CSS ("css") in the styles was left out.';
        }
        // Keys theme.json does not know are stored but never used: say which (2.52.0).
        $ignored = $this->ignored_paths( $params );
        if ( $ignored ) {
            $extra['ignored_paths'] = $ignored;
            $extra = self::with_note( $extra, 'WordPress does not use these keys (theme.json does not know them, or not in that place), so they have no effect: ' . implode( ', ', $ignored ) . '. Check the names with get_global_styles or the theme.json reference.' );
        }
        if ( $was_ignored ) {
            $extra = self::with_note( $extra, 'The saved styles had been ignored by WordPress until now (an earlier save had left out the flag it needs); they are now in effect too.' );
        }

        return $this->success( 'Global styles updated. Changes are live immediately.', $extra );
    }

    /**
     * The paths of what was sent that WordPress's theme.json sanitizing drops
     * (unknown keys, or known ones in the wrong place), e.g. "styles.typography.fontFamilly".
     * Lists are not looked into.
     *
     * @param array<string,mixed> $params
     * @return string[]
     */
    private function ignored_paths( array $params ): array {
        if ( ! class_exists( 'WP_Theme_JSON' ) ) {
            return [];
        }
        $sent = [ 'version' => class_exists( 'WP_Theme_JSON' ) && defined( 'WP_Theme_JSON::LATEST_SCHEMA' ) ? \WP_Theme_JSON::LATEST_SCHEMA : 2 ];
        foreach ( [ 'settings', 'styles' ] as $part ) {
            if ( ! empty( $params[ $part ] ) && is_array( $params[ $part ] ) ) {
                $sent[ $part ] = $params[ $part ];
            }
        }
        try {
            $kept = ( new \WP_Theme_JSON( $sent, 'custom' ) )->get_raw_data();
        } catch ( \Throwable $e ) {
            return [];
        }
        $out = [];
        foreach ( [ 'settings', 'styles' ] as $part ) {
            if ( isset( $sent[ $part ] ) ) {
                $this->collect_dropped( $sent[ $part ], $kept[ $part ] ?? null, $part, $out );
            }
        }
        return $out;
    }

    /** Walks $sent and records the paths missing from $kept (presets are kept under their origin, "custom"). */
    private function collect_dropped( $sent, $kept, string $path, array &$out ): void {
        if ( ! is_array( $sent ) || wp_is_numeric_array( $sent ) ) {
            return;
        }
        foreach ( $sent as $key => $value ) {
            $here = $path . '.' . $key;
            if ( ! is_array( $kept ) || ! array_key_exists( $key, $kept ) ) {
                $out[] = $here;
                continue;
            }
            $next = $kept[ $key ];
            // A preset list ("palette": [...]) comes back as {"custom": [...]}.
            if ( is_array( $value ) && wp_is_numeric_array( $value ) ) {
                continue;
            }
            $this->collect_dropped( $value, $next, $here, $out );
        }
    }

    /**
     * Recursively merge $overlay into $base, with $overlay values taking priority.
     */
    private function deep_merge( array $base, array $overlay ): array {
        foreach ( $overlay as $key => $value ) {
            if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
                $base[ $key ] = $this->deep_merge( $base[ $key ], $value );
            } else {
                $base[ $key ] = $value;
            }
        }
        return $base;
    }
}

// ── List Block Patterns ────────────────────────────────────────────────────────

class List_Block_Patterns_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_block_patterns';
        $this->label        = __( 'List Block Patterns', 'mcp-abilities' );
        $this->description  = 'List all block patterns registered by the active theme and plugins. Returns each pattern\'s name, title, categories and block markup. Useful for understanding what default patterns a theme provides (e.g. TT25 header patterns) before overriding a template part.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'category'        => [ 'type' => 'string',  'description' => 'Filter by pattern category slug (e.g. "header", "footer", "featured").' ],
                'include_content' => [ 'type' => 'boolean', 'description' => 'Include the full block markup in the response (can be large). Default: false.', 'default' => false ],
                'search'          => [ 'type' => 'string',  'description' => 'Filter patterns by title keyword.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
            return $this->error( 'WP_Block_Patterns_Registry is not available. Block patterns require WordPress 5.5+.' );
        }

        $all_patterns    = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
        $include_content = ! empty( $params['include_content'] );
        $category_filter = ! empty( $params['category'] ) ? sanitize_key( $params['category'] ) : null;
        $search          = ! empty( $params['search'] ) ? strtolower( sanitize_text_field( $params['search'] ) ) : null;

        $result = [];
        foreach ( $all_patterns as $pattern ) {
            // Category filter.
            if ( $category_filter ) {
                $cats = $pattern['categories'] ?? [];
                if ( ! in_array( $category_filter, $cats, true ) ) {
                    continue;
                }
            }

            // Title search.
            if ( $search && ! str_contains( strtolower( $pattern['title'] ?? '' ), $search ) ) {
                continue;
            }

            $entry = [
                'name'        => $pattern['name'],
                'title'       => $pattern['title']       ?? '',
                'description' => $pattern['description'] ?? '',
                'categories'  => $pattern['categories']  ?? [],
                'keywords'    => $pattern['keywords']    ?? [],
                'source'      => $pattern['source']      ?? 'unknown',
            ];

            if ( $include_content ) {
                $entry['content'] = $pattern['content'] ?? '';
            }

            $result[] = $entry;
        }

        $out = [
            'total'    => count( $result ),
            'patterns' => $result,
        ];
        // A category no pattern is registered under gave an empty list (2.52.0).
        if ( $category_filter && ! $result && class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
            $cats = array_column( (array) \WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered(), 'name' );
            if ( ! in_array( $category_filter, $cats, true ) ) {
                $out['note'] = sprintf( 'There is no pattern category "%s". Categories: %s.', $category_filter, implode( ', ', $cats ) );
            }
        }
        return $this->json_result( $out );
    }
}
