<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Create_User_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_user';
        $this->label        = __( 'Create User', 'mcp-abilities' );
        $this->description  = 'Create a new WordPress user.';
        $this->required_cap = 'create_users';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'username'     => [ 'type' => 'string' ],
                'email'        => [ 'type' => 'string' ],
                'password'     => [ 'type' => 'string', 'description' => 'If omitted, a random password is generated.' ],
                'role'         => [ 'type' => 'string', 'enum' => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ], 'default' => 'subscriber', 'description' => 'Assigning administrator requires manage_options capability.' ],
                'first_name'   => [ 'type' => 'string' ],
                'last_name'    => [ 'type' => 'string' ],
                'display_name' => [ 'type' => 'string' ],
                'send_email'   => [ 'type' => 'boolean', 'default' => false, 'description' => 'Send new-user notification email.' ],
            ],
            'required' => [ 'username', 'email' ],
        ];
    }

    public function execute( array $params ): array {
        $role = sanitize_key( $params['role'] ?? 'subscriber' );

        // Prevent privilege escalation: assigning roles above the current user's
        // own highest role requires promote_users, just as the WP REST API enforces.
        if ( ! $this->current_user_can_assign_role( $role ) ) {
            return $this->error( "You do not have permission to create users with the '{$role}' role." );
        }

        $user_id = wp_insert_user( [
            'user_login'   => sanitize_user( $params['username'] ),
            'user_email'   => sanitize_email( $params['email'] ),
            'user_pass'    => ! empty( $params['password'] ) ? $params['password'] : wp_generate_password( 16 ),
            'role'         => $role,
            'first_name'   => sanitize_text_field( $params['first_name'] ?? '' ),
            'last_name'    => sanitize_text_field( $params['last_name'] ?? '' ),
            'display_name' => sanitize_text_field( $params['display_name'] ?? '' ),
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $this->error( $user_id->get_error_message() );
        }

        if ( ! empty( $params['send_email'] ) ) {
            wp_new_user_notification( $user_id, null, 'user' );
        }

        return $this->json_result( [
            'id'    => $user_id,
            'login' => get_user_by( 'id', $user_id )->user_login,
        ] );
    }

    /**
     * Mirrors the WP REST API role-assignment gate:
     * - administrator role requires promote_users
     * - any other editable role requires promote_users OR the role must not
     *   exceed the current user's highest role in the WP hierarchy.
     */
    private function current_user_can_assign_role( string $role ): bool {
        if ( ! current_user_can( 'promote_users' ) ) {
            // Without promote_users only subscriber/contributor-level roles are safe.
            $safe_roles = [ 'subscriber', 'customer' ]; // customer = WooCommerce subscriber equivalent
            return in_array( $role, $safe_roles, true );
        }
        // promote_users holders can assign any role except administrator
        // unless they are themselves an administrator.
        if ( $role === 'administrator' && ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        return true;
    }
}
