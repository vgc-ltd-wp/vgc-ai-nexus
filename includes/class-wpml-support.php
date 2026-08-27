<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Optional WPML integration helpers.
 *
 * All methods are safe to call when WPML is not installed — they no-op or
 * return null so core abilities keep working on non-multilingual sites.
 *
 * Language scoping is done by temporarily switching WPML's active language
 * around a WP_Query (WPML filters queries by the active language, including
 * "all"). Callers should always restore() afterwards.
 */
final class Wpml_Support {

    public static function active(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' );
    }

    /** Current request language code, or null when WPML is inactive. */
    public static function current_language(): ?string {
        if ( ! self::active() ) {
            return null;
        }
        $code = apply_filters( 'wpml_current_language', null );
        return $code ? (string) $code : null;
    }

    /** Site default language code, or null when WPML is inactive. */
    public static function default_language(): ?string {
        if ( ! self::active() ) {
            return null;
        }
        $code = apply_filters( 'wpml_default_language', null );
        return $code ? (string) $code : null;
    }

    /** True if the given code is an active WPML language. */
    public static function is_valid_language( string $code ): bool {
        if ( ! self::active() ) {
            return false;
        }
        $langs = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
        return is_array( $langs ) && isset( $langs[ $code ] );
    }

    /**
     * Switch WPML to a language for the duration of a query. Pass 'all' to
     * disable language filtering. Returns the previous language so the caller
     * can restore() it, or null when WPML is inactive.
     */
    public static function switch_to( string $language ): ?string {
        if ( ! self::active() || '' === $language ) {
            return null;
        }
        $previous = self::current_language();
        do_action( 'wpml_switch_language', 'all' === $language ? 'all' : $language );
        return $previous;
    }

    /** Restore the language previously returned by switch_to(). */
    public static function restore( ?string $previous ): void {
        if ( self::active() && null !== $previous ) {
            do_action( 'wpml_switch_language', $previous );
        }
    }

    /** Language code of a post, or null when WPML is inactive. */
    public static function post_language( int $post_id ): ?string {
        if ( ! self::active() ) {
            return null;
        }
        $details = apply_filters( 'wpml_post_language_details', null, $post_id );
        if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
            return (string) $details['language_code'];
        }
        return null;
    }

    /** Translation-group id (trid) of a post, or null. */
    public static function post_trid( int $post_id, string $post_type ): ?int {
        if ( ! self::active() ) {
            return null;
        }
        $trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
        return $trid ? (int) $trid : null;
    }

    /**
     * Language code of a taxonomy term, or null.
     *
     * Looked up via the term_taxonomy_id read straight from the DB: WPML's
     * element tables key terms by term_taxonomy_id, and reading the id directly
     * avoids WPML's own get_term/get_term_by filters, which in a wrong-language
     * request context can silently translate the id to a sibling term.
     */
    public static function term_language( int $term_id, string $taxonomy ): ?string {
        if ( ! self::active() ) {
            return null;
        }
        global $wpdb;
        $tt_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
            $term_id,
            $taxonomy
        ) );
        if ( ! $tt_id ) {
            return null;
        }
        $code = apply_filters( 'wpml_element_language_code', null, [
            'element_id'   => (int) $tt_id,
            'element_type' => 'tax_' . $taxonomy,
        ] );
        return $code ? (string) $code : null;
    }
}
