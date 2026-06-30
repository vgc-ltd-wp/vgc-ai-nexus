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
        $this->guide       = __( "Work with the Media Library: find images, read and rewrite alt text, titles and captions for accessibility and SEO, upload new files, and remove unused attachments.", 'mcp-abilities' );
        $this->examples    = [
            __( "Find media items missing alt text and suggest alt text for each", 'mcp-abilities' ),
            __( "Upload this image and set its alt text to 'red mountain bike'", 'mcp-abilities' ),
            __( "Delete the unused PNGs uploaded last month", 'mcp-abilities' ),
        ];
    }
}
