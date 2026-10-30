<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce orders and order notes changed by an AI through MCP.
 *
 * Order notes are comments, so Comment_Attribution already records them
 * (_mcp_abilities_actor on every note made during an MCP call — the notes
 * WooCommerce writes itself, like "Order status changed from … to …",
 * included). This class adds:
 *
 *   - Orders: created, updated, status changed, refunded, noted, trashed or
 *     deleted during an MCP call go into the call's log row as
 *     { type:"order", id, action }, and get the order meta
 *     _mcp_abilities_last_change through the WooCommerce order API, so it
 *     works with HPOS on and off. The write never re-saves the order (no new
 *     modified date, no update hooks or webhooks).
 *   - "Added by": the label "Name — via MCP (App)" for notes made through MCP,
 *     through WooCommerce's own filters, never by changing stored data:
 *       - woocommerce_get_order_note — the order screen's notes box and
 *         wc_get_order_notes(), in wp-admin, for users who can edit orders;
 *         a system note keeps its system-note styling;
 *       - woocommerce_rest_prepare_order_note — "author" (+ mcp_actor) in the
 *         wc/v1–v3 order-notes REST responses (shop managers only).
 *
 * Customers never see the label: the note text and the customer email are
 * exactly as written, and nothing changes outside wp-admin and the
 * shop-manager REST API ("My account" shows note text only).
 *
 * Booted only when WooCommerce is active.
 */
final class Order_Attribution {

    const META_LAST = '_mcp_abilities_last_change';

    /**
     * Orders the calls touched: call id => order id => state.
     *
     * @var array<int, array<int, array{actions:string[],deleted:bool}>>
     */
    private static array $orders = [];

    /** True while this class writes its own order meta. */
    private static bool $writing = false;

    public static function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Changes (both order storages fire these).
        add_action( 'woocommerce_new_order', [ self::class, 'on_new' ], 10, 1 );
        add_action( 'woocommerce_update_order', [ self::class, 'on_update' ], 10, 1 );
        add_action( 'woocommerce_order_status_changed', [ self::class, 'on_status' ], 10, 3 );
        add_action( 'woocommerce_order_refunded', [ self::class, 'on_refund' ], 10, 2 );
        add_action( 'woocommerce_order_note_added', [ self::class, 'on_note' ], 10, 2 );
        add_action( 'woocommerce_trash_order', [ self::class, 'on_trash' ], 10, 1 );
        add_action( 'woocommerce_before_delete_order', [ self::class, 'on_delete' ], 10, 1 );

        // End of call / request.
        add_action( 'mcp_abilities_call_end', [ self::class, 'on_call_end' ], 10, 1 );
        add_action( 'mcp_abilities_call_logged', [ self::class, 'on_call_logged' ], 10, 2 );
        add_action( 'shutdown', [ self::class, 'on_shutdown' ], 1 );

