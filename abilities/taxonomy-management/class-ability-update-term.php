<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Update_Term_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_term';
        $this->label        = __( 'Update Term', 'mcp-abilities' );
        $this->description  = 'Update an existing taxonomy term.';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'          => [ 'type' => 'integer' ],
                'taxonomy'    => [ 'type' => 'string', 'default' => 'category' ],
                'name'        => [ 'type' => 'string' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'parent'      => [ 'type' => 'integer' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $term_id  = absint( $params['id'] );
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );

        $args = [];
        if ( isset( $params['name'] ) )        $args['name']        = sanitize_text_field( $params['name'] );
        if ( isset( $params['slug'] ) )         $args['slug']        = sanitize_title( $params['slug'] );
        if ( isset( $params['description'] ) )  $args['description'] = sanitize_textarea_field( $params['description'] );
        if ( isset( $params['parent'] ) )       $args['parent']      = absint( $params['parent'] );

        $result = wp_update_term( $term_id, $taxonomy, $args );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "Term {$term_id} updated." );
    }
}
