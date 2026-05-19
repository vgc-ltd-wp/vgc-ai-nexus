<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Page_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'page-management';
        $this->label       = __( 'Page Management', 'mcp-abilities' );
        $this->description = __( 'Create, read, update and delete WordPress pages and manage page hierarchy.', 'mcp-abilities' );
        $this->icon        = 'dashicons-admin-page';
    }
}
