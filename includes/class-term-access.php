<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Permission rules for term writes (create_term, update_term, delete_term,
 * update_term_seo), as WordPress applies them on its own term screens and in
 * WP_REST_Terms_Controller.
 *
 * The tool gate is manage_categories; these add the taxonomy's own
 * capabilities: product categories need the WooCommerce term capabilities,
 * and taxonomies without an admin screen (show_ui false: menus, block
 * template areas, WooCommerce product types and visibility, themes) are not
 * edited through these tools at all.
 */
final class Term_Access {

    /**
     * The taxonomy, or why it cannot be edited through a tool.
     *
     * @return \WP_Taxonomy|string
     */
    public static function taxonomy( string $taxonomy ) {
        $tax = get_taxonomy( $taxonomy );
        if ( ! $tax ) {
            return sprintf( 'Taxonomy "%s" does not exist.', $taxonomy );
        }
        if ( ! $tax->show_ui ) {
            return sprintf( 'Taxonomy "%s" has no admin screen: WordPress or a plugin manages its terms, so they are not edited with this tool (menus have their own tools).', $taxonomy );
        }
        return $tax;
    }

    /** Why the current user may not add a term to this taxonomy, or null. */
    public static function create_error( string $taxonomy ): ?string {
        $tax = self::taxonomy( $taxonomy );
        if ( is_string( $tax ) ) {
            return $tax;
        }
        if ( ! current_user_can( $tax->cap->manage_terms ) || ! current_user_can( $tax->cap->edit_terms ) ) {
            return sprintf( 'You do not have permission to add terms to "%s" (requires %s).', $taxonomy, $tax->cap->edit_terms );
        }
        return null;
    }

    /**
     * Why the current user may not change or delete this term, or null.
     *
     * @param string $cap 'edit_term' or 'delete_term' (meta capabilities).
     */
    public static function term_error( int $term_id, string $taxonomy, string $cap ): ?string {
        $tax = self::taxonomy( $taxonomy );
        if ( is_string( $tax ) ) {
            return $tax;
        }
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return sprintf( 'Term %d was not found in taxonomy "%s".', $term_id, $taxonomy );
        }
        if ( ! current_user_can( $cap, $term_id ) ) {
            return 'delete_term' === $cap
                ? sprintf( 'You do not have permission to delete this term (requires %s; the default category can never be deleted).', $tax->cap->delete_terms )
                : sprintf( 'You do not have permission to edit this term (requires %s).', $tax->cap->edit_terms );
        }
        return null;
    }
}
