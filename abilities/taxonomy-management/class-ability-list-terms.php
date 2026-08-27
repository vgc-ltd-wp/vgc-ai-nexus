<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Wpml_Support;

defined( 'ABSPATH' ) || exit;

class List_Terms_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_terms';
        $this->label        = __( 'List Terms', 'mcp-abilities' );
        $this->description  = 'List taxonomy terms (categories, tags, or any registered taxonomy). On WPML sites pass "language" to list a specific language\'s terms (e.g. "de"), or "all" for every language — without it only the default language is returned and translated terms are invisible.';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'taxonomy'   => [ 'type' => 'string',  'description' => 'Taxonomy slug. Default: category.', 'default' => 'category' ],
                'parent'     => [ 'type' => 'integer', 'description' => 'Filter by parent term ID.' ],
                'search'     => [ 'type' => 'string' ],
                'per_page'   => [ 'type' => 'integer', 'default' => 50 ],
                'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
                'language'   => [ 'type' => 'string', 'description' => 'WPML only: language code (e.g. "de", "cs") or "all" for every language. Default: the site\'s default language.' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return $this->error( "Taxonomy '{$taxonomy}' does not exist." );
        }

        $language = isset( $params['language'] ) && '' !== trim( (string) $params['language'] )
            ? strtolower( sanitize_key( (string) $params['language'] ) )
            : null;
        $previous = null;
        if ( null !== $language && Wpml_Support::active() ) {
            if ( 'all' !== $language && ! Wpml_Support::is_valid_language( $language ) ) {
                return $this->error( sprintf( 'Unknown WPML language code "%s". Use an active language code or "all".', $language ) );
            }
            $previous = Wpml_Support::switch_to( $language );
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

        Wpml_Support::restore( $previous );

        if ( is_wp_error( $terms ) ) {
            return $this->error( $terms->get_error_message() );
        }

        $wpml   = Wpml_Support::active();
        $result = array_map( function ( $t ) use ( $wpml, $taxonomy ) {
            $row = [
                'id'          => $t->term_id,
                'name'        => $t->name,
                'slug'        => $t->slug,
                'description' => $t->description,
                'parent_id'   => $t->parent,
                'count'       => $t->count,
            ];
            if ( $wpml ) {
                $row['language'] = Wpml_Support::term_language( (int) $t->term_id, $taxonomy );
            }
            return $row;
        }, $terms );

        $out = [ 'taxonomy' => $taxonomy, 'terms' => $result ];
        if ( null !== $language ) {
            $out['language'] = $language;
        }
        return $this->json_result( $out );
    }
}
