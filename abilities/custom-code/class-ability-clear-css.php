<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Clear_Css_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'clear_css';
        $this->label        = __( 'Clear CSS', 'mcp-abilities' );
        $this->description  = 'Remove all custom CSS from the site. This wipes the entire WordPress Additional CSS field. Use get_custom_code first to read what will be deleted.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [ 'type' => 'object', 'properties' => [] ];
    }

    /**
     * Emptying Additional CSS is a change to it: WordPress's Customizer and REST API
     * ask `edit_css` for that, as inject_css does. While raw code is locked the whole
     * custom-code group, this tool included, is not there at all.
     */
    public function current_user_can(): bool {
        return parent::current_user_can() && \current_user_can( 'edit_css' );
    }

    public function refusal_message(): ?string {
        return \current_user_can( 'edit_css' )
            ? null
            : 'clear_css is refused: changing the site\'s custom CSS, emptying it included, needs the "edit_css" capability on top of "manage_options", and this WordPress account does not have it on this site (multisite gives it only to super admins; DISALLOW_UNFILTERED_HTML takes it from everyone). get_custom_code can still read the CSS.';
    }

    public function execute( array $params ): array {
        // Here as well as in the gate: the group tool checks only the required capability.
        $refused = $this->refusal_message();
        if ( null !== $refused ) {
            return $this->error( $refused );
        }

        $result = wp_update_custom_css_post( '' );

        if ( is_wp_error( $result ) ) {
            return $this->error( 'Failed to clear CSS: ' . $result->get_error_message() );
        }

        return $this->success( 'Custom CSS cleared. The Additional CSS field is now empty.' );
    }
}
