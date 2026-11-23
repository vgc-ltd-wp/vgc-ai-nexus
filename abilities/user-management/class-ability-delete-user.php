<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\User_Access;

defined( 'ABSPATH' ) || exit;

class Delete_User_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'delete_user';
        $this->label        = __( 'Delete User', 'mcp-abilities' );
        $this->description  = 'Delete a WordPress user. When the user owns any content (posts of any type and status, media, revisions, links), say what happens to it: reassign_to gives it to another user, delete_content: true removes it with the account (posts and pages to the trash, other content types permanently; all permanently on a site whose trash is off). Without either such a call is refused and the error says how many items the user owns. A user with no content is deleted without either. The result lists what was reassigned or removed.';
        $this->required_cap = 'delete_users';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'             => [ 'type' => 'integer' ],
                'reassign_to'    => [ 'type' => 'integer', 'description' => 'User ID to give the user\'s content to (posts of every type, media, links). Another existing user.' ],
                'delete_content' => [ 'type' => 'boolean', 'description' => 'true: remove the user\'s content with the account (posts and pages to the trash, other content types permanently). Needed, or reassign_to, when the user owns content; not with reassign_to.' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $user_id        = absint( $params['id'] );
        $reassign       = ! empty( $params['reassign_to'] ) ? absint( $params['reassign_to'] ) : null;
        $delete_content = (bool) filter_var( $params['delete_content'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( $user_id === get_current_user_id() ) {
            return $this->error( 'You cannot delete yourself.' );
        }

        // One choice for the content (2.53.0): both at once cannot be done.
        if ( null !== $reassign && $delete_content ) {
            return $this->error( 'Send either reassign_to (give the user\'s content to another user) or delete_content: true (remove it with the account), not both. Nothing was deleted.' );
        }

        // Content goes to an account that exists and stays, as WordPress's REST API
        // asks; otherwise it would be left with an author who is gone.
        if ( null !== $reassign && ( $reassign === $user_id || ! get_userdata( $reassign ) ) ) {
            return $this->error( sprintf( 'reassign_to must be another existing user: user %d %s.', $reassign, $reassign === $user_id ? 'is the account being deleted' : 'does not exist' ) );
        }

        $target = get_user_by( 'id', $user_id );
        $owned  = null;
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

            // A user who owns content is deleted only with an explicit choice for it
            // (2.53.0): without reassign_to, WordPress removed it with the account,
            // other types than posts and pages for good. WordPress's own Users screen
            // asks the same question, counted the same way.
            $owned = User_Access::owned_content( $user_id );
            if ( null === $reassign && ! $delete_content && ( $owned['total'] > 0 || $owned['other'] ) ) {
                return $this->error( self::choice_needed( $user_id, $owned ) );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        // With delete_content WordPress removes the user's content with the account:
        // posts and pages to the trash, other types for good. The answer says so,
        // item by item type (2.52.0).
        $removed = null === $reassign ? self::owned_items( $user_id, self::types_deleted_with( $user_id ), true ) : [];
        $kept    = null === $reassign ? self::owned_items( $user_id, self::types_deleted_with( $user_id ), false ) : [];
        $links   = null === $reassign && $owned ? $owned['links'] : 0;
        if ( ! wp_delete_user( $user_id, $reassign ) ) {
            return $this->error( "Failed to delete user {$user_id}." );
        }

        if ( null !== $reassign ) {
            $extra = [ 'reassigned_to' => $reassign ];
            if ( $owned && $owned['total'] > 0 ) {
                // What moved, by type (2.53.0); links under "link".
                $extra['content_reassigned'] = $owned['by_type'] + ( $owned['links'] ? [ 'link' => $owned['links'] ] : [] );
            }
            return $this->success( "User {$user_id} deleted. Their content now belongs to user {$reassign}.", $extra );
        }
        if ( ! $removed && ! $kept && ! $links ) {
            return $this->success( "User {$user_id} deleted. They had no content." );
        }
        $trashed = [];
        $deleted = [];
        foreach ( $removed as $post_id => $type ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                $deleted[ $type ] = ( $deleted[ $type ] ?? 0 ) + 1;
            } elseif ( 'trash' === $post->post_status ) {
                $trashed[ $type ] = ( $trashed[ $type ] ?? 0 ) + 1;
            }
        }
        if ( $links ) {
            // Links Manager links: WordPress deletes them with the account.
            global $wpdb;
            $gone = $links - (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->links} WHERE link_owner = %d", $user_id ) );
            if ( $gone > 0 ) {
                $deleted['link'] = $gone;
            }
        }
        $still = [];
        foreach ( $kept as $post_id => $type ) {
            if ( get_post( $post_id ) ) {
                $still[ $type ] = ( $still[ $type ] ?? 0 ) + 1;
            }
        }
        $extra = [ 'content_trashed' => $trashed, 'content_deleted' => $deleted ];
        if ( $still ) {
            $extra['content_kept'] = $still;
        }
        $extra['note'] = trim( implode( ' ', array_filter( [
            'delete_content: true was sent, so WordPress removed this user\'s content with the account (reassign_to would have kept it).',
            $trashed ? 'Moved to the trash (can be restored): ' . self::count_text( $trashed ) . '.' : '',
            $deleted ? 'Deleted permanently: ' . self::count_text( $deleted ) . '.' : '',
            $still ? 'WordPress does not remove these types with a user, so they are still there, with no author account: ' . self::count_text( $still ) . '.' : '',
        ] ) ) );
        return $this->success( "User {$user_id} deleted, with their content.", $extra );
    }

    /**
     * The refusal for a user who owns content and no choice was sent (2.53.0).
     *
     * @param array{by_type:array<string,int>,links:int,total:int,other:bool} $owned
     */
    private static function choice_needed( int $user_id, array $owned ): string {
        $parts = $owned['by_type'] + ( $owned['links'] ? [ 'link' => $owned['links'] ] : [] );
        if ( $owned['total'] > 0 ) {
            $what = sprintf( 'User %d owns %d %s (%s)%s.', $user_id, $owned['total'], 1 === $owned['total'] ? 'item' : 'items', self::count_text( $parts ), $owned['other'] ? ', and a plugin on this site reports more content of theirs' : '' );
        } else {
            $what = sprintf( 'A plugin on this site reports that user %d owns content.', $user_id );
        }
        $how = EMPTY_TRASH_DAYS
            ? 'posts and pages go to the trash, other types such as media files are deleted permanently'
            : 'this site\'s trash is switched off, so all of it is deleted permanently';
        return $what . " Say what happens to it and send the call again: reassign_to: <user ID> gives it to another user (nothing is lost), or delete_content: true removes it with the account ({$how}). Nothing was deleted.";
    }

    /** "3 post, 1 page" from [ type => count ]. */
    private static function count_text( array $by_type ): string {
        $parts = [];
        foreach ( $by_type as $type => $n ) {
            $parts[] = "{$n} {$type}";
        }
        return implode( ', ', $parts );
    }

    /**
     * The types wp_delete_user() removes with an account when no reassign_to is
     * given (the same choice of types, filter included).
     *
     * @return string[]
     */
    private static function types_deleted_with( int $user_id ): array {
        $types = [];
        foreach ( get_post_types( [], 'objects' ) as $pto ) {
            if ( $pto->delete_with_user || ( null === $pto->delete_with_user && post_type_supports( $pto->name, 'author' ) ) ) {
                $types[] = $pto->name;
            }
        }
        return array_values( (array) apply_filters( 'post_types_to_delete_with_user', $types, $user_id ) );
    }

    /**
     * The user's items whose type is one of $types (what wp_delete_user() removes
     * with the account when no reassign_to is given), or with $in false is not
     * (what it keeps: menu items, reusable blocks and the like; 2.53.0):
     * post ID => post type.
     *
     * @param string[] $types
     * @return array<int,string>
     */
    private static function owned_items( int $user_id, array $types, bool $in ): array {
        global $wpdb;
        if ( ! $types ) {
            $rows = $in ? [] : $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_author = %d", $user_id ) );
        } else {
            $list = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
            $op   = $in ? 'IN' : 'NOT IN';
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_author = %d AND post_type {$op} ({$list})", array_merge( [ $user_id ], array_values( $types ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row->ID ] = (string) $row->post_type;
        }
        return $out;
    }
}
