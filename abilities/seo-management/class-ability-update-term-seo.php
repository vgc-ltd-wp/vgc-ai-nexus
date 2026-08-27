<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Term-level Yoast SEO writes, through Yoast's own API.
 *
 * Yoast stores taxonomy SEO meta in ONE site-wide option (wpseo_taxonomy_meta,
 * keyed [taxonomy][term_id]). Raw option access is the wrong tool for it twice
 * over: reads are denied (the wpseo_ prefix carries licence keys), and a raw
 * write would overwrite every term's meta at once. WPSEO_Taxonomy_Meta handles
 * the read-merge-write and validation, is WPML-agnostic (term id addresses the
 * translation directly), and never touches the term row — so no slug checks.
 */
class Update_Term_Seo_Ability extends Ability {

    /** Ability field => Yoast meta key. */
    private const FIELD_MAP = [
        'meta_description' => 'wpseo_desc',
        'seo_title'        => 'wpseo_title',
        'focus_keyword'    => 'wpseo_focuskw',
        'canonical'        => 'wpseo_canonical',
        'noindex'          => 'wpseo_noindex',
    ];

    protected function define_meta(): void {
        $this->key          = 'update_term_seo';
        $this->label        = __( 'Update Term SEO', 'mcp-abilities' );
        $this->description  = 'Set Yoast SEO fields on a taxonomy term (category, tag, or any taxonomy): meta description, SEO title, focus keyword, canonical URL, noindex. Writes through Yoast\'s own API, so it works for WPML translated terms directly by term id — no language context or slug involved. Only the fields you pass are changed. Requires Yoast SEO.';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'               => [ 'type' => 'integer', 'description' => 'Term ID (of the exact term/translation to edit).' ],
                'taxonomy'         => [ 'type' => 'string', 'description' => 'Taxonomy slug. Default: category.', 'default' => 'category' ],
                'meta_description' => [ 'type' => 'string', 'description' => 'Yoast meta description for the term archive. Empty string clears it.' ],
                'seo_title'        => [ 'type' => 'string', 'description' => 'Yoast SEO title. Empty string resets to the template default.' ],
                'focus_keyword'    => [ 'type' => 'string', 'description' => 'Yoast focus keyphrase.' ],
                'canonical'        => [ 'type' => 'string', 'description' => 'Canonical URL override.' ],
                'noindex'          => [ 'type' => 'string', 'enum' => [ 'default', 'index', 'noindex' ], 'description' => 'Search engine visibility of the term archive.' ],
            ],
            'required'   => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! class_exists( '\WPSEO_Taxonomy_Meta' ) ) {
            return $this->error( 'Yoast SEO is not active on this site — update_term_seo needs it.' );
        }
        if ( ! method_exists( '\WPSEO_Taxonomy_Meta', 'set_values' ) || ! method_exists( '\WPSEO_Taxonomy_Meta', 'get_term_meta' ) ) {
            return $this->error( 'This Yoast SEO version does not expose the taxonomy meta API this tool uses.' );
        }

        $term_id  = absint( $params['id'] ?? 0 );
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return $this->error( "Taxonomy '{$taxonomy}' does not exist." );
        }
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return $this->error( "Term {$term_id} was not found in taxonomy '{$taxonomy}'." );
        }

        $values = [];
        foreach ( self::FIELD_MAP as $field => $meta_key ) {
            if ( ! array_key_exists( $field, $params ) ) {
                continue;
            }
            $raw = (string) $params[ $field ];
            if ( 'canonical' === $field ) {
                $raw = '' === $raw ? '' : esc_url_raw( $raw );
            } elseif ( 'noindex' === $field ) {
                $raw = in_array( $raw, [ 'default', 'index', 'noindex' ], true ) ? $raw : 'default';
            } else {
                $raw = sanitize_text_field( $raw );
            }
            $values[ $meta_key ] = $raw;
        }
        if ( ! $values ) {
            return $this->error( 'Nothing to update: pass at least one of meta_description, seo_title, focus_keyword, canonical, noindex.' );
        }

        \WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $values );

        // Read back through Yoast so the result reflects what was actually stored.
        $stored = [];
        foreach ( $values as $meta_key => $_ ) {
            $stored[ $meta_key ] = \WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy, $meta_key );
        }

        return $this->success( sprintf( 'SEO meta updated on term %d (%s).', $term_id, $term->name ), [
            'id'       => $term_id,
            'taxonomy' => $taxonomy,
            'name'     => $term->name,
            'stored'   => $stored,
        ] );
    }
}
