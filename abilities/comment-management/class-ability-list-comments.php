<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Comment_Access;

defined( 'ABSPATH' ) || exit;

class List_Comments_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_comments';
        $this->label        = __( 'List Comments', 'mcp-abilities' );
        $this->description  = 'List comments with filtering by status, post or author. Only comments this account may see are listed (the result says how many were left out in "not_shown").';
        $this->required_cap = 'moderate_comments';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'status'   => [ 'type' => 'string',  'enum' => [ 'hold', 'approve', 'spam', 'trash', 'all' ], 'default' => 'all', 'description' => '"all" is approved and pending comments (not spam or trash).' ],
                'post_id'  => [ 'type' => 'integer', 'description' => 'Filter by post ID.' ],
                'per_page' => [ 'type' => 'integer', 'default' => 20, 'description' => 'Comments per page (1-100). The result has total and has_more.' ],
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
                'search'   => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $status_map = [ 'hold' => '0', 'approve' => '1', 'spam' => 'spam', 'trash' => 'trash', 'all' => 'all' ];
        $status     = $status_map[ $params['status'] ?? 'all' ] ?? 'all';
        // 1-100 a page; per_page 0 or less is the default 20 (0 used to mean every
        // comment at once, past the cap).
        $number     = isset( $params['per_page'] ) && (int) $params['per_page'] > 0 ? min( (int) $params['per_page'], 100 ) : 20;
        $page       = max( 1, (int) ( $params['page'] ?? 1 ) );
        $offset     = ( $page - 1 ) * $number;

        $args = [
            'status'  => $status,
            'orderby' => 'comment_date',
            'order'   => 'DESC',
        ];

        if ( ! empty( $params['post_id'] ) ) $args['post_id'] = absint( $params['post_id'] );
        if ( ! empty( $params['search'] ) )  $args['search']  = sanitize_text_field( $params['search'] );

        // How many there are in all (2.52.0): the list is cut at 100 a page, and
        // nothing said how many more there were.
        $total = (int) get_comments( array_merge( $args, [ 'count' => true ] ) );

        // Only what this account may see (Comment_Access): comments it may edit, and
        // approved ones on items it may read; never notes on internal items.
        $comments  = get_comments( array_merge( $args, [ 'number' => $number, 'offset' => $offset ] ) );
        $on_page   = count( $comments );
        $readable  = array_values( array_filter( $comments, [ Comment_Access::class, 'can_read' ] ) );
        $not_shown = count( $comments ) - count( $readable );
        $comments  = $readable;
        $result    = array_map( fn( $c ) => [
            'id'      => $c->comment_ID,
            'post_id' => $c->comment_post_ID,
            'author'  => $c->comment_author,
            'email'   => $c->comment_author_email,
            'content' => $c->comment_content,
            'date'    => $c->comment_date,
            'status'  => wp_get_comment_status( $c->comment_ID ),
            'parent'  => $c->comment_parent,
        ], $comments );

        $out = [
            'comments' => $result,
            'total'    => $total,
            'per_page' => $number,
            'page'     => $page,
            'has_more' => $offset + $on_page < $total,
        ];
        if ( $not_shown ) {
            $out['not_shown'] = $not_shown;
            $out['note']      = sprintf( '%d comment(s) on this page are not shown: this account may not see them.', $not_shown );
        }
        if ( $out['has_more'] ) {
            $out = self::with_note( $out, sprintf( 'Comments %d-%d of %d. For the next ones send "page": %d.', $offset + 1, $offset + $on_page, $total, $page + 1 ) );
        }
        return $this->json_result( $out );
    }
}
