<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Object-level permission rules for post reads and writes.
 *
 * The tool gate (Ability::current_user_can()) checks one capability per tool;
 * which post, which status and which meta key the caller may touch is decided
 * here, the way WordPress decides it for its own screens and REST API:
 *
 * - Reading a post: the post type must be public or editable by the caller,
 *   `read_post` must pass, and a post that is not publicly visible (draft,
 *   pending, private, scheduled, trashed, password-protected) also needs
 *   `edit_post`.
 * - Listing: other authors' unpublished posts need the type's
 *   `edit_others_posts` (and `read_private_posts` for private ones).
 * - Meta: protected keys (`is_protected_meta()`, e.g. a leading underscore)
 *   need `edit_post_meta` for that key, or the type's `edit_others_posts`.
 *   The attachment file keys are never taken from input.
 * - Status: publish, future and private need `publish_post`; without it an
 *   update is saved as pending (what `_wp_translate_postdata()` does).
 */
final class Post_Access {

    /** Keys that decide which files deleting an attachment removes: never from input. */
    public const NEVER_WRITE_META = [ '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ];

    /** Statuses that put a post live, schedule it or make it private. */
    public const PUBLISH_STATUSES = [ 'publish', 'future', 'private' ];

    /** Whether the caller may see items of this post type at all. */
    public static function can_see_type( string $post_type ): bool {
        $pto = get_post_type_object( $post_type );
        if ( ! $pto ) {
            return false;
        }
        return $pto->public || current_user_can( $pto->cap->edit_posts );
    }

    /** Whether everyone may see this post as it is (public status, no password). */
    public static function is_public( \WP_Post $post ): bool {
        if ( '' !== (string) $post->post_password ) {
            return false;
        }
        $status = get_post_status_object( (string) get_post_status( $post ) );
        return $status && $status->public;
    }

    /** Whether the caller may read this post: its content, meta and details. */
    public static function can_read( \WP_Post $post ): bool {
        if ( ! self::can_see_type( $post->post_type ) ) {
            return false;
        }
        if ( ! current_user_can( 'read_post', $post->ID ) ) {
            return false;
        }
        return self::is_public( $post ) || current_user_can( 'edit_post', $post->ID );
    }

    /**
     * Whether the caller may list every author's unpublished posts of these
     * types (drafts, pending, scheduled, private).
     *
     * @param string[] $post_types
     */
    public static function can_list_unpublished( array $post_types ): bool {
        foreach ( $post_types as $type ) {
            $pto = get_post_type_object( $type );
            if ( ! $pto || ! current_user_can( $pto->cap->edit_others_posts ) || ! current_user_can( $pto->cap->read_private_posts ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Restrict a list query to what the caller may see.
     *
     * Adds `perm => readable`. Explicit IDs (`post__in`) are kept only when the
     * caller may read each one. Otherwise a status other than publish is kept
     * for callers who may list everyone's unpublished posts; anyone else gets
     * only their own posts for it (asking for another author's is refused).
     *
     * @param array<string,mixed> $args WP_Query args, changed in place.
     * @return array{error:?string,note:?string}
     */
    public static function scope_list_query( array &$args ): array {
        $args['perm'] = 'readable';

        if ( ! empty( $args['post__in'] ) ) {
            $ids     = array_map( 'intval', (array) $args['post__in'] );
            $allowed = array_values( array_filter( $ids, function ( int $id ): bool {
                $post = get_post( $id );
                return $post && self::can_read( $post );
            } ) );
            $dropped          = count( $ids ) - count( $allowed );
            $args['post__in'] = $allowed ? $allowed : [ 0 ];
            return [
                'error' => null,
                'note'  => $dropped ? sprintf( '%d of the requested IDs are not shown: they do not exist or this account may not read them.', $dropped ) : null,
            ];
        }

        $type  = $args['post_type'] ?? 'post';
        $types = 'any' === $type
            ? array_values( get_post_types( [ 'exclude_from_search' => false ] ) )
            : (array) $type;
        foreach ( $types as $t ) {
            if ( 'any' !== $type && ! self::can_see_type( (string) $t ) ) {
                return [ 'error' => sprintf( 'You do not have permission to list "%s" items.', $t ), 'note' => null ];
            }
        }

        $statuses = array_map( 'strval', (array) ( $args['post_status'] ?? 'publish' ) );
        if ( [ 'publish' ] === $statuses || self::can_list_unpublished( $types ) ) {
            return [ 'error' => null, 'note' => null ];
        }

        $me     = get_current_user_id();
        $author = isset( $args['author'] ) ? (int) $args['author'] : 0;
        if ( $author && $author !== $me ) {
            return [
                'error' => 'You can list other authors\' published items only. Their drafts, pending, scheduled and private items need the "edit others" permission for this content type; use status "publish" or your own user ID.',
                'note'  => null,
            ];
        }
        $args['author'] = $me;
        return [
            'error' => null,
            'note'  => 'Only your own items are listed for this status: other authors\' unpublished items need the "edit others" permission for this content type.',
        ];
    }

    /** Whether the caller may read or write this meta key of this post. */
    public static function can_use_meta_key( int $post_id, string $key ): bool {
        if ( ! is_protected_meta( $key, 'post' ) ) {
            return true;
        }
        if ( current_user_can( 'edit_post_meta', $post_id, $key ) ) {
            return true;
        }
        $pto = get_post_type_object( (string) get_post_type( $post_id ) );
        return $pto && current_user_can( $pto->cap->edit_others_posts ) && current_user_can( 'edit_post', $post_id );
    }

    /**
     * Drop the meta keys the caller may not read.
     *
     * @param array<string,mixed> $meta   key => value(s).
     * @param string[]            $hidden Keys left out, appended in place.
     * @return array<string,mixed>
     */
    public static function readable_meta( int $post_id, array $meta, array &$hidden ): array {
        foreach ( array_keys( $meta ) as $key ) {
            if ( ! self::can_use_meta_key( $post_id, (string) $key ) ) {
                unset( $meta[ $key ] );
                $hidden[] = (string) $key;
            }
        }
        return $meta;
    }

    /**
     * Split caller meta into what may be written and what is refused.
     *
     * @param array<string,mixed> $meta Raw key => value from input.
     * @return array{0:array<string,mixed>,1:array<string,string>} [ writable, skipped key => reason ]
     */
    public static function writable_meta( int $post_id, array $meta ): array {
        $ok      = [];
        $skipped = [];
        foreach ( $meta as $raw_key => $value ) {
            $key = sanitize_key( (string) $raw_key );
            if ( '' === $key ) {
                continue;
            }
            if ( in_array( $key, self::NEVER_WRITE_META, true ) ) {
                $skipped[ $key ] = 'WordPress manages this attachment key itself; it is never set from a tool.';
            } elseif ( ! self::can_use_meta_key( $post_id, $key ) ) {
                $skipped[ $key ] = 'Protected meta key: needs permission to edit this key (or other authors\' content of this type).';
            } else {
                $ok[ $key ] = $value;
            }
        }
        return [ $ok, $skipped ];
    }

    /**
     * The status an update of an existing post may set.
     *
     * Publish, future and private need `publish_post`. Without it the post is
     * saved as pending instead, unless it already has that status and no date
     * change can move it (WordPress keeps a published or private post that way
     * when someone who may edit it saves it). Trash needs `delete_post`.
     *
     * @param string|null $requested   Status sent by the caller, sanitized; null when none.
     * @param bool        $date_change Whether the update changes the post date.
     * @return array{status:?string,note:?string,error:?string} status null = leave the status alone.
     */
    public static function update_status( \WP_Post $post, ?string $requested, bool $date_change ): array {
        $out = [ 'status' => $requested, 'note' => null, 'error' => null ];
        if ( 'trash' === $requested && ! current_user_can( 'delete_post', $post->ID ) ) {
            $out['error'] = 'You do not have permission to move this item to the trash.';
            return $out;
        }
        $target = $requested ?? ( $date_change ? $post->post_status : null );
        if ( null === $target || ! in_array( $target, self::PUBLISH_STATUSES, true ) ) {
            return $out;
        }
        if ( current_user_can( 'publish_post', $post->ID ) ) {
            return $out;
        }
        if ( $target === $post->post_status && ( 'future' !== $target || ! $date_change ) ) {
            $out['status'] = null;
            return $out;
        }
        $pto           = get_post_type_object( $post->post_type );
        $cap           = $pto ? $pto->cap->publish_posts : 'publish_posts';
        $out['status'] = 'pending';
        $out['note']   = sprintf( 'Status "%s" needs the permission to publish (%s), which this account does not have, so the item was saved as "pending" (Pending Review) for an editor to publish.', $target, $cap );
        return $out;
    }
}
