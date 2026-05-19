<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Comments_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_comments';
        $this->label        = __( 'List Comments', 'mcp-abilities' );
        $this->description  = 'List comments with filtering by status, post or author.';
        $this->required_cap = 'moderate_comments';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'status'   => [ 'type' => 'string',  'enum' => [ 'hold', 'approve', 'spam', 'trash', 'all' ], 'default' => 'all' ],
                'post_id'  => [ 'type' => 'integer', 'description' => 'Filter by post ID.' ],
                'per_page' => [ 'type' => 'integer', 'default' => 20 ],
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
                'search'   => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $status_map = [ 'hold' => '0', 'approve' => '1', 'spam' => 'spam', 'trash' => 'trash', 'all' => 'all' ];
        $status     = $status_map[ $params['status'] ?? 'all' ] ?? 'all';
        $number     = min( absint( $params['per_page'] ?? 20 ), 100 );
        $offset     = ( max( 1, absint( $params['page'] ?? 1 ) ) - 1 ) * $number;

        $args = [
            'status'  => $status,
            'number'  => $number,
            'offset'  => $offset,
            'orderby' => 'comment_date',
            'order'   => 'DESC',
        ];

        if ( ! empty( $params['post_id'] ) ) $args['post_id'] = absint( $params['post_id'] );
        if ( ! empty( $params['search'] ) )  $args['search']  = sanitize_text_field( $params['search'] );

        $comments = get_comments( $args );
        $result   = array_map( fn( $c ) => [
            'id'      => $c->comment_ID,
            'post_id' => $c->comment_post_ID,
            'author'  => $c->comment_author,
            'email'   => $c->comment_author_email,
            'content' => $c->comment_content,
            'date'    => $c->comment_date,
            'status'  => wp_get_comment_status( $c->comment_ID ),
            'parent'  => $c->comment_parent,
        ], $comments );

        return $this->json_result( [ 'comments' => $result ] );
    }
}
