<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Taxonomy_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'taxonomy-management';
        $this->label       = __( 'Taxonomy Management', 'mcp-abilities' );
        $this->description = __( 'Manage categories, tags and custom taxonomy terms.', 'mcp-abilities' );
        $this->icon        = 'dashicons-tag';
        $this->guide       = __( "Organise content with categories, tags and custom taxonomy terms: create and rename terms, build hierarchies, and clean up duplicates.", 'mcp-abilities' );
        $this->examples    = [
            __( "Create a Tutorials category", 'mcp-abilities' ),
            __( "Rename the 'misc' tag to 'general'", 'mcp-abilities' ),
            __( "List all product categories and how many items each has", 'mcp-abilities' ),
        ];
    }
}
