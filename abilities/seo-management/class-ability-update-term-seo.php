<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Term-level Yoast SEO writes, through Yoast's own API — hardened.
 *
 * Yoast stores ALL taxonomy SEO meta in ONE site-wide option
 * (wpseo_taxonomy_meta, keyed [taxonomy][term_id]), and its set_values() is an
 * unserialized read-modify-write of that whole record. Field reality from a
 * customer's Semrush audit (hundreds of term writes in quick succession):
 *
 *  1. Concurrent writes lose updates: two set_values() close together read the
 *     same base and the second overwrites the first's terms. A persistent
 *     object cache serving a stale option copy widens the window. Writes here
 *     are therefore serialized under a MySQL GET_LOCK with the option cache
 *     busted before the read and after the write.
 *  2. Yoast's validate_term_meta_data() rebuilds a term's record from DEFAULTS
 *     plus only the submitted keys — desc/title/focuskw/canonical have no
 *     "retain old" branch. A naive partial update silently wipes the term's
 *     other SEO fields, so the existing values are merged in before saving.
 *  3. WPSEO_Taxonomy_Meta::get_term_meta() prefixes the requested key with
 *     "wpseo_" itself; passing an already-prefixed key made every read-back
 *     return false ("stored": false on successful saves). Read-backs use the
 *     full meta array instead, after a fresh cache-busted read.
 *
 * The response reports what is ACTUALLY stored post-write, verified per field;
 * a mismatch (e.g. characters mangled by a non-utf8mb4 DB connection) is
 * flagged rather than hidden.
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

    private const LOCK_NAME    = 'vgc_wpseo_tax_meta_lock';
    private const LOCK_TIMEOUT = 10; // seconds

    protected function define_meta(): void {
        $this->key          = 'update_term_seo';
        $this->label        = __( 'Update Term SEO', 'mcp-abilities' );
        $this->description  = 'Set Yoast SEO fields on a taxonomy term (category, tag, or any taxonomy): meta description, SEO title, focus keyword, canonical URL, noindex. Safe for bulk runs: writes are serialized under a database lock (no lost updates when calls land close together), only the fields you pass are changed (existing fields are preserved), and the response returns the values as re-read from the database after saving, with a per-field verified flag — if "verified" is false for a field, the stored value differs from what was sent (the response shows both). Works for WPML translated terms directly by term id. Requires Yoast SEO. Read current values with get_term_seo.';
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
        global $wpdb;

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

        // Serialize the whole read-modify-write across ALL callers/processes:
        // the shared option means unlocked concurrent writes lose updates.
        $got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, self::LOCK_TIMEOUT ) );
        if ( '1' !== (string) $got_lock ) {
            return $this->error( 'Another SEO write is in progress and did not finish within 10s — retry this call.' );
        }

        try {
            // Fresh read: a persistent object cache may hold a stale copy.
            self::bust_option_cache();

            // Merge with the term's EXISTING meta: Yoast's validator rebuilds
            // the record from defaults + submitted keys only, so an unmerged
            // partial update would wipe the fields not being edited.
            $old = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy );
            if ( ! is_array( $old ) ) {
                $old = [];
            }
            $merged = array_merge( $old, $values );

            \WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $merged );

            // Post-write bust so this read — and every later reader — sees the DB.
            self::bust_option_cache();
            $fresh = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
        }

        if ( ! is_array( $fresh ) ) {
            return $this->error( 'The write completed but the term meta could not be re-read for verification.' );
        }

        // Report what is ACTUALLY stored, verified field by field.
        $stored       = [];
        $verified     = [];
        $all_verified = true;
        foreach ( $values as $meta_key => $sent ) {
            $field            = (string) array_search( $meta_key, self::FIELD_MAP, true );
            $now              = isset( $fresh[ $meta_key ] ) ? (string) $fresh[ $meta_key ] : '';
            $stored[ $field ] = $now;
            // '' and Yoast's stripped-default are equivalent for our fields.
            $ok                 = ( $now === $sent ) || ( '' === $sent && in_array( $now, [ '', 'default' ], true ) ) || ( 'default' === $sent && '' === $now );
            $verified[ $field ] = $ok;
            $all_verified       = $all_verified && $ok;
        }

        $extra = [
            'id'       => $term_id,
            'taxonomy' => $taxonomy,
            'name'     => $term->name,
            'stored'   => $stored,
            'verified' => $verified,
        ];
        if ( ! $all_verified ) {
            $extra['warning']    = 'One or more fields were stored DIFFERENTLY than sent (see stored vs your input). If characters are missing or replaced, the database connection is likely not utf8mb4 — that conversion happens below WordPress and cannot be fixed by this tool.';
            $extra['db_charset'] = (string) $wpdb->charset;
        }

        return $this->success(
            sprintf(
                $all_verified
                    ? 'SEO meta updated on term %d (%s) — all fields verified against the database.'
                    : 'SEO meta written on term %d (%s), but verification found differences — inspect "stored".',
                $term_id,
                $term->name
            ),
            $extra
        );
    }

    /** Drop any cached copy of the shared Yoast option so reads hit the DB. */
    private static function bust_option_cache(): void {
        wp_cache_delete( 'wpseo_taxonomy_meta', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }
}
