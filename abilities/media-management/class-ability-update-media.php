<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Update_Media_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'update_media';
        $this->label        = __( 'Update Media', 'mcp-abilities' );
        $this->description  = 'Update title, alt text, caption, or description of a media item.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'id'            => [ 'type' => 'integer' ],
                'title'         => [ 'type' => 'string' ],
                'alt'           => [ 'type' => 'string' ],
                'caption'       => [ 'type' => 'string', 'description' => self::TEXT_RULE_DESCRIPTIONS['post'] ],
                'description'   => [ 'type' => 'string', 'description' => self::TEXT_RULE_DESCRIPTIONS['post'] ],
            ],
            'required' => [ 'id' ],
        ];
    }

    public function execute( array $params ): array {
        $id = absint( $params['id'] );
        if ( ! get_post( $id ) ) {
            return $this->error( "Attachment {$id} not found." );
        }
        if ( 'attachment' !== get_post_type( $id ) ) {
            return $this->error( "Item {$id} is not a media attachment. Use update_post or update_page for other content." );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return $this->error( 'You do not have permission to edit this media item.' );
        }

        $update = [ 'ID' => $id ];
        if ( isset( $params['title'] ) )       $update['post_title']   = sanitize_text_field( $params['title'] );
        // Caption and description follow WordPress's rule for posts (2.53.0; they
        // were plain text): they are the attachment's excerpt and content. What was
        // removed is said from what was stored.
        $sent = [];
        foreach ( [ 'caption' => 'post_excerpt', 'description' => 'post_content' ] as $param => $column ) {
            if ( isset( $params[ $param ] ) ) {
                $sent[ $param ]    = is_scalar( $params[ $param ] ) ? (string) $params[ $param ] : '';
                $update[ $column ] = self::rich_text( $sent[ $param ], self::TEXT_RULE_POST );
            }
        }

        // wp_update_post() and update_post_meta() take slashed data: unslashed, every
        // backslash in the title, caption, description and alt text was lost (2.50.0).
        // Its answer is checked (2.52.0): a failed save was reported as "updated".
        if ( count( $update ) > 1 ) {
            $saved = wp_update_post( wp_slash( $update ), true );
            if ( is_wp_error( $saved ) ) {
                return $this->error( "Attachment {$id} was not updated: " . $saved->get_error_message() );
            }
        }

        if ( isset( $params['alt'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $params['alt'] ) ) );
        }

        $extra = [];
        if ( $sent ) {
            clean_post_cache( $id );
            $stored = get_post( $id );
            foreach ( [ 'caption' => 'post_excerpt', 'description' => 'post_content' ] as $param => $column ) {
                if ( isset( $sent[ $param ] ) && $stored ) {
                    $extra = self::with_text_note( $extra, $sent[ $param ], (string) $stored->{$column}, self::TEXT_RULE_POST, $param, $param );
                }
            }
        }
        return $this->success( "Attachment {$id} updated.", $extra );
    }
}
