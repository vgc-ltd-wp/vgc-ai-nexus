<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class User_Management_Group extends Ability_Group {

    protected bool $default_enabled = false;

    protected function define_meta(): void {
        $this->slug             = 'user-management';
        $this->label            = __( 'User Management', 'mcp-abilities' );
        $this->description      = __( 'List, inspect, create, update and delete WordPress users.', 'mcp-abilities' );
        $this->icon             = 'dashicons-admin-users';
        $this->guide       = __( "Administer WordPress accounts: look up users, create new ones with a given role, update profiles and roles, and remove accounts. High-impact - keep it restricted to trusted connectors.", 'mcp-abilities' );
        $this->examples    = [
            __( "Create an editor account for jane@example.com", 'mcp-abilities' ),
            __( "List all administrators on the site", 'mcp-abilities' ),
            __( "Change Bob's role to Author", 'mcp-abilities' ),
        ];
        $this->security_warning = __( 'Security risk: enabling these tools gives AI agents the ability to list all user accounts, create new administrators, change passwords, and delete users. Only enable this if you fully trust the connected AI client and the credentials used to authenticate it.', 'mcp-abilities' );
    }
}
