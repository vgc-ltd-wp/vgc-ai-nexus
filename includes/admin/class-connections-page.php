<?php
namespace MCP_Abilities\Admin;

use MCP_Abilities\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Connections admin page — shows the AI activity log.
 *
 * Registered as a submenu under the main AI Nexus menu by Settings_Page.
 * This class only handles its own rendering and asset loading.
 */
class Connections_Page {

    const PAGE_SLUG = 'mcp-abilities-connections';

    private string $hook = '';

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'add_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_submenu(): void {
        $this->hook = add_submenu_page(
            'mcp-abilities',                                      // parent slug
            __( 'Connections – VGC AI Nexus', 'mcp-abilities' ), // page title
            __( 'Connections', 'mcp-abilities' ),                 // menu label
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->hook ) {
            return;
        }
        // Reuse the shared admin stylesheet (already enqueued by Settings_Page on its own pages).
        // We also need it here on the Connections page.
        wp_enqueue_style(
            'mcp-abilities-admin',
            MCP_ABILITIES_URL . 'admin/css/admin.css',
            [],
            MCP_ABILITIES_VERSION
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ) );
        }

        // ── Sanitize filter inputs ────────────────────────────────────────────
        $filter_tool      = isset( $_GET['filter_tool'] )      ? sanitize_text_field( wp_unslash( $_GET['filter_tool'] ) )      : '';
        $filter_user      = isset( $_GET['filter_user'] )      ? absint( $_GET['filter_user'] )                                 : 0;
        $filter_status    = isset( $_GET['filter_status'] )    ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) )    : '';
        $filter_date_from = isset( $_GET['filter_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_date_from'] ) ) : '';
        $filter_date_to   = isset( $_GET['filter_date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['filter_date_to'] ) )   : '';
        $paged            = isset( $_GET['paged'] )            ? max( 1, absint( $_GET['paged'] ) )                             : 1;
        // One entry, linked from a revision ("activity log").
        $filter_id        = isset( $_GET['log_id'] )           ? absint( $_GET['log_id'] )                                      : 0;

        // ── Fetch data ────────────────────────────────────────────────────────
        $summary = Logger::get_summary();
        $result  = Logger::get_log( [
            'per_page'  => 50,
            'page'      => $paged,
            'id'        => $filter_id,
            'tool'      => $filter_tool,
            'user_id'   => $filter_user,
            'status'    => $filter_status,
            'date_from' => $filter_date_from,
            'date_to'   => $filter_date_to,
        ] );
        $all_tools = Logger::get_all_tools();

        include MCP_ABILITIES_DIR . 'admin/views/connections-page.php';
    }

    /**
     * One changed object of a log row, linked to its screen: a post (and the
     * revision it got), a comment (its edit screen), an order note (its
     * order) or an order (the HPOS-aware order edit screen).
     *
     * @param array $object { type, id, revision?, action?, post?, comment_type?, deleted? }
     */
    public static function object_html( array $object ): string {
        $id      = (int) ( $object['id'] ?? 0 );
        $type    = (string) ( $object['type'] ?? '' );
        $action  = (string) ( $object['action'] ?? '' );
        $deleted = ! empty( $object['deleted'] );
        $suffix  = '' !== $action ? ' <span class="mcp-log-object__action">' . esc_html( $action ) . '</span>' : '';

        switch ( $type ) {
            case 'post':
                $html = self::post_html( $id, (int) ( $object['revision'] ?? 0 ), $deleted );
                break;
            case 'comment':
                $html = self::comment_html( $id, (int) ( $object['post'] ?? 0 ), (string) ( $object['comment_type'] ?? '' ), $deleted );
                break;
            case 'order':
                $html = self::order_html( $id, $deleted );
                break;
            case 'connection':
                $html = self::connection_html( (string) ( $object['key'] ?? '' ) );
                break;
            case 'setting':
                $html = esc_html__( 'setting: require admin approval', 'mcp-abilities' );
                break;
            default:
                $html = esc_html( $type . ' #' . $id );
        }
        return '<span class="mcp-log-object">' . $html . $suffix . '</span>';
    }

    private static function deleted_html( string $what, int $id ): string {
        /* translators: 1: "post", "comment" or "order", 2: its ID */
        return '<span class="mcp-muted">' . esc_html( sprintf( __( '%1$s #%2$d (deleted)', 'mcp-abilities' ), $what, $id ) ) . '</span>';
    }

    private static function link( string $url, string $text ): string {
        return '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
    }

    private static function post_html( int $id, int $rev, bool $deleted ): string {
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || $deleted ) {
            return self::deleted_html( __( 'post', 'mcp-abilities' ), $id );
        }
        $title = get_the_title( $post );
        $title = '' !== trim( $title ) ? wp_html_excerpt( $title, 40, '…' ) : sprintf( '#%d', $id );
        $edit  = current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $post, 'raw' ) : '';
        $html  = self::link( $edit, $title ) . ' <span class="mcp-log-object__type">' . esc_html( $post->post_type ) . '</span>';
        if ( $rev && wp_get_post_revision( $rev ) ) {
            /* translators: %d: revision ID */
            $html .= ' <a class="mcp-log-object__rev" href="' . esc_url( admin_url( 'revision.php?revision=' . $rev ) ) . '">' . esc_html( sprintf( __( 'revision %d', 'mcp-abilities' ), $rev ) ) . '</a>';
        }
        return $html;
    }

    private static function comment_html( int $id, int $post_id, string $comment_type, bool $deleted ): string {
        $comment = $id ? get_comment( $id ) : null;
        if ( ! $comment || $deleted ) {
            return self::deleted_html( __( 'comment', 'mcp-abilities' ), $id );
        }
        $post_id = (int) $comment->comment_post_ID ?: $post_id;
        if ( 'order_note' === ( (string) $comment->comment_type ?: $comment_type ) ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_id ) : null;
            /* translators: 1: note ID, 2: order number */
            $text = sprintf( __( 'note #%1$d on order #%2$s', 'mcp-abilities' ), $id, $order ? $order->get_order_number() : (string) $post_id );
            $url  = $order && current_user_can( 'edit_shop_orders' ) ? (string) $order->get_edit_order_url() : '';
            return self::link( $url, $text );
        }
        $title = $post_id ? get_the_title( $post_id ) : '';
        $text  = '' !== trim( (string) $title )
            /* translators: 1: comment ID, 2: post title */
            ? sprintf( __( 'comment #%1$d on %2$s', 'mcp-abilities' ), $id, wp_html_excerpt( (string) $title, 30, '…' ) )
            /* translators: %d: comment ID */
            : sprintf( __( 'comment #%d', 'mcp-abilities' ), $id );
        $url   = current_user_can( 'edit_comment', $id ) ? (string) get_edit_comment_link( $id ) : '';
        return self::link( $url, $text );
    }

    /** A connection (approval record), linked to the Connect page. */
    private static function connection_html( string $key ): string {
        $record = \MCP_Abilities\Connection_Approval::records()[ $key ] ?? null;
        if ( ! is_array( $record ) ) {
            /* translators: %s: connection key */
            return '<span class="mcp-muted">' . esc_html( sprintf( __( 'connection %s (no record)', 'mcp-abilities' ), $key ) ) . '</span>';
        }
        $user = get_userdata( (int) $record['user_id'] );
        $who  = $user ? $user->user_login : '#' . (int) $record['user_id'];
        $app  = (string) ( $record['app'] ?? '' );
        /* translators: 1: user login, 2: app name */
        $text = sprintf( __( 'connection: %1$s via %2$s', 'mcp-abilities' ), $who, '' !== $app ? $app : __( 'unnamed app', 'mcp-abilities' ) );
        return self::link( admin_url( 'admin.php?page=mcp-abilities-connect#mcp-approval' ), $text )
            . ' <span class="mcp-log-object__type">' . esc_html( (string) ( $record['status'] ?? '' ) ) . '</span>';
    }

    private static function order_html( int $id, bool $deleted ): string {
        $order = ( $id && ! $deleted && function_exists( 'wc_get_order' ) ) ? wc_get_order( $id ) : null;
        if ( ! $order ) {
            return self::deleted_html( __( 'order', 'mcp-abilities' ), $id );
        }
        /* translators: %s: order number */
        $text = sprintf( __( 'Order #%s', 'mcp-abilities' ), $order->get_order_number() );
        $url  = current_user_can( 'edit_shop_orders' ) && method_exists( $order, 'get_edit_order_url' ) ? (string) $order->get_edit_order_url() : '';
        return self::link( $url, $text );
    }
}