        // "Added by" on notes.
        add_filter( 'woocommerce_get_order_note', [ self::class, 'admin_note' ], 10, 2 );
        add_filter( 'woocommerce_order_note_class', [ self::class, 'note_class' ], 10, 2 );
        add_filter( 'woocommerce_rest_prepare_order_note', [ self::class, 'rest_note' ], 10, 3 );
    }

    // ── Watching changes ─────────────────────────────────────────────────────

    private static function recording(): bool {
        return Request_Context::is_mcp() && ! self::$writing && ! Request_Context::is_read_call();
    }

    /** @param int $order_id */
    public static function on_new( $order_id ): void {
        self::touch( (int) $order_id, 'create' );
    }

    /** @param int $order_id */
    public static function on_update( $order_id ): void {
        self::touch( (int) $order_id, 'update' );
    }

    /**
     * @param int    $order_id
     * @param string $from
     * @param string $to
     */
    public static function on_status( $order_id, $from = '', $to = '' ): void {
        self::touch( (int) $order_id, 'status:' . sanitize_key( (string) $from ) . '>' . sanitize_key( (string) $to ) );
    }

    /**
     * @param int $order_id
     * @param int $refund_id
     */
    public static function on_refund( $order_id, $refund_id = 0 ): void {
        self::touch( (int) $order_id, 'refund:' . (int) $refund_id );
    }

    /**
     * @param int   $note_id
     * @param mixed $order
     */
    public static function on_note( $note_id, $order = null ): void {
        if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
            self::touch( (int) $order->get_id(), 'note:' . (int) $note_id );
        }
    }

    /** @param int $order_id */
    public static function on_trash( $order_id ): void {
        self::touch( (int) $order_id, 'trash' );
    }

    /** @param int $order_id */
    public static function on_delete( $order_id ): void {
        self::touch( (int) $order_id, 'delete' );
        if ( isset( self::$orders[ Request_Context::call_id() ][ (int) $order_id ] ) ) {
            self::$orders[ Request_Context::call_id() ][ (int) $order_id ]['deleted'] = true;
        }
    }

    private static function touch( int $order_id, string $action ): void {
        if ( $order_id <= 0 || ! self::recording() ) {
            return;
        }
        // A refund is recorded on its order.
        if ( 'shop_order_refund' === self::order_type( $order_id ) ) {
            $refund_id = $order_id;
            $refund    = wc_get_order( $refund_id );
            $parent    = $refund && method_exists( $refund, 'get_parent_id' ) ? (int) $refund->get_parent_id() : 0;
            if ( ! $parent ) {
                return;
            }
            $order_id = $parent;
            $action   = 'refund:' . $refund_id;
        }
        $call  = Request_Context::call_id();
        $entry = self::$orders[ $call ][ $order_id ] ?? [ 'actions' => [], 'deleted' => false ];
        if ( ! in_array( $action, $entry['actions'], true ) ) {
            $entry['actions'][] = $action;
        }
        self::$orders[ $call ][ $order_id ] = $entry;
    }

    private static function order_type( int $order_id ): string {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            return (string) \Automattic\WooCommerce\Utilities\OrderUtil::get_order_type( $order_id );
        }
        return (string) get_post_type( $order_id );
    }

    /** "update" says nothing once a more specific action is known. */
    private static function action_text( array $actions ): string {
        $specific = array_values( array_diff( $actions, [ 'update' ] ) );
        return implode( ',', $specific ?: $actions );
    }

    // ── End of a call ────────────────────────────────────────────────────────

    /**
     * mcp_abilities_call_end: the orders into the call's log row.
     *
     * @param int $call_id
     */
    public static function on_call_end( $call_id ): void {
        foreach ( self::$orders[ (int) $call_id ] ?? [] as $order_id => $entry ) {
            Request_Context::add_object( 'order', (int) $order_id, array_filter( [
                'action'  => self::action_text( $entry['actions'] ),
                'deleted' => $entry['deleted'] ?: null,
            ], static fn( $v ) => null !== $v ) );
        }
    }

    /**
     * mcp_abilities_call_logged: mark each order's last change.
     *
     * @param int $log_id
     * @param int $call_id
     */
    public static function on_call_logged( $log_id, $call_id ): void {
        $bucket = self::$orders[ (int) $call_id ] ?? [];
        unset( self::$orders[ (int) $call_id ] );
        if ( $bucket && Request_Context::is_mcp() ) {
            self::mark( $bucket, (int) $log_id );
        }
    }

    /** Changes made outside any call frame: marked at the end of the request. */
    public static function on_shutdown(): void {
        $bucket = self::$orders[0] ?? [];
        unset( self::$orders[0] );
        if ( $bucket && Request_Context::is_mcp() ) {
            self::mark( $bucket, 0 );
        }
    }

    private static function mark( array $bucket, int $log_id ): void {
        $actor = Request_Context::actor();
        foreach ( $bucket as $order_id => $entry ) {
            if ( $entry['deleted'] ) {
                continue;
            }
            $order = wc_get_order( (int) $order_id );
            if ( ! $order || ! method_exists( $order, 'update_meta_data' ) ) {
                continue;
            }
            $record           = Request_Context::record( $actor );
            $record['action'] = self::action_text( $entry['actions'] );
            $record['log_id'] = $log_id;
            $json             = wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            if ( false === $json ) {
                continue;
            }
            self::$writing = true;
            // On HPOS a meta write would otherwise save the whole order again
            // (new modified date, update hooks, webhooks).
            add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', [ self::class, 'no_save_after_meta' ] );
            try {
                $order->update_meta_data( self::META_LAST, $json );
                $order->save_meta_data();
            } catch ( \Throwable $e ) {
                // Recording must never break the call.
            } finally {
                remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', [ self::class, 'no_save_after_meta' ] );
                self::$writing = false;
            }
        }
    }

    /** woocommerce_orders_table_datastore_should_save_after_meta_change */
    public static function no_save_after_meta(): bool {
        return false;
    }

    /** The MCP record of an order's last change, or null. */
    public static function order_last_change( int $order_id ): ?array {
        $order = wc_get_order( $order_id );
        $raw   = $order ? $order->get_meta( self::META_LAST, true ) : '';
        $data  = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
        return is_array( $data ) ? $data : null;
    }

    // ── "Added by" on notes ──────────────────────────────────────────────────

    /**
     * woocommerce_get_order_note: the order screen's notes box and
     * wc_get_order_notes(). In wp-admin only, for users who can edit orders.
     *
     * @param array       $note
     * @param \WP_Comment $comment
     * @return array
     */
    public static function admin_note( $note, $comment = null ) {
        if ( ! is_array( $note ) || ! $comment instanceof \WP_Comment || ! is_admin() || ! current_user_can( 'edit_shop_orders' ) ) {
            return $note;
        }
        $actor = Comment_Attribution::comment_actor( (int) $comment->comment_ID );
        if ( ! $actor ) {
            return $note;
        }
        if ( 'system' === ( $note['added_by'] ?? '' ) ) {
            $note['mcp_system'] = true; // keeps the system-note look, see note_class()
        }
        $note['added_by']  = Request_Context::label( $actor );
        $note['mcp_actor'] = Request_Context::public_record( $actor );
        return $note;
    }

    /**
     * woocommerce_order_note_class: a note WooCommerce wrote itself keeps its
     * system-note look although it now says who caused it.
     *
     * @param array  $classes
     * @param object $note
     * @return array
     */
    public static function note_class( $classes, $note = null ) {
        if ( ! is_array( $classes ) || ! is_object( $note ) ) {
            return $classes;
        }
        if ( ! empty( $note->mcp_system ) && ! in_array( 'system-note', $classes, true ) ) {
            $classes[] = 'system-note';
        }
        if ( ! empty( $note->mcp_actor ) ) {
            $classes[] = 'mcp-note';
        }
        return $classes;
    }

    /**
     * woocommerce_rest_prepare_order_note: "author" is the label for a note
     * made through MCP, plus the mcp_actor record. The endpoint itself
     * requires shop-manager rights; checked again here.
     *
     * @param \WP_REST_Response $response
     * @param \WP_Comment       $note
     * @param \WP_REST_Request  $request
     * @return \WP_REST_Response
     */
    public static function rest_note( $response, $note = null, $request = null ) {
        if ( ! $response instanceof \WP_REST_Response || ! $note instanceof \WP_Comment || ! current_user_can( 'edit_shop_orders' ) ) {
            return $response;
        }
        $actor = Comment_Attribution::comment_actor( (int) $note->comment_ID );
        if ( ! $actor ) {
            return $response;
        }
        $data              = $response->get_data();
        $data['author']    = Request_Context::label( $actor );
        $data['mcp_actor'] = Request_Context::public_record( $actor );
        $response->set_data( $data );
        return $response;
    }

    /** Tests that simulate several requests in one process. */
    public static function reset(): void {
        self::$orders  = [];
        self::$writing = false;
    }
}
