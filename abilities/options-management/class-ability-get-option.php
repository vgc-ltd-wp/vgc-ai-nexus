<?php
namespace MCP_Abilities\Abilities;

use MCP_Abilities\Ability;

defined( 'ABSPATH' ) || exit;

class Get_Option_Ability extends Ability {

    private const DENYLIST = [
        // Auth secrets
        'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
        'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',

        // Admin contact
        'admin_email',

        // Plugin own settings
        'mcp_abilities_settings', 'mcp_woo_settings',

        // Who is connected, from where, and who approved it.
        'mcp_abilities_connection_approvals', 'mcp_abilities_approval_mail_times',
        'mcp_abilities_oauth_operators',
        // Every live connection, when and from which address it was last used (2.39.0).
        'mcp_abilities_connection_registry', 'mcp_abilities_connections',
        // This site's licence key (2.40.0).
        'mcp_abilities_licence',

        // Mail and service credentials stored under names the patterns miss.
        'wp_mail_smtp', 'mailserver_pass', 'jetpack_private_options',
        'woocommerce-ppcp-settings', 'fs_accounts',

        // Apps allowed to connect (secret hashes, redirect URIs).
        'vgc_cc_oauth_clients',
    ];

    /** Option name patterns blocked via prefix/substring match. */
    private const DENYLIST_PATTERNS = [
        'woocommerce_stripe',      // Stripe keys
        'woocommerce_paypal',      // PayPal credentials
        'woocommerce_square',
        'woocommerce_braintree',
        'wc_connect_options',      // WC Services (contains API creds)
        'smtp',                    // SMTP passwords (various mailer plugins, wp_mail_smtp)
        '_pass',                   // passwords (mailserver_pass, *_password)
        'token',                   // access / refresh tokens
        'ppcp',                    // WooCommerce PayPal Payments
        'jetpack_private',
        'fs_accounts',             // Freemius accounts (licence + API keys)
        'vgc_cc_',                 // AI Nexus OAuth: clients, tokens, policy
        'vgc_analytics_',          // Analytics extension: Google client secret + refresh token (vgc_analytics_oauth), access tokens (2.39.0)
        'mailchimp',               // Mailchimp API keys
        'sendgrid',
        'postmark',
        'sparkpost',
        'wpml_',                   // WPML site key
        'wpseo_',                  // Yoast licence keys
        '_license',                // Generic licence key pattern
        '_licence',                // The same, in British spelling
        '_api_key',                // Generic API key pattern
        '_secret',                 // Generic secret pattern
        '_private_key',
    ];

    protected function define_meta(): void {
        $this->key          = 'get_option';
        $this->label        = __( 'Get Option', 'mcp-abilities' );
        $this->description  = 'Retrieve a WordPress site option. Required: name (string key). Examples: blogname, blogdescription, siteurl, admin_email, blogpublic.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'    => [ 'type' => 'string', 'description' => 'Option key name (e.g. blogname, blogdescription).' ],
                'default' => [ 'description' => 'Default value if not found.' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    /** Cache rows of a refused name are refused too: _transient_x, _transient_timeout_x and the site-wide forms (2.39.0). */
    private const CACHE_PREFIXES = [ '_site_transient_timeout_', '_site_transient_', '_transient_timeout_', '_transient_' ];

    public function execute( array $params ): array {
        $key = sanitize_key( $params['name'] );

        if ( self::is_protected( $key ) ) {
            return $this->error( "Option '{$key}' is protected." );
        }

        $value = get_option( $key, $params['default'] ?? false );
        return $this->json_result( [ 'option' => $key, 'value' => $value ] );
    }

    /** Whether a (sanitized) option name may not be read. */
    public static function is_protected( string $key ): bool {
        $names = [ $key ];
        foreach ( self::CACHE_PREFIXES as $prefix ) {
            if ( str_starts_with( $key, $prefix ) ) {
                $names[] = substr( $key, strlen( $prefix ) );
                break;
            }
        }
        foreach ( $names as $name ) {
            if ( in_array( $name, self::DENYLIST, true ) ) {
                return true;
            }
            foreach ( self::DENYLIST_PATTERNS as $pattern ) {
                if ( str_contains( $name, $pattern ) ) {
                    return true;
                }
            }
        }
        return false;
    }
}
