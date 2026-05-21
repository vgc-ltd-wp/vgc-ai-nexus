<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── List Template Parts ────────────────────────────────────────────────────────

class List_Template_Parts_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_template_parts';
        $this->label        = __( 'List Template Parts', 'mcp-abilities' );
        $this->description  = 'List all Full Site Editing template parts (wp_template_part) for the active theme — header, footer, sidebar, etc. Returns each part\'s ID in "{theme}//{slug}" format — use this ID with get_template_part or update_template_part. Requires a block theme.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'area' => [
                    'type'        => 'string',
                    'enum'        => [ 'header', 'footer', 'sidebar', 'uncategorized' ],
                    'description' => 'Filter by template part area.',
                ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! function_exists( 'get_block_templates' ) ) {
            return $this->error( 'get_block_templates() is not available. Full Site Editing requires WordPress 5.9+.' );
        }

        $query = [];
        if ( ! empty( $params['area'] ) ) {
            $query['area'] = sanitize_key( $params['area'] );
        }

        $parts = get_block_templates( $query, 'wp_template_part' );

        if ( is_wp_error( $parts ) ) {
            return $this->error( $parts->get_error_message() );
        }

        $result = array_map( function ( $t ) {
            $data         = mcp_template_to_array( $t, false );
            $data['area'] = $t->area ?? 'uncategorized';
            return $data;
        }, $parts );

        return $this->json_result( [
            'theme'          => get_stylesheet(),
            'total'          => count( $result ),
            'template_parts' => $result,
        ] );
    }
}

// ── Get Template Part ──────────────────────────────────────────────────────────

class Get_Template_Part_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_template_part';
        $this->label        = __( 'Get Template Part', 'mcp-abilities' );
        $this->description  = 'Retrieve the full block markup content of a specific template part. Pass the "id" from list_template_parts (format: "{theme}//{slug}", e.g. "twentytwentyfive//header"). Always call this before update_template_part so you have the current content.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'   => [ 'type' => 'string', 'description' => 'Template part ID in "{theme}//{slug}" format (from list_template_parts).' ],
                'slug' => [ 'type' => 'string', 'description' => 'Template part slug (e.g. "header", "footer"). Used if id is omitted; active theme is assumed.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $id = mcp_resolve_template_id( $params );
        if ( is_wp_error( $id ) ) {
            return $this->error( $id->get_error_message() );
        }

        $template = get_block_template( $id, 'wp_template_part' );
        if ( ! $template ) {
            return $this->error( "Template part \"{$id}\" not found." );
        }

        $data         = mcp_template_to_array( $template, true );
        $data['area'] = $template->area ?? 'uncategorized';

        return $this->json_result( $data );
    }
}

// ── Update Template Part ───────────────────────────────────────────────────────

class Update_Template_Part_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_template_part';
        $this->label        = __( 'Update Template Part', 'mcp-abilities' );
        $this->description  = 'Write new block markup to a template part (header, footer, sidebar, etc.). If the part is theme-file-backed (source: "theme"), WordPress creates a custom DB override — the theme file is not modified. Provide "area" to ensure the part is correctly categorised (required when setting a header or footer). Use get_template_part first to read the current content.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'string', 'description' => 'Template part ID in "{theme}//{slug}" format (from list_template_parts).' ],
                'content' => [ 'type' => 'string', 'description' => 'New block markup for the template part.' ],
                'title'   => [ 'type' => 'string', 'description' => 'Optional new title for the template part.' ],
                'area'    => [
                    'type'        => 'string',
                    'enum'        => [ 'header', 'footer', 'sidebar', 'uncategorized' ],
                    'description' => 'Functional area. Provide this when setting a header or footer part so WordPress registers it correctly in the Site Editor.',
                ],
            ],
            'required' => [ 'id', 'content' ],
        ];
    }

    public function execute( array $params ): array {
        $id      = sanitize_text_field( $params['id'] );
        $content = $params['content'];
        $title   = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : null;
        $area    = isset( $params['area'] ) ? sanitize_key( $params['area'] ) : null;

        if ( '' === trim( $content ) ) {
            return $this->error( 'Template part content cannot be empty.' );
        }

        // Pass area to the upsert so it sets wp_template_part_area taxonomy.
        $result = mcp_upsert_template( $id, $content, $title, 'wp_template_part', $area );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "Template part \"{$id}\" updated (post ID: {$result})." );
    }
}

// ── Create Template Part ───────────────────────────────────────────────────────

class Create_Template_Part_Ability extends Ability {

    private const VALID_AREAS = [ 'header', 'footer', 'sidebar', 'uncategorized' ];

