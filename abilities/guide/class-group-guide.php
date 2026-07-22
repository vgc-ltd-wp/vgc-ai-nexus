<?php
namespace MCP_Abilities\Groups;

use MCP_Abilities\Ability_Group;

defined( 'ABSPATH' ) || exit;

class Guide_Group extends Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'guide';
        $this->label       = __( 'Usage Guide', 'mcp-abilities' );
        $this->description = __( 'Serves an AI-readable guide to this site: real post type slugs, installed extensions, and known anti-patterns.', 'mcp-abilities' );
        $this->icon        = 'dashicons-book';
        // One ability: keep it as its own tool so it is obvious and reachable.
        $this->consolidate = false;
        $this->guide       = __( "Gives connected AI assistants a short, site-specific manual instead of leaving them to guess. It reports the exact post type and taxonomy slugs on this site, which AI Nexus extensions are installed, and the anti-patterns that have produced wrong results before — so the assistant reaches for the right tool instead of inventing a workaround. Add your own house rules under AI Nexus → Guide and they are included too. Keep this group enabled.", 'mcp-abilities' );
        $this->examples    = [
            __( 'How should you work with this site?', 'mcp-abilities' ),
            __( 'What are the real post type slugs here?', 'mcp-abilities' ),
        ];
    }

    protected function register_abilities(): void {
        require_once __DIR__ . '/class-ability-guide.php';
        $class = 'MCP_Abilities\\Abilities\\Usage_Guide_Ability';
        if ( class_exists( $class ) ) {
            $this->register_ability( new $class() );
        }
    }
}
