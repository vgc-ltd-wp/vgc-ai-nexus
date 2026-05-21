<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

// ── Shared helpers ─────────────────────────────────────────────────────────────

/**
 * Serialise a WP_Block_Template object to a compact array.
 *
 * @param \WP_Block_Template $t
 * @param bool               $include_content  Whether to include the block markup.
 */
function mcp_template_to_array( \WP_Block_Template $t, bool $include_content = false ): array {
    $data = [
        'id'              => $t->id,            // "{theme}//{slug}" — use this as the identifier
        'slug'            => $t->slug,
        'title'           => $t->title,
        'description'     => $t->description,
        'status'          => $t->status,
        'source'          => $t->source,        // 'theme' (file-backed) or 'custom' (DB override)
        'has_theme_file'  => $t->has_theme_file,
        'is_custom'       => $t->is_custom,
        'wp_id'           => $t->wp_id ?? null, // post ID; null until first edit of a theme template
    ];
    if ( $include_content ) {
        $data['content'] = $t->content;
    }
    return $data;
}

/**
 * Upsert a wp_template: update the existing custom post, or create a DB override
 * for a theme-backed template that hasn't been edited yet.
 *
 * @return int|\WP_Error  Post ID on success.
 */
function mcp_upsert_template(
    string $id,
    string $content,
    ?string $title,
    string $post_type
) {
    $template = get_block_template( $id, $post_type );
    if ( ! $template ) {
        return new \WP_Error( 'not_found', "Template \"{$id}\" not found." );
    }

    $post_data = [ 'post_content' => $content ];
    if ( null !== $title ) {
        $post_data['post_title'] = $title;
    }

    if ( ! empty( $template->wp_id ) ) {
        // Already has a DB record — update in place.
        $post_data['ID'] = $template->wp_id;
        return wp_update_post( $post_data, true );
    }

    // Theme-file-backed template: create a custom override post.
    $post_data['post_name']   = $template->slug;
    $post_data['post_type']   = $post_type;
    $post_data['post_status'] = 'publish';
    if ( empty( $post_data['post_title'] ) ) {
        $post_data['post_title'] = $template->title;
    }

    $post_id = wp_insert_post( $post_data, true );
    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    // Tag with the active theme so WP knows which theme this override belongs to.
    wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );

    return $post_id;
}

// ── List Templates ─────────────────────────────────────────────────────────────

class List_Templates_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_templates';
        $this->label        = __( 'List Templates', 'mcp-abilities' );
        $this->description  = 'List all Full Site Editing page templates (wp_template) for the active theme. Returns each template\'s ID in "{theme}//{slug}" format — use this ID with get_template or update_template. Requires a block theme.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'search' => [ 'type' => 'string', 'description' => 'Filter templates by title or slug.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! function_exists( 'get_block_templates' ) ) {
            return $this->error( 'get_block_templates() is not available. Full Site Editing requires WordPress 5.9+.' );
        }

        $query = [];
        if ( ! empty( $params['search'] ) ) {
            $query['slug__in'] = [ sanitize_title( $params['search'] ) ];
        }

        $templates = get_block_templates( $query, 'wp_template' );

        if ( is_wp_error( $templates ) ) {
            return $this->error( $templates->get_error_message() );
        }

        $result = array_map(
            fn( $t ) => mcp_template_to_array( $t, false ),
            $templates
        );

        return $this->json_result( [
            'theme'     => get_stylesheet(),
            'total'     => count( $result ),
            'templates' => $result,
        ] );
    }
}

// ── Get Template ───────────────────────────────────────────────────────────────

