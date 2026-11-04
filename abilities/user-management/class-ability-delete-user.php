<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\User_Access;

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

        $target = get_user_by( 'id', $user_id );
        if ( $target ) {
            // Prevent deleting accounts that outrank the current user.
            if ( User_Access::outranks( $target ) ) {
                return $this->error( 'You cannot delete an administrator account.' );
            }

            // delete_users above says the tool may be used at all; this asks about
            // this account, so WooCommerce's and multisite's rules apply.
            if ( ! User_Access::can_on( 'delete_user', $target ) ) {
                return $this->error( 'You do not have permission to delete this user.' );
            }

            // Prevent deleting the last administrator — that would lock everyone out.
            if ( User_Access::is_last_admin( $target ) ) {
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
