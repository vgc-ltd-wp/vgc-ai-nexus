<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Get_User_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_user';
        $this->label        = __( 'Get User', 'mcp-abilities' );
        $this->description  = 'Retrieve details of a single user by ID or email.';
        $this->required_cap = 'list_users';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'    => [ 'type' => 'integer', 'description' => 'User ID.' ],
                'email' => [ 'type' => 'string',  'description' => 'User email (used if id omitted).' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! empty( $params['id'] ) ) {
            $user = get_user_by( 'id', absint( $params['id'] ) );
        } elseif ( ! empty( $params['email'] ) ) {
            $user = get_user_by( 'email', sanitize_email( $params['email'] ) );
        } else {
            return $this->error( 'Provide id or email.' );
        }

        if ( ! $user ) {
            return $this->error( 'User not found.' );
        }

        return $this->json_result( [
            'id'           => $user->ID,
            'login'        => $user->user_login,
            'display_name' => $user->display_name,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'email'        => $user->user_email,
            'url'          => $user->user_url,
            'bio'          => $user->description,
            'roles'        => $user->roles,
            'registered'   => $user->user_registered,
        ] );
    }
}
