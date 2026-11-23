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

        // Money and access (2.41.0): the bank account shown for bank transfers,
        // and stored recovery-mode keys (a planted key opens recovery mode).
        'woocommerce_bacs_accounts', 'recovery_keys',
    ];

    /**
     * Prefixes of other plugins' options that move mail, money or access, or switch
     * a protection off (2.41.0). The review's example: pointing an SMTP plugin at
     * another server, then asking for an administrator's password reset.
     */
    private const DENY_PREFIXES_SECURITY = [
        // Mail: SMTP and mailer plugins, core's post-by-email account.
        'wp_mail_', 'mailserver_', 'fluentmail', 'postman_', 'easy_wp_smtp', 'swpsmtp_', 'vgc_mailer_',
        // Security, login and backup plugins.
        'itsec', 'wordfence', 'aiowps_', 'sg_security', 'cerber', 'wpcerber', 'limit_login_',
        'wp_2fa_', 'two_factor_', 'jetpack_', 'sucuri', 'updraft_',
        // Google Site Kit (which property and whose Google sign-in Analytics reads).
        'googlesitekit_',
        // VGC plugins whose settings are a protection or a credential.
        'vgct_', 'vgc_trash_guard_', 'vgc_lic_', 'vgc_cookie_', 'vgc_ainx_',
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
        $this->description  = 'Update a WordPress site option. Certain critical options are write-protected, including the settings of mail, payment, security and backup plugins. Credential fields inside a value cannot be changed: send them back as shown by get_option ("*** hidden by AI Nexus ***") or leave them out, and they keep their stored value. The value is read back after saving: one WordPress does not accept for that option is refused, and one it changes on saving is given as "stored_value".';
        $this->required_cap = 'manage_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name'     => [ 'type' => 'string', 'description' => 'Option key name (e.g. blogname).' ],
                'value'    => [ 'description' => 'New value for the option.' ],
                'autoload' => [ 'type' => 'string', 'description' => 'yes or no. Not sent: the option keeps its setting (a new option gets WordPress\'s default).' ],
            ],
            'required' => [ 'name', 'value' ],
        ];
    }

    public function execute( array $params ): array {
        if ( ! class_exists( Get_Option_Ability::class ) ) {
            require_once __DIR__ . '/class-ability-get-option.php';
        }
        // The name as sent, or as the site stores it in other letter case (2.52.0;
        // sanitize_key() rewrote it). Every rule below is checked in lowercase.
        $resolved = Get_Option_Ability::resolve_name( $params['name'] ?? '' );
        if ( null !== $resolved['error'] ) {
            return $this->error( $resolved['error'] );
        }
        $name = $resolved['name'];
        $key  = strtolower( $name );

        if ( in_array( $key, Get_Option_Ability::ENVLOCK_OPTIONS, true ) ) {
            return $this->error( Get_Option_Ability::refusal( $name ) );
        }

        // yes / no as before; also WordPress's own on / off and true / false. Not
        // sent: the option keeps its autoload setting (a new one gets WordPress's
        // default). Until 2.51.x a missing value forced "yes" on every update, and
        // anything but "yes" (even "on") meant "no" (2.52.0).
        $autoload = null;
        if ( isset( $params['autoload'] ) && '' !== $params['autoload'] ) {
            $flag = is_bool( $params['autoload'] ) ? ( $params['autoload'] ? 'yes' : 'no' ) : strtolower( trim( (string) $params['autoload'] ) );
            if ( in_array( $flag, [ 'yes', 'on', 'true', '1' ], true ) ) {
                $autoload = 'yes';
            } elseif ( in_array( $flag, [ 'no', 'off', 'false', '0' ], true ) ) {
                $autoload = 'no';
            } else {
                return $this->error( sprintf( 'autoload takes "yes" or "no", not "%s". Option \'%s\' was not changed.', sanitize_text_field( (string) $params['autoload'] ), $name ) );
            }
        }

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

        $refusal = self::security_refusal( $key );
        if ( null !== $refusal ) {
            return $this->error( $refusal );
        }

        if ( ! apply_filters( 'mcp_abilities_allow_option_write', true, $key ) ) {
            return $this->error( "Option '{$key}' is not writable." );
        }

        // Credentials inside the value cannot be changed or removed; the mask a read
        // showed keeps the stored value (2.41.0).
        $value  = $params['value'];
        $stored = get_option( $name, null );
        if ( null !== $stored ) {
            $guard = \MCP_Abilities\Option_Secrets::guard_write( $value, $stored );
            if ( null !== $guard['error'] ) {
                return $this->error( "Option '{$name}' was not changed. " . $guard['error'] );
            }
            $value = $guard['value'];
        }

        $before = self::raw_option( $name );
        update_option( $name, $value, $autoload );

        // Read back (2.52.0): WordPress's sanitize_option() puts an invalid value
        // back (a time zone or language it does not know) or turns it into another
        // one ("ten" posts per page became 1), and the answer said "updated". The
        // row itself is read, so a plugin's read filter (a translation) is not
        // taken for a change.
        $after = self::raw_option( $name );
        $extra = self::with_note( [ 'option' => $name ], $resolved['note'] );
        if ( null === $after ) {
            return $this->error( "Option '{$name}' was not saved: WordPress did not store the value sent (it does not accept it for this option, or it is the option's default)." );
        }
        if ( ! self::same_value( $after, $value ) ) {
            $masked = [];
            $shown  = \MCP_Abilities\Option_Secrets::mask( $after, $masked );
            if ( null !== $before && self::same_value( $after, $before ) ) {
                return $this->error( sprintf(
                    'Option \'%s\' was not changed: WordPress does not accept the value sent for it and kept the stored one, %s.',
                    $name,
                    self::short( $shown )
                ) );
            }
            $extra['stored_value'] = $shown;
            $extra = self::with_note( $extra, sprintf( 'WordPress changed the value on saving (its own check of this option): it stored %s, not the value sent.', self::short( $shown ) ) );
            return $this->success( "Option '{$name}' updated, with another value than the one sent.", $extra );
        }

        return $this->success( "Option '{$name}' updated.", count( $extra ) > 1 ? $extra : [] );
    }

    /** The option's stored value, read from its row (no filters, no cache); null when there is none. */
    private static function raw_option( string $name ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
        return $row ? maybe_unserialize( $row->option_value ) : null;
    }

    /** Whether two option values are the same once stored (5 and "5", true and "1"). */
    private static function same_value( $a, $b ): bool {
        $norm = static function ( $v ) {
            $v = is_object( $v ) ? (array) $v : $v;
            return map_deep( $v, static function ( $s ) {
                if ( is_bool( $s ) ) {
                    return $s ? '1' : '';
                }
                return null === $s ? '' : ( is_scalar( $s ) ? (string) $s : $s );
            } );
        };
        $a = $norm( $a );
        $b = $norm( $b );
        if ( is_array( $a ) || is_array( $b ) ) {
            return is_array( $a ) && is_array( $b ) && maybe_serialize( $a ) === maybe_serialize( $b );
        }
        return $a === $b;
    }

    /** A value for a message: JSON, cut at 200 characters. */
    private static function short( $value ): string {
        $json = (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return strlen( $json ) > 200 ? substr( $json, 0, 200 ) . '…' : $json;
    }

    /**
     * Why an option may not be written for mail, money or security reasons, or
     * null (2.41.0): anything the AI may not read (get_option's rules), payment,
     * shipping and e-mail settings of WooCommerce, mailer and security plugins.
     */
    public static function security_refusal( string $key ): ?string {
        if ( ! class_exists( Get_Option_Ability::class ) ) {
            require_once __DIR__ . '/class-ability-get-option.php';
        }
        if ( Get_Option_Ability::is_protected( $key ) ) {
            return "Option '{$key}' is protected: an AI may not read it, so it may not change it either.";
        }
        foreach ( self::DENY_PREFIXES_SECURITY as $prefix ) {
            if ( str_starts_with( $key, $prefix ) ) {
                return "Option '{$key}' is protected: it belongs to a mail, security or credential setting, which only an administrator may change, in that plugin's own settings screen.";
            }
        }
        // woocommerce_<gateway | shipping method | e-mail>_settings: payment details,
        // and who receives order and account e-mails.
        if ( str_starts_with( $key, 'woocommerce_' ) && str_ends_with( $key, '_settings' ) ) {
            return "Option '{$key}' is protected: WooCommerce payment, shipping and e-mail settings may only be changed in WooCommerce's own settings screens.";
        }
        return null;
    }
}
