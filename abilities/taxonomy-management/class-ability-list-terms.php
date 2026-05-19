<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Terms_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_terms';
        $this->label        = __( 'List Terms', 'mcp-abilities' );
        $this->description  = 'List taxonomy terms (categories, tags, or any registered taxonomy).';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'taxonomy'   => [ 'type' => 'string',  'description' => 'Taxonomy slug. Default: category.', 'default' => 'category' ],
                'parent'     => [ 'type' => 'integer', 'description' => 'Filter by parent term ID.' ],
                'search'     => [ 'type' => 'string' ],
                'per_page'   => [ 'type' => 'integer', 'default' => 50 ],
                'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return $this->error( "Taxonomy '{$taxonomy}' does not exist." );
        }

        $args = [
            'taxonomy'   => $taxonomy,
            'number'     => min( absint( $params['per_page'] ?? 50 ), 200 ),
            'hide_empty' => ! empty( $params['hide_empty'] ),
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];
        if ( isset( $params['parent'] ) )   $args['parent'] = absint( $params['parent'] );
        if ( ! empty( $params['search'] ) ) $args['search'] = sanitize_text_field( $params['search'] );

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            return $this->error( $terms->get_error_message() );
        }

        $result = array_map( fn( $t ) => [
            'id'          => $t->term_id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'description' => $t->description,
            'parent_id'   => $t->parent,
            'count'       => $t->count,
        ], $terms );

        return $this->json_result( [ 'taxonomy' => $taxonomy, 'terms' => $result ] );
    }
}
