<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Posts_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_posts';
        $this->label        = __( 'List Posts', 'mcp-abilities' );
        $this->description  = 'List WordPress posts with filtering by status, category, author or date.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'status'   => [ 'type' => 'string',  'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ], 'default' => 'publish' ],
                'per_page' => [ 'type' => 'integer', 'description' => 'Number of posts to return (1-100).', 'default' => 10 ],
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
                'search'   => [ 'type' => 'string',  'description' => 'Search term.' ],
                'author'   => [ 'type' => 'integer', 'description' => 'Filter by author user ID.' ],
                'category' => [ 'type' => 'integer', 'description' => 'Filter by category ID.' ],
                'orderby'  => [ 'type' => 'string',  'enum' => [ 'date', 'title', 'ID', 'modified', 'rand' ], 'default' => 'date' ],
                'order'    => [ 'type' => 'string',  'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $args = [
            'post_type'      => 'post',
            'post_status'    => sanitize_text_field( $params['status']   ?? 'publish' ),
            'posts_per_page' => min( absint( $params['per_page'] ?? 10 ), 100 ),
            'paged'          => max( 1, absint( $params['page']    ?? 1 ) ),
            'orderby'        => sanitize_key( $params['orderby']   ?? 'date' ),
            'order'          => strtoupper( $params['order']       ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
        ];

        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }
        if ( ! empty( $params['author'] ) ) {
            $args['author'] = absint( $params['author'] );
        }
        if ( ! empty( $params['category'] ) ) {
            $args['cat'] = absint( $params['category'] );
        }

        $query = new \WP_Query( $args );
        $posts = [];
        foreach ( $query->posts as $post ) {
            $posts[] = $this->format_post( $post );
        }

        return $this->json_result( [
            'posts'       => $posts,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        ] );
    }

    private function format_post( \WP_Post $p ): array {
        return [
            'id'           => $p->ID,
            'title'        => $p->post_title,
            'slug'         => $p->post_name,
            'status'       => $p->post_status,
            'date'         => $p->post_date,
            'modified'     => $p->post_modified,
            'author_id'    => (int) $p->post_author,
            'excerpt'      => get_the_excerpt( $p ),
            'permalink'    => get_permalink( $p->ID ),
            'comment_count'=> (int) $p->comment_count,
        ];
    }
}
