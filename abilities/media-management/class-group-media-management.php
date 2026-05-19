<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Media_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'media-management';
        $this->label       = __( 'Media Management', 'mcp-abilities' );
        $this->description = __( 'Search, inspect, update and delete media library items.', 'mcp-abilities' );
        $this->icon        = 'dashicons-admin-media';
    }
}
