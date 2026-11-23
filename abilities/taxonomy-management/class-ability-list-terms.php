<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Query_Support;
use MCP_Abilities\Term_Access;
use MCP_Abilities\Wpml_Support;

defined( 'ABSPATH' ) || exit;

class List_Terms_Ability extends Ability {

    /** The fields "fields" may name (2.47.0). */
    public const ITEM_FIELDS = [ 'id', 'name', 'slug', 'description', 'parent_id', 'count' ];

    protected function define_meta(): void {
        $this->key          = 'list_terms';
        $this->label        = __( 'List Terms', 'mcp-abilities' );
        $this->description  = 'List taxonomy terms (categories, tags, or any taxonomy with an admin screen or public archives; internal ones such as menus are refused). On WPML sites pass "language" to list a specific language\'s terms (e.g. "de"), or "all" for every language — without it only the default language is returned and translated terms are invisible.';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'taxonomy'   => [ 'type' => 'string',  'description' => 'Taxonomy slug. Default: category.', 'default' => 'category' ],
                'parent'     => [ 'type' => 'integer', 'description' => 'Filter by parent term ID.' ],
                'search'     => [ 'type' => 'string' ],
                'per_page'   => [ 'type' => 'integer', 'default' => 50, 'description' => 'Terms per answer (1-200). The result has total and has_more; use page or offset for the rest.' ],
                'page'       => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'offset'     => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'How many terms to skip; overrides page.' ],
                'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
                'language'   => [ 'type' => 'string', 'description' => 'WPML only: language code (e.g. "de", "cs") or "all" for every language. Default: the site\'s default language.' ],
                'fields'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Only these fields of each term (id is always kept), e.g. ["id", "name"]: a smaller answer. Fields: ' . implode( ', ', self::ITEM_FIELDS ) . ' (and language with WPML).' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return $this->error( "Taxonomy '{$taxonomy}' does not exist." );
        }
        $refused = Term_Access::read_error( $taxonomy );
        if ( null !== $refused ) {
            return $this->error( $refused );
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

        // Pages (2.52.0): up to 200 terms an answer, and "total" / "has_more" say
        // how many there are. Until then a bigger taxonomy was cut at per_page
        // (50 by default) with nothing saying so, and could not be listed whole.
        $per_page = isset( $params['per_page'] ) && (int) $params['per_page'] > 0 ? min( (int) $params['per_page'], 200 ) : 50;
        if ( isset( $params['offset'] ) && '' !== $params['offset'] ) {
            if ( (int) $params['offset'] < 0 ) {
                Wpml_Support::restore( $previous );
                return $this->error( 'offset cannot be negative.' );
            }
            $offset = (int) $params['offset'];
            $page   = (int) floor( $offset / $per_page ) + 1;
        } else {
            $page   = max( 1, (int) ( $params['page'] ?? 1 ) );
            $offset = ( $page - 1 ) * $per_page;
        }
        if ( isset( $params['parent'] ) && (int) $params['parent'] < 0 ) {
            Wpml_Support::restore( $previous );
            return $this->error( 'parent must be a term ID (0 for top-level terms), not a negative number.' );
        }

        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => ! empty( $params['hide_empty'] ),
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];
        if ( isset( $params['parent'] ) )   $args['parent'] = absint( $params['parent'] );
        if ( ! empty( $params['search'] ) ) $args['search'] = sanitize_text_field( $params['search'] );

        $total = wp_count_terms( $args );
        $terms = get_terms( array_merge( $args, [ 'number' => $per_page, 'offset' => $offset ] ) );

        Wpml_Support::restore( $previous );

        if ( is_wp_error( $terms ) ) {
            return $this->error( $terms->get_error_message() );
        }
        $total = is_wp_error( $total ) ? count( $terms ) : (int) $total;

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

        // "fields" (2.47.0): only the fields named; unknown names are said, not guessed at.
        $fields = $wpml ? array_merge( self::ITEM_FIELDS, [ 'language' ] ) : self::ITEM_FIELDS;
        $split  = Query_Support::split_fields( Query_Support::meta_keys( $params ), $fields, false );
        if ( null !== $split['keep'] ) {
            $result = array_map( static fn( array $row ): array => Query_Support::finish_item( $row, $split['keep'] ), $result );
        }

        $out = [
            'taxonomy' => $taxonomy,
            'terms'    => $result,
            'total'    => $total,
            'per_page' => $per_page,
            'page'     => $page,
            'offset'   => $offset,
            'has_more' => $offset + count( $result ) < $total,
        ];
        if ( $out['has_more'] ) {
            $out['note'] = sprintf( 'Terms %d-%d of %d. For the next ones send "offset": %d (or "page": %d).', $offset + 1, $offset + count( $result ), $total, $offset + count( $result ), $page + 1 );
        }
        // "language" only when it did something: with WPML off it was echoed as if
        // the list were in that language (2.52.0).
        if ( null !== $language && Wpml_Support::active() ) {
            $out['language'] = $language;
        } elseif ( null !== $language ) {
            $out = self::with_note( $out, 'WPML is not active on this site, so "language" was ignored: these are all the terms.' );
        }
        $note = Query_Support::fields_note( $result, [], $split['unknown'], $fields );
        if ( null !== $note ) {
            $out['fields_note'] = $note;
        }
        if ( ! Query_Support::meta_keys( $params ) ) {
            // 2.48.0: a big list of whole terms says how small it is with "fields".
            $out = Query_Support::with_size_note( $out, $result, Query_Support::compact_fields( $fields ) );
        }
        return $this->json_result( $out );
    }
}
