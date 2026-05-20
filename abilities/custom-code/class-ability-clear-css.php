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

    public function execute( array $params ): array {
        $result = wp_update_custom_css_post( '' );

        if ( is_wp_error( $result ) ) {
            return $this->error( 'Failed to clear CSS: ' . $result->get_error_message() );
        }

        return $this->success( 'Custom CSS cleared. The Additional CSS field is now empty.' );
    }
}
