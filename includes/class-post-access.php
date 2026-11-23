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
 * - Meta: a key with a permission rule of its own (a register_meta()
 *   auth_callback, or an auth_post_meta_{$key} filter) follows that rule,
 *   through `edit_post_meta`, as in WordPress's editor and REST API. Other
 *   protected keys (`is_protected_meta()`, e.g. a leading underscore) need
 *   `edit_post_meta` for that key, or the type's `edit_others_posts`.
 *   The attachment file keys are never taken from input.
 * - Status: publish, future and private need `publish_post`; without it an
 *   update is saved as pending (what `_wp_translate_postdata()` does).
 * - Internal types: a post type without an admin screen (show_ui
 *   false) is not read, listed, created, changed or deleted through the
 *   general post tools at all. WordPress and plugins keep their own records
 *   there (revisions, menu items, privacy requests, oEmbed cache, block
 *   templates, global styles, fonts, WooCommerce variations, refunds and
 *   order placeholders), often with capabilities that do not say who may
 *   really touch them; their own tools (menus, templates, revisions,
 *   WooCommerce) have their own checks.
 */
final class Post_Access {

    /** Keys that decide which files deleting an attachment removes: never from input. */
    public const NEVER_WRITE_META = [ '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ];

    /** Statuses that put a post live, schedule it or make it private. */
    public const PUBLISH_STATUSES = [ 'publish', 'future', 'private' ];

    /** Whether the caller may see items of this post type at all. Internal types: never. */
    public static function can_see_type( string $post_type ): bool {
        $pto = get_post_type_object( $post_type );
        if ( ! $pto || ! $pto->show_ui ) {
            return false;
        }
        return $pto->public || current_user_can( $pto->cap->edit_posts );
    }

    /**
     * Whether a post type is internal: it has no admin screen (show_ui false),
     * or it does not exist. See the class comment.
     */
    public static function is_internal_type( string $post_type ): bool {
        $pto = get_post_type_object( $post_type );
        return ! $pto || ! $pto->show_ui;
    }

    /** Why the general post tools do not touch items of this post type, or null when they do. */
    public static function internal_type_error( string $post_type ): ?string {
        if ( ! self::is_internal_type( $post_type ) ) {
            return null;
        }
        return sprintf(
            'Post type "%s" is internal: it has no admin screen, and WordPress or a plugin manages these items itself, so the general post tools do not read, list, create, change or delete them. Menus, templates, global styles, revisions and WooCommerce products and orders have their own tools.',
            $post_type
        );
    }

    /** Why the caller may not read this post, or null when they may. */
    public static function read_error( \WP_Post $post ): ?string {
        $internal = self::internal_type_error( $post->post_type );
        if ( null !== $internal ) {
            return $internal;
        }
        return self::can_read( $post ) ? null : 'You do not have permission to read this item.';
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

        $type = $args['post_type'] ?? 'post';
        if ( 'any' === $type ) {
            // What WP_Query's "any" covers, less what the caller may not see.
            $all   = array_values( get_post_types( [ 'exclude_from_search' => false ] ) );
            $types = array_values( array_filter( $all, fn( $t ): bool => self::can_see_type( (string) $t ) ) );
            if ( ! $types ) {
                return [ 'error' => 'There is no content type this account may list.', 'note' => null ];
            }
            if ( $types !== $all ) {
                $args['post_type'] = $types;
            }
        } else {
            $types = (array) $type;
        }
        foreach ( $types as $t ) {
            $internal = self::internal_type_error( (string) $t );
            if ( null !== $internal ) {
                return [ 'error' => $internal, 'note' => null ];
            }
            if ( ! self::can_see_type( (string) $t ) ) {
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
        // A key with a rule of its own: that rule decides, protected or not.
        if ( self::has_meta_auth_rule( $post_id, $key ) ) {
            return current_user_can( 'edit_post_meta', $post_id, $key );
        }
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
     * Whether WordPress has a permission rule of its own for this meta key on this
     * post: an auth_callback given to register_meta() / register_post_meta(), or an
     * auth_post_meta_{$key}(_for_{$post_type}) filter (or the old
     * auth_post_{$post_type}_meta_{$key} one). map_meta_cap() asks that rule for
     * `edit_post_meta`, as the block editor and the REST API do.
     *
     * A key registered without an auth_callback gets WordPress's default one, which
     * only repeats is_protected_meta() (__return_true or __return_false); that is no
     * rule of its own, and the protected-key rule in can_use_meta_key() applies as
     * before.
     */
    private static function has_meta_auth_rule( int $post_id, string $key ): bool {
        $subtype = (string) get_object_subtype( 'post', $post_id );
        if ( '' !== $subtype && has_filter( "auth_post_{$subtype}_meta_{$key}" ) ) {
            return true;
        }
        $hook = "auth_post_meta_{$key}";
        // The hook map_meta_cap() reads: the post type's own one when it has callbacks.
        if ( '' !== $subtype && has_filter( "{$hook}_for_{$subtype}" ) ) {
            $hook = "{$hook}_for_{$subtype}";
        } elseif ( ! has_filter( $hook ) ) {
            return false;
        }
        $default = is_protected_meta( $key, 'post' ) ? '__return_false' : '__return_true';
        $wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;
        if ( ! $wp_hook instanceof \WP_Hook ) {
            return true;
        }
        foreach ( $wp_hook->callbacks as $at_priority ) {
            foreach ( (array) $at_priority as $callback ) {
                if ( $default !== ( $callback['function'] ?? null ) ) {
                    return true;
                }
            }
        }
        return false;
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
        global $wpdb;
        $ok      = [];
        $skipped = [];
        foreach ( $meta as $raw_key => $value ) {
            // The key as sent (2.52.0): sanitize_key() lowercased it and dropped
            // other characters, so "_EventStartDate" was written as
            // "_eventstartdate" (reads keep the case) and a key it emptied vanished.
            $key   = (string) $raw_key;
            $error = self::meta_key_error( $key );
            if ( null !== $error ) {
                $skipped[ '' === $key ? '(empty)' : $key ] = $error;
                continue;
            }
            $lower = strtolower( $key );
            // Keys of this post the database takes for the same one (it compares
            // keys without regard to case or accents): an update would change
            // that row, under its own name, and a read of the key as sent would
            // find nothing.
            $twins = array_values( array_diff( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
                $post_id,
                $key
            ) ) ), [ $key ] ) );
            if ( in_array( $lower, self::NEVER_WRITE_META, true ) || in_array( remove_accents( $lower ), self::NEVER_WRITE_META, true ) ) {
                $skipped[ $key ] = 'WordPress manages this attachment key itself; it is never set from a tool.';
            } elseif ( $twins ) {
                $skipped[ $key ] = sprintf( 'This item already has the meta key "%s", which the database treats as the same key; send it exactly as "%s" to change it.', $twins[0], $twins[0] );
            } elseif ( ! self::can_use_meta_key( $post_id, $key ) || ( $lower !== $key && ! self::can_use_meta_key( $post_id, $lower ) ) ) {
                $skipped[ $key ] = 'Protected meta key: needs permission to edit this key (or other authors\' content of this type).';
            } elseif ( '_wp_page_template' === $lower && '_wp_page_template' !== $key ) {
                $skipped[ $key ] = 'The page template key is "_wp_page_template", in lowercase.';
            } elseif ( '_wp_page_template' === $key && null !== ( $template_error = self::page_template_error( $value, get_post( $post_id ) ) ) ) {
                $skipped[ $key ] = $template_error;
            } else {
                $ok[ $key ] = $value;
            }
        }
        return [ $ok, $skipped ];
    }

    /**
     * Why a meta key sent by a caller cannot be stored as sent, or null.
     * WordPress takes any string; refused here: an empty key, spaces around it
     * (the database would match it to the key without them), control
     * characters, and more than 255 characters (the column's size).
     */
    public static function meta_key_error( string $key ): ?string {
        if ( '' === trim( $key ) ) {
            return 'Empty meta key: send the key\'s name.';
        }
        if ( trim( $key ) !== $key ) {
            return 'Meta key with spaces at the start or end: send it without them.';
        }
        if ( preg_match( '/[\x00-\x1F\x7F]/', $key ) ) {
            return 'Meta key with control characters (line breaks, tabs): send the plain name.';
        }
        if ( strlen( $key ) > 255 ) {
            return 'Meta key longer than 255 characters, which WordPress cannot store.';
        }
        return null;
    }

    /**
     * A meta value as update_post_meta() and the other metadata writers expect it:
     * slashed, because they unslash what they get (every string at any depth, in
     * arrays and objects alike). Unslashed, every backslash in a meta value was lost
     * (2.50.0): JSON meta such as a page builder's data broke, "\u003c" came back as
     * "u003c", "C:\Temp" as "C:Temp".
     *
     * @param mixed $value
     * @return mixed
     */
    public static function slash_meta( $value ) {
        return map_deep( $value, static function ( $v ) {
            return is_string( $v ) ? addslashes( $v ) : $v;
        } );
    }

    /**
     * Why this page template cannot be set, or null when it can.
     *
     * The list is the one wp_insert_post() and WordPress's REST API check:
     * WP_Theme::get_page_templates(), which holds the theme's template files, a block
     * theme's custom templates, and whatever plugins add through theme_page_templates
     * or theme_{post_type}_templates (Elementor's elementor_canvas, for one). "default"
     * and empty always pass.
     *
     * @param mixed         $template  What the caller sent.
     * @param \WP_Post|null $post      The post, when it exists already.
     * @param string        $post_type The post type, when it does not.
     */
    public static function page_template_error( $template, ?\WP_Post $post = null, string $post_type = 'page' ): ?string {
        if ( ! is_string( $template ) ) {
            return 'The page template must be a string.';
        }
        $template = self::page_template_value( $template );
        if ( 'default' === $template ) {
            return null;
        }
        $templates = wp_get_theme()->get_page_templates( $post, $post_type );
        if ( isset( $templates[ $template ] ) ) {
            return null;
        }
        return sprintf( 'Unknown page template "%s". Page templates on this site: %s.', $template, implode( ', ', array_merge( [ 'default' ], array_map( 'strval', array_keys( $templates ) ) ) ) );
    }

    /** The value to store for a page template that passed page_template_error(). */
    public static function page_template_value( string $template ): string {
        $template = trim( $template );
        return '' === $template ? 'default' : $template;
    }

    /** The term lists create_post / update_post take, as IDs: parameter => taxonomy. */
    public const TERM_PARAMS = [ 'categories' => 'category', 'tags' => 'post_tag' ];

    /**
     * Check the "categories" and "tags" a caller sent for an item of this type
     * (2.52.0, audit of 2026-11-23). Until then IDs that do not exist were
     * skipped, so a list of only wrong IDs took every category off a post;
     * an empty list changed nothing; and both were ignored on types without
     * that taxonomy (create_post: on every type but "post"). Now an unknown
     * ID, or a list for a type without the taxonomy, is refused before
     * anything is written.
     *
     * @param array<string,mixed> $params
     * @return array{error:?string,set:array<string,int[]>} taxonomy => term IDs to set.
     */
    public static function term_params( array $params, string $post_type ): array {
        $set = [];
        foreach ( self::TERM_PARAMS as $param => $tax ) {
            if ( ! isset( $params[ $param ] ) ) {
                continue;
            }
            $raw = is_array( $params[ $param ] ) ? array_values( $params[ $param ] ) : [ $params[ $param ] ];
            if ( ! is_object_in_taxonomy( $post_type, $tax ) ) {
                if ( ! $raw ) {
                    continue;
                }
                $taxes = get_object_taxonomies( $post_type );
                return [
                    'error' => sprintf(
                        'Items of type "%s" have no %s (the taxonomy "%s" is not registered for this type), so nothing was changed.%s',
                        $post_type,
                        $param,
                        $tax,
                        $taxes ? ' Its taxonomies: ' . implode( ', ', $taxes ) . '.' : ' It has no taxonomies.'
                    ),
                    'set'   => [],
                ];
            }
            $ids     = [];
            $unknown = [];
            foreach ( $raw as $v ) {
                $id   = is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ? (int) $v : 0;
                $term = $id > 0 ? get_term( $id, $tax ) : null;
                if ( $term instanceof \WP_Term ) {
                    $ids[] = $id;
                } else {
                    $unknown[] = is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v );
                }
            }
            if ( $unknown ) {
                return [
                    'error' => sprintf(
                        'Unknown %s ID%s: %s. Nothing was changed. "%s" takes the IDs of existing terms of the taxonomy "%s"; list_terms with taxonomy "%s" lists them.',
                        'categories' === $param ? 'category' : 'tag',
                        count( $unknown ) > 1 ? 's' : '',
                        implode( ', ', $unknown ),
                        $param,
                        $tax,
                        $tax
                    ),
                    'set'   => [],
                ];
            }
            $set[ $tax ] = array_values( array_unique( $ids ) );
        }
        return [ 'error' => null, 'set' => $set ];
    }

