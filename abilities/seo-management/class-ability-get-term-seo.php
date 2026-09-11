<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Read a term's Yoast SEO meta.
 *
 * The raw wpseo_taxonomy_meta option is (rightly) closed to the generic
 * get_option ability, which left term SEO writes unverifiable from the
 * outside. This is the sanctioned read: one term's values, fetched through
 * Yoast's own accessor after busting the option cache, so it reflects the
 * database — not a stale object-cache copy.
 */
class Get_Term_Seo_Ability extends Ability {

    /** Yoast meta key => ability field (the inverse of update_term_seo's map). */
    private const FIELD_MAP = [
        'wpseo_desc'      => 'meta_description',
        'wpseo_title'     => 'seo_title',
        'wpseo_focuskw'   => 'focus_keyword',
        'wpseo_canonical' => 'canonical',
        'wpseo_noindex'   => 'noindex',
    ];

    protected function define_meta(): void {
        $this->key          = 'get_term_seo';
        $this->label        = __( 'Get Term SEO', 'mcp-abilities' );
        $this->description  = 'Read the Yoast SEO fields stored on a taxonomy term (meta description, SEO title, focus keyword, canonical, noindex), fresh from the database — use it to verify what update_term_seo actually saved, or to audit terms. Empty string means the field is unset and Yoast falls back to its Search Appearance template. Requires Yoast SEO.';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'       => [ 'type' => 'integer', 'description' => 'Term ID (of the exact term/translation to read).' ],
                'taxonomy' => [ 'type' => 'string', 'description' => 'Taxonomy slug. Default: category.', 'default' => 'category' ],
            ],
            'required'   => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! class_exists( '\WPSEO_Taxonomy_Meta' ) || ! method_exists( '\WPSEO_Taxonomy_Meta', 'get_term_meta' ) ) {
            return $this->error( 'Yoast SEO is not active on this site — get_term_seo needs it.' );
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

        // Read fresh: a persistent object cache may hold a stale option copy.
        wp_cache_delete( 'wpseo_taxonomy_meta', 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        $meta = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy );
        if ( ! is_array( $meta ) ) {
            $meta = [];
        }

        $fields = [];
        foreach ( self::FIELD_MAP as $meta_key => $field ) {
            $fields[ $field ] = isset( $meta[ $meta_key ] ) ? (string) $meta[ $meta_key ] : '';
        }

        return $this->json_result( [
            'id'       => $term_id,
            'taxonomy' => $taxonomy,
            'name'     => $term->name,
            'seo'      => $fields,
        ] );
    }
}
