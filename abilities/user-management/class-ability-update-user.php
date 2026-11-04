<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\User_Access;

defined( 'ABSPATH' ) || exit;

class Update_User_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_user';
        $this->label        = __( 'Update User', 'mcp-abilities' );
        $this->description  = 'Update a WordPress user\'s profile fields.';
        $this->required_cap = 'edit_users';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'           => [ 'type' => 'integer' ],
                'email'        => [ 'type' => 'string' ],
                'role'         => [ 'type' => 'string', 'description' => 'Role slug, e.g. subscriber, contributor, author, editor, administrator, or a site role such as customer or shop_manager. Requires promote_users; administrator needs manage_options; without it no role with permissions your account lacks.' ],
                'first_name'   => [ 'type' => 'string' ],
                'last_name'    => [ 'type' => 'string' ],
                'display_name' => [ 'type' => 'string' ],
                'bio'          => [ 'type' => 'string' ],
                'password'     => [ 'type' => 'string' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $user_id = absint( $params['id'] );
        $target  = get_user_by( 'id', $user_id );
        if ( ! $target ) {
            return $this->error( "User {$user_id} not found." );
        }

        // Prevent modifying accounts that outrank the current user.
        if ( User_Access::outranks( $target ) ) {
            return $this->error( 'You cannot modify a user with a higher privilege level than your own.' );
        }

        // edit_users above says the tool may be used at all; this asks about this
        // account, so WooCommerce's and multisite's rules apply.
        if ( ! User_Access::can_on( 'edit_user', $target ) ) {
            return $this->error( 'You do not have permission to edit this user.' );
        }

        // Role changes require promote_user on this account, and the new role
        // must not exceed the current user's own privilege level.
        if ( isset( $params['role'] ) ) {
            $new_role = sanitize_key( $params['role'] );
            if ( ! User_Access::can_on( 'promote_user', $target ) ) {
                return $this->error( 'You do not have permission to change user roles.' );
            }
            // A role WordPress lets this user assign, never one with more power
            // than the caller; an unknown role would leave the user with none.
            $role_error = User_Access::role_error( $new_role );
            if ( null !== $role_error ) {
                return $this->error( $role_error );
            }
        }

        $data = [ 'ID' => $user_id ];
        if ( isset( $params['email'] ) )        $data['user_email']   = sanitize_email( $params['email'] );
        if ( isset( $params['role'] ) )          $data['role']          = $new_role;
        if ( isset( $params['first_name'] ) )    $data['first_name']    = sanitize_text_field( $params['first_name'] );
        if ( isset( $params['last_name'] ) )     $data['last_name']     = sanitize_text_field( $params['last_name'] );
        if ( isset( $params['display_name'] ) )  $data['display_name']  = sanitize_text_field( $params['display_name'] );
        if ( isset( $params['bio'] ) )           $data['description']   = sanitize_textarea_field( $params['bio'] );
        if ( isset( $params['password'] ) )      $data['user_pass']     = $params['password'];

        $result = wp_update_user( $data );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "User {$user_id} updated." );
    }
}
