<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Custom_Code_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug             = 'custom-code';
        $this->label            = __( 'Custom CSS & JS', 'mcp-abilities' );
        $this->description      = __( 'Inject custom CSS and JavaScript into the site frontend.', 'mcp-abilities' );
        $this->icon             = 'dashicons-editor-code';
        $this->security_warning = __( 'Security risk: these tools inject raw CSS and JavaScript directly into your site\'s frontend. Only enable for AI agents authenticated as an administrator.', 'mcp-abilities' );
    }
}
