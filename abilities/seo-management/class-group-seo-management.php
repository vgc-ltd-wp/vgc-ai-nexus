<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Seo_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'seo-management';
        $this->label       = __( 'SEO Management', 'mcp-abilities' );
        $this->description = __( 'WPML- and Yoast-aware SEO audit: list content with SEO meta (meta description, SEO title, focus keyword), scoped by language, with a server-side filter for items missing a meta description.', 'mcp-abilities' );
        $this->icon        = 'dashicons-search';
        $this->guide       = __( "Audit on-page SEO across your content - pull SEO titles, meta descriptions and focus keywords (Yoast-aware, language-scoped with WPML) and pinpoint pages missing a meta description.", 'mcp-abilities' );
        $this->examples    = [
            __( "List all posts missing a meta description", 'mcp-abilities' ),
            __( "Show the SEO titles and focus keywords for my product pages", 'mcp-abilities' ),
            __( "Which Bulgarian pages have no meta description?", 'mcp-abilities' ),
        ];
    }
}
