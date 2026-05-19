<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Delete_User_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_user';
        $this->label        = __( 'Delete User', 'mcp-abilities' );
        $this->description  = 'Delete a WordPress user, optionally reassigning their content.';
        $this->required_cap = 'delete_users';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'          => [ 'type' => 'integer' ],
                'reassign_to' => [ 'type' => 'integer', 'description' => 'User ID to reassign posts to.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $user_id  = absint( $params['id'] );
        $reassign = ! empty( $params['reassign_to'] ) ? absint( $params['reassign_to'] ) : null;

        if ( $user_id === get_current_user_id() ) {
            return $this->error( 'You cannot delete yourself.' );
        }

        // Prevent deleting accounts that outrank the current user.
        $target = get_user_by( 'id', $user_id );
        if ( $target && in_array( 'administrator', (array) $target->roles, true )
            && ! current_user_can( 'manage_options' ) ) {
            return $this->error( 'You cannot delete an administrator account.' );
        }

        // Prevent deleting the last administrator — that would lock everyone out.
        if ( $target && in_array( 'administrator', (array) $target->roles, true ) ) {
            $admin_count = count( get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ] ) );
            if ( $admin_count <= 1 ) {
                return $this->error( 'Cannot delete the last administrator account.' );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        if ( ! wp_delete_user( $user_id, $reassign ) ) {
            return $this->error( "Failed to delete user {$user_id}." );
        }

        return $this->success( "User {$user_id} deleted." );
    }
}
