<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Get_Site_Info_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_site_info';
        $this->label        = __( 'Get Site Info', 'mcp-abilities' );
        $this->description  = 'Retrieve key information about the WordPress installation.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [ 'type' => [ 'object', 'array' ] ];
    }

    public function execute( array $params ): array {
        return $this->json_result( [
            'site_name'    => get_bloginfo( 'name' ),
            'tagline'      => get_bloginfo( 'description' ),
            'url'          => home_url(),
            'admin_url'    => admin_url(),
            'charset'      => get_bloginfo( 'charset' ),
            'language'     => get_bloginfo( 'language' ),
            'timezone'     => get_option( 'timezone_string' ),
            'date_format'  => get_option( 'date_format' ),
            'time_format'  => get_option( 'time_format' ),
            'active_theme' => get_option( 'stylesheet' ),
            'multisite'    => is_multisite(),
        ] );
    }
}
