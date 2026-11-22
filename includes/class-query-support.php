<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared list-query helper for ability list endpoints.
 *
 * Centralises pagination (offset / page + per_page cap), WPML language scoping
 * and the standard response envelope so list_posts, list_pages and the SEO
 * audit action behave identically.
 */
final class Query_Support {

    /**
     * Run a paginated, optionally language-scoped WP_Query and format each post.
     *
     * The query is first limited to what the caller may see
     * (Post_Access::scope_list_query()); a refusal comes back as an error result.
     *
     * With $item_fields given (2.47.0), "fields" works like get_post's: a name
     * that is one of $item_fields cuts every item down to id and the fields
     * named (see split_fields()). Since 2.48.0 meta keys alone cut items down
     * too, to id, title and the meta named (not inside another tool call, such
     * as a Batch step: there items stay whole); and a big answer of whole items
     * (no "fields") says in "note" how small the same list is with "fields"
     * (size_note()). The formatter may give a Closure for a costly value: it
     * is called only for a field that is kept.
     *
     * @param array<string,mixed> $args        Base WP_Query args (post_type, post_status, …).
     * @param array<string,mixed> $params      Raw ability input (per_page, page, offset, language, fields).
     * @param callable            $formatter   fn( \WP_Post $post, string[] $meta_keys, ?string[] $keep ): array
     * @param string[]            $item_fields The item fields "fields" may name; none = every name is a meta key.
     * @return array{items:array,total:int,total_pages:int,page:int,per_page:int,offset:int,note?:string,fields_note?:string}|array{success:false,error:string}
     */
    public static function run( array $args, array $params, callable $formatter, array $item_fields = [] ): array {
        $scope = Post_Access::scope_list_query( $args );
        if ( null !== $scope['error'] ) {
            return [ 'success' => false, 'error' => $scope['error'] ];
        }

        $per_page = min( max( 1, absint( $params['per_page'] ?? 10 ) ), 100 );
        $args['posts_per_page'] = $per_page;

        $page = max( 1, absint( $params['page'] ?? 1 ) );
        if ( isset( $params['offset'] ) && '' !== $params['offset'] ) {
            $offset         = max( 0, absint( $params['offset'] ) );
            $args['offset'] = $offset;            // WP_Query: offset overrides paged
        } else {
            $offset        = ( $page - 1 ) * $per_page;
            $args['paged'] = $page;
        }

        $keep    = null;
        $dropped = [];
        $named   = self::meta_keys( $params );
        if ( $item_fields ) {
            // Inside another tool call (a Batch step) meta keys alone keep whole
            // items, as in 2.47.0: a batch's conditions run on the server and may
            // read any item field ({"status": {"ne": "publish"}}); a field missing
            // from cut-down items would read as empty there and match every item.
            $split     = self::split_fields( $named, $item_fields, true, ! Request_Context::nested() );
            $keep      = $split['keep'];
            $meta_keys = $split['meta'];
            $dropped   = $split['unknown'];
        } else {
            $meta_keys = $named;
        }

        // WPML language scoping (no-op when WPML is inactive or no language given).
        $language = isset( $params['language'] ) ? sanitize_text_field( (string) $params['language'] ) : '';
        $previous = '' !== $language ? Wpml_Support::switch_to( $language ) : null;

        $query = new \WP_Query( $args );
        $items = [];
        foreach ( $query->posts as $post ) {
            $item    = $formatter( $post, $meta_keys, $keep );
            $items[] = $item_fields ? self::finish_item( $item, $keep, [] !== $meta_keys ) : $item;
        }
        $total       = (int) $query->found_posts;
        $total_pages = (int) ceil( $total / $per_page );

        Wpml_Support::restore( $previous );

        $out = [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => $page,
            'per_page'    => $per_page,
            'offset'      => $offset,
        ];
        if ( null !== $scope['note'] ) {
            $out['note'] = $scope['note'];
        }
        if ( $item_fields ) {
            $fields_note = self::fields_note( $items, $meta_keys, $dropped, $item_fields, true );
            if ( null !== $fields_note ) {
                $out['fields_note'] = $fields_note;
            }
            if ( ! $named ) {
                $out = self::with_size_note( $out, $items, self::compact_fields( $item_fields ), '_thumbnail_id' );
            }
        }
        return $out;
    }

    /**
     * From this many characters (the whole items of one answer, as the client
     * shows them), size_note() says how to get a smaller answer (2.48.0).
     * 100 posts are about 33,000; 30 about 10,000.
     */
    public const SIZE_NOTE_MIN = 10000;

    /** The smallest useful form of an item: id and its name ("title" or "name"). */
    public static function compact_fields( array $item_fields ): array {
        foreach ( [ 'title', 'name' ] as $label ) {
            if ( in_array( $label, $item_fields, true ) ) {
                return [ 'id', $label ];
            }
        }
        return [ 'id' ];
    }

