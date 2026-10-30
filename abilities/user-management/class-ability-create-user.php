<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\User_Access;

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
                'role'         => [ 'type' => 'string', 'default' => 'subscriber', 'description' => 'Role slug, e.g. subscriber, contributor, author, editor, administrator, or a site role such as customer or shop_manager. Only roles you may give: administrator needs manage_options; without it no role with permissions your account lacks; without promote_users only subscriber-level roles.' ],
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

        // Prevent privilege escalation: only a role WordPress lets this user
        // assign, and never one with more power than the caller (User_Access).
        $role_error = User_Access::role_error( $role );
        if ( null !== $role_error ) {
            return $this->error( $role_error );
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
}
