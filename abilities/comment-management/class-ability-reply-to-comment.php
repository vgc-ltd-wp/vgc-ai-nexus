<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Comment_Access;

defined( 'ABSPATH' ) || exit;

class Reply_To_Comment_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'reply_to_comment';
        $this->label        = __( 'Reply to Comment', 'mcp-abilities' );
        $this->description  = 'Post an admin reply to a comment.';
        $this->required_cap = 'moderate_comments';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'integer', 'description' => 'ID of the comment to reply to.' ],
                'content' => [ 'type' => 'string',  'description' => 'Reply text.' ],
            ],
            'required' => [ 'id', 'content' ],
        ];
    }

    public function execute( array $params ): array {
        $parent = get_comment( absint( $params['id'] ) );
        if ( ! $parent ) {
            return $this->error( 'Comment not found.' );
        }
        // A reply goes on the same item: WordPress asks edit_post on it.
        $refused = Comment_Access::edit_error( $parent );
        if ( null !== $refused ) {
            return $this->error( $refused );
        }

        // Plain text as before; a note when markup was taken out (2.52.0).
        [ $content, $content_note ] = self::plain_text_with_note( $params['content'] ?? '', 'reply' );
        if ( '' === trim( $content ) ) {
            return $this->error( 'Reply content cannot be empty.' );
        }

        $current_user = wp_get_current_user();
        $data = [
            'comment_post_ID'      => $parent->comment_post_ID,
            'comment_parent'       => $parent->comment_ID,
            'comment_content'      => $content,
            'comment_author'       => $current_user->display_name,
            'comment_author_email' => $current_user->user_email,
            'user_id'              => $current_user->ID,
            'comment_approved'     => 1,
        ];

        // Slashed: wp_insert_comment() unslashes all of it, so every backslash in the
        // reply (and in the author's name) was lost (2.51.0).
        $id = wp_insert_comment( wp_slash( $data ) );
        if ( ! $id ) {
            return $this->error( 'Failed to post reply.' );
        }

        return $this->json_result( self::with_note( [ 'id' => (int) $id, 'reply_id' => $id ], $content_note ) );
    }
}
