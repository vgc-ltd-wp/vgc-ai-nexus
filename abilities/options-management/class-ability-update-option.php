<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Update_Option_Ability extends Ability {

    private const DENYLIST = [
        // Auth secrets
        'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
        'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',

        // Core site identity / redirect vectors
        'siteurl', 'home', 'admin_email',

        // Plugin / theme activation — can load arbitrary PHP
        'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet',
        'current_theme', 'auth_version',

        // Registration privilege escalation
        'default_role', 'users_can_register',

        // Upload path manipulation
        'upload_path', 'upload_url_path',

        // Plugin own settings (prevent AI from re-enabling disabled tools)
        'mcp_abilities_settings', 'mcp_woo_settings',

        // Cron manipulation
        'cron', 'doing_cron',

        // Schema/version markers
        'db_version', 'initial_db_version',

        // Custom code — must be managed via inject_css / inject_js abilities
        'mcp_custom_js_head', 'mcp_custom_js_footer',
    ];

    /** Option name prefixes that are blocked (matched with str_starts_with). */
    private const DENY_PREFIXES = [
        'theme_mods_',   // per-theme customizer mods (can change templates/CSS)
        'mcp_custom_',   // custom code storage
        'widget_',       // widget content (markup injection vector)
    ];

    protected function define_meta(): void {
        $this->key          = 'update_option';
        $this->label        = __( 'Update Option', 'mcp-abilities' );
        $this->description  = 'Update a WordPress site option. Certain critical options are write-protected.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'     => [ 'type' => 'string', 'description' => 'Option key name (e.g. blogname).' ],
                'value'    => [ 'description' => 'New value for the option.' ],
                'autoload' => [ 'type' => 'string', 'description' => 'yes or no.', 'default' => 'yes' ],
            ],
            'required' => [ 'name', 'value' ],
        ];
    }

    public function execute( array $params ): array {
        $key = sanitize_key( $params['name'] );

        if ( in_array( $key, self::DENYLIST, true ) ) {
            return $this->error( "Option '{$key}' is protected and cannot be modified." );
        }

        foreach ( self::DENY_PREFIXES as $prefix ) {
            if ( str_starts_with( $key, $prefix ) ) {
                return $this->error( "Option '{$key}' is protected and cannot be modified." );
            }
        }

        if ( ! apply_filters( 'mcp_abilities_allow_option_write', true, $key ) ) {
            return $this->error( "Option '{$key}' is not writable." );
        }

        $autoload = ( $params['autoload'] ?? 'yes' ) === 'yes' ? 'yes' : 'no';
        update_option( $key, $params['value'], $autoload );

        return $this->success( "Option '{$key}' updated." );
    }
}
