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
        flush_rewrite_rules( $hard );
        $mode = $hard ? 'hard (including .htaccess)' : 'soft (database only)';
        return $this->success( "Rewrite rules flushed ({$mode})." );
    }
}
