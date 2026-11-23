<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Comment_Access;

defined( 'ABSPATH' ) || exit;

class Moderate_Comment_Ability extends Ability {

    private const MODERATIONS = [ 'approve', 'hold', 'spam', 'trash', 'delete' ];

    protected function define_meta(): void {
        $this->key          = 'moderate_comment';
        $this->label        = __( 'Moderate Comment', 'mcp-abilities' );
        $this->description  = 'Approve, hold, spam, trash or delete a comment. Send what to do as "moderation".';
        $this->required_cap = 'moderate_comments';
        // "moderation", not "action": through the group tool, "action" selects
        // the operation ("moderate_comment"), so a parameter of the same name
        // could never reach this ability. "action" still works when the
        // ability is called on its own (consolidation off).
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'         => [ 'type' => 'integer', 'description' => 'Comment ID.' ],
                'moderation' => [ 'type' => 'string', 'enum' => self::MODERATIONS, 'description' => 'What to do with the comment: approve, hold, spam, trash or delete (permanently).' ],
                'action'     => [ 'type' => 'string', 'enum' => self::MODERATIONS, 'description' => 'Same as "moderation", for a direct call of this ability.' ],
            ],
            'required'   => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $id     = absint( $params['id'] ?? 0 );
        $action = sanitize_key( (string) ( $params['moderation'] ?? '' ) );
        if ( '' === $action && isset( $params['action'] ) && in_array( $params['action'], self::MODERATIONS, true ) ) {
            $action = (string) $params['action'];
        }

        $comment = get_comment( $id );
        if ( ! $comment ) {
            return $this->error( "Comment {$id} not found." );
        }

        // Every moderation needs the right to edit this comment (edit_comment, the
        // item it belongs to), as WordPress's comment screens and REST API ask;
        // WooCommerce order notes need the permission to edit orders.
        $refused = Comment_Access::edit_error( $comment );
        if ( null !== $refused ) {
            return $this->error( $refused );
        }

        if ( ! in_array( $action, self::MODERATIONS, true ) ) {
            return $this->error( 'Send "moderation": one of ' . implode( ', ', self::MODERATIONS ) . '.' );
        }
        // Where WordPress has no trash for it, a trash would be a permanent delete
        // (wp_trash_comment() deletes when EMPTY_TRASH_DAYS is 0), and the answer
        // said "trash applied" (2.52.0).
        if ( 'trash' === $action ) {
            if ( ! EMPTY_TRASH_DAYS ) {
                return $this->error( "This site has the trash switched off (EMPTY_TRASH_DAYS is 0), so comment {$id} cannot be moved to the trash and was not changed. To delete it permanently, send \"delete\"." );
            }
            if ( 'trash' === $comment->comment_approved ) {
                return $this->error( "Comment {$id} is already in the trash, so it was not changed. To delete it permanently, send \"delete\"." );
            }
        }

        $before = wp_get_comment_status( $comment );
        switch ( $action ) {
            case 'approve': wp_set_comment_status( $id, 'approve' ); break;
            case 'hold':    wp_set_comment_status( $id, 'hold' );    break;
            case 'spam':    wp_spam_comment( $id );                   break;
            case 'trash':   wp_trash_comment( $id );                  break;
            case 'delete':  wp_delete_comment( $id, true );           break;
        }

        // The outcome is read back (2.52.0): the WordPress functions' answers were
        // ignored, so a moderation that did not happen was reported as applied.
        clean_comment_cache( $id );
        $after = get_comment( $id ) ? wp_get_comment_status( $id ) : 'deleted';
        $want  = [ 'approve' => 'approved', 'hold' => 'unapproved', 'spam' => 'spam', 'trash' => 'trash', 'delete' => 'deleted' ][ $action ];
        if ( $after !== $want ) {
            return $this->error( sprintf(
                'WordPress or a plugin did not %s comment %d: it is %s.',
                [ 'approve' => 'approve', 'hold' => 'hold', 'spam' => 'mark as spam', 'trash' => 'move to the trash', 'delete' => 'delete' ][ $action ],
                $id,
                'deleted' === $after ? 'deleted' : '"' . $after . '"' . ( $after === $before ? ' as before' : '' )
            ) );
        }

        return $this->success( "Comment {$id}: {$action} applied.", [ 'id' => $id, 'status' => $after ] );
    }
}
