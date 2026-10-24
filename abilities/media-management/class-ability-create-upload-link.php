<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Upload_Links;

defined( 'ABSPATH' ) || exit;

class Create_Upload_Link_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'create_upload_link';
        $this->label        = __( 'Create Upload Link', 'mcp-abilities' );
        $this->description  = 'Get a one-time link for uploading an image file into the Media Library WITHOUT passing it through the conversation — the right tool for a file you created or have in your workspace (Claude Code, scheduled routines). Then send the file to the returned "upload_url" with curl, e.g. curl -sS -F "file=@red-fox.png" "<upload_url>"; the response is the new attachment (id, url). The link works once and expires after 15 minutes. JPEG, PNG, GIF, WebP or AVIF, max 10 MB. Title, alt text, caption, post to attach to and featured image are set here, up front. For an image already on the web, use upload_image with its url instead.';
        $this->required_cap = 'upload_files';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'filename'     => [ 'type' => 'string', 'description' => 'File name to store, e.g. "red-fox-coloring-page.png" (extension corrected to the real type). Defaults to the uploaded file\'s own name.' ],
                'title'        => [ 'type' => 'string', 'description' => 'Attachment title. Defaults to the file name.' ],
                'alt_text'     => [ 'type' => 'string', 'description' => 'Alt text (important for accessibility and image SEO).' ],
                'caption'      => [ 'type' => 'string', 'description' => 'Caption for the image.' ],
                'description'  => [ 'type' => 'string', 'description' => 'Long description for the image attachment.' ],
                'post_id'      => [ 'type' => 'integer', 'description' => 'Attach the image to this post/page/product.' ],
                'set_featured' => [ 'type' => 'boolean', 'description' => 'With post_id: also make it the featured image. Default false.', 'default' => false ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $post_id = (int) ( $params['post_id'] ?? 0 );
        if ( $post_id > 0 ) {
            if ( ! get_post( $post_id ) ) {
                return $this->error( "Post {$post_id} does not exist." );
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return $this->error( "You are not allowed to edit post {$post_id}, so an image cannot be attached to it." );
            }
        }

        $meta = [];
        foreach ( [ 'filename', 'title', 'alt_text', 'caption', 'description' ] as $field ) {
            if ( isset( $params[ $field ] ) && '' !== trim( (string) $params[ $field ] ) ) {
                $meta[ $field ] = (string) $params[ $field ];
            }
        }
        if ( $post_id > 0 ) {
            $meta['post_id']      = $post_id;
            $meta['set_featured'] = ! empty( $params['set_featured'] );
        }

        $link = Upload_Links::create( get_current_user_id(), $meta );

        return $this->json_result( [
            'upload_url' => $link['url'],
            'expires_at' => $link['expires_at'],
            'single_use' => true,
            'max_bytes'  => \MCP_Abilities\Media_Import::MAX_BYTES,
            'how'        => [
                'multipart' => 'curl -sS -F "file=@/path/to/image.png" "' . $link['url'] . '"',
                'raw_body'  => 'curl -sS --data-binary "@/path/to/image.png" -H "Content-Type: image/png" "' . $link['url'] . '"',
            ],
            'note'       => 'The response to the upload is the new attachment: {"success":true,"id":…,"url":…}. If it fails, ask for a new link — each link works once.',
        ] );
    }
}
