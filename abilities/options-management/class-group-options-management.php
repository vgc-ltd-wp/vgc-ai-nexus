<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Options_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'options-management';
        $this->label       = __( 'Options Management', 'mcp-abilities' );
        $this->description = __( 'Read and write WordPress site options and settings.', 'mcp-abilities' );
        $this->icon        = 'dashicons-admin-settings';
    }
}
