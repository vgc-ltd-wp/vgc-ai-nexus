<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Comment_Management_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'comment-management';
        $this->label       = __( 'Comment Management', 'mcp-abilities' );
        $this->description = __( 'Moderate, approve, spam-mark and delete comments.', 'mcp-abilities' );
        $this->icon        = 'dashicons-admin-comments';
        $this->guide       = __( "Triage your comment queue end to end - approve genuine feedback, mark spam, reply on behalf of an author, and clear out the trash, all from a conversation.", 'mcp-abilities' );
        $this->examples    = [
            __( "Approve all pending comments that aren't spam", 'mcp-abilities' ),
            __( "Mark the comment from 'buy-now.ru' as spam and delete it", 'mcp-abilities' ),
            __( "Reply to the latest comment on my Pricing post thanking them", 'mcp-abilities' ),
        ];
    }
}
