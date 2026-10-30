<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Says in wp-admin when an AI wrote or changed a comment through MCP.
 *
 * During an MCP request (Request_Context::is_mcp()), for any comment —
 * whichever tool or code path made the change, WooCommerce order notes
 * included (they are comments of type "order_note"):
 *
 *   - Created: comment meta _mcp_abilities_actor (the same record as a
 *     revision's: who, through which app and sign-in, which ability, when;
 *     the activity-log id is added when the call is logged).
 *   - Created, edited, approved, held, spammed, trashed or deleted: the
 *     comment goes into the call's log row as { type:"comment", id, action,
 *     post, comment_type }, and (unless deleted) gets comment meta
 *     _mcp_abilities_last_change (the record plus the action and log id).
 *
 * An edit or moderation of someone else's comment is recorded as a change,
 * not as authorship: the author stays the person who wrote it.
 *
 * Shown only in wp-admin, and only to users who can edit that comment: the
 * Comments list and the dashboard Activity widget show the author of an
 * MCP-written comment as "Name — via MCP (App)" (get_comment_author), and the
 * comment edit screen gets an "AI change (MCP)" box. The edit form and Quick
 * Edit read the stored author, so the label is never saved. The front end
 * keeps the plain name. REST: fields mcp_actor and mcp_last_change on
 * comments, for users who can edit the comment.
 *
 * The meta is the audit trail: it stays when AI Nexus is uninstalled.
 */
final class Comment_Attribution {

    const META_ACTOR = '_mcp_abilities_actor';
    const META_LAST  = '_mcp_abilities_last_change';

    /**
     * Comments the calls touched: call id => comment id => state.
     * Call id 0 holds changes made outside any call frame (finished at shutdown).
     *
     * @var array<int, array<int, array{actions:string[],deleted:bool,post:int,type:string}>>
     */
    private static array $comments = [];

    /** True while this class writes its own meta. */
    private static bool $writing = false;

    public static function init(): void {
        // Changes.
        add_action( 'wp_insert_comment', [ self::class, 'on_insert' ], 10, 2 );
        add_action( 'edit_comment', [ self::class, 'on_edit' ], 10, 1 );
        add_action( 'wp_set_comment_status', [ self::class, 'on_status' ], 10, 2 );
        add_action( 'delete_comment', [ self::class, 'on_delete' ], 10, 2 );

        // End of call / request.
        add_action( 'mcp_abilities_call_end', [ self::class, 'on_call_end' ], 10, 1 );
        add_action( 'mcp_abilities_call_logged', [ self::class, 'on_call_logged' ], 10, 2 );
        add_action( 'shutdown', [ self::class, 'on_shutdown' ], 1 );

        // Display (wp-admin only) and REST.
        add_filter( 'get_comment_author', [ self::class, 'admin_author' ], 20, 3 );
        add_action( 'add_meta_boxes_comment', [ self::class, 'add_edit_screen_box' ], 10, 1 );
        add_action( 'rest_api_init', [ self::class, 'register_rest_fields' ], 20 );
    }

    // ── Watching changes ─────────────────────────────────────────────────────

    private static function recording(): bool {
        return Request_Context::is_mcp() && ! self::$writing;
    }

    /**
     * wp_insert_comment: an MCP-written comment. The actor is stored at once,
     * so it is there even if the call later fails.
     *
     * @param int         $comment_id
     * @param \WP_Comment $comment
     */
    public static function on_insert( $comment_id, $comment = null ): void {
        if ( ! self::recording() ) {
            return;
        }
        $comment_id = (int) $comment_id;
        self::write_json( $comment_id, self::META_ACTOR, Request_Context::record() );
        self::touch( $comment_id, 'create', $comment );
    }

    /** @param int $comment_id */
    public static function on_edit( $comment_id ): void {
        if ( self::recording() ) {
            self::touch( (int) $comment_id, 'edit' );
        }
    }

    /**
     * wp_set_comment_status: approve, hold, spam, trash (and back), delete.
     *
     * @param int        $comment_id
     * @param string|int $status
     */
    public static function on_status( $comment_id, $status = '' ): void {
        if ( ! self::recording() ) {
            return;
        }
        $map    = [ '1' => 'approve', '0' => 'hold', 'approve' => 'approve', 'hold' => 'hold' ];
        $status = (string) $status;
        self::touch( (int) $comment_id, $map[ $status ] ?? sanitize_key( $status ) );
    }