    protected function define_meta(): void {
        $this->key          = 'create_template_part';
        $this->label        = __( 'Create Template Part', 'mcp-abilities' );
        $this->description  = 'Create a new custom template part for the active block theme. Useful for adding new reusable sections like a docs sidebar or a promo banner.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'slug'    => [ 'type' => 'string', 'description' => 'URL-safe slug (e.g. "docs-sidebar").' ],
                'title'   => [ 'type' => 'string', 'description' => 'Human-readable name shown in the Site Editor.' ],
                'content' => [ 'type' => 'string', 'description' => 'Block markup for the template part.' ],
                'area'    => [
                    'type'        => 'string',
                    'enum'        => self::VALID_AREAS,
                    'default'     => 'uncategorized',
                    'description' => 'Functional area: "header", "footer", "sidebar", or "uncategorized".',
                ],
            ],
            'required' => [ 'slug', 'title', 'content' ],
        ];
    }

    public function execute( array $params ): array {
        $slug    = sanitize_title( $params['slug'] );
        $title   = sanitize_text_field( $params['title'] );
        $content = $params['content'];
        $area    = sanitize_key( $params['area'] ?? 'uncategorized' );

        if ( '' === $slug ) {
            return $this->error( 'Slug cannot be empty.' );
        }
        if ( '' === trim( $content ) ) {
            return $this->error( 'Template part content cannot be empty.' );
        }
        if ( ! in_array( $area, self::VALID_AREAS, true ) ) {
            return $this->error( 'Invalid area. Use: ' . implode( ', ', self::VALID_AREAS ) . '.' );
        }

        $existing_id = get_stylesheet() . '//' . $slug;
        if ( get_block_template( $existing_id, 'wp_template_part' ) ) {
            return $this->error( "A template part with slug \"{$slug}\" already exists. Use update_template_part to edit it." );
        }

        $post_id = wp_insert_post( [
            'post_name'    => $slug,
            'post_title'   => $title,
            'post_content' => $content,
            'post_type'    => 'wp_template_part',
            'post_status'  => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $this->error( $post_id->get_error_message() );
        }

        wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );
        wp_set_object_terms( $post_id, $area, 'wp_template_part_area' );

        return $this->json_result( [
            'id'    => get_stylesheet() . '//' . $slug,
            'wp_id' => $post_id,
            'slug'  => $slug,
            'area'  => $area,
        ] );
    }
}

// ── Delete Template Part ───────────────────────────────────────────────────────

class Delete_Template_Part_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_template_part';
        $this->label        = __( 'Delete Template Part', 'mcp-abilities' );
        $this->description  = 'Delete a custom template part override and revert to the theme default. Only works on parts with a DB override (wp_id is not null). Use list_template_parts to check which parts have overrides.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'string', 'description' => 'Template part ID in "{theme}//{slug}" format (from list_template_parts).' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $id       = sanitize_text_field( $params['id'] );
        $template = get_block_template( $id, 'wp_template_part' );

        if ( ! $template ) {
            return $this->error( "Template part \"{$id}\" not found." );
        }

        if ( empty( $template->wp_id ) ) {
            return $this->error( "Template part \"{$id}\" is a theme-file part with no custom override. There is nothing to delete — it is already the theme default." );
        }

        $result = wp_delete_post( $template->wp_id, true );

        if ( ! $result ) {
            return $this->error( "Failed to delete template part override for \"{$id}\"." );
        }

        return $this->success( "Template part override for \"{$id}\" deleted. The theme default is now restored." );
    }
}

// ── Set Template Part Area ─────────────────────────────────────────────────────

class Set_Template_Part_Area_Ability extends Ability {

    private const VALID_AREAS = [ 'header', 'footer', 'sidebar', 'uncategorized' ];

    protected function define_meta(): void {
        $this->key          = 'set_template_part_area';
        $this->label        = __( 'Set Template Part Area', 'mcp-abilities' );
        $this->description  = 'Assign or correct the area taxonomy (header/footer/sidebar/uncategorized) on an existing template part. Use this to fix a part that was saved with the wrong area, or to reclassify a part without changing its content.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'   => [ 'type' => 'string', 'description' => 'Template part ID in "{theme}//{slug}" format.' ],
                'area' => [
                    'type'        => 'string',
                    'enum'        => self::VALID_AREAS,
                    'description' => 'The area to assign: "header", "footer", "sidebar", or "uncategorized".',
                ],
            ],
            'required' => [ 'id', 'area' ],
        ];
    }

    public function execute( array $params ): array {
        $id   = sanitize_text_field( $params['id'] );
        $area = sanitize_key( $params['area'] );

        if ( ! in_array( $area, self::VALID_AREAS, true ) ) {
            return $this->error( 'Invalid area. Use: ' . implode( ', ', self::VALID_AREAS ) . '.' );
        }

        $template = get_block_template( $id, 'wp_template_part' );
        if ( ! $template ) {
            return $this->error( "Template part \"{$id}\" not found." );
        }

        if ( empty( $template->wp_id ) ) {
            return $this->error( "Template part \"{$id}\" is theme-file-backed with no DB override. Call update_template_part (with the area param) first to create an override." );
        }

        wp_set_object_terms( $template->wp_id, $area, 'wp_template_part_area' );

        return $this->success( "Template part \"{$id}\" area set to \"{$area}\"." );
    }
}

// ── Shared helper ──────────────────────────────────────────────────────────────

/**
 * Resolve a template/part ID from params that may supply either 'id' or 'slug'.
 */
function mcp_resolve_template_id( array $params ): string|\WP_Error {
    if ( ! empty( $params['id'] ) ) {
        return sanitize_text_field( $params['id'] );
    }
    if ( ! empty( $params['slug'] ) ) {
        return get_stylesheet() . '//' . sanitize_title( $params['slug'] );
    }
    return new \WP_Error( 'missing_param', 'Provide "id" (e.g. "twentytwentyfive//header") or "slug" (e.g. "header").' );
}
