<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Custom_Code;

defined( 'ABSPATH' ) || exit;

class Inject_Css_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'inject_css';
        $this->label        = __( 'Inject CSS', 'mcp-abilities' );
        $this->description  = 'Write a CSS snippet to the site. Uses WordPress\'s built-in Additional CSS, so the result is also visible under Customizer → Additional CSS. Use get_custom_code first to see what is already stored.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'css'  => [
                    'type'        => 'string',
                    'description' => 'Valid CSS to inject. Do not include <style> tags — provide only the CSS rules.',
                ],
                'mode' => [
                    'type'        => 'string',
                    'enum'        => [ 'append', 'replace' ],
                    'default'     => 'append',
                    'description' => '"append" adds the new CSS after any existing CSS. "replace" overwrites everything. Use get_custom_code first to preview what is already stored.',
                ],
            ],
            'required' => [ 'css' ],
        ];
    }

    public function execute( array $params ): array {
        $css  = $params['css'] ?? '';
        $mode = in_array( $params['mode'] ?? 'append', [ 'append', 'replace' ], true )
            ? ( $params['mode'] ?? 'append' )
            : 'append';

        if ( '' === trim( $css ) ) {
            return $this->error( 'CSS cannot be empty.' );
        }

        // Strip any <style> tags the AI might have included by mistake.
        $css = preg_replace( '#</?style[^>]*>#i', '', $css );

        $result = Custom_Code::set_css( $css, $mode );

        if ( is_wp_error( $result ) ) {
            return $this->error( 'Failed to save CSS: ' . $result->get_error_message() );
        }

        $action = ( 'replace' === $mode ) ? 'replaced' : 'appended';
        return $this->success( "Custom CSS {$action} successfully. Changes are live immediately." );
    }
}
