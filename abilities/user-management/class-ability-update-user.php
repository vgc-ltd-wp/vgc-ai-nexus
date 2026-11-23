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
                'bio'          => [ 'type' => 'string', 'description' => 'Biographical info (the profile\'s description). ' . self::TEXT_RULE_DESCRIPTIONS['user'] ],
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
            // Nobody locks the site or themselves out: the last administrator keeps
            // the role, and your own new role must keep the user-management
            // permissions (WordPress's profile screen and REST API ask the same).
            if ( 'administrator' !== $new_role && User_Access::is_last_admin( $target ) ) {
                return $this->error( 'This is the last administrator account: its role cannot be changed, or nobody could manage the site. Make another account an administrator first.' );
            }
            $self_error = User_Access::own_role_error( $target, $new_role );
            if ( null !== $self_error ) {
                return $this->error( $self_error );
            }
        }

        // An address WordPress would not accept is refused (2.52.0): sanitize_email()
        // gives "" for it and wp_update_user() saved that, blanking the user's e-mail.
        if ( isset( $params['email'] ) ) {
            $email_error = User_Access::email_error( $params['email'] );
            if ( null !== $email_error ) {
                return $this->error( $email_error . ' The user was not changed.' );
            }
        }

        // Names, bio and password slashed (2.51.0), as in create_user: wp_update_user()
        // unslashes them, and the login form checks the password slashed.
        $data = [ 'ID' => $user_id ];
        if ( isset( $params['email'] ) )        $data['user_email']   = trim( (string) $params['email'] );
        if ( isset( $params['role'] ) )          $data['role']          = $new_role;
        if ( isset( $params['first_name'] ) )    $data['first_name']    = wp_slash( sanitize_text_field( $params['first_name'] ) );
        if ( isset( $params['last_name'] ) )     $data['last_name']     = wp_slash( sanitize_text_field( $params['last_name'] ) );
        if ( isset( $params['display_name'] ) )  $data['display_name']  = wp_slash( sanitize_text_field( $params['display_name'] ) );
        $bio_sent = null;
        if ( isset( $params['bio'] ) ) {
            // WordPress's own rule for a bio: basic markup kept (2.53.0; it was plain
            // text). What it removed is said from what was stored.
            $bio_sent            = is_scalar( $params['bio'] ) ? (string) $params['bio'] : '';
            $data['description'] = wp_slash( self::rich_text( $bio_sent, self::TEXT_RULE_USER ) );
        }
        if ( isset( $params['password'] ) )      $data['user_pass']     = wp_slash( (string) $params['password'] );

        $result = wp_update_user( $data );
        if ( is_wp_error( $result ) ) {
            return $this->error( $result->get_error_message() );
        }

        return $this->success( "User {$user_id} updated.", self::with_text_note( [], $bio_sent, null !== $bio_sent ? User_Access::stored_bio( $user_id ) : null, self::TEXT_RULE_USER, 'bio', 'bio' ) );
    }
}
