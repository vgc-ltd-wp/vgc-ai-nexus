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
        $this->guide       = __( "Read and update WordPress site settings and options (site title, tagline and other registered options) and inspect site info. Writes are denylist-guarded against sensitive keys.", 'mcp-abilities' );
        $this->examples    = [
            __( "Change my site tagline to 'Handmade ceramics from Sofia'", 'mcp-abilities' ),
            __( "What's my current timezone and date format?", 'mcp-abilities' ),
            __( "Show me the registered public post types", 'mcp-abilities' ),
        ];
    }
}
