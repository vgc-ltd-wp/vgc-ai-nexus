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
    }
}
