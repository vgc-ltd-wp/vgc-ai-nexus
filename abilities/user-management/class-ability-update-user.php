<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

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
                'role'         => [ 'type' => 'string', 'enum' => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ], 'description' => 'Requires promote_users capability. Assigning administrator requires manage_options.' ],
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
        if ( $this->target_outranks_current_user( $target ) ) {
            return $this->error( 'You cannot modify a user with a higher privilege level than your own.' );
        }

        // Role changes require promote_users and the new role must not
        // exceed the current user's own privilege level.
        if ( isset( $params['role'] ) ) {
            $new_role = sanitize_key( $params['role'] );
            if ( ! current_user_can( 'promote_users' ) ) {
                return $this->error( 'You do not have permission to change user roles.' );
            }
            if ( $new_role === 'administrator' && ! current_user_can( 'manage_options' ) ) {
                return $this->error( "You cannot promote users to the 'administrator' role." );
            }
        }

        // Password changes for other users require edit_users; additionally
        // you cannot change the password of a higher-ranked account (already blocked above).
        if ( isset( $params['password'] ) && $user_id !== get_current_user_id() ) {
            if ( ! current_user_can( 'edit_users' ) ) {
                return $this->error( 'You do not have permission to change another user\'s password.' );
            }
        }

        $data = [ 'ID' => $user_id ];
        if ( isset( $params['email'] ) )        $data['user_email']   = sanitize_email( $params['email'] );
        if ( isset( $params['role'] ) )          $data['role']          = sanitize_key( $params['role'] );
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

    /**
     * Returns true if the target user has the administrator role and the
     * current user does not — i.e. the target outranks the caller.
     */
    private function target_outranks_current_user( \WP_User $target ): bool {
        return in_array( 'administrator', (array) $target->roles, true )
            && ! current_user_can( 'manage_options' );
    }
}