    /** The JSON text a client shows the model for $data: its length in characters. */
    public static function json_length( $data ): int {
        $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return false === $json ? 0 : ( function_exists( 'mb_strlen' ) ? mb_strlen( $json, 'UTF-8' ) : strlen( $json ) );
    }

    /**
     * One line for a big list of whole items (2.48.0), or null: how many
     * characters the items are, and how many the same list is with "fields"
     * naming $compact (worked out from these very items). Null when the items
     * are under SIZE_NOTE_MIN characters or the compact form is not at least
     * half the size. A client may have a tool description from long ago (a
     * connector keeps the first one it saw), so the answer itself says it.
     *
     * @param array<int,array<string,mixed>> $items        Whole items, as answered.
     * @param string[]                       $compact      E.g. [ 'id', 'title' ].
     * @param string                         $meta_example A meta key to show in the example; '' when items have no meta.
     */
    public static function size_note( array $items, array $compact, string $meta_example = '' ): ?string {
        if ( ! $items ) {
            return null;
        }
        $whole = self::json_length( $items );
        if ( $whole < self::SIZE_NOTE_MIN ) {
            return null;
        }
        $flip  = array_flip( $compact );
        $small = self::json_length( array_map( static fn( $item ): array => array_intersect_key( (array) $item, $flip ), $items ) );
        if ( $small * 2 > $whole ) {
            return null;
        }
        $quote = static fn( array $names ): string => '["' . implode( '", "', $names ) . '"]';
        return sprintf(
            'Big answer: these %d whole items are %s characters. Name only the fields you need: "fields": %s gives the same list in %s characters%s.',
            count( $items ),
            number_format( $whole ),
            $quote( $compact ),
            number_format( $small ),
            '' !== $meta_example ? sprintf( '; add any meta key you need, e.g. %s', $quote( array_merge( $compact, [ $meta_example ] ) ) ) : ''
        );
    }

    /**
     * $out with size_note() added to its "note" (after any note already there).
     *
     * @param array<string,mixed>            $out
     * @param array<int,array<string,mixed>> $items
     * @param string[]                       $compact
     * @return array<string,mixed>
     */
    public static function with_size_note( array $out, array $items, array $compact, string $meta_example = '' ): array {
        $note = self::size_note( $items, $compact, $meta_example );
        if ( null !== $note ) {
            $out['note'] = isset( $out['note'] ) && '' !== (string) $out['note'] ? $out['note'] . ' ' . $note : $note;
        }
        return $out;
    }

    /** Sanitised list of requested meta keys from the `fields` param. */
    public static function meta_keys( array $params ): array {
        if ( empty( $params['fields'] ) || ! is_array( $params['fields'] ) ) {
            return [];
        }
        return array_values( array_filter( array_map( 'sanitize_text_field', $params['fields'] ) ) );
    }

    /** A name in "fields" that is always a meta key: "meta._thumbnail_id". */
    private const META_PREFIX = 'meta.';

    /**
     * Sort the names in "fields" (2.47.0).
     *
     * - A name in $item_fields is an item field to keep. When there is one,
     *   every item is cut down to id and the fields named ("keep").
     * - "meta.<key>" is always the meta key <key> (a meta key may have an item
     *   field's name; agents also write the path they read it by).
     * - Any other name is a meta key, or unknown where items have no meta.
     * - Meta keys with no item field (2.48.0; until then whole items plus
     *   "meta", about 5 times larger): items are cut down to id and "title"
     *   (or "name"; just id when items have neither) besides the meta named.
     *   So where items have meta, any name at all gives cut-down items.
     *   $meta_alone_small false keeps 2.47.0's whole items for them (run()
     *   passes it inside another tool call, e.g. a Batch step).
     * - "meta" alone cannot ask for a list item's whole meta: when items are
     *   cut down it is left out (and reported); a meta key named "meta" is
     *   "meta.meta". In whole items it stays the meta key "meta", as before.
     *
     * @param string[] $names
     * @param string[] $item_fields
     * @return array{keep:?array,meta:array,unknown:array} keep null = whole items.
     */
    public static function split_fields( array $names, array $item_fields, bool $meta_ok = true, bool $meta_alone_small = true ): array {
        $keep    = [];
        $meta    = [];
        $unknown = [];
        $bare    = false;
        foreach ( $names as $name ) {
            $name = (string) $name;
            if ( $meta_ok && 0 === strpos( $name, self::META_PREFIX ) && strlen( $name ) > strlen( self::META_PREFIX ) ) {
                $meta[] = substr( $name, strlen( self::META_PREFIX ) );
            } elseif ( in_array( $name, $item_fields, true ) ) {
                $keep[] = $name;
            } elseif ( $meta_ok ) {
                $bare   = $bare || 'meta' === $name;
                $meta[] = $name;
            } else {
                $unknown[] = $name;
            }
        }
        if ( $meta_ok && $meta_alone_small && ! $keep && $meta ) {
            $keep = array_values( array_diff( self::compact_fields( $item_fields ), [ 'id' ] ) ) ?: [ 'id' ];
        }
        if ( $bare && $keep ) {
            $meta      = array_diff( $meta, [ 'meta' ] );
            $unknown[] = 'meta';
        }
        return [
            'keep'    => $keep ? array_values( array_unique( $keep ) ) : null,
            'meta'    => array_values( array_unique( $meta ) ),
            'unknown' => array_values( array_unique( $unknown ) ),
        ];
    }

