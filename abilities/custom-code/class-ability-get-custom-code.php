<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;
use MCP_Abilities\Custom_Code;

defined( 'ABSPATH' ) || exit;

class Get_Custom_Code_Ability extends Ability {

    protected function define_meta(): void {
        $this->key          = 'get_custom_code';
        $this->label        = __( 'Get Custom Code', 'mcp-abilities' );
        $this->description  = 'Retrieve the current custom CSS and JavaScript snippets injected into the site. Always call this before inject_css or inject_js so you can make informed append vs replace decisions.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [ 'type' => 'object', 'properties' => [] ];
    }

    public function execute( array $params ): array {
        return $this->json_result( [
            'css'       => Custom_Code::get_css(),
            'js_header' => Custom_Code::get_js( 'header' ),
            'js_footer' => Custom_Code::get_js( 'footer' ),
        ] );
    }
}
