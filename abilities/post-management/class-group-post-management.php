<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Post_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'post-management';
        $this->label       = __( 'Post Management', 'mcp-abilities' );
        $this->description = __( 'Create, read, update and delete WordPress posts.', 'mcp-abilities' );
        $this->icon        = 'dashicons-admin-post';
    }
}
