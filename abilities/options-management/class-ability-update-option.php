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

        // Read-only mode is an administrator's decision, never the AI's.
        'mcp_abilities_read_only',

        // So are connection approval and who-is-connecting names: an AI must
        // never approve itself or rename the person behind a connection.
        'mcp_abilities_require_approval', 'mcp_abilities_connection_approvals',
        'mcp_abilities_approval_mail_times', 'mcp_abilities_oauth_operators',

        // Cron manipulation
        'cron', 'doing_cron',

        // Schema/version markers
        'db_version', 'initial_db_version',

        // Custom code — must be managed via inject_css / inject_js abilities
        'mcp_custom_js_head', 'mcp_custom_js_footer',

        // Admin email change in progress (confirmation hash and new address).
        'adminhash', 'new_admin_email',

        // Stored uninstall callbacks: run as PHP when a plugin is deleted.
        'uninstall_plugins',

        // Which widgets show where, and how URLs resolve.
        'sidebars_widgets', 'rewrite_rules', 'permalink_structure',
    ];

    /** Option name prefixes that are blocked (matched with str_starts_with). */
    private const DENY_PREFIXES = [
        'theme_mods_',      // per-theme customizer mods (can change templates/CSS)
        'mcp_custom_',      // custom code storage
        'widget_',          // widget content (markup injection vector)
        'vgc_cc_',          // OAuth tokens, apps allowed to connect, registration policy
        'mcp_abilities_',   // this plugin's own settings, guide conventions, connections
        '_transient_',      // caches, incl. update and extension package URLs
        '_site_transient_', // core update caches (package URLs)
        'auto_update_',     // which plugins and themes update themselves
        // AI Nexus extensions' settings and stored sign-ins (2.39.0): an AI must
        // not switch an extension's disabled tools back on, nor plant a definition
        // or a Google / Pinterest credential.
        'mcp_cpt_', 'mcp_wpml_', 'mcp_analytics_', 'mcp_tracker_',
        'vgc_analytics_', 'vgc_pinterest_',
    ];

    /**
     * Extension settings that always count, whether or not the extension is
     * active right now (2.39.0): Tracker's connection, Environment Lock, and the
     * option keys of the extensions that register with mcp_abilities_extensions.
     */
    private const DENY_EXTENSION_KEYS = [
        'vgct_nexus', 'vgc_ainx_envlock',
        'mcp_avada_settings', 'mcp_elementor_settings', 'mcp_woo_settings',
    ];

    /**
     * Every option an AI Nexus extension keeps its settings in, as the
     * extensions register with core (mcp_abilities_extensions → option_key),
     * plus the fixed list above.
     *
     * @return string[]
     */
    public static function extension_keys(): array {
        $keys = self::DENY_EXTENSION_KEYS;
        try {
            foreach ( (array) apply_filters( 'mcp_abilities_extensions', [] ) as $ext ) {
                if ( is_array( $ext ) && ! empty( $ext['option_key'] ) && is_string( $ext['option_key'] ) ) {
                    $keys[] = sanitize_key( $ext['option_key'] );
                }
            }
        } catch ( \Throwable $e ) {
            // An extension that fails to describe itself must not open its options.
        }
        return array_values( array_unique( array_filter( $keys ) ) );
    }

    /**
     * Exact keys that depend on the table prefix: the role → capability table.
     *
     * @return string[]
     */
    private static function deny_runtime(): array {
        global $wpdb;
        return array_unique( [
            strtolower( $wpdb->prefix . 'user_roles' ),
            strtolower( $wpdb->base_prefix . 'user_roles' ),
        ] );
    }

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

        if ( in_array( $key, self::DENYLIST, true ) || in_array( $key, self::deny_runtime(), true ) ) {
            return $this->error( "Option '{$key}' is protected and cannot be modified." );
        }

        foreach ( self::DENY_PREFIXES as $prefix ) {
            if ( str_starts_with( $key, $prefix ) ) {
                return $this->error( "Option '{$key}' is protected and cannot be modified." );
            }
        }

        if ( in_array( $key, self::extension_keys(), true ) ) {
            return $this->error( "Option '{$key}' is an AI Nexus extension's settings: it is protected and cannot be modified." );
        }

        if ( ! apply_filters( 'mcp_abilities_allow_option_write', true, $key ) ) {
            return $this->error( "Option '{$key}' is not writable." );
        }

        $autoload = ( $params['autoload'] ?? 'yes' ) === 'yes' ? 'yes' : 'no';
        update_option( $key, $params['value'], $autoload );

        return $this->success( "Option '{$key}' updated." );
    }
}
