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

    /**
     * Hard kill-switch for the custom-code (inject CSS/JS) abilities.
     *
     * Locked-down sites can fully disable these abilities — so they are never
     * registered, never exposed as MCP tools, and cannot be re-enabled from the
     * admin UI — by either:
     *
     *   define( 'MCP_ABILITIES_DISABLE_CUSTOM_CODE', true );   // in wp-config.php
     *
     * or returning false from the 'mcp_abilities_custom_code_enabled' filter.
     *
     * Note: this only governs the AI abilities. It does not remove CSS/JS an
     * administrator previously stored; that frontend output is admin-managed.
     */
    public function is_locked(): bool {
        $enabled = ! ( defined( 'MCP_ABILITIES_DISABLE_CUSTOM_CODE' ) && MCP_ABILITIES_DISABLE_CUSTOM_CODE );
        $enabled = (bool) apply_filters( 'mcp_abilities_custom_code_enabled', $enabled );
        return ! $enabled;
    }

    public function is_enabled(): bool {
        return $this->is_locked() ? false : parent::is_enabled();
    }

    public function get_mcp_abilities(): array {
        return $this->is_locked() ? array() : parent::get_mcp_abilities();
    }
}
