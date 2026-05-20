<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Custom_Code;

defined( 'ABSPATH' ) || exit;

class Inject_Css_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'inject_css';
        $this->label        = __( 'Inject CSS', 'mcp-abilities' );
        $this->description  = 'Write or edit the site\'s custom CSS. Three modes: '
            . '"append" adds CSS after existing rules (default); '
            . '"replace" overwrites everything with the new CSS — use this to fully edit what\'s already there; '
            . '"find_replace" makes a surgical edit by finding an exact string and replacing it. '
            . 'Always call get_custom_code first so you know what is already stored.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'css'          => [
                    'type'        => 'string',
                    'description' => 'CSS rules to write. Required for "append" and "replace" modes. Do not include <style> tags.',
                ],
                'mode'         => [
                    'type'        => 'string',
                    'enum'        => [ 'append', 'replace', 'find_replace' ],
                    'default'     => 'append',
                    'description' => '"append" — add after existing CSS. "replace" — overwrite all existing CSS with the new value. "find_replace" — find an exact string and replace it (requires find + replace_with).',
                ],
                'find'         => [
                    'type'        => 'string',
                    'description' => 'The exact CSS string to search for. Required when mode is "find_replace". Must match character-for-character.',
                ],
                'replace_with' => [
                    'type'        => 'string',
                    'description' => 'The string to substitute in place of "find". Required when mode is "find_replace". Pass an empty string to delete the matched text.',
                ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $mode = $params['mode'] ?? 'append';

        if ( ! in_array( $mode, [ 'append', 'replace', 'find_replace' ], true ) ) {
            return $this->error( 'Invalid mode. Use "append", "replace", or "find_replace".' );
        }

        // ── find_replace ───────────────────────────────────────────────────────
        if ( 'find_replace' === $mode ) {
            if ( ! isset( $params['find'] ) ) {
                return $this->error( '"find" is required for find_replace mode.' );
            }
            if ( ! isset( $params['replace_with'] ) ) {
                return $this->error( '"replace_with" is required for find_replace mode.' );
            }

            $find         = $params['find'];
            $replace_with = $params['replace_with'];

            $current = Custom_Code::get_css();

            if ( ! str_contains( $current, $find ) ) {
                return $this->error( 'The "find" string was not found in the current custom CSS. Use get_custom_code to check the exact content.' );
            }

            // Replace only the first occurrence to avoid unintended mass-replacement.
            $updated = implode( $replace_with, explode( $find, $current, 2 ) );
            $updated = preg_replace( '#</?style[^>]*>#i', '', $updated );

            $result = wp_update_custom_css_post( $updated );

            if ( is_wp_error( $result ) ) {
                return $this->error( 'Failed to save CSS: ' . $result->get_error_message() );
            }

            return $this->success( 'Custom CSS updated via find_replace. Changes are live immediately.' );
        }

        // ── append / replace ───────────────────────────────────────────────────
        $css = $params['css'] ?? '';

        if ( '' === trim( $css ) ) {
            return $this->error( '"css" is required and cannot be empty for "' . $mode . '" mode.' );
        }

        $css    = preg_replace( '#</?style[^>]*>#i', '', $css );
        $result = Custom_Code::set_css( $css, $mode );

        if ( is_wp_error( $result ) ) {
            return $this->error( 'Failed to save CSS: ' . $result->get_error_message() );
        }

        $action = ( 'replace' === $mode ) ? 'replaced' : 'appended';
        return $this->success( "Custom CSS {$action} successfully. Changes are live immediately." );
    }
}
