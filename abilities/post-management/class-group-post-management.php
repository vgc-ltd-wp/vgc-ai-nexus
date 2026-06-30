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
        $this->guide       = __( "Full lifecycle for blog posts: create drafts, edit content and metadata, manage revisions, publish, and delete - the backbone of content workflows.", 'mcp-abilities' );
        $this->examples    = [
            __( "Draft a blog post about our summer sale and leave it as a draft", 'mcp-abilities' ),
            __( "Publish my latest draft and set its category to News", 'mcp-abilities' ),
            __( "Update the excerpt on my Welcome post", 'mcp-abilities' ),
        ];
    }
}
