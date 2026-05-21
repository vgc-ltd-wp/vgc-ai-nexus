<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Get_Navigation_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_navigation';
        $this->label        = __( 'Get Block Navigation', 'mcp-abilities' );
        $this->description  = 'Retrieve the full block markup of a wp_navigation post. The content is a serialised block string containing core/navigation-link, core/navigation-submenu blocks etc. Always call this before update_navigation so you have the current content.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'wp_navigation post ID (from list_navigations).' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $id   = absint( $params['id'] );
        $post = get_post( $id );

        if ( ! $post || 'wp_navigation' !== $post->post_type ) {
            return $this->error( "Navigation {$id} not found." );
        }

        return $this->json_result( [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'status'       => $post->post_status,
            'content'      => $post->post_content,
            'date_modified' => $post->post_modified,
        ] );
    }
}
