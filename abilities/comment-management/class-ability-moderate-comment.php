<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

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

        if ( ! get_comment( $id ) ) {
            return $this->error( "Comment {$id} not found." );
        }

        switch ( $action ) {
            case 'approve': wp_set_comment_status( $id, 'approve' ); break;
            case 'hold':    wp_set_comment_status( $id, 'hold' );    break;
            case 'spam':    wp_spam_comment( $id );                   break;
            case 'trash':   wp_trash_comment( $id );                  break;
            case 'delete':  wp_delete_comment( $id, true );           break;
            default:        return $this->error( 'Send "moderation": one of ' . implode( ', ', self::MODERATIONS ) . '.' );
        }

        return $this->success( "Comment {$id}: {$action} applied." );
    }
}
