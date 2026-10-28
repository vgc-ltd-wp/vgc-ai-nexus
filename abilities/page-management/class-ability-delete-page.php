<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Delete_Page_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_page';
        $this->label        = __( 'Delete Page', 'mcp-abilities' );
        $this->description  = 'Trash or permanently delete a page.';
        $this->required_cap = 'delete_pages';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'integer' ],
                'force'   => [ 'type' => 'boolean', 'default' => false ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $page_id = absint( $params['id'] );
        $force   = ! empty( $params['force'] );

        $page = get_post( $page_id );
        if ( ! $page ) {
            return $this->error( "Page {$page_id} not found." );
        }
        if ( 'page' !== $page->post_type ) {
            return $this->error( "Item {$page_id} is a {$page->post_type}, not a page. Use delete_post for other post types." );
        }
        if ( ! current_user_can( 'delete_post', $page_id ) ) {
            return $this->error( 'You do not have permission to delete this page.' );
        }

        if ( ! wp_delete_post( $page_id, $force ) ) {
            return $this->error( 'Failed to delete the page.' );
        }
        return $this->success( "Page {$page_id} " . ( $force ? 'deleted permanently' : 'moved to trash' ) . '.' );
    }
}