    /**
     * delete_comment: before a permanent delete.
     *
     * @param int         $comment_id
     * @param \WP_Comment $comment
     */
    public static function on_delete( $comment_id, $comment = null ): void {
        if ( ! self::recording() ) {
            return;
        }
        self::touch( (int) $comment_id, 'delete', $comment );
        self::$comments[ Request_Context::call_id() ][ (int) $comment_id ]['deleted'] = true;
    }

    /**
     * @param int              $comment_id
     * @param string           $action
     * @param \WP_Comment|null $comment
     */
    private static function touch( int $comment_id, string $action, $comment = null ): void {
        if ( $comment_id <= 0 ) {
            return;
        }
        $call  = Request_Context::call_id();
        $entry = self::$comments[ $call ][ $comment_id ] ?? null;
        if ( null === $entry ) {
            $c     = $comment instanceof \WP_Comment ? $comment : get_comment( $comment_id );
            $entry = [
                'actions' => [],
                'deleted' => false,
                'post'    => $c ? (int) $c->comment_post_ID : 0,
                'type'    => $c && '' !== (string) $c->comment_type ? (string) $c->comment_type : 'comment',
            ];
        }
        if ( '' !== $action && ! in_array( $action, $entry['actions'], true ) ) {
            $entry['actions'][] = $action;
        }
        self::$comments[ $call ][ $comment_id ] = $entry;
    }

    // ── End of a call ────────────────────────────────────────────────────────

    /**
     * mcp_abilities_call_end: the comments into the call's log row.
     *
     * @param int $call_id
     */
    public static function on_call_end( $call_id ): void {
        foreach ( self::$comments[ (int) $call_id ] ?? [] as $comment_id => $entry ) {
            Request_Context::add_object( 'comment', (int) $comment_id, array_filter( [
                'action'       => implode( ',', $entry['actions'] ),
                'post'         => $entry['post'] ?: null,
                'comment_type' => $entry['type'],
                'deleted'      => $entry['deleted'] ?: null,
            ], static fn( $v ) => null !== $v ) );
        }
    }

    /**
     * mcp_abilities_call_logged: link to the log row, mark the last change.
     *
     * @param int $log_id
     * @param int $call_id
     */
    public static function on_call_logged( $log_id, $call_id ): void {
        $bucket = self::$comments[ (int) $call_id ] ?? [];
        unset( self::$comments[ (int) $call_id ] );
        if ( $bucket && Request_Context::is_mcp() ) {
            self::mark( $bucket, (int) $log_id );
        }
    }

    /** Changes made outside any call frame: marked at the end of the request. */
    public static function on_shutdown(): void {
        $bucket = self::$comments[0] ?? [];
        unset( self::$comments[0] );
        if ( $bucket && Request_Context::is_mcp() ) {
            self::mark( $bucket, 0 );
        }
    }

    private static function mark( array $bucket, int $log_id ): void {
        $actor = Request_Context::actor();
        foreach ( $bucket as $comment_id => $entry ) {
            if ( $entry['deleted'] || ! get_comment( (int) $comment_id ) ) {
                continue;
            }
            if ( $log_id ) {
                $written = Request_Context::get_meta_json( 'comment', (int) $comment_id, self::META_ACTOR );
                if ( $written && in_array( 'create', $entry['actions'], true ) ) {
                    $written['log_id'] = $log_id;
                    self::write_json( (int) $comment_id, self::META_ACTOR, $written );
                }
            }
            $record           = Request_Context::record( $actor );
            $record['action'] = implode( ',', $entry['actions'] );
            $record['log_id'] = $log_id;
            self::write_json( (int) $comment_id, self::META_LAST, $record );
        }
    }

    private static function write_json( int $comment_id, string $key, array $data ): void {
        $was           = self::$writing;
        self::$writing = true;
        Request_Context::put_meta_json( 'comment', $comment_id, $key, $data );
        self::$writing = $was;
    }

    /** The MCP record of who wrote a comment, or null. */
    public static function comment_actor( int $comment_id ): ?array {
        return Request_Context::get_meta_json( 'comment', $comment_id, self::META_ACTOR );
    }

    /** The MCP record of a comment's last change, or null. */
    public static function comment_last_change( int $comment_id ): ?array {
        return Request_Context::get_meta_json( 'comment', $comment_id, self::META_LAST );
    }

    // ── Display ──────────────────────────────────────────────────────────────