    /**
     * A list item cut down to id and $keep ($keep null: whole), with every
     * Closure value of what is kept worked out.
     *
     * @param array<string,mixed> $item
     * @param string[]|null       $keep
     * @return array<string,mixed>
     */
    public static function finish_item( array $item, ?array $keep, bool $with_meta = false ): array {
        if ( null !== $keep ) {
            $allow = array_merge( [ 'id' ], $keep );
            if ( $with_meta ) {
                $allow[] = 'meta';
                $allow[] = 'meta_hidden';
            }
            $item = array_intersect_key( $item, array_flip( $allow ) );
        }
        foreach ( $item as $key => $value ) {
            if ( $value instanceof \Closure ) {
                $item[ $key ] = $value();
            }
        }
        return $item;
    }

    /**
     * One sentence about the names in "fields" that gave nothing, or null:
     * names that are no field of these items, and meta keys that no listed
     * item has a value for (often a guess, such as "featured_media"). Says
     * which fields can be named. Only what the caller was shown counts: a key
     * withheld from them (meta_hidden) is not called empty.
     *
     * @param array<int,array<string,mixed>> $items
     * @param string[]                       $meta_keys
     * @param string[]                       $unknown
     * @param string[]                       $item_fields
     */
    public static function fields_note( array $items, array $meta_keys, array $unknown, array $item_fields, bool $meta_ok = false ): ?string {
        $empty = [];
        if ( $items ) {
            foreach ( $meta_keys as $key ) {
                $has = false;
                foreach ( $items as $item ) {
                    $value = $item['meta'][ $key ] ?? '';
                    if ( ( '' !== $value && null !== $value && [] !== $value ) || in_array( $key, (array) ( $item['meta_hidden'] ?? [] ), true ) ) {
                        $has = true;
                        break;
                    }
                }
                if ( ! $has ) {
                    $empty[] = $key;
                }
            }
        }
        if ( ! $empty && ! $unknown ) {
            return null;
        }
        $quote = static fn( array $names ): string => '"' . implode( '", "', $names ) . '"';
        $parts = [];
        if ( $unknown ) {
            $parts[] = sprintf( 'Not a field of these items, left out: %s.', $quote( $unknown ) );
        }
        if ( $empty ) {
            $parts[] = sprintf( 'No item in this list has a value for the meta key%s %s.', count( $empty ) > 1 ? 's' : '', $quote( $empty ) );
        }
        $parts[] = sprintf(
            'Fields you can name: %s%s.',
            implode( ', ', $item_fields ),
            $meta_ok ? '; any other name is a meta key, under "meta"' : ''
        );
        if ( in_array( 'featured_image', $item_fields, true ) && preg_grep( '/thumb|image|featured|media/i', array_diff( array_merge( $empty, $unknown ), [ '_thumbnail_id' ] ) ) ) {
            $parts[] = 'A featured image is "featured_image" (its address) or the meta key "_thumbnail_id" (its ID).';
        }
        return implode( ' ', $parts );
    }

    /**
     * Build a meta map for the requested keys (single values). Keys the caller
     * may not read (Post_Access) are left out and listed in `$hidden`; a post
     * the caller may not read gives no meta at all.
     *
     * @param string[] $hidden Keys left out, appended in place.
     */
    public static function meta_block( int $post_id, array $meta_keys, array &$hidden = [] ): array {
        $post = get_post( $post_id );
        if ( ! $post || ! Post_Access::can_read( $post ) ) {
            $hidden = array_merge( $hidden, $meta_keys );
            return [];
        }
        $meta = [];
        foreach ( $meta_keys as $key ) {
            if ( ! Post_Access::can_use_meta_key( $post_id, $key ) ) {
                $hidden[] = $key;
                continue;
            }
            $meta[ $key ] = get_post_meta( $post_id, $key, true );
        }
        return $meta;
    }

    /**
     * Meta for a list item: the readable keys under `meta`, the withheld ones
     * under `meta_hidden` (only when there are any).
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function with_meta( array $item, int $post_id, array $meta_keys ): array {
        $hidden       = [];
        $item['meta'] = self::meta_block( $post_id, $meta_keys, $hidden );
        if ( $hidden ) {
            $item['meta_hidden'] = $hidden;
        }
        return $item;
    }

    /**
     * Add WPML language + trid to an item array when WPML is active.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function with_wpml( array $item, \WP_Post $post ): array {
        if ( ! Wpml_Support::active() ) {
            return $item;
        }
        $item['language'] = Wpml_Support::post_language( $post->ID );
        $item['trid']     = Wpml_Support::post_trid( $post->ID, $post->post_type );
        return $item;
    }
}
