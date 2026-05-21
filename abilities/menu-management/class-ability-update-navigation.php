<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Update_Navigation_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_navigation';
        $this->label        = __( 'Update Block Navigation', 'mcp-abilities' );
        $this->description  = 'Write new block markup to a wp_navigation post. Use get_navigation first to read the current content. The content should be serialised WordPress block markup using core/navigation-link and core/navigation-submenu blocks.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'integer', 'description' => 'wp_navigation post ID (from list_navigations).' ],
                'content' => [ 'type' => 'string',  'description' => 'New block markup for the navigation.' ],
                'title'   => [ 'type' => 'string',  'description' => 'Optional new title for the navigation.' ],
            ],
            'required' => [ 'id', 'content' ],
        ];
    }

    public function execute( array $params ): array {
        $id      = absint( $params['id'] );
        $content = $params['content'];
        $post    = get_post( $id );

        if ( ! $post || 'wp_navigation' !== $post->post_type ) {
            return $this->error( "Navigation {$id} not found." );
        }

        if ( '' === trim( $content ) ) {
            return $this->error( 'Navigation content cannot be empty.' );
        }

        $update_data = [
            'ID'           => $id,
            'post_content' => $content,
        ];

        if ( ! empty( $params['title'] ) ) {
            $update_data['post_title'] = sanitize_text_field( $params['title'] );
        }

        $result = wp_update_post( $update_data, true );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "Navigation {$id} updated." );
    }
}
