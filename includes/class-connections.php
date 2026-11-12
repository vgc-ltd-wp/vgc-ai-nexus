<?php
namespace MCP_Abilities;

use MCP_Abilities\OAuth\Client_Manager;
use MCP_Abilities\OAuth\Lifecycle;
use MCP_Abilities\OAuth\Operators;
use MCP_Abilities\OAuth\Token_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Every AI connection that is on, and when it was last used (2.39.0).
 *
 * The owner's decisions 2 and 3 (access analysis 2026-11-06):
 *
 *  - A registry of connections, kept whatever the approval setting and for
 *    administrators too (Connection_Approval's records exist only while
 *    approval is on and skip administrators). One entry per OAuth app + user
 *    and per Application Password used on MCP, in the non-autoloaded option
 *    OPTION, key as Connection_Approval::key():
 *      "oauth:<client_id>:<user_id>" | "app:<uuid>:<user_id>" => {
 *        kind ('oauth' | 'app_password'), user_id, client_id, app_pass_uuid,
 *        app (the app's name), operator ("Who is connecting?"),
 *        first_seen, last_active (unix), ip (of the last request)
 *      }
 *    Written on MCP requests (rest_pre_dispatch, after Request_Context has
 *    found the route), at most once a minute per connection; at most MAX
 *    entries, the longest unused go first. Neither get_option nor
 *    update_option (the AI's tools) can touch it.
 *
 *  - live(): what is on right now. OAuth: every app + user with a live
 *    refresh token. Application Passwords: the ones AI Nexus's Connect page
 *    made (option CONNECT_OPTION) and every other one used on MCP.
 *
 *  - The daily sweep (cron CRON): expired and dead OAuth rows and their
 *    entries go; an Application Password the Connect page made is deleted
 *    once it has not been used for Lifecycle::idle_days() (14), judged by the
 *    later of WordPress's own last_used and the last activity here, or its
 *    creation date if never used. Other Application Passwords are only
 *    listed, never removed (the owner's decision, 2026-11-06), unless an
 *    administrator switches on "Also switch off other Application Passwords"
 *    (option IDLE_OTHERS, off by default, 2.42.0): then every Application
 *    Password of this site's users goes after the same idle time, counted from
 *    the moment it was switched on at the earliest. Every removal is written
 *    to the activity log.
 *
 *  - Since 2.42.0 such a password is also refused at use once its idle time
 *    is over (wp_authenticate_application_password_errors) and deleted then,
 *    so a site whose cron does not run still ends it. The list flags an
 *    Application Password made in the last NEW_DAYS days, so an administrator
 *    sees one that someone made with a stolen login.
 *
 *  - Disconnect (AI Nexus → Connections, manage_options) and Revoke on each
 *    user's own profile ("Your AI connections", own connections only).
 */
final class Connections {

    public const OPTION         = 'mcp_abilities_connection_registry';
    public const CONNECT_OPTION = 'mcp_abilities_connections';
    public const CRON           = 'mcp_abilities_idle_sweep';
    public const NONCE_OWN      = 'mcp_abilities_own_connections';
    public const TOUCH_EVERY    = 60;
    public const MAX            = 1000;
    /** "Also switch off other Application Passwords": 0 off, else when it was switched on (2.42.0). */
    public const IDLE_OTHERS    = 'mcp_abilities_idle_others';
    /** An Application Password made this many days ago or less is flagged as new in the list (2.42.0). */
    public const NEW_DAYS       = 7;

    private static bool $booted = false;

    /** @var array<string, true> Connections already looked at in this request. */
    private static array $seen = [];

    public static function init(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        // After Request_Context::detect (0) and the approval gate (1).
        add_filter( 'rest_pre_dispatch', [ self::class, 'on_rest' ], 2, 3 );

        add_action( 'wp_delete_application_password', [ self::class, 'on_app_password_deleted' ], 10, 2 );
        // Past its idle time an Application Password is refused, cron or not (2.42.0).
        add_action( 'wp_authenticate_application_password_errors', [ self::class, 'refuse_idle_app_password' ], 10, 4 );
        add_action( 'deleted_user', [ self::class, 'forget_user' ], 20, 1 );
        add_action( 'remove_user_from_blog', [ self::class, 'forget_user' ], 20, 1 );

        add_action( self::CRON, [ self::class, 'sweep' ] );
        // On init, not earlier: scheduling runs other plugins' cron filters, and
        // WooCommerce's load translations (WordPress 6.7+ flags that before init).
        if ( did_action( 'init' ) ) {
            self::schedule();
        } else {
            add_action( 'init', [ self::class, 'schedule' ], 20 );
        }

        add_action( 'show_user_profile', [ self::class, 'render_profile_section' ], 50 );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_profile' ] );
        add_action( 'wp_ajax_mcp_own_connection_revoke', [ self::class, 'ajax_own_revoke' ] );
    }

    /** The daily sweep, scheduled once. */
    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
        }
    }

    // ── The registry ─────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    public static function entries(): array {
        $entries = get_option( self::OPTION, [] );
        return is_array( $entries ) ? $entries : [];
    }

    /** @param array<string, array<string, mixed>> $entries */
    private static function save( array $entries ): void {
        if ( count( $entries ) > self::MAX ) {
            uasort( $entries, static fn( $a, $b ) => (int) ( $b['last_active'] ?? 0 ) <=> (int) ( $a['last_active'] ?? 0 ) );
            $entries = array_slice( $entries, 0, self::MAX, true );
        }
        if ( ! $entries ) {
            delete_option( self::OPTION );
            return;
        }
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, $entries, '', false );
        } else {
            update_option( self::OPTION, $entries, false );
        }
    }

    /**
     * rest_pre_dispatch: an MCP request signed in with OAuth or an Application
     * Password marks its connection as used now.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed Unchanged.
     */
    public static function on_rest( $result, $server = null, $request = null ) {
        if ( Request_Context::is_mcp() ) {
            try {
                self::touch_current();
            } catch ( \Throwable $e ) {
                // The list must never stop a request.
            }
        }
        return $result;
    }

    /** Record the current request's connection (at most once a minute each). */
    public static function touch_current(): void {
        $actor   = Request_Context::actor();
        $user_id = (int) $actor['user_id'];
        $kind    = (string) $actor['auth'];
        if ( $user_id <= 0 || ! in_array( $kind, [ 'oauth', 'app_password' ], true ) ) {
            return;
        }
        $id = 'oauth' === $kind ? (string) $actor['client_id'] : (string) $actor['app_pass_uuid'];
        if ( '' === $id ) {
            return;
        }
        $key = Connection_Approval::key( $kind, $id, $user_id );
        if ( isset( self::$seen[ $key ] ) ) {
            return;
        }
        self::$seen[ $key ] = true;

        $entries = self::entries();
        $old     = isset( $entries[ $key ] ) && is_array( $entries[ $key ] ) ? $entries[ $key ] : null;
        $now     = time();
        if ( null !== $old && $now - (int) ( $old['last_active'] ?? 0 ) < self::TOUCH_EVERY ) {
            return;
        }
        $entries[ $key ] = [
            'kind'          => $kind,
            'user_id'       => $user_id,
            'client_id'     => 'oauth' === $kind ? $id : '',
            'app_pass_uuid' => 'app_password' === $kind ? $id : '',
            'app'           => Operators::sanitize( (string) $actor['app'] ),
            'operator'      => Operators::sanitize( (string) ( $actor['operator'] ?? '' ) ),
            'first_seen'    => null !== $old ? (int) ( $old['first_seen'] ?? $now ) : $now,
            'last_active'   => $now,
            'ip'            => Client_Ip::get(),
        ];
        self::save( $entries );
    }

    public static function forget( string $key ): void {
        $entries = self::entries();
        if ( isset( $entries[ $key ] ) ) {
            unset( $entries[ $key ] );
            self::save( $entries );
        }
    }

    /** A user's OAuth entries: of one app, or all. */
    public static function forget_oauth( int $user_id, ?string $client_id = null ): void {
        self::forget_matching( static fn( array $e ): bool => 'oauth' === ( $e['kind'] ?? '' )
            && (int) ( $e['user_id'] ?? 0 ) === $user_id
            && ( null === $client_id || (string) ( $e['client_id'] ?? '' ) === $client_id ) );
    }

    /** Every user's entry for an app that is no longer registered. */
    public static function forget_client( string $client_id ): void {
        self::forget_matching( static fn( array $e ): bool => 'oauth' === ( $e['kind'] ?? '' ) && (string) ( $e['client_id'] ?? '' ) === $client_id );
    }

    /** deleted_user, remove_user_from_blog: every entry of the user. */
    public static function forget_user( $user_id ): void {
        $user_id = (int) $user_id;
        self::forget_matching( static fn( array $e ): bool => (int) ( $e['user_id'] ?? 0 ) === $user_id );
        $made = (array) get_option( self::CONNECT_OPTION, [] );
        if ( isset( $made[ $user_id ] ) ) {
            unset( $made[ $user_id ] );
            update_option( self::CONNECT_OPTION, $made, false );
        }
    }

    /** OAuth entries (of one user, or all) that no live refresh token backs any more. */
    public static function prune_oauth( ?int $user_id = null ): array {
        $live    = self::live_oauth();
        $entries = self::entries();
        $gone    = [];
        foreach ( $entries as $key => $e ) {
            if ( 'oauth' === ( $e['kind'] ?? '' ) && ( null === $user_id || (int) ( $e['user_id'] ?? 0 ) === $user_id ) && ! isset( $live[ $key ] ) ) {
                $gone[ $key ] = $e;
                unset( $entries[ $key ] );
            }
        }
        if ( $gone ) {
            self::save( $entries );
        }
        return $gone;
    }

    /** @param callable(array<string, mixed>): bool $match */
    private static function forget_matching( callable $match ): void {
        $entries = self::entries();
        $before  = count( $entries );
        foreach ( $entries as $key => $e ) {
            if ( is_array( $e ) && $match( $e ) ) {
                unset( $entries[ $key ] );
            }
        }
        if ( count( $entries ) !== $before ) {
            self::save( $entries );
        }
    }

    /**
     * wp_delete_application_password: whoever deleted it (the profile screen,
     * WP-CLI, the sweep, Disconnect), its entry goes.
     *
     * @param int   $user_id
     * @param array $item
     */
    public static function on_app_password_deleted( $user_id, $item = [] ): void {
        $uuid = is_array( $item ) ? (string) ( $item['uuid'] ?? '' ) : '';
        if ( '' === $uuid ) {
            return;
        }
        self::forget( Connection_Approval::key( 'app_password', $uuid, (int) $user_id ) );
        $made = (array) get_option( self::CONNECT_OPTION, [] );
        if ( isset( $made[ (int) $user_id ] ) && $made[ (int) $user_id ] === $uuid ) {
            unset( $made[ (int) $user_id ] );
            update_option( self::CONNECT_OPTION, $made, false );
        }
    }

    // ── What is on ───────────────────────────────────────────────────────────

    /**
     * App + user pairs with a live refresh token, and until when it lives
     * unused and since when it was connected.
     *
     * @return array<string, array{client_id:string,user_id:int,until:int,since:int}>
     */
    private static function live_oauth(): array {
        $out = [];
        if ( ! class_exists( Token_Store::class ) ) {
            return $out;
        }
        foreach ( Token_Store::all_of( 'refresh' ) as $data ) {
            if ( ! empty( $data['rotated_at'] ) || ! Lifecycle::row_is_live( $data ) ) {
                continue;
            }
            $cid = (string) ( $data['client_id'] ?? '' );
            $uid = (int) ( $data['user_id'] ?? 0 );
            if ( '' === $cid || null === Client_Manager::get( $cid ) ) {
                continue;
            }
            $key   = Connection_Approval::key( 'oauth', $cid, $uid );
            $since = (int) ( $data['since'] ?? 0 );
            $prev  = $out[ $key ] ?? [ 'client_id' => $cid, 'user_id' => $uid, 'until' => 0, 'since' => 0 ];
            $prev['until'] = max( $prev['until'], (int) $data['expires'] );
            if ( $since > 0 && ( 0 === $prev['since'] || $since < $prev['since'] ) ) {
                $prev['since'] = $since;
            }
            $out[ $key ] = $prev;
        }
        return $out;
    }

    /** @return array<int, string> user_id => uuid of the Application Passwords the Connect page made. */
    public static function made_here(): array {
        $out = [];
        foreach ( (array) get_option( self::CONNECT_OPTION, [] ) as $uid => $uuid ) {
            if ( (int) $uid > 0 && is_string( $uuid ) && '' !== $uuid ) {
                $out[ (int) $uid ] = $uuid;
            }
        }
        return $out;
    }

    /**
     * Every connection that is on, most recently used first.
     *
     * @param int|null $user_id Only this user's.
     * @return array<int, array{key:string,kind:string,user_id:int,client_id:string,uuid:string,app:string,operator:string,since:int,last_active:int,ip:string,disconnects_at:int,made_here:bool,approval:string,new:bool}>
     */
    public static function live( ?int $user_id = null ): array {
        $entries = self::entries();
        $rows    = [];

        foreach ( self::live_oauth() as $key => $o ) {
            if ( null !== $user_id && $o['user_id'] !== $user_id ) {
                continue;
            }
            $client = Client_Manager::get( $o['client_id'] );
            $e      = isset( $entries[ $key ] ) && is_array( $entries[ $key ] ) ? $entries[ $key ] : [];
            $since  = array_filter( [ (int) ( $e['first_seen'] ?? 0 ), $o['since'] ] );
            $rows[] = [
                'key'            => $key,
                'kind'           => 'oauth',
                'user_id'        => $o['user_id'],
                'client_id'      => $o['client_id'],
                'uuid'           => '',
                'app'            => is_array( $client ) ? (string) ( $client['name'] ?? '' ) : '',
                'operator'       => Operators::get( $o['client_id'], $o['user_id'] ),
                'since'          => $since ? min( $since ) : 0,
                'last_active'    => (int) ( $e['last_active'] ?? 0 ),
                'ip'             => (string) ( $e['ip'] ?? '' ),
                'disconnects_at' => $o['until'],
                'made_here'      => true,
                'approval'       => self::approval_of( $key, $o['user_id'] ),
                'new'            => false,
            ];
        }

        if ( class_exists( '\WP_Application_Passwords' ) ) {
            $made       = self::made_here();
            $candidates = [];
            foreach ( $made as $uid => $uuid ) {
                $candidates[ $uuid . ':' . $uid ] = [ $uid, $uuid ];
            }
            foreach ( $entries as $e ) {
                if ( is_array( $e ) && 'app_password' === ( $e['kind'] ?? '' ) && '' !== (string) ( $e['app_pass_uuid'] ?? '' ) ) {
                    $candidates[ $e['app_pass_uuid'] . ':' . (int) $e['user_id'] ] = [ (int) $e['user_id'], (string) $e['app_pass_uuid'] ];
                }
            }
            foreach ( $candidates as [ $uid, $uuid ] ) {
                if ( null !== $user_id && $uid !== $user_id ) {
                    continue;
                }
                $item = \WP_Application_Passwords::get_user_application_password( $uid, $uuid );
                if ( ! is_array( $item ) || ! get_userdata( $uid ) ) {
                    continue;
                }
                $key       = Connection_Approval::key( 'app_password', $uuid, $uid );
                $e         = isset( $entries[ $key ] ) && is_array( $entries[ $key ] ) ? $entries[ $key ] : [];
                $wp_last   = (int) ( $item['last_used'] ?? 0 );
                $here_last = (int) ( $e['last_active'] ?? 0 );
                $ours      = isset( $made[ $uid ] ) && $made[ $uid ] === $uuid;
                $off_from  = self::switch_off_from( $uid, $item, $entries );
                $rows[]    = [
                    'key'            => $key,
                    'kind'           => 'app_password',
                    'user_id'        => $uid,
                    'client_id'      => '',
                    'uuid'           => $uuid,
                    'app'            => (string) ( $item['name'] ?? '' ),
                    'operator'       => (string) ( $e['operator'] ?? '' ),
                    'since'          => (int) ( $item['created'] ?? 0 ),
                    'last_active'    => max( $wp_last, $here_last ),
                    'ip'             => $here_last >= $wp_last ? (string) ( $e['ip'] ?? '' ) : (string) ( $item['last_ip'] ?? '' ),
                    'disconnects_at' => null !== $off_from ? $off_from + Lifecycle::idle_seconds() : 0,
                    'made_here'      => $ours,
                    'approval'       => self::approval_of( $key, $uid ),
                    // Made in the last NEW_DAYS days: check that its user made it (2.42.0).
                    'new'            => (int) ( $item['created'] ?? 0 ) >= time() - self::NEW_DAYS * DAY_IN_SECONDS,
                ];
            }
        }

        usort( $rows, static fn( $a, $b ) => [ $b['last_active'], $b['since'] ] <=> [ $a['last_active'], $a['since'] ] );
        return $rows;
    }

    /**
     * When an Application Password was last used: the later of WordPress's own
     * last_used and the last MCP request here, else when it was made.
     *
     * @param array<string, mixed> $item
     */
    private static function idle_from( array $item, int $here_last ): int {
        $last = max( (int) ( $item['last_used'] ?? 0 ), $here_last );
        $last = $last > 0 ? $last : (int) ( $item['created'] ?? 0 );
        // Counted from the update to 2.39.0 at the earliest: one unused for
        // weeks before it gets the same 14 days as a claude.ai sign-in, so
        // nothing switches off on the first night.
        return max( $last, self::counting_since() );
    }

    /** When the 14 days started for connections from before 2.39.0 (the upgrade step's time; 0 = not run). */
    private static function counting_since(): int {
        $done = get_option( Lifecycle::UPGRADES, [] );
        return is_array( $done ) ? (int) ( $done['refresh_cap_239'] ?? 0 ) : 0;
    }

    // ── Application Passwords not made on AI Nexus (2.42.0) ─────────────────

    /** "Also switch off other Application Passwords": 0 when off, else when it was switched on. */
    public static function idle_others_since(): int {
        return max( 0, (int) get_option( self::IDLE_OTHERS, 0 ) );
    }

    /**
     * Switch "Also switch off other Application Passwords" on or off. Switching
     * on starts every such password's idle time now at the earliest, so nothing
     * is switched off the first night. Logged in the activity log.
     */
    public static function set_idle_others( bool $on ): void {
        if ( $on === ( self::idle_others_since() > 0 ) ) {
            return;
        }
        update_option( self::IDLE_OTHERS, $on ? time() : 0, true );
        Logger::log( 'connection_idle_others', [ 'success' => true ], [ [
            'type'   => 'setting',
            'id'     => 0,
            'action' => $on
                ? sprintf( 'other Application Passwords are switched off after %d days without use', Lifecycle::idle_days() )
                : 'other Application Passwords are only listed',
        ] ] );
    }

    /**
     * When an Application Password's idle time started, or null when it never
     * switches off (not made on AI Nexus, and the setting above is off).
     *
     * @param array<string, mixed>                     $item    WordPress's record of it.
     * @param array<string, array<string, mixed>>|null $entries The registry, when already read.
     */
    private static function switch_off_from( int $uid, array $item, ?array $entries = null ): ?int {
        $uuid = (string) ( $item['uuid'] ?? '' );
        if ( $uid <= 0 || '' === $uuid ) {
            return null;
        }
        $ours   = ( self::made_here()[ $uid ] ?? '' ) === $uuid;
        $others = self::idle_others_since();
        if ( ! $ours && 0 === $others ) {
            return null;
        }
        $entries = $entries ?? self::entries();
        $key     = Connection_Approval::key( 'app_password', $uuid, $uid );
        $from    = self::idle_from( $item, (int) ( $entries[ $key ]['last_active'] ?? 0 ) );
        return $ours ? $from : max( $from, $others );
    }

    /**
     * wp_authenticate_application_password_errors: an Application Password
     * whose idle time is over is refused, and deleted as the sweep would
     * have, so a site whose cron does not run still ends it (VT-39).
     *
     * @param \WP_Error $error
     * @param \WP_User  $user
     * @param array     $item
     * @param string    $password
     */
    public static function refuse_idle_app_password( $error, $user = null, $item = [], $password = '' ): void {
        if ( ! $error instanceof \WP_Error || ! $user instanceof \WP_User || ! is_array( $item ) ) {
            return;
        }
        $uid  = (int) $user->ID;
        $from = self::switch_off_from( $uid, $item );
        if ( null === $from || time() - $from < Lifecycle::idle_seconds() ) {
            return;
        }
        $days = Lifecycle::idle_days();
        $error->add(
            'mcp_abilities_idle_app_password',
            /* translators: %d: number of days */
            sprintf( __( 'This Application Password was switched off because it was not used for %d days. Make a new one.', 'mcp-abilities' ), $days ),
            [ 'status' => 401 ]
        );
        $ours = ( self::made_here()[ $uid ] ?? '' ) === (string) $item['uuid'];
        self::remove_idle_app_password( $uid, $item, $ours, 'refused at use' );
    }

    /** Delete an Application Password that went unused for the idle time, and log it. */
    private static function remove_idle_app_password( int $uid, array $item, bool $ours, string $when ): void {
        $uuid = (string) ( $item['uuid'] ?? '' );
        if ( '' === $uuid || ! class_exists( '\WP_Application_Passwords' ) ) {
            return;
        }
        // Its registry entry and the Connect page's record go with it (on_app_password_deleted).
        \WP_Application_Passwords::delete_application_password( $uid, $uuid );
        self::log_event( 'connection_idle_removed', $uid, [
            'type'   => 'connection',
            'id'     => 0,
            'key'    => Connection_Approval::key( 'app_password', $uuid, $uid ),
            'app'    => (string) ( $item['name'] ?? '' ),
            'action' => sprintf(
                'Application Password deleted: not used for %d days%s%s',
                Lifecycle::idle_days(),
                $ours ? '' : ' (not made on AI Nexus; other Application Passwords are switched off too)',
                'sweep' === $when ? '' : ' (' . $when . ')'
            ),
        ] );
    }

    /**
     * The sweep's part for Application Passwords not made on AI Nexus, while
     * the setting is on: every one of this site's users (on a network, the
     * users of this site).
     *
     * @return int How many were deleted.
     */
    private static function sweep_others( int $now, int $idle ): int {
        if ( 0 === self::idle_others_since() || ! class_exists( '\WP_Application_Passwords' ) ) {
            return 0;
        }
        $made    = self::made_here();
        $entries = self::entries();
        $removed = 0;
        $users   = new \WP_User_Query( [
            'meta_key' => '_application_passwords', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'fields'   => 'ID',
            'number'   => -1,
        ] );
        foreach ( (array) $users->get_results() as $uid ) {
            $uid = (int) $uid;
            foreach ( (array) \WP_Application_Passwords::get_user_application_passwords( $uid ) as $item ) {
                if ( ! is_array( $item ) || '' === (string) ( $item['uuid'] ?? '' ) || ( $made[ $uid ] ?? '' ) === (string) $item['uuid'] ) {
                    continue; // The Connect page's own are handled by the sweep above.
                }
                $from = self::switch_off_from( $uid, $item, $entries );
                if ( null !== $from && $now - $from >= $idle ) {
                    self::remove_idle_app_password( $uid, $item, false, 'sweep' );
                    ++$removed;
                }
            }
        }
        return $removed;
    }

    /** '' (approval off), 'exempt', 'approved', 'pending', 'rejected' or 'unknown' (not decided yet). */
    private static function approval_of( string $key, int $user_id ): string {
        if ( ! Connection_Approval::enabled() ) {
            return '';
        }
        if ( Connection_Approval::is_exempt_user( $user_id ) ) {
            return 'exempt';
        }
        $record = Connection_Approval::records()[ $key ] ?? null;
        $status = is_array( $record ) ? (string) ( $record['status'] ?? '' ) : '';
        return in_array( $status, [ 'approved', 'pending', 'rejected' ], true ) ? $status : 'unknown';
    }

    // ── Ending a connection ──────────────────────────────────────────────────

    /** Whether a key has the shape of a connection key. */
    public static function valid_key( string $key ): bool {
        return (bool) preg_match( '/^(oauth|app):[A-Za-z0-9_\-]{1,100}:[1-9][0-9]{0,19}$/', $key );
    }

    /**
     * Switch a connection off: an OAuth app + user loses every code and token
     * (the app has to connect again); an Application Password is deleted.
     *
     * @param string $key   Connection key.
     * @param string $by_whom Words for the activity log ("an administrator", "its user").
     * @return bool False when there is no such live connection.
     */
    public static function disconnect( string $key, string $by_whom ): bool {
        if ( ! self::valid_key( $key ) ) {
            return false;
        }
        [ $kind, $id, $uid ] = explode( ':', $key, 3 );
        $uid                 = (int) $uid;
        $live                = array_column( self::live( $uid ), null, 'key' );
        if ( ! isset( $live[ $key ] ) ) {
            return false;
        }
        $row = $live[ $key ];

        if ( 'oauth' === $kind ) {
            Lifecycle::revoke( $uid, $id, 'disconnected by ' . $by_whom );
        } else {
            \WP_Application_Passwords::delete_application_password( $uid, $id );
            self::forget( $key );
        }
        self::log_event( 'connection_disconnected', $uid, [
            'type'    => 'connection',
            'id'      => 0,
            'key'     => $key,
            'user_id' => $uid,
            'app'     => (string) $row['app'],
            'action'  => sprintf( 'disconnected by %s', $by_whom ),
        ] );
        return true;
    }

    /**
     * One line in the activity log for something that happened to a
     * connection outside a tool call (removed as unused, disconnected,
     * revoked on refresh token reuse).
     *
     * @param array<string, mixed> $object
     */
    public static function log_event( string $what, int $user_id, array $object ): void {
        try {
            $object['user_id'] = $user_id;
            Logger::log( $what, [ 'success' => true ], [ $object ] );
        } catch ( \Throwable $e ) {
            // Never let logging stop the change itself.
        }
    }

    // ── The daily sweep ──────────────────────────────────────────────────────

    /**
     * Cron: switch off what has not been used for Lifecycle::idle_days().
     *
     * @return array{rows:int,oauth:int,app_passwords:int}
     */
    public static function sweep(): array {
        $now  = time();
        $idle = Lifecycle::idle_seconds();
        $days = Lifecycle::idle_days();

        // OAuth: expired rows, and rows whose user, app or password is gone.
        $rows = class_exists( Token_Store::class ) ? Token_Store::delete_where( static function ( string $type, array $data ) use ( $now ): bool {
            if ( (int) ( $data['expires'] ?? 0 ) < $now ) {
                return true;
            }
            return 'tomb' !== $type && '' !== Lifecycle::row_problem( $data );
        } ) : 0;

        // Their entries in the list; a connection that ran out is logged.
        $oauth = 0;
        foreach ( self::prune_oauth() as $key => $e ) {
            ++$oauth;
            self::log_event( 'connection_idle_removed', (int) ( $e['user_id'] ?? 0 ), [
                'type'   => 'connection',
                'id'     => 0,
                'key'    => (string) $key,
                'app'    => (string) ( $e['app'] ?? '' ),
                'action' => sprintf( 'claude.ai sign-in ended: not used for %d days', $days ),
            ] );
        }

        // Application Passwords the Connect page made, unused for the idle time.
        $apps    = 0;
        $made    = (array) get_option( self::CONNECT_OPTION, [] );
        $changed = false;
        $entries = self::entries();
        foreach ( self::made_here() as $uid => $uuid ) {
            $item = class_exists( '\WP_Application_Passwords' ) ? \WP_Application_Passwords::get_user_application_password( $uid, $uuid ) : null;
            if ( ! is_array( $item ) ) {
                unset( $made[ $uid ] );
                $changed = true;
                continue;
            }
            $key  = Connection_Approval::key( 'app_password', $uuid, $uid );
            $here = (int) ( $entries[ $key ]['last_active'] ?? 0 );
            if ( $now - self::idle_from( $item, $here ) < $idle ) {
                continue;
            }
            \WP_Application_Passwords::delete_application_password( $uid, $uuid );
            unset( $made[ $uid ] );
            $changed = true;
            ++$apps;
            self::log_event( 'connection_idle_removed', $uid, [
                'type'   => 'connection',
                'id'     => 0,
                'key'    => $key,
                'app'    => (string) ( $item['name'] ?? '' ),
                'action' => sprintf( 'Application Password deleted: not used for %d days', $days ),
            ] );
        }
        if ( $changed ) {
            // Re-read: deleting fired wp_delete_application_password, which may have written it.
            $now_made = (array) get_option( self::CONNECT_OPTION, [] );
            foreach ( array_keys( $now_made ) as $uid ) {
                if ( ! isset( $made[ $uid ] ) ) {
                    unset( $now_made[ $uid ] );
                }
            }
            update_option( self::CONNECT_OPTION, $now_made, false );
        }

        // Other Application Passwords, when an administrator switched that on (2.42.0).
        $apps += self::sweep_others( $now, $idle );

        // Entries of Application Passwords that no longer exist.
        $entries = self::entries();
        $before  = count( $entries );
        foreach ( $entries as $key => $e ) {
            if ( 'app_password' === ( $e['kind'] ?? '' ) && class_exists( '\WP_Application_Passwords' )
                && ! \WP_Application_Passwords::get_user_application_password( (int) ( $e['user_id'] ?? 0 ), (string) ( $e['app_pass_uuid'] ?? '' ) ) ) {
                unset( $entries[ $key ] );
            }
        }
        if ( count( $entries ) !== $before ) {
            self::save( $entries );
        }

        return [ 'rows' => $rows, 'oauth' => $oauth, 'app_passwords' => $apps ];
    }

    // ── Words and dates for both lists ───────────────────────────────────────

    public static function kind_label( string $kind ): string {
        return 'oauth' === $kind ? __( 'claude.ai sign-in (OAuth)', 'mcp-abilities' ) : __( 'Application Password', 'mcp-abilities' );
    }

    public static function approval_label( string $approval ): string {
        $labels = [
            'exempt'   => __( 'not needed (administrator)', 'mcp-abilities' ),
            'approved' => __( 'approved', 'mcp-abilities' ),
            'pending'  => __( 'waiting for approval', 'mcp-abilities' ),
            'rejected' => __( 'rejected', 'mcp-abilities' ),
            'unknown'  => __( 'not decided yet', 'mcp-abilities' ),
        ];
        return $labels[ $approval ] ?? '';
    }

    public static function when( int $ts ): string {
        return $ts > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
    }

    // ── "Your AI connections" on each user's own profile ─────────────────────

    /** @param \WP_User $user */
    public static function render_profile_section( $user ): void {
        if ( ! $user instanceof \WP_User || (int) $user->ID !== get_current_user_id() ) {
            return;
        }
        $rows = self::live( (int) $user->ID );
        ?>
        <h2 id="mcp-own-connections"><?php esc_html_e( 'Your AI connections', 'mcp-abilities' ); ?></h2>
        <p class="description"><?php
            printf(
                /* translators: %d: number of days */
                esc_html__( 'AI assistants that can use this site with your account. Revoke one and it loses access at once. A connection made on AI Nexus switches itself off after %d days without use.', 'mcp-abilities' ),
                (int) Lifecycle::idle_days()
            );
        ?></p>
        <div class="mcp-table-scroll">
        <table class="widefat striped mcp-own-connections" id="mcp-own-connections-table">
            <thead><tr>
                <th><?php esc_html_e( 'App', 'mcp-abilities' ); ?></th>
                <th><?php esc_html_e( 'Signed in with', 'mcp-abilities' ); ?></th>
                <th><?php esc_html_e( 'Connected since', 'mcp-abilities' ); ?></th>
                <th><?php esc_html_e( 'Last active', 'mcp-abilities' ); ?></th>
                <th><?php esc_html_e( 'Switches off if unused on', 'mcp-abilities' ); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) : ?>
                <tr data-key="<?php echo esc_attr( $r['key'] ); ?>">
                    <td data-label="<?php esc_attr_e( 'App', 'mcp-abilities' ); ?>"><strong><?php echo esc_html( '' !== $r['app'] ? $r['app'] : __( 'Unnamed app', 'mcp-abilities' ) ); ?></strong>
                        <?php if ( '' !== $r['operator'] ) : ?><br><span class="description"><?php echo esc_html( $r['operator'] ); ?></span><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e( 'Signed in with', 'mcp-abilities' ); ?>"><?php echo esc_html( self::kind_label( $r['kind'] ) ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Connected since', 'mcp-abilities' ); ?>"><?php echo esc_html( self::when( $r['since'] ) ?: '—' ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Last active', 'mcp-abilities' ); ?>"><?php echo esc_html( self::when( $r['last_active'] ) ?: __( 'not recorded yet', 'mcp-abilities' ) ); ?>
                        <?php if ( '' !== $r['ip'] ) : ?><br><span class="description"><?php echo esc_html( $r['ip'] ); ?></span><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e( 'Switches off if unused on', 'mcp-abilities' ); ?>"><?php echo esc_html( $r['disconnects_at'] ? self::when( $r['disconnects_at'] ) : __( 'never (not made on AI Nexus)', 'mcp-abilities' ) ); ?></td>
                    <td class="mcp-own-connections__actions">
                        <button type="button" class="button mcp-own-revoke"><?php esc_html_e( 'Revoke', 'mcp-abilities' ); ?></button>
                        <span class="mcp-own-confirm" hidden>
                            <?php esc_html_e( 'Revoke? The assistant loses access at once.', 'mcp-abilities' ); ?>
                            <button type="button" class="button button-primary mcp-own-revoke-yes"><?php esc_html_e( 'Revoke', 'mcp-abilities' ); ?></button>
                            <button type="button" class="button mcp-own-revoke-no"><?php esc_html_e( 'Cancel', 'mcp-abilities' ); ?></button>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr class="mcp-own-empty"<?php echo $rows ? ' hidden' : ''; ?>><td colspan="6"><?php esc_html_e( 'No AI assistant is connected with your account.', 'mcp-abilities' ); ?></td></tr>
            </tbody>
        </table>
        </div>
        <p id="mcp-own-status" class="mcp-own-status" role="status"></p>
        <?php
    }

    public static function enqueue_profile( $hook ): void {
        if ( 'profile.php' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'mcp-abilities-admin', MCP_ABILITIES_URL . 'admin/css/admin.css', [], MCP_ABILITIES_VERSION );
        wp_enqueue_script( 'mcp-abilities-admin', MCP_ABILITIES_URL . 'admin/js/admin.js', [ 'jquery' ], MCP_ABILITIES_VERSION, true );
        wp_localize_script( 'mcp-abilities-admin', 'mcpAbilities', [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => '',
            'ownNonce' => wp_create_nonce( self::NONCE_OWN ),
            'i18n'     => [
                'working' => __( 'Working…', 'mcp-abilities' ),
                'revoked' => __( 'Revoked. That assistant can no longer use this site with your account.', 'mcp-abilities' ),
                'error'   => __( 'That did not work. Reload the page and try again.', 'mcp-abilities' ),
            ],
        ] );
    }

    /** AJAX: a user revokes one of their own connections (nonce, own only). */
    public static function ajax_own_revoke(): void {
        if ( ! check_ajax_referer( self::NONCE_OWN, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'The page has expired. Reload it and try again.', 'mcp-abilities' ) ], 403 );
        }
        $me  = get_current_user_id();
        $key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        if ( ! $me || ! self::valid_key( $key ) || (int) substr( $key, (int) strrpos( $key, ':' ) + 1 ) !== $me ) {
            wp_send_json_error( [ 'message' => __( 'You can only revoke your own connections.', 'mcp-abilities' ) ], 403 );
        }
        $user = wp_get_current_user();
        if ( ! self::disconnect( $key, sprintf( 'its user %s', $user->user_login ) ) ) {
            wp_send_json_error( [ 'message' => __( 'That connection is not on any more.', 'mcp-abilities' ) ] );
        }
        wp_send_json_success( [ 'message' => __( 'Revoked. That assistant can no longer use this site with your account.', 'mcp-abilities' ) ] );
    }

    /** Tests that simulate several requests in one process. */
    public static function reset(): void {
        self::$seen = [];
    }
}
