<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Delete_Page_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_page';
        $this->label        = __( 'Delete Page', 'mcp-abilities' );
        $this->description  = 'Trash or permanently delete (force: true) a page. A page already in the trash, or a site with the trash switched off, needs force: true; the result says in "outcome" whether it was "trashed" or "deleted".';
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

        // A page already in the trash, or a site without a trash, is no longer
        // deleted permanently while the answer says "moved to trash" (2.52.0).
        $done = \MCP_Abilities\Post_Access::trash_or_delete( $page, $force );
        if ( null !== $done['error'] ) {
            return $this->error( str_replace( 'Item ', 'Page ', $done['error'] ) );
        }
        $extra = [ 'id' => $page_id, 'outcome' => $done['outcome'] ];
        if ( null !== $done['note'] ) {
            $extra['note'] = $done['note'];
        }
        return $this->success( "Page {$page_id} " . ( 'deleted' === $done['outcome'] ? 'deleted permanently' : 'moved to trash' ) . '.', $extra );
    }
}