    /**
     * Give the item the terms term_params() checked, and read them back: the
     * result echoes "categories" / "tags" as stored ({id, name, slug}), and a
     * note when that is not what was sent (a post always keeps a category, so
     * an empty list gives it the default one).
     *
     * @param array<string,int[]> $set taxonomy => term IDs.
     * @return array{fields:array<string,array>,note:?string}
     */
    public static function apply_terms( int $post_id, array $set ): array {
        $fields = [];
        $notes  = [];
        foreach ( $set as $tax => $ids ) {
            if ( 'category' === $tax ) {
                wp_set_post_categories( $post_id, $ids );
            } else {
                wp_set_post_terms( $post_id, $ids, $tax );
            }
            $param = (string) array_search( $tax, self::TERM_PARAMS, true );
            $terms = wp_get_object_terms( $post_id, $tax );
            $terms = is_wp_error( $terms ) ? [] : $terms;
            $fields[ $param ] = array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $terms );
            $got  = array_map( static fn( $t ) => (int) $t->term_id, $terms );
            $want = $ids;
            sort( $got );
            sort( $want );
            if ( $got !== $want ) {
                $notes[] = ! $ids && 'category' === $tax && 1 === count( $terms )
                    ? sprintf( 'No category was sent, and a post always keeps one, so WordPress gave it the default category "%s".', $terms[0]->name )
                    : sprintf( 'The %s stored are not the ones sent: see "%s".', $param, $param );
            }
        }
        return [ 'fields' => $fields, 'note' => $notes ? implode( ' ', $notes ) : null ];
    }

    /**
     * Move an item to the trash, or delete it permanently ($force), and say
     * what really happened (2.52.0, audit of 2026-11-23).
     *
     * wp_delete_post( $id, false ) trashes only posts and pages: any other type
     * (a product, a portfolio item, a reusable block, a media file), an item
     * already in the trash, and every item on a site with EMPTY_TRASH_DAYS 0
     * were deleted permanently, while the tools answered "moved to trash".
     * Now a trash is wp_trash_post() for every type, as wp-admin's Trash link
     * does; where WordPress has no trash for the item (EMPTY_TRASH_DAYS 0,
     * already trashed, media without MEDIA_TRASH) nothing is changed and the
     * error says to send force: true. The outcome is read back.
     *
     * @return array{error:?string,outcome:?string,note:?string} outcome "trashed" or "deleted".
     */
    public static function trash_or_delete( \WP_Post $post, bool $force ): array {
        $id    = (int) $post->ID;
        $label = 'attachment' === $post->post_type ? 'media file' : 'item';
        $out   = [ 'error' => null, 'outcome' => null, 'note' => null ];

        if ( ! $force ) {
            if ( 'trash' === $post->post_status ) {
                $out['error'] = "Item {$id} is already in the trash, so it was not changed. To delete it permanently, send force: true.";
                return $out;
            }
            if ( ! EMPTY_TRASH_DAYS ) {
                $out['error'] = "This site has the trash switched off (EMPTY_TRASH_DAYS is 0), so {$label} {$id} cannot be moved to the trash and was not changed. To delete it permanently, send force: true.";
                return $out;
            }
            if ( 'attachment' === $post->post_type && ! MEDIA_TRASH ) {
                $out['error'] = "Item {$id} is a media file, and this site has no trash for media (MEDIA_TRASH is off), so it was not changed. To delete the file permanently, send force: true or use delete_media.";
                return $out;
            }
            $status = $post->post_status;
            wp_trash_post( $id );
            clean_post_cache( $id );
            $after = get_post( $id );
            if ( ! $after ) {
                $out['outcome'] = 'deleted';
                $out['note']    = "A plugin on this site deleted {$label} {$id} permanently instead of moving it to the trash.";
                return $out;
            }
            if ( 'trash' !== $after->post_status ) {
                $out['error'] = "WordPress or a plugin did not move {$label} {$id} to the trash; it was not changed (status \"{$status}\").";
                return $out;
            }
            $out['outcome'] = 'trashed';
            return $out;
        }

        wp_delete_post( $id, true );
        clean_post_cache( $id );
        if ( get_post( $id ) ) {
            $out['error'] = "WordPress or a plugin did not delete {$label} {$id}; it is still there.";
            return $out;
        }
        $out['outcome'] = 'deleted';
        return $out;
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
