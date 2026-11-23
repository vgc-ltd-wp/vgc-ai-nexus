<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Post_Access;

defined( 'ABSPATH' ) || exit;

/**
 * list_revisions and get_revision, registered by the post-management group
 * (2.53.0; this file holds two tools, so discovery by file name never found
 * them).
 *
 * Who may read a post's revisions and autosaves is WordPress's own rule: its
 * REST API (revisions and autosaves controllers) asks edit_post on the post,
 * and the revisions screen read_post as well; both are asked here. So an
 * account reads the history of a post only when it may edit that post: not
 * another author's draft, pending, private or password-protected post unless
 * it may edit others' posts, not a contributor's own post once published.
 * Every autosave of such a post is listed, whoever made it, as WordPress's
 * REST API lists them; each item says whether it is one. A post type without
 * an admin screen (templates, global styles, navigation, plugins' internal
 * records) is refused, as the general post tools refuse it.
 */
final class Revision_Access {

    /**
     * The post whose history the caller may read, or why not.
     *
     * @return \WP_Post|string
     */
    public static function parent( int $post_id ) {
        $post = $post_id > 0 ? get_post( $post_id ) : null;
        if ( ! $post ) {
            return "Post {$post_id} not found.";
        }
        if ( 'revision' === $post->post_type ) {
            return sprintf( 'Item %d is itself a revision (of post %d): send the post\'s own ID to list its revisions, or read this one with get_revision.', $post_id, (int) $post->post_parent );
        }
        if ( Post_Access::is_internal_type( $post->post_type ) ) {
            return sprintf( 'Post %d is a "%s" item, a type without an admin screen that WordPress or a plugin manages itself, so its revisions are not read through these tools.', $post_id, $post->post_type );
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'read_post', $post->ID ) ) {
            return 'You do not have permission to view revisions of this post: WordPress shows a post\'s revisions and autosaves only to accounts that may edit the post.';
        }
        return $post;
    }

    /** Whether a revision is an autosave (WordPress names them "{parent}-autosave-v1"). */
    public static function is_autosave( \WP_Post $revision ): bool {
        return (bool) wp_is_post_autosave( $revision );
    }
}

// ── List Revisions ────────────────────────────────────────────────────────────

class List_Revisions_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_revisions';
        $this->label        = __( 'List Revisions', 'mcp-abilities' );
        $this->description  = 'List the saved revisions and autosaves of a post, page or custom post type item you may edit. Returns revision ID, author, date, title and whether it is an autosave for each, newest first. Read one with get_revision.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'description' => 'ID of the post whose revisions to list.' ],
            ],
            'required' => [ 'post_id' ],
        ];
    }

    public function execute( array $params ): array {
        // A negative ID was turned into another post's (absint).
        $post_id = (int) ( $params['post_id'] ?? 0 );
        $post    = Revision_Access::parent( $post_id );
        if ( is_string( $post ) ) {
            return $this->error( $post );
        }

        $revisions = wp_get_post_revisions( $post_id, [ 'order' => 'DESC' ] );

        if ( empty( $revisions ) ) {
            $out = [ 'post_id' => $post_id, 'total' => 0, 'revisions' => [] ];
            // Say why, when it is not "no edits yet" (2.52.0).
            if ( ! wp_revisions_enabled( $post ) ) {
                $out['note'] = sprintf( 'Revisions are switched off for "%s" items on this site (the post type does not support them, or WP_POST_REVISIONS is off), so none are kept.', $post->post_type );
            }
            return $this->json_result( $out );
        }

        $result = array_values( array_map( function ( $rev ) {
            $author = get_userdata( $rev->post_author );
            return [
                'id'          => $rev->ID,
                'date'        => $rev->post_modified,
                'title'       => $rev->post_title,
                'author_id'   => (int) $rev->post_author,
                'author_name' => $author ? $author->display_name : '',
                'autosave'    => Revision_Access::is_autosave( $rev ),
            ];
        }, $revisions ) );

        return $this->json_result( [
            'post_id'   => $post_id,
            'total'     => count( $result ),
            'revisions' => $result,
        ] );
    }
}

// ── Get Revision ──────────────────────────────────────────────────────────────

class Get_Revision_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_revision';
        $this->label        = __( 'Get Revision', 'mcp-abilities' );
        $this->description  = 'Retrieve the full content of a specific post revision or autosave by its ID (from list_revisions), for a post you may edit.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id' => [ 'type' => 'integer', 'description' => 'The revision ID (from list_revisions).' ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        // A negative ID was turned into another revision's (absint).
        $id       = (int) ( $params['id'] ?? 0 );
        $revision = $id > 0 ? wp_get_post_revision( $id ) : null;

        if ( ! $revision ) {
            return $this->error( sprintf( 'Revision %d not found: no revision has that ID. list_revisions with the post\'s ID lists its revisions.', $id ) );
        }
        // The post the revision belongs to decides, as in WordPress. A revision
        // whose post is gone is refused: current_user_can( 'edit_post', 0 ) would
        // ask about whatever post is global at the time.
        $parent_id = (int) $revision->post_parent;
        $parent    = Revision_Access::parent( $parent_id );
        if ( is_string( $parent ) ) {
            return $this->error( $parent_id > 0 && get_post( $parent_id ) ? $parent : sprintf( 'Revision %d belongs to no post that exists, so it is not read.', $id ) );
        }

        $author = get_userdata( $revision->post_author );

        return $this->json_result( [
            'id'          => $revision->ID,
            'post_id'     => $parent_id,
            'date'        => $revision->post_modified,
            'title'       => $revision->post_title,
            'content'     => $revision->post_content,
            'excerpt'     => $revision->post_excerpt,
            'author_id'   => (int) $revision->post_author,
            'author_name' => $author ? $author->display_name : '',
            'autosave'    => Revision_Access::is_autosave( $revision ),
        ] );
    }
}
