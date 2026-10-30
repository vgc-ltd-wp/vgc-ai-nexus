<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Says in the revision history when an AI made a change through MCP.
 *
 * During an MCP request (Request_Context::is_mcp()):
 *
 *   - Every revision WordPress creates gets the revision meta
 *     _mcp_abilities_actor: who, through which app and sign-in, which ability,
 *     when, and (once the call is logged) the activity-log row id.
 *   - Every post the call changes — content, date, status or only meta — gets
 *     the post meta _mcp_abilities_last_change (the same record plus the
 *     revision and the post's modified time) and _edit_last = the user, since
 *     wp_update_post() does not set it and the previous human would show.
 *   - A revision is made even when WordPress would skip it: the first save of
 *     a post in a call counts as changed (date-only, status-only), and a post
 *     changed without any save (meta-only) gets one at the end of the call.
 *     At most one forced revision per post per call.
 *
 * No forced revision when the site or the caller opted out: revisions off
 * (wp_revisions_enabled false: WP_POST_REVISIONS, the filters), a post type
 * without revision support (WooCommerce products: marker + log only), an
 * auto-draft, an autosave, a read-only call, or a caller that unhooked
 * wp_save_post_revision while writing (the Avada extension's bulk runs with
 * keep_revisions=false do exactly that). Filter mcp_abilities_force_revision.
 *
 * Shown in: the revision compare screen (wp_prepare_revision_for_js), the
 * classic Revisions box (wp_post_revision_title_expanded, with a link to the
 * log row), the_modified_author, the classic Publish box, and the REST
 * revisions endpoint (field mcp_actor) — always only to users who can edit the
 * post. Nothing here changes a human's edit outside MCP.
 *
 * The meta is the audit trail: it stays when AI Nexus is uninstalled.
 */
final class Revision_Attribution {

    const META_ACTOR = '_mcp_abilities_actor';
    const META_LAST  = '_mcp_abilities_last_change';

    /** Post types that are not content. WooCommerce orders stored as posts are
     *  recorded as orders by Order_Attribution (both order storages alike). */
    private const SKIP_TYPES = [ 'revision', 'oembed_cache', 'customize_changeset', 'user_request', 'shop_order', 'shop_order_refund', 'shop_order_placehold' ];

    /** Meta that WordPress or builders write as a cache or lock, not a change. */
    private const UNTRACKED_KEYS = [
        '_edit_lock', '_edit_last', '_encloseme', '_pingme', '_trackbackme',
        '_elementor_css', '_elementor_page_assets', '_elementor_element_cache',
        self::META_ACTOR, self::META_LAST, '_vgc_mcp', '_vgc_mcp_actor', '_vgc_mcp_tool',
    ];

    /**
     * Posts the calls touched: call id => post id => state. Call id 0 holds
     * changes made outside any call frame (finished at shutdown).
     *
     * @var array<int, array<int, array{optout:bool,deleted:bool,revisions:int[]}>>
     */
    private static array $posts = [];

    /** True while this class writes its own meta. */
    private static bool $writing = false;

    public static function init(): void {
        // Changes.
        add_action( 'post_updated', [ self::class, 'on_post_updated' ], 1, 1 );
        add_action( 'wp_insert_post', [ self::class, 'on_insert_post' ], 1, 3 );
        add_action( 'added_post_meta', [ self::class, 'on_meta' ], 10, 3 );
        add_action( 'updated_post_meta', [ self::class, 'on_meta' ], 10, 3 );
        add_action( 'deleted_post_meta', [ self::class, 'on_meta' ], 10, 3 );
        add_action( 'before_delete_post', [ self::class, 'on_delete' ], 1, 1 );

        // Revisions.
        add_action( '_wp_put_post_revision', [ self::class, 'on_revision' ], 10, 2 );
        add_filter( 'wp_save_post_revision_post_has_changed', [ self::class, 'force_has_changed' ], 99, 3 );

        // End of call / request.
        add_action( 'mcp_abilities_call_end', [ self::class, 'on_call_end' ], 10, 1 );
        add_action( 'mcp_abilities_call_logged', [ self::class, 'on_call_logged' ], 10, 2 );
        add_action( 'shutdown', [ self::class, 'on_shutdown' ], 1 );

        // Display.
        add_filter( 'wp_prepare_revision_for_js', [ self::class, 'revision_for_js' ], 10, 3 );
        add_filter( 'wp_post_revision_title_expanded', [ self::class, 'revision_title' ], 10, 3 );
        add_filter( 'the_modified_author', [ self::class, 'modified_author' ], 10, 1 );
        add_action( 'post_submitbox_misc_actions', [ self::class, 'submitbox_line' ], 20, 1 );
        add_action( 'rest_api_init', [ self::class, 'register_rest_fields' ], 20 );
    }

    // ── Watching changes ─────────────────────────────────────────────────────

    /** Record only during an MCP request, and not for a call that only reads. */
    private static function recording(): bool {
        return Request_Context::is_mcp() && ! self::$writing && ! Request_Context::is_read_call();
    }

    /** @param int $post_id */
    public static function on_post_updated( $post_id ): void {
        if ( self::recording() ) {
            self::touch( (int) $post_id );
        }
    }

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     * @param bool     $update
     */
    public static function on_insert_post( $post_id, $post = null, $update = false ): void {
        if ( ! $update && self::recording() ) {
            self::touch( (int) $post_id );
        }
    }

    /**
     * added_post_meta / updated_post_meta / deleted_post_meta.
     *
     * @param mixed  $meta_id
     * @param int    $object_id
     * @param string $meta_key
     */
    public static function on_meta( $meta_id, $object_id, $meta_key ): void {
        if ( ! self::recording() || ! is_string( $meta_key ) ) {
            return;
        }
        if ( in_array( $meta_key, self::UNTRACKED_KEYS, true ) || 0 === strpos( $meta_key, '_oembed_' ) ) {
            return;
        }
        /**
         * Whether a post meta change counts as a change to the post.
         *
         * @param bool   $tracked
         * @param string $meta_key
         * @param int    $post_id
         */
        if ( ! apply_filters( 'mcp_abilities_track_meta_key', true, $meta_key, (int) $object_id ) ) {
            return;
        }
        self::touch( (int) $object_id );
    }

    /** @param int $post_id */
    public static function on_delete( $post_id ): void {
        // Deleting a post deletes its revisions too: those are not objects.
        if ( ! Request_Context::is_mcp() || in_array( get_post_type( (int) $post_id ), self::SKIP_TYPES, true ) ) {
            return;
        }
        $entry            = self::entry( (int) $post_id );
        $entry['deleted'] = true;
        self::put_entry( (int) $post_id, $entry );
    }

    private static function touch( int $post_id ): void {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || in_array( $post->post_type, self::SKIP_TYPES, true ) ) {
            return;
        }
        $entry = self::entry( $post_id );
        // A caller that unhooks WordPress's revision save while it writes has
        // asked for no revision (Avada bulk keep_revisions=false): keep it so.
        if ( ! has_action( 'post_updated', 'wp_save_post_revision' ) ) {
            $entry['optout'] = true;
        }
        self::put_entry( $post_id, $entry );
    }

    private static function entry( int $post_id ): array {
        return self::$posts[ Request_Context::call_id() ][ $post_id ]
            ?? [ 'optout' => false, 'deleted' => false, 'revisions' => [] ];
    }

    private static function put_entry( int $post_id, array $entry ): void {
        self::$posts[ Request_Context::call_id() ][ $post_id ] = $entry;
    }

    // ── Revisions ────────────────────────────────────────────────────────────

    /**
     * _wp_put_post_revision: label the revision.
     *
     * @param int $revision_id
     * @param int $post_id
     */
    public static function on_revision( $revision_id, $post_id = 0 ): void {
        if ( ! Request_Context::is_mcp() ) {
            return;
        }
        $revision_id = (int) $revision_id;
        $post_id     = (int) $post_id ?: (int) wp_get_post_parent_id( $revision_id );
        if ( ! $revision_id || ! $post_id ) {
            return;
        }
        $entry = self::entry( $post_id );
        if ( wp_is_post_autosave( $revision_id ) ) {
            // An autosave stands for the change; do not add a full revision.
            $entry['optout'] = true;
            self::put_entry( $post_id, $entry );
            return;
        }
        self::put_json( $revision_id, self::META_ACTOR, self::record( Request_Context::actor() ) );
        $entry['revisions'][] = $revision_id;
        self::put_entry( $post_id, $entry );
    }

    /**
     * The first save of a post in an MCP call always makes a revision, so a
     * date-only or status-only change is in the history too.
     *
     * @param bool     $has_changed
     * @param \WP_Post $latest_revision
     * @param \WP_Post $post
     * @return bool
     */
    public static function force_has_changed( $has_changed, $latest_revision = null, $post = null ) {
        if ( $has_changed || ! $post instanceof \WP_Post || ! Request_Context::is_mcp() || Request_Context::is_read_call() ) {
            return $has_changed;
        }
        $entry = self::entry( (int) $post->ID );
        if ( $entry['revisions'] || $entry['optout'] || ! self::may_force( $post ) ) {
            return $has_changed;
        }
        return true;
    }

    private static function may_force( \WP_Post $post ): bool {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return false;
        }
        if ( 'auto-draft' === $post->post_status || ! post_type_supports( $post->post_type, 'revisions' ) || ! wp_revisions_enabled( $post ) ) {
            return false;
        }
        /**
         * Whether AI Nexus may add a revision for an MCP change WordPress
         * would not have kept a revision for.
         *
         * @param bool     $force
         * @param \WP_Post $post
         */
        return (bool) apply_filters( 'mcp_abilities_force_revision', true, $post );
    }

    // ── End of a call ────────────────────────────────────────────────────────

    /**
     * mcp_abilities_call_end: a revision for each post changed without one,
     * and the posts into the call's log row.
     *
     * @param int $call_id
     */
    public static function on_call_end( $call_id ): void {
        $call_id = (int) $call_id;
        foreach ( array_keys( self::$posts[ $call_id ] ?? [] ) as $post_id ) {
            $entry = self::$posts[ $call_id ][ $post_id ];
            $post  = $entry['deleted'] ? null : get_post( $post_id );
            if ( ! $post ) {
                Request_Context::add_object( 'post', $post_id, [ 'revision' => null, 'deleted' => true ] );
                continue;
            }
            if ( ! $entry['revisions'] && ! $entry['optout'] && Request_Context::is_mcp() && self::may_force( $post ) ) {
                // Labelled and recorded by on_revision(); force_has_changed()
                // lets it through although no revisioned field changed.
                wp_save_post_revision( $post_id );
            }
            $revisions = self::$posts[ $call_id ][ $post_id ]['revisions'];
            Request_Context::add_object( 'post', $post_id, [ 'revision' => $revisions ? (int) end( $revisions ) : null ] );
        }
    }

    /**
     * mcp_abilities_call_logged: link the revisions to the log row, mark the
     * posts, set "last edited by".
     *
     * @param int $log_id
     * @param int $call_id
     */
    public static function on_call_logged( $log_id, $call_id ): void {
        $call_id = (int) $call_id;
        $bucket  = self::$posts[ $call_id ] ?? [];
        unset( self::$posts[ $call_id ] );
        if ( ! $bucket || ! Request_Context::is_mcp() ) {
            return;
        }
        self::mark( $bucket, (int) $log_id );
    }

    /** Changes made outside any call frame: marked at the end of the request. */
    public static function on_shutdown(): void {
        $bucket = self::$posts[0] ?? [];
        unset( self::$posts[0] );
        if ( ! $bucket || ! Request_Context::is_mcp() ) {
            return;
        }
        foreach ( $bucket as $post_id => $entry ) {
            $post = $entry['deleted'] ? null : get_post( $post_id );
            if ( $post && ! $entry['revisions'] && ! $entry['optout'] && self::may_force( $post ) ) {
                wp_save_post_revision( $post_id );
                $bucket[ $post_id ] = self::$posts[0][ $post_id ] ?? $entry;
            }
        }
        unset( self::$posts[0] );
        self::mark( $bucket, 0 );
    }

    private static function mark( array $bucket, int $log_id ): void {
        $actor = Request_Context::actor();
        self::$writing = true;
        try {
            foreach ( $bucket as $post_id => $entry ) {
                $last_rev = $entry['revisions'] ? (int) end( $entry['revisions'] ) : 0;
                if ( $log_id ) {
                    foreach ( $entry['revisions'] as $rev_id ) {
                        $stored = self::get_json( (int) $rev_id, self::META_ACTOR );
                        if ( $stored ) {
                            $stored['log_id'] = $log_id;
                            self::put_json( (int) $rev_id, self::META_ACTOR, $stored );
                        }
                    }
                }
                $post = $entry['deleted'] ? null : get_post( (int) $post_id );
                if ( ! $post ) {
                    continue;
                }
                if ( $actor['user_id'] ) {
                    update_post_meta( (int) $post_id, '_edit_last', (int) $actor['user_id'] );
                }
                $record                 = self::record( $actor );
                $record['revision']     = $last_rev;
                $record['log_id']       = $log_id;
                $record['modified_gmt'] = (string) $post->post_modified_gmt;
                self::put_json( (int) $post_id, self::META_LAST, $record );
            }
        } finally {
            self::$writing = false;
        }
    }

    /** What is stored: the actor snapshot plus the time (shared shape). */
    private static function record( array $actor ): array {
        return Request_Context::record( $actor );
    }

    // ── Meta storage (JSON, slashed: meta writes unslash their value) ────────

    private static function put_json( int $object_id, string $key, array $data ): void {
        $was           = self::$writing;
        self::$writing = true;
        Request_Context::put_meta_json( 'post', $object_id, $key, $data );
        self::$writing = $was;
    }

    private static function get_json( int $object_id, string $key ): ?array {
        return Request_Context::get_meta_json( 'post', $object_id, $key );
    }

    /** The actor stored on a revision, or null. */
    public static function revision_actor( int $revision_id ): ?array {
        return $revision_id ? self::get_json( $revision_id, self::META_ACTOR ) : null;
    }

    /**
     * The MCP record of a post's last change, when the post's current state is
     * that change (no human save since).
     */
    public static function current_mcp_change( \WP_Post $post ): ?array {
        $last = self::get_json( (int) $post->ID, self::META_LAST );
        if ( ! $last ) {
            return null;
        }
        if ( (string) ( $last['modified_gmt'] ?? '' ) !== (string) $post->post_modified_gmt ) {
            return null;
        }
        if ( (int) get_post_meta( $post->ID, '_edit_last', true ) !== (int) ( $last['user_id'] ?? 0 ) ) {
            return null;
        }
        return $last;
    }

    /** Link to the activity-log row, for users who can open the log. */
    public static function log_url( int $log_id ): string {
        if ( $log_id <= 0 || ! current_user_can( 'manage_options' ) ) {
            return '';
        }
        return add_query_arg(
            [ 'page' => Admin\Connections_Page::PAGE_SLUG, 'log_id' => $log_id ],
            admin_url( 'admin.php' )
        );
    }

    // ── Display ──────────────────────────────────────────────────────────────

    /**
     * Revision compare screen: the author name becomes the label.
     *
     * @param array    $data
     * @param \WP_Post $revision
     * @param \WP_Post $post
     * @return array
     */
    public static function revision_for_js( $data, $revision = null, $post = null ) {
        if ( ! is_array( $data ) || ! $revision instanceof \WP_Post ) {
            return $data;
        }
        $actor = self::revision_actor( (int) $revision->ID );
        if ( ! $actor ) {
            return $data;
        }
        // The author array is shared by every revision of this author: change
        // this revision's copy only.
        $author         = isset( $data['author'] ) && is_array( $data['author'] ) ? $data['author'] : [];
        $author['name'] = Request_Context::label( $actor );
        $data['author'] = $author;
        $data['mcpActor'] = [
            'label'   => $author['name'],
            'ability' => (string) ( $actor['ability'] ?? '' ),
            'auth'    => (string) ( $actor['auth'] ?? '' ),
            'logId'   => (int) ( $actor['log_id'] ?? 0 ),
            'logUrl'  => self::log_url( (int) ( $actor['log_id'] ?? 0 ) ),
        ];
        return $data;
    }

    /**
     * Classic Revisions box: the label instead of the name, and a link to the
     * activity-log row.
     *
     * @param string   $html
     * @param \WP_Post $revision
     * @param bool     $link
     * @return string
     */
    public static function revision_title( $html, $revision = null, $link = true ) {
        if ( ! is_string( $html ) || ! $revision instanceof \WP_Post ) {
            return $html;
        }
        $actor = self::revision_actor( (int) $revision->ID );
        if ( ! $actor ) {
            return $html;
        }
        $label = '<span class="mcp-revision-label">' . esc_html( Request_Context::label( $actor ) ) . '</span>';
        $url   = self::log_url( (int) ( $actor['log_id'] ?? 0 ) );
        if ( '' !== $url ) {
            $label .= ' <a class="mcp-revision-log" href="' . esc_url( $url ) . '">' . esc_html__( 'activity log', 'mcp-abilities' ) . '</a>';
        }
        // The name follows the avatar; never match inside the <img> tag.
        $start = 0;
        if ( preg_match( '/<img\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
            $start = (int) $m[0][1] + strlen( $m[0][0] );
        }
        $name = (string) get_the_author_meta( 'display_name', $revision->post_author );
        $pos  = '' !== $name ? strpos( $html, $name, $start ) : false;
        if ( false !== $pos ) {
            return substr_replace( $html, $label, $pos, strlen( $name ) );
        }
        return $html . ' — ' . $label;
    }

    /**
     * get_the_modified_author(): the label while the post's current state is
     * the MCP change, for users who can edit the post.
     *
     * @param string $name
     * @return string
     */
    public static function modified_author( $name ) {
        $post = get_post();
        if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
            return $name;
        }
        $last = self::current_mcp_change( $post );
        if ( ! $last ) {
            return $name;
        }
        // The filter is not told which post it is for; get_the_modified_author()
        // can be asked about another post than the global one. Only relabel when
        // the name WordPress found is that of the user the marker records.
        $user = get_userdata( (int) ( $last['user_id'] ?? 0 ) );
        if ( ! $user || (string) $user->display_name !== (string) $name ) {
            return $name;
        }
        // Escaped: the_modified_author() prints this as it is, and the label
        // carries text a person typed ("who is connecting").
        return esc_html( Request_Context::label( $last ) );
    }

    /**
     * Classic editor Publish box. WordPress's own "Last edited by" there reads
     * the user's name directly, so the label goes on a line of its own.
     *
     * @param \WP_Post $post
     */
    public static function submitbox_line( $post ): void {
        if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }
        $last = self::current_mcp_change( $post );
        if ( ! $last ) {
            return;
        }
        $url = self::log_url( (int) ( $last['log_id'] ?? 0 ) );
        echo '<div class="misc-pub-section mcp-last-change"><span class="dashicons dashicons-admin-generic" style="color:#8c8f94;margin-right:4px" aria-hidden="true"></span>';
        /* translators: %s: "Name — via MCP (App)" */
        printf( esc_html__( 'Last change: %s', 'mcp-abilities' ), '<strong>' . esc_html( Request_Context::label( $last ) ) . '</strong>' );
        if ( '' !== $url ) {
            echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'activity log', 'mcp-abilities' ) . '</a>';
        }
        echo '</div>';
    }

    /** REST: mcp_actor on every revisions endpoint ("{post type}-revision"). */
    public static function register_rest_fields(): void {
        foreach ( get_post_types_by_support( 'revisions' ) as $type ) {
            register_rest_field( $type . '-revision', 'mcp_actor', [
                'get_callback' => [ self::class, 'rest_actor' ],
                'schema'       => [
                    'description' => __( 'Set when an AI made this revision through MCP: who, through which app, and the activity-log entry.', 'mcp-abilities' ),
                    'type'        => [ 'object', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
            ] );
        }
    }

    /**
     * @param array $data Prepared revision.
     * @return array|null
     */
    public static function rest_actor( $data ) {
        $id       = is_array( $data ) ? (int) ( $data['id'] ?? 0 ) : 0;
        $revision = $id ? get_post( $id ) : null;
        if ( ! $revision || 'revision' !== $revision->post_type || ! current_user_can( 'edit_post', (int) $revision->post_parent ) ) {
            return null;
        }
        $actor = self::revision_actor( $id );
        if ( ! $actor ) {
            return null;
        }
        return [
            'label'     => Request_Context::label( $actor ),
            'user_id'   => (int) ( $actor['user_id'] ?? 0 ),
            'user_name' => (string) ( $actor['user_name'] ?? '' ),
            'app'       => (string) ( $actor['app'] ?? '' ),
            'operator'  => (string) ( $actor['operator'] ?? '' ),
            'auth'      => (string) ( $actor['auth'] ?? '' ),
            'ability'   => (string) ( $actor['ability'] ?? '' ),
            'time'      => (string) ( $actor['time'] ?? '' ),
            'log_id'    => (int) ( $actor['log_id'] ?? 0 ),
        ];
    }

    /** Tests that simulate several requests in one process. */
    public static function reset(): void {
        self::$posts   = [];
        self::$writing = false;
    }
}
