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
        'fluentmail',              // FluentSMTP (2.41.0)
        'postman_',                // Post SMTP
        'updraft_',                // UpdraftPlus remote storage (S3, FTP, Dropbox …)
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
        $this->description  = 'Retrieve a WordPress site option. Required: name (string key). Examples: blogname, blogdescription, siteurl, admin_email, blogpublic. Fields inside the value that hold credentials (passwords, tokens, secrets, API keys) are shown as "*** hidden by AI Nexus ***" and listed in masked_fields.';
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

    /**
     * The Environment Lock extension's own options (2.52.0). The extension hides
     * them from AI requests by answering "" (its pre_option filter), so a read
     * here said "" as if the lock were not set; now it is a refusal.
     */
    public const ENVLOCK_OPTIONS = [ 'vgc_ainx_envlock', 'vgc_ainx_envlock_set' ];

    public function execute( array $params ): array {
        $resolved = self::resolve_name( $params['name'] ?? '' );
        if ( null !== $resolved['error'] ) {
            return $this->error( $resolved['error'] );
        }
        $name = $resolved['name'];
        $key  = strtolower( $name );

        if ( self::is_protected( $key ) ) {
            return $this->error( self::refusal( $name ) );
        }

        // Credentials inside the value (an API password in a plugin's settings array)
        // are masked, whatever the option is called (2.41.0).
        $masked = [];
        $value  = \MCP_Abilities\Option_Secrets::mask( get_option( $name, $params['default'] ?? false ), $masked );
        $out    = [ 'option' => $name, 'value' => $value, 'exists' => $resolved['exists'] ];
        if ( $masked ) {
            $out['masked_fields'] = $masked;
            $out['note']          = sprintf( 'Fields that hold credentials are shown as "%s". They cannot be read or changed through AI tools; to change one, use the plugin\'s own settings screen. update_option keeps them when they are sent back as shown.', \MCP_Abilities\Option_Secrets::MASK );
        }
        $out = self::with_note( $out, $resolved['note'] );
        if ( ! $resolved['exists'] ) {
            $out = self::with_note( $out, sprintf( 'No option "%s" is stored on this site, so "value" is the default%s.', $name, array_key_exists( 'default', $params ) ? ' you sent' : ' (false, unless WordPress or a plugin defines one)' ) );
        }
        return $this->json_result( $out );
    }

    /**
     * The option name to use for a name a caller sent (2.52.0), or why not.
     *
     * Until 2.51.x the name went through sanitize_key(), which lowercased it and
     * dropped other characters: "WPLANG" was read and written as "wplang"
     * (skipping WordPress's own check of that option), "my.option" as
     * "myoption". Now the name is used as sent; one with spaces, accents or
     * other characters outside printable ASCII is refused (the database
     * compares names without regard to case, accents or trailing spaces, so
     * such a name could reach another option). An option stored under the
     * same name in other letter case is used under its stored name, with a
     * note. Protection rules are checked against the lowercase name.
     *
     * @param mixed $raw
     * @return array{name:string,error:?string,note:?string,exists:bool}
     */
    public static function resolve_name( $raw ): array {
        global $wpdb;
        $out  = [ 'name' => '', 'error' => null, 'note' => null, 'exists' => false ];
        $name = is_string( $raw ) ? trim( $raw ) : ( is_int( $raw ) ? (string) $raw : '' );
        if ( '' === $name ) {
            $out['error'] = 'Send the option\'s name in "name".';
            return $out;
        }
        if ( ! preg_match( '/^[\x21-\x7E]+$/', $name ) || strlen( $name ) > 191 ) {
            $out['error'] = sprintf( 'Option name "%s" is not one WordPress uses: option names are up to 191 characters of letters a-z, digits and symbols such as _ and -, with no spaces or accents.', sanitize_text_field( $name ) );
            return $out;
        }
        $stored = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
        if ( in_array( $name, $stored, true ) ) {
            $out['exists'] = true;
        } elseif ( $stored ) {
            $out['note']   = sprintf( 'This site stores the option as "%s" (option names are matched without regard to letter case), so that one was used.', $stored[0] );
            $name          = $stored[0];
            $out['exists'] = true;
        }
        $out['name'] = $name;
        return $out;
    }

    /** The refusal for a protected option name. */
    public static function refusal( string $name ): string {
        if ( in_array( strtolower( $name ), self::ENVLOCK_OPTIONS, true ) ) {
            return "Option '{$name}' is protected: it is the Environment Lock's own setting, which only an administrator may see or change, on its settings screen. It cannot be read or changed through AI tools.";
        }
        return "Option '{$name}' is protected.";
    }

    /** Whether a (lowercase) option name may not be read. */
    public static function is_protected( string $key ): bool {
        $key   = strtolower( $key );
        if ( in_array( $key, self::ENVLOCK_OPTIONS, true ) ) {
            return true;
        }
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
