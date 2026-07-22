<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Guide;

defined( 'ABSPATH' ) || exit;

class Usage_Guide_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'usage_guide';
        $this->label        = __( 'Usage Guide', 'mcp-abilities' );
        $this->description  = 'READ THIS FIRST when working with this site, and whenever something seems impossible or a call returns unexpected content. Returns a short Markdown guide generated for THIS specific site: the exact post type and taxonomy slugs in use, which AI Nexus extensions are installed and what they can do, and the known anti-patterns that have caused wrong results before (e.g. REST exposure does NOT limit these tools; never read large markup just to write it back — use the server-side copy tools). Call with no parameters for the overview plus the anti-patterns, or pass "topic" for one section. Long sections are chunked via offset/length.';
        $this->required_cap = 'edit_posts';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'topic'  => [ 'type' => 'string',  'description' => 'Section to return: "overview", "anti-patterns", "site", "conventions", or an extension topic (see the "topics" list in any response). Use "all" for everything. Omit for the recommended starting set.' ],
                'offset' => [ 'type' => 'integer', 'description' => 'Character offset for chunked reads of long sections.', 'default' => 0 ],
                'length' => [ 'type' => 'integer', 'description' => 'Characters to return (max 40000).', 'default' => 20000 ],
            ],
        ];
    }

    public function execute( array $params ): array {
        $sections = Guide::sections();
        $topic    = isset( $params['topic'] ) ? sanitize_key( str_replace( '-', '_', (string) $params['topic'] ) ) : '';
        $topic    = str_replace( '_', '-', $topic ); // slugs use dashes

        $toc = [];
        foreach ( $sections as $slug => $section ) {
            $toc[] = [
                'topic'  => $slug,
                'title'  => $section['title'] ?? $slug,
                'length' => strlen( (string) ( $section['body'] ?? '' ) ),
            ];
        }

        // Which sections to render.
        if ( '' === $topic ) {
            // Default: the two that prevent the most damage, plus site facts.
            $wanted = array_values( array_intersect( [ 'overview', 'anti-patterns', 'site' ], array_keys( $sections ) ) );
        } elseif ( 'all' === $topic ) {
            $wanted = array_keys( $sections );
        } elseif ( isset( $sections[ $topic ] ) ) {
            $wanted = [ $topic ];
        } else {
            return $this->error( sprintf(
                'Unknown guide topic "%s". Available topics: %s (or "all").',
                $topic,
                implode( ', ', array_keys( $sections ) )
            ) );
        }

        $parts = [];
        foreach ( $wanted as $slug ) {
            $parts[] = '## ' . ( $sections[ $slug ]['title'] ?? $slug ) . "\n\n" . trim( (string) ( $sections[ $slug ]['body'] ?? '' ) );
        }
        $content = implode( "\n\n", $parts );

        $total  = strlen( $content );
        $offset = max( 0, (int) ( $params['offset'] ?? 0 ) );
        $length = min( 40000, max( 1, (int) ( $params['length'] ?? 20000 ) ) );
        $chunk  = $offset < $total ? substr( $content, $offset, $length ) : '';

        return $this->json_result( [
            'guide_version' => Guide::version(),
            'topic'         => '' === $topic ? 'default (overview, anti-patterns, site)' : $topic,
            'topics'        => $toc,
            'total_length'  => $total,
            'offset'        => $offset,
            'returned'      => strlen( $chunk ),
            'has_more'      => ( $offset + strlen( $chunk ) ) < $total,
            'next_offset'   => $offset + strlen( $chunk ),
            'content'       => $chunk,
        ] );
    }
}
