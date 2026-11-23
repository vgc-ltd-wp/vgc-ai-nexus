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

    /**
     * class-ability-revisions.php holds two tools, list_revisions and
     * get_revision. Discovery looks for one class named after each file
     * (Revisions_Ability), so neither was ever registered (2.53.0). They are
     * added after the discovered tools, so the group's other actions keep
     * their order and their "id" parameter text in the group tool.
     */
    public function __construct() {
        parent::__construct();
        require_once __DIR__ . '/class-ability-revisions.php';
        foreach ( [
            'MCP_Abilities\\Abilities\\List_Revisions_Ability',
            'MCP_Abilities\\Abilities\\Get_Revision_Ability',
        ] as $class ) {
            if ( class_exists( $class ) ) {
                $this->register_ability( new $class() );
            }
        }
    }
}
