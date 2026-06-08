<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * MCP Revision Guard.
 *
 * Ensures that when an ability updates a post during an MCP request, the
 * resulting WordPress revision (created natively by wp_update_post when the post
 * type supports revisions and a revisioned field changed) is:
 *
 *   1. Attributed to the authenticating user — the WP user whose Application
 *      Password / OAuth token the request used. WordPress already defaults a
 *      revision's author to the current user; this enforces it as a safety net
 *      against setups that copy the parent author into revisions.
 *   2. Tagged with audit meta (_vgc_mcp / _vgc_mcp_actor / _vgc_mcp_tool) so
 *      AI-made revisions are distinguishable from human edits.
 *
 * It arms only during MCP REST requests, so normal wp-admin editing is
 * unaffected. It never forces a revision when the post type has revisions
 * disabled — i.e. it records "if revisions are activated", as required.
 *
 * A shared constant (VGC_MCP_REVISION_GUARD) guarantees only one instance runs
 * when AI Nexus and a connector plugin are active together.
 */
final class Revision_Support {

    private static bool   $armed = false;
    private static ?string $tool = null;

    public static function init(): void {
        if ( defined( 'VGC_MCP_REVISION_GUARD' ) ) {
            return; // another plugin already provides the guard
        }
        define( 'VGC_MCP_REVISION_GUARD', true );
        add_filter( 'rest_pre_dispatch', array( self::class, 'maybe_arm' ), 10, 3 );
    }

    /**
     * Arm the revision hooks for MCP server requests only.
     *
     * @param mixed            $result
     * @param mixed            $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function maybe_arm( $result, $server, $request ) {
        if ( self::$armed || ! $request instanceof \WP_REST_Request ) {
            return $result;
        }

        $route = (string) $request->get_route();
        if ( 0 !== strpos( $route, '/mcp/' ) ) {
            return $result;
        }
        if ( ! get_current_user_id() ) {
            return $result; // no user to attribute the revision to
        }

        self::$armed = true;
        self::$tool  = self::detect_tool( $request );

        add_action( '_wp_put_post_revision', array( self::class, 'on_revision' ), 10, 1 );

        return $result;
    }

    /** Best-effort extraction of the MCP tool name from a tools/call request. */
    private static function detect_tool( \WP_REST_Request $request ): ?string {
        $params = $request->get_json_params();
        if ( is_array( $params ) ) {
            if ( isset( $params['params']['name'] ) ) {
                return sanitize_text_field( (string) $params['params']['name'] );
            }
            if ( isset( $params['name'] ) ) {
                return sanitize_text_field( (string) $params['name'] );
            }
        }
        return null;
    }

    /**
     * Force authorship + add audit meta on a freshly created revision.
     */
    public static function on_revision( $revision_id ): void {
        $revision_id = (int) $revision_id;
        $uid         = get_current_user_id();
        if ( ! $uid || ! $revision_id ) {
            return;
        }

        $revision = get_post( $revision_id );
        if ( ! $revision || 'revision' !== $revision->post_type ) {
            return;
        }

        // 1) Guarantee the revision is attributed to the authenticating user.
        if ( (int) $revision->post_author !== $uid ) {
            global $wpdb;
            $wpdb->update( $wpdb->posts, array( 'post_author' => $uid ), array( 'ID' => $revision_id ) );
            clean_post_cache( $revision_id );
        }

        // 2) Audit tags (use update_metadata so it applies to the revision post).
        update_metadata( 'post', $revision_id, '_vgc_mcp', 1 );
        update_metadata( 'post', $revision_id, '_vgc_mcp_actor', $uid );
        if ( self::$tool ) {
            update_metadata( 'post', $revision_id, '_vgc_mcp_tool', self::$tool );
        }
    }
}