    /**
     * get_comment_author: the label for an MCP-written comment, in wp-admin,
     * for users who can edit the comment. Escaped like a stored comment author.
     *
     * @param string           $author
     * @param string|int       $comment_id
     * @param \WP_Comment|null $comment
     * @return string
     */
    public static function admin_author( $author, $comment_id = 0, $comment = null ) {
        $comment_id = (int) $comment_id;
        if ( ! $comment_id || ! is_admin() || self::$writing ) {
            return $author;
        }
        $actor = self::comment_actor( $comment_id );
        if ( ! $actor || ! current_user_can( 'edit_comment', $comment_id ) ) {
            return $author;
        }
        return esc_html( Request_Context::label( $actor ) );
    }

    /**
     * Comment edit screen: a box with who wrote or last changed the comment
     * through MCP. The author field above stays the stored name.
     *
     * @param \WP_Comment $comment
     */
    public static function add_edit_screen_box( $comment ): void {
        if ( ! $comment instanceof \WP_Comment || ! current_user_can( 'edit_comment', (int) $comment->comment_ID ) ) {
            return;
        }
        if ( ! self::comment_actor( (int) $comment->comment_ID ) && ! self::comment_last_change( (int) $comment->comment_ID ) ) {
            return;
        }
        add_meta_box( 'mcp-abilities-attribution', __( 'AI change (MCP)', 'mcp-abilities' ), [ self::class, 'render_edit_screen_box' ], 'comment', 'normal' );
    }

    /** @param \WP_Comment $comment */
    public static function render_edit_screen_box( $comment ): void {
        $id      = (int) $comment->comment_ID;
        $written = self::comment_actor( $id );
        $last    = self::comment_last_change( $id );
        echo '<div class="mcp-comment-attribution">';
        if ( $written ) {
            echo '<p>';
            /* translators: %s: "Name — via MCP (App)" */
            printf( esc_html__( 'Written by %s.', 'mcp-abilities' ), '<strong>' . esc_html( Request_Context::label( $written ) ) . '</strong>' );
            self::echo_log_link( (int) ( $written['log_id'] ?? 0 ) );
            echo '</p>';
        }
        if ( $last ) {
            echo '<p>';
            printf(
                /* translators: 1: "Name — via MCP (App)", 2: what was done, e.g. "approve", 3: UTC time */
                esc_html__( 'Last change by %1$s: %2$s, %3$s UTC.', 'mcp-abilities' ),
                '<strong>' . esc_html( Request_Context::label( $last ) ) . '</strong>',
                '<code>' . esc_html( (string) ( $last['action'] ?? '' ) ) . '</code>',
                esc_html( (string) ( $last['time'] ?? '' ) )
            );
            self::echo_log_link( (int) ( $last['log_id'] ?? 0 ) );
            echo '</p>';
        }
        echo '</div>';
    }

    private static function echo_log_link( int $log_id ): void {
        $url = Revision_Attribution::log_url( $log_id );
        if ( '' !== $url ) {
            echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'activity log', 'mcp-abilities' ) . '</a>';
        }
    }

    /** REST: mcp_actor (who wrote it) and mcp_last_change on comments. */
    public static function register_rest_fields(): void {
        $schema = [
            'type'     => [ 'object', 'null' ],
            'context'  => [ 'view', 'edit' ],
            'readonly' => true,
        ];
        register_rest_field( 'comment', 'mcp_actor', [
            'get_callback' => [ self::class, 'rest_actor' ],
            'schema'       => [ 'description' => __( 'Set when an AI wrote this comment through MCP: who, through which app, and the activity-log entry.', 'mcp-abilities' ) ] + $schema,
        ] );
        register_rest_field( 'comment', 'mcp_last_change', [
            'get_callback' => [ self::class, 'rest_last_change' ],
            'schema'       => [ 'description' => __( 'The last change an AI made to this comment through MCP (edit, approve, spam, trash…).', 'mcp-abilities' ) ] + $schema,
        ] );
    }

    /** @param array $data Prepared comment. */
    public static function rest_actor( $data ) {
        $id = is_array( $data ) ? (int) ( $data['id'] ?? 0 ) : 0;
        if ( ! $id || ! current_user_can( 'edit_comment', $id ) ) {
            return null;
        }
        $actor = self::comment_actor( $id );
        return $actor ? Request_Context::public_record( $actor ) : null;
    }

    /** @param array $data Prepared comment. */
    public static function rest_last_change( $data ) {
        $id = is_array( $data ) ? (int) ( $data['id'] ?? 0 ) : 0;
        if ( ! $id || ! current_user_can( 'edit_comment', $id ) ) {
            return null;
        }
        $last = self::comment_last_change( $id );
        return $last ? Request_Context::public_record( $last ) : null;
    }

    /** Tests that simulate several requests in one process. */
    public static function reset(): void {
        self::$comments = [];
        self::$writing  = false;
    }
}
