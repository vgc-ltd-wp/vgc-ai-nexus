<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Moderate_Comment_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'moderate_comment';
        $this->label        = __( 'Moderate Comment', 'mcp-abilities' );
        $this->description  = 'Approve, hold, spam, or trash a comment.';
        $this->required_cap = 'moderate_comments';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'     => [ 'type' => 'integer' ],
                'action' => [ 'type' => 'string', 'enum' => [ 'approve', 'hold', 'spam', 'trash', 'delete' ] ],
            ],
            'required' => [ 'id', 'action' ],
        ];
    }

    public function execute( array $params ): array {
        $id     = absint( $params['id'] );
        $action = sanitize_key( $params['action'] ?? '' );

        if ( ! get_comment( $id ) ) {
            return $this->error( "Comment {$id} not found." );
        }

        switch ( $action ) {
            case 'approve': wp_set_comment_status( $id, 'approve' ); break;
            case 'hold':    wp_set_comment_status( $id, 'hold' );    break;
            case 'spam':    wp_spam_comment( $id );                   break;
            case 'trash':   wp_trash_comment( $id );                  break;
            case 'delete':  wp_delete_comment( $id, true );           break;
            default:        return $this->error( "Unknown action '{$action}'." );
        }

        return $this->success( "Comment {$id}: action '{$action}' applied." );
    }
}
