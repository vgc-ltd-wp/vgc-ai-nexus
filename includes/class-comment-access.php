<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Object-level permission rules for comments (list_comments, moderate_comment,
 * reply_to_comment), as WordPress applies them on its own screens and in its
 * REST API.
 *
 * The tool gate is moderate_comments; these add, per comment:
 * - Changing it (approve, hold, spam, trash, delete, reply to it): `edit_comment`
 *   on that comment, which is `edit_post` on the item it belongs to.
 * - Seeing it in a list: `edit_comment`, or an approved comment on an item the
 *   caller may read (Post_Access::can_read()).
 * - A comment on an internal item (a post type without an admin screen, see
 *   Post_Access): never, except a WooCommerce order note, which needs the
 *   order type's edit capability (edit_shop_orders), as WooCommerce's own order
 *   screen asks. With HPOS an order note hangs on a placeholder post that maps
 *   to the ordinary post capabilities, so `edit_comment` alone would let an
 *   Editor change it.
 */
final class Comment_Access {

    /** Why the caller may not change this comment (moderate, delete, reply to it), or null when they may. */
    public static function edit_error( \WP_Comment $comment ): ?string {
        $internal = self::internal_item_error( $comment );
        if ( null !== $internal ) {
            return $internal;
        }
        if ( ! current_user_can( 'edit_comment', (int) $comment->comment_ID ) ) {
            return sprintf( 'You do not have permission to change comment %d: it needs permission to edit the item it belongs to.', (int) $comment->comment_ID );
        }
        return null;
    }

    /** Whether the caller may see this comment in a list. */
    public static function can_read( \WP_Comment $comment ): bool {
        if ( null !== self::internal_item_error( $comment ) ) {
            return false;
        }
        if ( current_user_can( 'edit_comment', (int) $comment->comment_ID ) ) {
            return true;
        }
        $post = get_post( (int) $comment->comment_post_ID );
        return $post && '1' === (string) $comment->comment_approved && Post_Access::can_read( $post );
    }

    /**
     * Why a comment on an internal item is out of reach, or null when the item is
     * not internal (or is a WooCommerce order the caller may edit).
     */
    private static function internal_item_error( \WP_Comment $comment ): ?string {
        $post = get_post( (int) $comment->comment_post_ID );
        if ( ! $post || ! Post_Access::is_internal_type( $post->post_type ) ) {
            return null;
        }
        $order_type = self::order_type( $post );
        if ( null !== $order_type ) {
            $pto = get_post_type_object( $order_type );
            if ( $pto && current_user_can( $pto->cap->edit_posts ) ) {
                return null;
            }
            return sprintf( 'Comment %d is a note on a WooCommerce order: it needs permission to edit orders (%s).', (int) $comment->comment_ID, $pto ? $pto->cap->edit_posts : 'edit_shop_orders' );
        }
        return sprintf( 'Comment %d belongs to an internal item (post type "%s", which has no admin screen), so the comment tools do not touch it.', (int) $comment->comment_ID, $post->post_type );
    }

    /** The real WooCommerce order type of a post (an HPOS placeholder included), or null when it is no order. */
    private static function order_type( \WP_Post $post ): ?string {
        if ( ! function_exists( 'wc_get_order_types' ) || ! in_array( $post->post_type, (array) wc_get_order_types(), true ) ) {
            return null;
        }
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            $type = (string) \Automattic\WooCommerce\Utilities\OrderUtil::get_order_type( (int) $post->ID );
            if ( '' !== $type && post_type_exists( $type ) ) {
                return $type;
            }
        }
        return post_type_exists( 'shop_order' ) ? 'shop_order' : null;
    }
}
