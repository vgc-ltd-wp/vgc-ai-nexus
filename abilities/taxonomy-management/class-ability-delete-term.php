<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Delete_Term_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_term';
        $this->label        = __( 'Delete Term', 'mcp-abilities' );
        $this->description  = 'Delete a taxonomy term.';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'       => [ 'type' => 'integer' ],
                'taxonomy' => [ 'type' => 'string', 'default' => 'category' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $term_id  = absint( $params['id'] );
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );

        if ( ! get_term( $term_id, $taxonomy ) ) {
            return $this->error( "Term {$term_id} not found in taxonomy '{$taxonomy}'." );
        }

        $result = wp_delete_term( $term_id, $taxonomy );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }
        if ( false === $result ) {
            return $this->error( "Failed to delete term {$term_id}." );
        }
        return $this->success( "Term {$term_id} deleted." );
    }
}
