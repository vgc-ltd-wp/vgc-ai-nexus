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

        if ( ! get_post( $page_id ) ) {
            return $this->error( "Page {$page_id} not found." );
        }

        wp_delete_post( $page_id, $force );
        return $this->success( "Page {$page_id} " . ( $force ? 'deleted permanently' : 'moved to trash' ) . '.' );
    }
}
