<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The AI Nexus licence: one key per site, checked against the VGC licence server.
 * AI Nexus core is free; a key is for its extensions (one key unlocks all of them).
 *
 * NOTICE ONLY, for now. Nothing in this class, or anywhere else, turns a tool off or
 * slows one down because of a licence: whatever the licence server says, every tool
 * keeps working. It records the licence, tells administrators, and tells the AI when
 * a key on the site has expired or stopped being valid. A site without a key is left
 * alone and contacts nothing.
 *
 * What the server's answers are trusted for: an answer counts only when it verifies
 * with a public key pinned here (Ed25519 over the exact bytes of its payload, by key
 * id), and it is about this site's host. Anything else, and any network failure, an
 * error or a rate limit, is "no new answer": the last verified answer stays as it was
 * (docs/client-contract.md in the licence server's repo). A stored answer is verified
 * again each time it is read, so editing the option changes nothing.
 *
 * Stored in one non-autoloaded option, OPTION, which the update_option tool refuses
 * (prefix mcp_abilities_) and the get_option tool refuses by name, because it holds
 * the key. The key is sent only in a POST body, to the licence server, over https.
 *
 *   { key, instance, answer: {payload, sig, kid}, checked_at, tried_at, last_error, last_detail }
 */
final class Licence {

    public const OPTION    = 'mcp_abilities_licence';
    public const CRON      = 'mcp_abilities_licence_check';
    public const PAGE_SLUG = 'mcp-abilities-licence';

    /** The licence server. Moves with its signing key when the shop site is ready. */
    private const SERVER = 'https://tools.vgc-ltd.com/wp-json/vgc-licence/v1';

    /** Key id => the licence server's public key. A later version can add one to rotate. */
    private const PINS = [
        '684d3aac289b332d' => 'Rp7SdlwpCLx1bjx9zsgRczHZ3I0zst6EXJfNaPeNnN4=',
    ];

    /** Add-ons that add no AI tools of their own and need no key. */
    private const NOT_LICENSED = [ 'vgc-ai-nexus-environment-lock' ];

    private const TIMEOUT = 10;

    /** How long the licence page waits between checks of its own. */
    private const PAGE_CHECK_EVERY = HOUR_IN_SECONDS;

    /** A stored answer of more than this is not one the server sent. */
    private const MAX_PAYLOAD = 262144;

    /** Answers that can be stored as this site's licence. */
    private const STATUSES = [ 'valid', 'expired', 'revoked', 'unknown', 'seat_taken', 'not_activated', 'released' ];

    /** @var array|null state(), for the rest of this request. */
    private static ?array $state = null;

    public static function init(): void {
        add_action( self::CRON, [ self::class, 'run_cron' ] );
        add_action( 'admin_init', [ self::class, 'keep_scheduled' ] );
        add_action( 'admin_notices', [ self::class, 'admin_notice' ] );
    }

    // ── What is stored ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function stored(): array {
        $raw = get_option( self::OPTION, [] );
        return is_array( $raw ) ? $raw : [];
    }

    private static function store( array $data ): void {
        update_option( self::OPTION, $data, false );
        self::$state = null;
    }

    /** Whether this site has a key. */
    public static function has_key(): bool {
        $key = self::stored()['key'] ?? '';
        return is_string( $key ) && '' !== $key;
    }

    /** Forget what this request has read (after a change made by other means; tests). */
    public static function reset(): void {
        self::$state = null;
    }

    /**
     * Where to ask and which keys to trust. The filter exists for the test suites, which
     * run a licence server of their own.
     *
     * @return array{url:string, pins:array<string,string>}
     */
    private static function server(): array {
        $server = apply_filters( 'mcp_abilities_licence_server', [ 'url' => self::SERVER, 'pins' => self::PINS ] );
        return [
            'url'  => is_array( $server ) ? (string) ( $server['url'] ?? self::SERVER ) : self::SERVER,
            'pins' => is_array( $server ) && is_array( $server['pins'] ?? null ) ? $server['pins'] : self::PINS,
        ];
    }

    /** The licence server's host, for the page. */
    public static function server_host(): string {
        return (string) wp_parse_url( self::server()['url'], PHP_URL_HOST );
    }

    // ── This site ────────────────────────────────────────────────────────────

    /**
     * This site's address as stored, not as filtered: a plugin that gives each language
     * its own host must not make the site look like another site.
     */
    private static function site_url(): string {
        return (string) get_option( 'home' );
    }

    /**
     * An address reduced to the host the licence server files it under: lower case,
     * "www." and a default port dropped, IDN as ASCII. The same rules as the server's
     * VGC_Lic_Sites::normalise_url(). '' when it has no host.
     */
    public static function host_of( string $url ): string {
        $parts = wp_parse_url( trim( $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }
        $host = rtrim( (string) $parts['host'], '.' );
        if ( '' === $host ) {
            return '';
        }
        if ( '[' === $host[0] ) {
            $packed = @inet_pton( trim( $host, '[]' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false for a malformed address
            $host   = false === $packed ? strtolower( $host ) : '[' . strtolower( (string) inet_ntop( $packed ) ) . ']';
        } else {
            if ( preg_match( '/[^\x00-\x7f]/', $host ) ) {
                $ascii = function_exists( 'idn_to_ascii' ) ? idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : false;
                if ( is_string( $ascii ) && '' !== $ascii ) {
                    $host = $ascii;
                } elseif ( function_exists( 'mb_strtolower' ) ) {
                    $host = mb_strtolower( $host, 'UTF-8' );
                }
            }
            $host = strtolower( $host );
            if ( str_starts_with( $host, 'www.' ) ) {
                $host = substr( $host, 4 );
            }
        }
        $port   = (int) ( $parts['port'] ?? 0 );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
            $port = 0;
        }
        return $host . ( $port ? ':' . $port : '' );
    }

    /**
     * The active extensions a key is for, as plugin files.
     *
     * @return string[]
     */
    public static function extensions(): array {
        $files = (array) get_option( 'active_plugins', [] );
        if ( is_multisite() ) {
            $files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }
        $out = [];
        foreach ( array_unique( $files ) as $file ) {
            $folder = strtok( (string) $file, '/' );
            if ( is_string( $folder ) && str_starts_with( $folder, 'vgc-ai-nexus-' ) && ! in_array( $folder, self::NOT_LICENSED, true ) ) {
                $out[] = (string) $file;
            }
        }
        sort( $out );
        return $out;
    }

    // ── Talking to the licence server ────────────────────────────────────────

    /**
     * What every call says about this site. The versions are cut to what the server
     * takes: a build's PHP_VERSION or WordPress version can carry a long suffix.
     */
    private static function request_body( string $key, string $instance ): array {
        return [
            'key'           => $key,
            'site'          => self::site_url(),
            'environment'   => wp_get_environment_type(),
            'instance'      => $instance,
            'wp_version'    => self::short_version( (string) get_bloginfo( 'version' ) ),
            'php_version'   => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'nexus_version' => self::short_version( self::nexus_version() ),
        ];
    }

    /** Also called while the plugin is being deleted, when its main file is not loaded. */
    private static function nexus_version(): string {
        return defined( 'MCP_ABILITIES_VERSION' ) ? (string) MCP_ABILITIES_VERSION : '';
    }

    private static function short_version( string $version ): string {
        return substr( (string) preg_replace( '/[^0-9A-Za-z.+-]/', '', $version ), 0, 20 );
    }

    /**
     * One call to the licence server.
     *
     * @return array{ok:true, answer:array, data:array}|array{ok:false, error:string, detail:string, retry_after:int}
     */
    private static function call( string $route, array $body ): array {
        $response = wp_remote_post(
            trailingslashit( self::server()['url'] ) . $route,
            [
                'timeout'     => self::TIMEOUT,
                'redirection' => 0,
                'user-agent'  => 'VGC-AI-Nexus/' . self::nexus_version(),
                'headers'     => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
                'body'        => (string) wp_json_encode( $body ),
            ]
        );
        if ( is_wp_error( $response ) ) {
            return self::failed( 'network', $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $text = (string) wp_remote_retrieve_body( $response );
        if ( 429 === $code ) {
            return self::failed( 'rate_limited', '', (int) wp_remote_retrieve_header( $response, 'retry-after' ) );
        }
        if ( $code >= 500 ) {
            return self::failed( 'server', 'HTTP ' . $code, (int) wp_remote_retrieve_header( $response, 'retry-after' ) );
        }
        if ( 200 !== $code ) {
            $error = json_decode( $text, true );
            return self::failed( 'refused', is_array( $error ) ? sanitize_key( (string) ( $error['code'] ?? '' ) ) : 'HTTP ' . $code );
        }
        $answer = json_decode( $text, true );
        $data   = is_array( $answer ) ? self::verified_data( $answer ) : null;
        if ( null === $data ) {
            return self::failed( 'not_verified', '' );
        }
        return [
            'ok'     => true,
            'answer' => [ 'payload' => $answer['payload'], 'sig' => $answer['sig'], 'kid' => $answer['kid'] ],
            'data'   => $data,
        ];
    }

    /** @return array{ok:false, error:string, detail:string, retry_after:int} */
    private static function failed( string $error, string $detail, int $retry_after = 0 ): array {
        return [ 'ok' => false, 'error' => $error, 'detail' => substr( $detail, 0, 200 ), 'retry_after' => max( 0, $retry_after ) ];
    }

    /**
     * The contents of an answer that verifies, or null. Only a pinned key id counts,
     * and the signature covers the exact bytes of the payload string.
     *
     * @return array<string, mixed>|null
     */
    private static function verified_data( array $answer ): ?array {
        $payload = $answer['payload'] ?? null;
        $sig     = $answer['sig'] ?? null;
        $kid     = $answer['kid'] ?? null;
        if ( ! is_string( $payload ) || ! is_string( $sig ) || ! is_string( $kid ) || '' === $payload || strlen( $payload ) > self::MAX_PAYLOAD ) {
            return null;
        }
        $pins   = self::server()['pins'];
        $public = isset( $pins[ $kid ] ) ? base64_decode( (string) $pins[ $kid ], true ) : false;
        $raw    = base64_decode( $sig, true );
        if ( ! is_string( $public ) || 32 !== strlen( $public ) || ! is_string( $raw ) || 64 !== strlen( $raw ) ) {
            return null;
        }
        try {
            if ( ! sodium_crypto_sign_verify_detached( $raw, $payload, $public ) ) {
                return null;
            }
        } catch ( \Throwable $e ) {
            return null;
        }
        $data = json_decode( $payload, true );
        if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || ! is_string( $data['site'] ?? null ) || ! in_array( $data['status'] ?? null, self::STATUSES, true ) ) {
            return null;
        }
        return $data;
    }

    /** A Unix time from one of the server's UTC dates; 0 when it has none. */
    private static function time_of( $iso ): int {
        $time = is_string( $iso ) ? strtotime( $iso ) : false;
        return false === $time ? 0 : (int) $time;
    }

    // ── What the administrator does ──────────────────────────────────────────

    /**
     * An administrator enters a key. Kept when the server knows it and it can apply
     * here (also "already in use on another live site": it may free up, and the page
     * has to name that site). Nothing changes for a key that cannot.
     *
     * @return array{ok:bool, result:string, detail:string} result: the status, or the error.
     */
    public static function activate( string $key ): array {
        $key = trim( $key );
        if ( '' === $key || strlen( $key ) > 64 ) {
            return [ 'ok' => false, 'result' => 'bad_key', 'detail' => '' ];
        }
        $stored   = self::stored();
        $instance = self::instance_of( $stored );
        $res      = self::call( 'activate', self::request_body( $key, $instance ) );
        if ( ! $res['ok'] ) {
            return [ 'ok' => false, 'result' => $res['error'], 'detail' => $res['detail'] ];
        }
        $status = (string) $res['data']['status'];
        if ( $res['data']['site'] !== self::host_of( self::site_url() ) ) {
            return [ 'ok' => false, 'result' => 'not_verified', 'detail' => '' ];
        }
        if ( ! in_array( $status, [ 'valid', 'expired', 'seat_taken' ], true ) ) {
            return [ 'ok' => false, 'result' => $status, 'detail' => '' ];
        }
        $old = (string) ( $stored['key'] ?? '' );
        self::store( [
            'key'         => $key,
            'instance'    => $instance,
            'answer'      => $res['answer'],
            'checked_at'  => time(),
            'tried_at'    => time(),
            'last_error'  => '',
            'last_detail' => '',
        ] );
        self::keep_scheduled();
        // Another key was on this site: it no longer needs its place here.
        if ( '' !== $old && ! self::same_key( $old, $key ) ) {
            self::call( 'deactivate', [ 'key' => $old, 'site' => self::site_url(), 'instance' => $instance ] );
        }
        return [ 'ok' => true, 'result' => $status, 'detail' => '' ];
    }

    /**
     * Whether two typed keys are one key, read the way the server reads them: case,
     * spaces and dashes do not count, I and L are 1, O is 0, the prefix may be left out.
     * Told apart wrongly, re-typing a key would release the site it was just activated on.
     */
    private static function same_key( string $a, string $b ): bool {
        $normal = static function ( string $key ): string {
            $key = strtr( (string) preg_replace( '/[\s\-]+/', '', strtoupper( $key ) ), [ 'I' => '1', 'L' => '1', 'O' => '0' ] );
            return 24 === strlen( $key ) && str_starts_with( $key, 'VGCN' ) ? substr( $key, 4 ) : $key;
        };
        return $normal( $a ) === $normal( $b );
    }

    /** A random id for this installation, made once. */
    private static function instance_of( array $stored ): string {
        $instance = $stored['instance'] ?? '';
        return is_string( $instance ) && 1 === preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $instance ) ? $instance : wp_generate_password( 24, false, false );
    }

    /**
     * Ask the licence server about this site. Only a verified answer about this host
     * changes anything; a failure is noted and the last answer stays. A site the server
     * has no activation for (it moved, or was released) activates itself once.
     *
     * @return array{ok:bool, result:string, detail:string, retry_after:int}
     */
    public static function check(): array {
        $stored = self::stored();
        if ( ! self::has_key() ) {
            return [ 'ok' => false, 'result' => 'no_key', 'detail' => '', 'retry_after' => 0 ];
        }
        $instance = self::instance_of( $stored );
        $body     = self::request_body( (string) $stored['key'], $instance );
        $res      = self::call( 'check', $body );
        if ( $res['ok'] && 'not_activated' === $res['data']['status'] ) {
            $res = self::call( 'activate', $body );
        }
        // The key may have been removed or replaced while the server was being asked.
        wp_cache_delete( self::OPTION, 'options' );
        if ( ( self::stored()['key'] ?? '' ) !== $stored['key'] ) {
            return [ 'ok' => false, 'result' => 'no_key', 'detail' => '', 'retry_after' => 0 ];
        }
        $stored['instance'] = $instance;
        $stored['tried_at'] = time();
        if ( ! $res['ok'] ) {
            return self::note_failure( $stored, $res['error'], $res['detail'], $res['retry_after'] );
        }
        if ( $res['data']['site'] !== self::host_of( self::site_url() ) ) {
            return self::note_failure( $stored, 'not_verified', '', 0 );
        }
        // Never go back to an older answer.
        $previous = is_array( $stored['answer'] ?? null ) ? self::verified_data( $stored['answer'] ) : null;
        if ( null !== $previous && self::time_of( $res['data']['issued_at'] ?? null ) < self::time_of( $previous['issued_at'] ?? null ) ) {
            return self::note_failure( $stored, 'not_verified', 'older than the last answer', 0 );
        }
        $stored['answer']      = $res['answer'];
        $stored['checked_at']  = time();
        $stored['last_error']  = '';
        $stored['last_detail'] = '';
        self::store( $stored );
        return [ 'ok' => true, 'result' => (string) $res['data']['status'], 'detail' => '', 'retry_after' => 0 ];
    }

    /** @return array{ok:false, result:string, detail:string, retry_after:int} */
    private static function note_failure( array $stored, string $error, string $detail, int $retry_after ): array {
        $stored['last_error']  = $error;
        $stored['last_detail'] = $detail;
        self::store( $stored );
        return [ 'ok' => false, 'result' => $error, 'detail' => $detail, 'retry_after' => $retry_after ];
    }

    /** The licence page was opened: ask, unless that was done within the hour. */
    public static function check_for_page(): void {
        if ( self::has_key() && time() - (int) ( self::stored()['tried_at'] ?? 0 ) >= self::PAGE_CHECK_EVERY ) {
            self::check();
        }
    }

    /**
     * An administrator removes the key. It always goes from this site; true when the
     * server has let the site go too.
     *
     * @return bool Whether the licence server confirmed it.
     */
    public static function remove(): bool {
        $released = self::release();
        delete_option( self::OPTION );
        wp_clear_scheduled_hook( self::CRON );
        self::$state = null;
        return $released;
    }

    /** Give up this site's place on the key. True also when it had none. */
    private static function release(): bool {
        $stored = self::stored();
        if ( ! self::has_key() ) {
            return true;
        }
        $res = self::call( 'deactivate', [ 'key' => (string) $stored['key'], 'site' => self::site_url(), 'instance' => self::instance_of( $stored ) ] );
        return $res['ok'];
    }

    /** The plugin is being deleted (Installer::uninstall), for the current site. */
    public static function uninstall(): void {
        self::remove();
    }

    // ── The daily check ──────────────────────────────────────────────────────

    /** Daily, for a site with a key; none without one. Spread over an hour so sites do not call at once. */
    public static function keep_scheduled(): void {
        if ( self::has_key() && ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS + wp_rand( 0, HOUR_IN_SECONDS ), 'daily', self::CRON );
        }
    }

    public static function run_cron(): void {
        if ( self::has_key() ) {
            self::check();
        } else {
            wp_clear_scheduled_hook( self::CRON );
        }
    }

    // ── What it means ────────────────────────────────────────────────────────

    /**
     * This site's licence, from the last verified answer.
     *
     * status: none     no key on this site
     *         valid    the key is good for this site
     *         expired  its date has passed
     *         off      the server says it does not apply here (see server_status)
     *         pending  there is a key but no verified answer for this site's address:
     *                  it moved, or the stored answer was changed. The next check settles it.
     *
     * @return array{status:string, server_status:string, kind:string, expires:string, hint:string, message:string, renew_url:string, checked_at:int, tried_at:int, last_error:string, last_detail:string, days_since_answer:int, unreachable:bool}
     */
    public static function state(): array {
        if ( null !== self::$state ) {
            return self::$state;
        }
        $stored = self::stored();
        $out    = [
            'status'            => 'none',
            'server_status'     => '',
            'kind'              => '',
            'expires'           => '',
            'hint'              => '',
            'message'           => '',
            'renew_url'         => '',
            'checked_at'        => (int) ( $stored['checked_at'] ?? 0 ),
            'tried_at'          => (int) ( $stored['tried_at'] ?? 0 ),
            'last_error'        => (string) ( $stored['last_error'] ?? '' ),
            'last_detail'       => (string) ( $stored['last_detail'] ?? '' ),
            'days_since_answer' => 0,
            'unreachable'       => false,
        ];
        if ( ! self::has_key() ) {
            return self::$state = $out;
        }
        $key                      = (string) $stored['key'];
        $normal                   = (string) preg_replace( '/[\s\-]+/', '', $key );
        $out['hint']              = strtoupper( substr( $normal, -5 ) );
        $out['status']            = 'pending';
        $out['days_since_answer'] = $out['checked_at'] ? (int) floor( ( time() - $out['checked_at'] ) / DAY_IN_SECONDS ) : 0;

        $data = is_array( $stored['answer'] ?? null ) ? self::verified_data( $stored['answer'] ) : null;
        if ( null === $data || $data['site'] !== self::host_of( self::site_url() ) ) {
            return self::$state = $out;
        }

        $out['server_status'] = (string) $data['status'];
        $out['kind']          = is_string( $data['kind'] ?? null ) ? $data['kind'] : '';
        $out['message']       = is_string( $data['message'] ?? null ) ? $data['message'] : '';
        $out['renew_url']     = is_string( $data['renew_url'] ?? null ) ? esc_url_raw( $data['renew_url'] ) : '';
        $out['hint']          = is_string( $data['key_hint'] ?? null ) ? $data['key_hint'] : $out['hint'];
        $expires              = self::time_of( $data['expires_at'] ?? null );
        $out['expires']       = $expires ? gmdate( 'Y-m-d', $expires ) : '';

        // A clock set back cannot outrun a date the server has already seen pass.
        $now = max( time(), self::time_of( $data['issued_at'] ?? null ) );
        switch ( $data['status'] ) {
            case 'valid':
                $out['status'] = ( $expires && $expires < $now ) ? 'expired' : 'valid';
                break;
            case 'expired':
                $out['status'] = 'expired';
                break;
            default:
                $out['status'] = 'off';
        }

        // The server is silent: warn a week before the end of the time the answer gives.
        $grace              = is_int( $data['grace_days'] ?? null ) && $data['grace_days'] > 0 ? $data['grace_days'] : 30;
        $out['unreachable'] = '' !== $out['last_error'] && $out['days_since_answer'] >= max( 1, $grace - 7 );

        return self::$state = $out;
    }

    /**
     * The line for AI assistants when a key on this site has a problem, or null. There
     * is none for a site without a key, or with no extension a key is for. Always
     * English, like the rest of what the AI reads.
     */
    public static function ai_notice(): ?string {
        if ( ! self::has_key() || ! self::extensions() ) {
            return null;
        }
        $state = self::state();
        if ( 'expired' === $state['status'] ) {
            return sprintf(
                "LICENCE: this site's AI Nexus licence expired on %s. The extension tools still work at full speed for now. When you use them, tell the user that the licence needs renewing%s.",
                $state['expires'],
                '' !== $state['renew_url'] ? ' at ' . $state['renew_url'] : ''
            );
        }
        if ( 'off' === $state['status'] ) {
            return 'LICENCE: the AI Nexus licence key on this site is not valid for this site. The extension tools still work for now. When you use them, tell the user that an administrator should check AI Nexus → Licence.';
        }
        return null;
    }

    // ── What the administrator sees ──────────────────────────────────────────

    /** Why the key does not apply here, in plain words, for the licence page and notices. */
    public static function off_reason( array $state ): string {
        switch ( $state['server_status'] ) {
            case 'revoked':
                return __( 'This licence key is no longer valid.', 'mcp-abilities' );
            case 'unknown':
                return __( 'The licence server does not know this key.', 'mcp-abilities' );
            case 'seat_taken':
                // The server names the live site that holds the key, which only it can know.
                return '' !== $state['message'] ? $state['message'] : __( 'This licence key is already in use on another live site.', 'mcp-abilities' );
        }
        return __( 'This site is not activated with this key.', 'mcp-abilities' );
    }

    public static function page_url(): string {
        return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
    }

    /**
     * On AI Nexus's own screens only, for administrators. Quiet unless there is
     * something to do: a key with a problem, or, on the Extensions page, active
     * extensions and no key yet. The licence page says all of it itself.
     */
    public static function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( self::PAGE_SLUG === $page || ( 'mcp-abilities' !== $page && ! str_starts_with( $page, 'mcp-abilities-' ) ) ) {
            return;
        }
        $state = self::state();
        $text  = '';
        $class = 'notice-warning';
        if ( 'none' === $state['status'] ) {
            if ( 'mcp-abilities-extensions' !== $page || ! self::extensions() ) {
                return;
            }
            $class = 'notice-info';
            $text  = __( 'Extensions will need a licence key in a later version. There is nothing to do yet.', 'mcp-abilities' );
        } elseif ( 'expired' === $state['status'] ) {
            /* translators: %s: date */
            $text = sprintf( __( "This site's licence expired on %s. Extensions still work at full speed for now.", 'mcp-abilities' ), $state['expires'] );
        } elseif ( 'off' === $state['status'] ) {
            $text = self::off_reason( $state ) . ' ' . __( 'Extensions still work for now.', 'mcp-abilities' );
        } elseif ( $state['unreachable'] ) {
            /* translators: %d: number of days */
            $text = sprintf( __( 'AI Nexus has not reached the licence server for %d days. Nothing changes because of that; it keeps trying.', 'mcp-abilities' ), $state['days_since_answer'] );
        }
        if ( '' === $text ) {
            return;
        }
        echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>VGC AI Nexus:</strong> ' . esc_html( $text )
            . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Licence', 'mcp-abilities' ) . '</a></p></div>';
    }
}
