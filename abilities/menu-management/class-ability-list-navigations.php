<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class List_Navigations_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'list_navigations';
        $this->label        = __( 'List Block Navigations', 'mcp-abilities' );
        $this->description  = 'List all wp_navigation posts — the block-based navigation menus used by FSE / block themes. Returns each navigation\'s ID, title and status. Use get_navigation to read the block markup, or update_navigation to edit it.';
        $this->required_cap = 'edit_theme_options';
        $this->input_schema = [ 'type' => 'object', 'properties' => [] ];
    }

    public function execute( array $params ): array {
        $navs = get_posts( [
            'post_type'      => 'wp_navigation',
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => 100,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $result = array_map( fn( $p ) => [
            'id'           => $p->ID,
            'title'        => $p->post_title,
            'status'       => $p->post_status,
            'date_modified' => $p->post_modified,
        ], $navs );

        return $this->json_result( [
            'total'       => count( $result ),
            'navigations' => $result,
        ] );
    }
}
