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
        $this->guide       = __( "Manage static pages: draft new ones, edit existing content, set parent/child hierarchy, and publish or delete - ideal for landing pages and evergreen content.", 'mcp-abilities' );
        $this->examples    = [
            __( "Create an About Us page with a short company intro", 'mcp-abilities' ),
            __( "Update the Contact page to add our new phone number", 'mcp-abilities' ),
            __( "List all draft pages", 'mcp-abilities' ),
        ];
    }
}
