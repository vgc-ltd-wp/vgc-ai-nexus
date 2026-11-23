<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Term_Access;
use MCP_Abilities\Wpml_Support;

defined( 'ABSPATH' ) || exit;

class Update_Term_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_term';
        $this->label        = __( 'Update Term', 'mcp-abilities' );
        $this->description  = 'Update an existing taxonomy term (name, slug, description, parent). Works with WPML translated terms: the update runs in the term\'s own language context, so editing a translated term no longer fails with a false "slug already in use" error. Pass only the fields you want to change — a description-only edit never touches the slug.';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'          => [ 'type' => 'integer' ],
                'taxonomy'    => [ 'type' => 'string', 'default' => 'category' ],
                'name'        => [ 'type' => 'string' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string', 'description' => self::TEXT_RULE_DESCRIPTIONS['term'] ],
                'parent'      => [ 'type' => 'integer' ],
                'language'    => [ 'type' => 'string', 'description' => 'WPML only, optional: language code of the term (e.g. "de"). Normally auto-detected from the term itself — pass only to override.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $term_id  = absint( $params['id'] );
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );
        $denied   = Term_Access::term_error( $term_id, $taxonomy, 'edit_term' );
        if ( null !== $denied ) {
            return $this->error( $denied );
        }

        // Name and description slashed: wp_update_term() unslashes them, so every
        // backslash was lost (2.51.0).
        $args = [];
        if ( isset( $params['name'] ) )        $args['name']        = wp_slash( sanitize_text_field( $params['name'] ) );
        if ( isset( $params['slug'] ) )         $args['slug']        = sanitize_title( $params['slug'] );
        $desc_sent = null;
        if ( isset( $params['description'] ) ) {
            // WordPress's own rule for term descriptions: basic markup kept (2.53.0;
            // it was plain text). What it removed is said from what was stored.
            $desc_sent           = is_scalar( $params['description'] ) ? (string) $params['description'] : '';
            $args['description'] = wp_slash( self::rich_text( $desc_sent, self::TEXT_RULE_TERM ) );
        }
        if ( isset( $params['parent'] ) )       $args['parent']      = absint( $params['parent'] );

        if ( ! $args ) {
            return $this->error( 'Nothing to update: pass at least one of name, slug, description, parent.' );
        }

        // WPML: run the update in the TERM'S language context. wp_update_term()
        // merges the existing slug back into every update and then checks it for
        // uniqueness via get_term_by(); in the wrong language context WPML's
        // filters resolve that lookup to a different-language sibling, and core
        // rejects the edit with a false "slug already in use by another term".
        $language = isset( $params['language'] ) && '' !== trim( (string) $params['language'] )
            ? sanitize_key( (string) $params['language'] )
            : null;
        $previous = null;
        if ( Wpml_Support::active() ) {
            if ( null === $language ) {
                $language = Wpml_Support::term_language( $term_id, $taxonomy );
            }
            if ( null !== $language && ! Wpml_Support::is_valid_language( $language ) ) {
                return $this->error( sprintf( 'Unknown WPML language code "%s".', $language ) );
            }
            if ( null !== $language ) {
                $previous = Wpml_Support::switch_to( $language );
            }
        }

        $result = wp_update_term( $term_id, $taxonomy, $args );

        Wpml_Support::restore( $previous );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        $extra = array_filter( [
            'id'       => $term_id,
            'taxonomy' => $taxonomy,
            'language' => $language,
            'updated'  => array_keys( $args ),
        ] );
        // The stored slug, with a note when it is not the one sent (2.52.0).
        if ( isset( $args['slug'] ) ) {
            $term = get_term( $term_id, $taxonomy );
            if ( $term instanceof \WP_Term ) {
                $extra['slug'] = $term->slug;
                if ( $term->slug !== $args['slug'] ) {
                    $extra['slug_note'] = sprintf( 'The slug "%s" is already used by another term, so WordPress stored "%s".', urldecode( $args['slug'] ), urldecode( $term->slug ) );
                }
            }
        }
        if ( null !== $desc_sent ) {
            $extra = self::with_text_note( $extra, $desc_sent, Term_Access::stored_description( $term_id, $taxonomy ), self::TEXT_RULE_TERM, 'term description', 'description' );
        }
        return $this->success( "Term {$term_id} updated.", $extra );
    }
}
