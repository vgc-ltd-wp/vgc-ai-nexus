<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Flush_Rewrite_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'flush_rewrite_rules';
        $this->label        = __( 'Flush Rewrite Rules', 'mcp-abilities' );
        $this->description  = 'Regenerate WordPress permalink/rewrite rules. Call this after creating new pages, changing slugs, registering new post types, or any change that affects URL routing. Equivalent to visiting Settings → Permalinks and clicking Save.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'hard' => [
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'If true, also updates the .htaccess file (Apache). Usually not needed — soft flush rebuilds the rules in the database. Default: false.',
                ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $hard = ! empty( $params['hard'] );
        flush_rewrite_rules( false );
        if ( ! $hard ) {
            return $this->success( 'Rewrite rules flushed (soft (database only)).' );
        }

        // A hard flush writes .htaccess through save_mod_rewrite_rules(), which
        // lives in wp-admin and is not loaded for an MCP request: the file was
        // never written, and the answer said it was (2.52.0). Loaded here, and
        // what happened is reported.
        if ( ! apply_filters( 'flush_rewrite_rules_hard', true ) ) {
            return $this->success( 'Rewrite rules flushed (database only).', [ 'htaccess' => 'not written', 'note' => 'A plugin on this site turns hard flushes off (flush_rewrite_rules_hard), so .htaccess was not written; the rules in the database were rebuilt.' ] );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        $htaccess = function_exists( 'save_mod_rewrite_rules' ) ? save_mod_rewrite_rules() : null;
        $webconfig = function_exists( 'iis7_save_url_rewrite_rules' ) ? iis7_save_url_rewrite_rules() : null;
        if ( true === $htaccess ) {
            return $this->success( 'Rewrite rules flushed (hard (including .htaccess)).', [ 'htaccess' => 'written' ] );
        }
        if ( true === $webconfig ) {
            return $this->success( 'Rewrite rules flushed (hard (including web.config)).', [ 'htaccess' => 'not used', 'web_config' => 'written' ] );
        }
        return $this->success( 'Rewrite rules flushed (database only).', [
            'htaccess' => 'not written',
            'note'     => is_multisite()
                ? 'On a multisite network WordPress does not write .htaccess itself; the rules in the database were rebuilt.'
                : '.htaccess was not written: the server does not use it (not Apache with mod_rewrite), the file is not writable, or the permalinks are plain. The rules in the database were rebuilt, which is all most sites need.',
        ] );
    }
}
