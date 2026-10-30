<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Post_Access;

defined( 'ABSPATH' ) || exit;

/**
 * Literal substring search of post_content.
 *
 * WordPress's built-in search (WP_Query `s`) tokenises on spaces and treats
 * double-quotes as phrase delimiters, so it cannot reliably match a raw markup
 * fragment like `fusion_global id="10850"` or a block/shortcode/CSS-class
 * signature. This does a plain `post_content LIKE '%needle%'`, which is the
 * reliable way to answer "which posts use this exact element / block / class?".
 */
class Search_Content_Ability extends Ability {

    /** Post types that never carry meaningful editable content. */
    private const NOISE_TYPES = [
        'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family',
        'wp_font_face', 'patterns_ai_data', 'attachment',
    ];

    protected function define_meta(): void {
        $this->key          = 'search_content';
        $this->label        = __( 'Search Content', 'mcp-abilities' );
        $this->description  = 'Find posts whose stored content contains an EXACT substring — a literal, case-insensitive match on post_content (NOT the fuzzy WordPress keyword search, which mangles quotes and punctuation). This is the reliable way to answer "which pages use this element / block / shortcode / CSS class / markup string?". Provide "query" (the exact text to find, e.g. `fusion_global id="10850"`, a block name, or a distinctive class). Returns each matching post with id, title, type, status, how many times it matches, and a snippet of context around the first match. Filter by post_type (default "any") and status (default "any"). For an Avada/Elementor element with no id reference, search a distinctive fragment of its markup.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'query'         => [ 'type' => 'string',  'description' => 'The exact substring to find in post content. Matched literally, including quotes and punctuation.' ],
                'post_type'     => [ 'type' => 'string',  'description' => 'Restrict to one post type slug, or "any" for all content types. Default "any".', 'default' => 'any' ],
                'status'        => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'pending', 'private', 'future', 'any' ], 'description' => 'Post status, or "any". Default "any".', 'default' => 'any' ],
                'match_case'    => [ 'type' => 'boolean', 'description' => 'Case-sensitive match. Default false (case-insensitive).', 'default' => false ],
                'context_chars' => [ 'type' => 'integer', 'description' => 'Characters of surrounding context to return around the first match (0 for none). Default 100.', 'default' => 100 ],
                'per_page'      => [ 'type' => 'integer', 'description' => 'Results per page (1-100).', 'default' => 25, 'maximum' => 100 ],
                'page'          => [ 'type' => 'integer', 'description' => 'Page number (1-based).', 'default' => 1 ],
            ],
            'required'   => [ 'query' ],
        ];
    }

    public function execute( array $params ): array {
        global $wpdb;

        $query = isset( $params['query'] ) ? (string) $params['query'] : '';
        if ( '' === trim( $query ) ) {
            return $this->error( 'Provide a non-empty "query" substring to search for.' );
        }

        // Resolve post types.
        $post_type = isset( $params['post_type'] ) && '' !== trim( (string) $params['post_type'] )
            ? sanitize_key( (string) $params['post_type'] ) : 'any';
        if ( 'any' === $post_type ) {
            $types = array_values( array_diff( get_post_types( [], 'names' ), self::NOISE_TYPES ) );
        } else {
            if ( ! post_type_exists( $post_type ) ) {
                $available = array_values( array_diff( get_post_types( [ 'show_ui' => true ], 'names' ), self::NOISE_TYPES ) );
                return $this->error( sprintf(
                    'Post type "%s" is not registered. Try "any", or one of: %s.',
                    $post_type,
                    implode( ', ', array_slice( $available, 0, 30 ) )
                ) );
            }
            $types = [ $post_type ];
        }
        if ( ! $types ) {
            return $this->error( 'No searchable post types resolved.' );
        }

        // Resolve statuses.
        $status = isset( $params['status'] ) && '' !== trim( (string) $params['status'] )
            ? sanitize_key( (string) $params['status'] ) : 'any';
        $statuses = 'any' === $status
            ? [ 'publish', 'draft', 'pending', 'private', 'future' ]
            : [ $status ];

        $match_case    = ! empty( $params['match_case'] );
        $context_chars = max( 0, min( 2000, (int) ( $params['context_chars'] ?? 100 ) ) );
        $per_page      = min( 100, max( 1, (int) ( $params['per_page'] ?? 25 ) ) );
        $page          = max( 1, (int) ( $params['page'] ?? 1 ) );
        $offset        = ( $page - 1 ) * $per_page;

        // Only what this account may read (Post_Access), worked into the SQL so
        // total and pages stay right: content types it may see; for a type
        // where it may not edit other authors' items, their unpublished and
        // password-protected items are left out.
        $user_id     = get_current_user_id();
        $scope_sql   = [];
        $scope_args  = [];
        foreach ( $types as $type ) {
            if ( ! Post_Access::can_see_type( $type ) ) {
                if ( 'any' !== $post_type ) {
                    return $this->error( sprintf( 'You do not have permission to search "%s" items.', $type ) );
                }
                continue;
            }
            if ( Post_Access::can_list_unpublished( [ $type ] ) ) {
                $scope_sql[] = '(post_type = %s AND post_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . '))';
                $scope_args  = array_merge( $scope_args, [ $type ], $statuses );
                continue;
            }
            $open = [];
            $own  = [];
            foreach ( $statuses as $s ) {
                $status_obj = get_post_status_object( $s );
                if ( $status_obj && $status_obj->public ) {
                    $open[] = $s;
                } else {
                    $own[] = $s;
                }
            }
            $parts     = [];
            $type_args = [ $type ];
            if ( $open ) {
                $parts[]   = '(post_status IN (' . implode( ', ', array_fill( 0, count( $open ), '%s' ) ) . ") AND (post_password = '' OR post_author = %d))";
                $type_args = array_merge( $type_args, $open, [ $user_id ] );
            }
            if ( $own ) {
                $parts[]   = '(post_status IN (' . implode( ', ', array_fill( 0, count( $own ), '%s' ) ) . ') AND post_author = %d)';
                $type_args = array_merge( $type_args, $own, [ $user_id ] );
            }
            $scope_sql[] = '(post_type = %s AND (' . implode( ' OR ', $parts ) . '))';
            $scope_args  = array_merge( $scope_args, $type_args );
        }
        if ( ! $scope_sql ) {
            return $this->error( 'No searchable post types resolved.' );
        }

        $like    = '%' . $wpdb->esc_like( $query ) . '%';
        $like_op = $match_case ? 'LIKE BINARY' : 'LIKE';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders below are all %s/%d via prepare()
        $where_args = array_merge( [ $like ], $scope_args );
        $where_sql  = "post_content $like_op %s AND (" . implode( ' OR ', $scope_sql ) . ')';

        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where_sql", $where_args )
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->posts} WHERE $where_sql
                 ORDER BY post_modified DESC LIMIT %d OFFSET %d",
                array_merge( $where_args, [ $per_page, $offset ] )
            )
        );
        // phpcs:enable
        update_post_cache( $rows );

        $items     = [];
        $not_shown = 0;
        foreach ( (array) $rows as $row ) {
            $post = get_post( (int) $row->ID );
            if ( ! $post || ! Post_Access::can_read( $post ) ) {
                $not_shown++;
                continue;
            }
            $content = (string) $row->post_content;
            $count   = $match_case
                ? substr_count( $content, $query )
                : substr_count( strtolower( $content ), strtolower( $query ) );

            $item = [
                'id'          => (int) $row->ID,
                'title'       => $row->post_title,
                'type'        => $row->post_type,
                'status'      => $row->post_status,
                'match_count' => $count,
                'permalink'   => get_permalink( (int) $row->ID ),
                'edit_link'   => get_edit_post_link( (int) $row->ID, 'raw' ),
            ];

            if ( $context_chars > 0 ) {
                $pos = $match_case ? strpos( $content, $query ) : stripos( $content, $query );
                if ( false !== $pos ) {
                    $start   = max( 0, $pos - $context_chars );
                    $len     = strlen( $query ) + ( 2 * $context_chars );
                    $snippet = substr( $content, $start, $len );
                    $item['snippet'] = ( $start > 0 ? '…' : '' ) . $snippet . ( $start + $len < strlen( $content ) ? '…' : '' );
                }
            }
            $items[] = $item;
        }

        $result = [
            'query'       => $query,
            'match_case'  => $match_case,
            'post_types'  => 'any' === $post_type ? 'any' : $post_type,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
            'items'       => $items,
        ];
        if ( $not_shown ) {
            $result['not_shown'] = $not_shown;
            $result['note']      = sprintf( '%d matching item(s) on this page are not shown: this account may not read them. "total" counts them.', $not_shown );
        }
        return $this->json_result( $result );
    }
}
