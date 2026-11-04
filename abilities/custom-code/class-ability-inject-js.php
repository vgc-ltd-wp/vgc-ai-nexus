<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Custom_Code;

defined( 'ABSPATH' ) || exit;

class Inject_Js_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'inject_js';
        $this->label        = __( 'Inject JS', 'mcp-abilities' );
        $this->description  = 'Write a JavaScript snippet to the site header or footer. Header JS loads in <head> before the page renders; footer JS loads before </body> after all content. Prefer footer unless the script must run before DOM parsing. Use get_custom_code first to see what is already stored.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'js'        => [
                    'type'        => 'string',
                    'description' => 'JavaScript to inject. Do not include <script> tags — provide only the JS code.',
                ],
                'placement' => [
                    'type'        => 'string',
                    'enum'        => [ 'footer', 'header' ],
                    'default'     => 'footer',
                    'description' => '"footer" outputs just before </body> (recommended). "header" outputs inside <head>.',
                ],
                'mode' => [
                    'type'        => 'string',
                    'enum'        => [ 'append', 'replace' ],
                    'default'     => 'append',
                    'description' => '"append" adds the new JS after any existing JS in that placement. "replace" overwrites everything in that placement. Header and footer are stored independently.',
                ],
            ],
            'required' => [ 'js' ],
        ];
    }

    /**
     * Raw JavaScript for every visitor is what WordPress reserves for
     * `unfiltered_html`, whatever else the account can do: on multisite only
     * super admins have it, and DISALLOW_UNFILTERED_HTML takes it from everyone.
     * This gates writes only: code already stored keeps running.
     */
    public function current_user_can(): bool {
        return parent::current_user_can() && \current_user_can( 'unfiltered_html' );
    }

    public function refusal_message(): ?string {
        return \current_user_can( 'unfiltered_html' )
            ? null
            : 'inject_js is refused: writing raw JavaScript to the site needs the "unfiltered_html" capability on top of "manage_options", and this WordPress account does not have it on this site (multisite gives it only to super admins; DISALLOW_UNFILTERED_HTML takes it from everyone). JavaScript already stored keeps running, and get_custom_code can still read it.';
    }

    public function execute( array $params ): array {
        // Here as well as in the gate: the group tool checks only the required capability.
        $refused = $this->refusal_message();
        if ( null !== $refused ) {
            return $this->error( $refused );
        }

        $js        = $params['js'] ?? '';
        $placement = in_array( $params['placement'] ?? 'footer', [ 'header', 'footer' ], true )
            ? ( $params['placement'] ?? 'footer' )
            : 'footer';
        $mode      = in_array( $params['mode'] ?? 'append', [ 'append', 'replace' ], true )
            ? ( $params['mode'] ?? 'append' )
            : 'append';

        if ( '' === trim( $js ) ) {
            return $this->error( 'JS cannot be empty.' );
        }

        // Strip any <script> tags the AI might have included by mistake.
        $js = preg_replace( '#</?script[^>]*>#i', '', $js );

        Custom_Code::set_js( $js, $placement, $mode );

        $action = ( 'replace' === $mode ) ? 'replaced' : 'appended';
        return $this->success( "Custom JS {$action} to {$placement} successfully. Changes are live immediately." );
    }
}
