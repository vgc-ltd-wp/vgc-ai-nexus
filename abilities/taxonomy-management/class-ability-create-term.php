<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Create_Term_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_term';
        $this->label        = __( 'Create Term', 'mcp-abilities' );
        $this->description  = 'Create a new taxonomy term.';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'        => [ 'type' => 'string' ],
                'taxonomy'    => [ 'type' => 'string', 'default' => 'category' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'parent'      => [ 'type' => 'integer', 'description' => 'Parent term ID.' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    public function execute( array $params ): array {
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return $this->error( "Taxonomy '{$taxonomy}' does not exist." );
        }

        $args = [];
        if ( ! empty( $params['slug'] ) )        $args['slug']        = sanitize_title( $params['slug'] );
        if ( ! empty( $params['description'] ) )  $args['description'] = sanitize_textarea_field( $params['description'] );
        if ( ! empty( $params['parent'] ) )       $args['parent']      = absint( $params['parent'] );

        $result = wp_insert_term( sanitize_text_field( $params['name'] ), $taxonomy, $args );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->json_result( [
            'term_id'          => $result['term_id'],
            'term_taxonomy_id' => $result['term_taxonomy_id'],
        ] );
    }
}
