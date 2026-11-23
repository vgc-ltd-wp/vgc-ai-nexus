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

        // An address WordPress would not accept is refused (2.52.0): sanitize_email()
        // gives "" for it, and wp_insert_user() made the account with no e-mail.
        $email_error = User_Access::email_error( $params['email'] ?? '' );
        if ( null !== $email_error ) {
            return $this->error( $email_error . ' No user was created.' );
        }

        // Names and password slashed (2.51.0): wp_insert_user() unslashes the display
        // name and the names it saves as user meta, so every backslash was lost; and
        // WordPress's login form checks the password slashed, so a password with a
        // quote or backslash set here never let the person log in. Login and e-mail
        // stay as they were: WordPress checks those unslashed for duplicates.
        $user_id = wp_insert_user( [
            'user_login'   => sanitize_user( $params['username'] ),
            'user_email'   => trim( (string) $params['email'] ),
            'user_pass'    => wp_slash( ! empty( $params['password'] ) ? (string) $params['password'] : wp_generate_password( 16 ) ),
            'role'         => $role,
            'first_name'   => wp_slash( sanitize_text_field( $params['first_name'] ?? '' ) ),
            'last_name'    => wp_slash( sanitize_text_field( $params['last_name'] ?? '' ) ),
            'display_name' => wp_slash( sanitize_text_field( $params['display_name'] ?? '' ) ),
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
