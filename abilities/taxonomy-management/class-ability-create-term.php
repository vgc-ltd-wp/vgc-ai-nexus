<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Term_Access;

defined( 'ABSPATH' ) || exit;

class Create_Term_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_term';
        $this->label        = __( 'Create Term', 'mcp-abilities' );
        $this->description  = 'Create a new taxonomy term. Its ID comes back as "id" (and "term_id").';
        $this->required_cap = 'manage_categories';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'        => [ 'type' => 'string' ],
                'taxonomy'    => [ 'type' => 'string', 'default' => 'category' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string', 'description' => self::TEXT_RULE_DESCRIPTIONS['term'] ],
                'parent'      => [ 'type' => 'integer', 'description' => 'Parent term ID.' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    public function execute( array $params ): array {
        $taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );
        $denied   = Term_Access::create_error( $taxonomy );
        if ( null !== $denied ) {
            return $this->error( $denied );
        }

        // Name and description slashed: wp_insert_term() unslashes them, so every
        // backslash was lost ("C:\Temp" became "C:Temp"; 2.51.0).
        $args = [];
        $desc_sent = null;
        if ( ! empty( $params['slug'] ) )        $args['slug']        = sanitize_title( $params['slug'] );
        if ( ! empty( $params['description'] ) ) {
            // WordPress's own rule for term descriptions: basic markup kept (2.53.0;
            // it was plain text). What it removed is said from what was stored.
            $desc_sent           = is_scalar( $params['description'] ) ? (string) $params['description'] : '';
            $args['description'] = wp_slash( self::rich_text( $desc_sent, self::TEXT_RULE_TERM ) );
        }
        if ( ! empty( $params['parent'] ) )       $args['parent']      = absint( $params['parent'] );

        $result = wp_insert_term( wp_slash( sanitize_text_field( $params['name'] ) ), $taxonomy, $args );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        // "id" as well (2.47.0): a later step referred to "id" and lost a request.
        // The stored slug too (2.52.0): a slug in use becomes "-2" without a word.
        $out  = [
            'id'               => (int) $result['term_id'],
            'term_id'          => $result['term_id'],
            'term_taxonomy_id' => $result['term_taxonomy_id'],
        ];
        $out  = self::with_text_note( $out, $desc_sent, Term_Access::stored_description( (int) $result['term_id'], $taxonomy ), self::TEXT_RULE_TERM, 'term description', 'description' );
        $term = get_term( (int) $result['term_id'], $taxonomy );
        if ( $term instanceof \WP_Term ) {
            $out['slug'] = $term->slug;
            $asked       = ! empty( $params['slug'] ) ? trim( (string) $params['slug'] ) : '';
            if ( '' !== $asked && urldecode( $term->slug ) !== $asked ) {
                $out['slug_note'] = sanitize_title( $asked ) !== $term->slug
                    ? sprintf( 'The slug "%s" is already used by another term, so WordPress stored "%s".', urldecode( sanitize_title( $asked ) ), urldecode( $term->slug ) )
                    : sprintf( 'WordPress stores slugs in lowercase, without spaces or most punctuation: "%s".', urldecode( $term->slug ) );
            }
        }
        return $this->json_result( $out );
    }
}
