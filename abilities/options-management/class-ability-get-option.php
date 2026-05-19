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
    ];

    /** Option name patterns blocked via prefix/substring match. */
    private const DENYLIST_PATTERNS = [
        'woocommerce_stripe',      // Stripe keys
        'woocommerce_paypal',      // PayPal credentials
        'woocommerce_square',
        'woocommerce_braintree',
        'wc_connect_options',      // WC Services (contains API creds)
        'smtp_',                   // SMTP passwords (various mailer plugins)
        'mailchimp',               // Mailchimp API keys
        'sendgrid',
        'postmark',
        'sparkpost',
        'wpml_',                   // WPML site key
        'wpseo_',                  // Yoast licence keys
        '_license',                // Generic licence key pattern
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

    public function execute( array $params ): array {
        $key = sanitize_key( $params['name'] );

        if ( in_array( $key, self::DENYLIST, true ) ) {
            return $this->error( "Option '{$key}' is protected." );
        }

        foreach ( self::DENYLIST_PATTERNS as $pattern ) {
            if ( str_contains( $key, $pattern ) ) {
                return $this->error( "Option '{$key}' is protected." );
            }
        }

        $value = get_option( $key, $params['default'] ?? false );
        return $this->json_result( [ 'option' => $key, 'value' => $value ] );
    }
}
