<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Wpml_Support;

defined( 'ABSPATH' ) || exit;

class Get_Post_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_post';
        $this->label        = __( 'Get Post', 'mcp-abilities' );
        $this->description  = 'Retrieve a single post by ID including content, meta, and taxonomy terms. Use "fields" (allowlist) or "exclude" (denylist) to control the payload — e.g. exclude ["content"] when auditing meta only.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'integer', 'description' => 'The post ID.' ],
                'fields'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => "Only return these top-level fields, e.g. ['id','title','permalink','meta']." ],
                'exclude' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => "Omit these top-level fields, e.g. ['content']." ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $post = get_post( absint( $params['id'] ) );
        if ( ! $post ) {
            return $this->error( 'Post not found.' );
        }

        $categories = wp_get_post_categories( $post->ID, [ 'fields' => 'all' ] );
        $tags       = wp_get_post_tags( $post->ID );

        $data = [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'content'        => $post->post_content,
            'excerpt'        => $post->post_excerpt,
            'status'         => $post->post_status,
            'slug'           => $post->post_name,
            'date'           => $post->post_date,
            'modified'       => $post->post_modified,
            'author_id'      => (int) $post->post_author,
            'parent_id'      => (int) $post->post_parent,
            'permalink'      => get_permalink( $post->ID ),
            'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: null,
            'categories'     => array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], $categories ),
            'tags'           => array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $tags ),
            'meta'           => get_post_meta( $post->ID ),
        ];

        if ( Wpml_Support::active() ) {
            $data['language'] = Wpml_Support::post_language( $post->ID );
            $data['trid']     = Wpml_Support::post_trid( $post->ID, $post->post_type );
        }

        // Payload control: allowlist then denylist (id is always kept).
        if ( ! empty( $params['fields'] ) && is_array( $params['fields'] ) ) {
            $allow         = array_map( 'sanitize_text_field', $params['fields'] );
            $allow[]       = 'id';
            $data          = array_intersect_key( $data, array_flip( $allow ) );
        }
        if ( ! empty( $params['exclude'] ) && is_array( $params['exclude'] ) ) {
            foreach ( array_map( 'sanitize_text_field', $params['exclude'] ) as $key ) {
                if ( 'id' !== $key ) {
                    unset( $data[ $key ] );
                }
            }
        }

        return $this->json_result( $data );
    }
}
