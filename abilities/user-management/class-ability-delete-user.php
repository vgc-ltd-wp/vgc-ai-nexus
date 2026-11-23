<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\User_Access;

defined( 'ABSPATH' ) || exit;

class Delete_User_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_user';
        $this->label        = __( 'Delete User', 'mcp-abilities' );
        $this->description  = 'Delete a WordPress user. Send reassign_to to give their content to another user; without it WordPress removes their content with the account (posts and pages to the trash, other content types permanently), and the result lists what was removed.';
        $this->required_cap = 'delete_users';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'          => [ 'type' => 'integer' ],
                'reassign_to' => [ 'type' => 'integer', 'description' => 'User ID to reassign posts to. Without it the user\'s content is removed with the account.' ],
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

        // Content goes to an account that exists and stays, as WordPress's REST API
        // asks; otherwise it would be left with an author who is gone.
        if ( null !== $reassign && ( $reassign === $user_id || ! get_userdata( $reassign ) ) ) {
            return $this->error( sprintf( 'reassign_to must be another existing user: user %d %s.', $reassign, $reassign === $user_id ? 'is the account being deleted' : 'does not exist' ) );
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
        // Without reassign_to WordPress removes the user's content with the account:
        // posts and pages to the trash, other types for good. The answer says so,
        // item by item type (2.52.0); before, it said only "deleted".
        $owned = null === $reassign ? self::content_deleted_with( $user_id ) : [];
        if ( ! wp_delete_user( $user_id, $reassign ) ) {
            return $this->error( "Failed to delete user {$user_id}." );
        }

        if ( null !== $reassign ) {
            return $this->success( "User {$user_id} deleted. Their content now belongs to user {$reassign}.", [ 'reassigned_to' => $reassign ] );
        }
        if ( ! $owned ) {
            return $this->success( "User {$user_id} deleted. They had no content." );
        }
        $trashed = [];
        $deleted = [];
        foreach ( $owned as $post_id => $type ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                $deleted[ $type ] = ( $deleted[ $type ] ?? 0 ) + 1;
            } elseif ( 'trash' === $post->post_status ) {
                $trashed[ $type ] = ( $trashed[ $type ] ?? 0 ) + 1;
            }
        }
        $count = static function ( array $by_type ): string {
            $parts = [];
            foreach ( $by_type as $type => $n ) {
                $parts[] = "{$n} {$type}";
            }
            return implode( ', ', $parts );
        };
        $extra = [ 'content_trashed' => $trashed, 'content_deleted' => $deleted ];
        $extra['note'] = trim( sprintf(
            'No reassign_to was sent, so WordPress removed this user\'s content with the account. %s %s',
            $trashed ? 'Moved to the trash (can be restored): ' . $count( $trashed ) . '.' : '',
            $deleted ? 'Deleted permanently: ' . $count( $deleted ) . '.' : ''
        ) );
        return $this->success( "User {$user_id} deleted, with their content.", $extra );
    }

    /**
     * The items wp_delete_user() removes with an account when no reassign_to is
     * given (the same choice of types, filter included): post ID => post type.
     *
     * @return array<int,string>
     */
    private static function content_deleted_with( int $user_id ): array {
        global $wpdb;
        $types = [];
        foreach ( get_post_types( [], 'objects' ) as $pto ) {
            if ( $pto->delete_with_user || ( null === $pto->delete_with_user && post_type_supports( $pto->name, 'author' ) ) ) {
                $types[] = $pto->name;
            }
        }
        $types = (array) apply_filters( 'post_types_to_delete_with_user', $types, $user_id );
        if ( ! $types ) {
            return [];
        }
        $in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ({$in})", array_merge( [ $user_id ], array_values( $types ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out  = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row->ID ] = (string) $row->post_type;
        }
        return $out;
    }
}