class Get_Template_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_template';
        $this->label        = __( 'Get Template', 'mcp-abilities' );
        $this->description  = 'Retrieve the full block markup content of a specific page template. Pass the "id" from list_templates (format: "{theme}//{slug}", e.g. "twentytwentyfive//single"). Always call this before update_template so you have the current content.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'   => [ 'type' => 'string', 'description' => 'Template ID in "{theme}//{slug}" format (from list_templates).' ],
                'slug' => [ 'type' => 'string', 'description' => 'Template slug (e.g. "single", "page", "archive"). Used if id is omitted; active theme is assumed.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $id = $this->resolve_id( $params, 'wp_template' );
        if ( is_wp_error( $id ) ) {
            return $this->error( $id->get_error_message() );
        }

        $template = get_block_template( $id, 'wp_template' );
        if ( ! $template ) {
            return $this->error( "Template \"{$id}\" not found." );
        }

        return $this->json_result( mcp_template_to_array( $template, true ) );
    }

    private function resolve_id( array $params, string $type ): string|\WP_Error {
        if ( ! empty( $params['id'] ) ) {
            return sanitize_text_field( $params['id'] );
        }
        if ( ! empty( $params['slug'] ) ) {
            return get_stylesheet() . '//' . sanitize_title( $params['slug'] );
        }
        return new \WP_Error( 'missing_param', 'Provide "id" (e.g. "twentytwentyfive//single") or "slug" (e.g. "single").' );
    }
}

// ── Update Template ────────────────────────────────────────────────────────────

class Update_Template_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_template';
        $this->label        = __( 'Update Template', 'mcp-abilities' );
        $this->description  = 'Write new block markup to a page template. If the template is theme-file-backed (source: "theme"), WordPress creates a custom DB override — the theme file is not touched. Use get_template first to read the current content.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'string', 'description' => 'Template ID in "{theme}//{slug}" format (from list_templates).' ],
                'content' => [ 'type' => 'string', 'description' => 'New block markup for the template.' ],
                'title'   => [ 'type' => 'string', 'description' => 'Optional new title for the template.' ],
            ],
            'required' => [ 'id', 'content' ],
        ];
    }

    public function execute( array $params ): array {
        $id      = sanitize_text_field( $params['id'] );
        $content = $params['content'];
        $title   = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : null;

        if ( '' === trim( $content ) ) {
            return $this->error( 'Template content cannot be empty.' );
        }

        $result = mcp_upsert_template( $id, $content, $title, 'wp_template' );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "Template \"{$id}\" updated (post ID: {$result})." );
    }
}

// ── Create Template ────────────────────────────────────────────────────────────

class Create_Template_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_template';
        $this->label        = __( 'Create Template', 'mcp-abilities' );
        $this->description  = 'Create a new custom page template for the active block theme. The template will be available for assignment to posts and pages. Use standard WordPress block markup for content.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'slug'        => [ 'type' => 'string', 'description' => 'URL-safe slug for the template (e.g. "docs-page").' ],
                'title'       => [ 'type' => 'string', 'description' => 'Human-readable template name shown in the editor.' ],
                'content'     => [ 'type' => 'string', 'description' => 'Block markup for the template.' ],
                'description' => [ 'type' => 'string', 'description' => 'Optional description shown in the template selector.' ],
            ],
            'required' => [ 'slug', 'title', 'content' ],
        ];
    }

    public function execute( array $params ): array {
        $slug        = sanitize_title( $params['slug'] );
        $title       = sanitize_text_field( $params['title'] );
        $content     = $params['content'];
        $description = sanitize_text_field( $params['description'] ?? '' );

        if ( '' === $slug ) {
            return $this->error( 'Slug cannot be empty.' );
        }
        if ( '' === trim( $content ) ) {
            return $this->error( 'Template content cannot be empty.' );
        }

        // Check for slug collision.
        $existing_id = get_stylesheet() . '//' . $slug;
        if ( get_block_template( $existing_id, 'wp_template' ) ) {
            return $this->error( "A template with slug \"{$slug}\" already exists. Use update_template to edit it." );
        }

        $post_id = wp_insert_post( [
            'post_name'    => $slug,
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $description,
            'post_type'    => 'wp_template',
            'post_status'  => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $this->error( $post_id->get_error_message() );
        }

        wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );

        return $this->json_result( [
            'id'      => get_stylesheet() . '//' . $slug,
            'wp_id'   => $post_id,
            'slug'    => $slug,
        ] );
    }
}
