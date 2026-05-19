<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Users_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_users';
        $this->label        = __( 'List Users', 'mcp-abilities' );
        $this->description  = 'List WordPress users with optional role filter.';
        $this->required_cap = 'list_users';
        $this->input_schema = [
            'type'       => [ 'object', 'array' ],
            'properties' => [
                'role'     => [ 'type' => 'string',  'description' => 'Filter by role slug. Leave empty for all roles.' ],
                'search'   => [ 'type' => 'string',  'description' => 'Search by name, email or username.' ],
                'per_page' => [ 'type' => 'integer', 'default' => 20 ],
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
                'orderby'  => [ 'type' => 'string',  'enum' => [ 'login', 'nicename', 'email', 'registered', 'display_name' ], 'default' => 'login' ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $number = min( absint( $params['per_page'] ?? 20 ), 100 );
        $offset = ( max( 1, absint( $params['page'] ?? 1 ) ) - 1 ) * $number;

        $args = [
            'number'  => $number,
            'offset'  => $offset,
            'orderby' => sanitize_key( $params['orderby'] ?? 'login' ),
            'order'   => 'ASC',
            'fields'  => 'all',
        ];

        if ( ! empty( $params['role'] ) )   $args['role']   = sanitize_text_field( $params['role'] );
        if ( ! empty( $params['search'] ) ) $args['search'] = '*' . sanitize_text_field( $params['search'] ) . '*';

        $users  = get_users( $args );
        $result = [];
        foreach ( $users as $user ) {
            $result[] = [
                'id'           => $user->ID,
                'login'        => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'roles'        => $user->roles,
                'registered'   => $user->user_registered,
                'url'          => $user->user_url,
            ];
        }

        $total = count( get_users( array_merge( $args, [ 'number' => -1, 'offset' => 0, 'fields' => 'ID' ] ) ) );

        return $this->json_result( [ 'users' => $result, 'total' => $total ] );
    }
}
