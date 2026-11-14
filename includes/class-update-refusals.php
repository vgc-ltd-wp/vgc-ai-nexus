<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Updates refused because of their entry in the VGC update channel, said on screen
 * (since 2.44.0, Tasker VT-56). Package_Guard refuses an update whose channel entry
 * is not signed, carries a signature that does not verify, gives no SHA-256 checksum
 * or does not name the package WordPress was given. WordPress then simply does not
 * show that update, and before 2.44.0 only the PHP error log said why.
 *
 *  - Recorded: by core's updater (AI Nexus's own and its extensions'), when it
 *    refuses the update offer, its "View details" download link or its download for
 *    one of those four reasons, and only for a version newer than the installed one.
 *    Not a package address off the channel (that is not the channel's entry), not a
 *    check that could not run (the log has that) and not the Extensions page's
 *    one-click install, which shows its own error at once.
 *  - Stored: one site transient (network-wide on multisite, as updates are), one
 *    record per plugin, at most MAX of them, each kept for a week from when it was
 *    recorded. A record is written only when it is new or different, so the update
 *    list, which WordPress reads on every admin page, does not write each time.
 *  - Cleared: when core's updater later accepts that plugin's entry (VGC published it
 *    again, signed), or the installed version reaches the refused one. A record whose
 *    plugin is not installed any more is not shown.
 *  - Shown: to whoever may update plugins (on multisite: manage_network_plugins, in
 *    the network admin), on the Plugins and Updates screens and on AI Nexus's own
 *    pages; never on the front end. It names the plugin (its installed name, not the
 *    channel's), the refused version and the reason, says that nothing was installed
 *    and to tell VGC. Everything in it is escaped.
 *
 * Recording must never be what breaks the check: every error in here is swallowed,
 * like Package_Guard's own log memory.
 */
final class Update_Refusals {

    /** The site transient that holds the records: slug => record. */
    public const STORE = 'mcp_abilities_refused_updates';

    /** At most this many records; the oldest go first. */
    public const MAX = 20;

    /** How long a record is kept, in seconds (a week). */
    public const TTL = 604800;

    /** The refusals that are recorded: the channel's own entry is at fault. */
    public const CODES = [
        'mcp_abilities_package_unsigned',
        'mcp_abilities_package_signature',
        'mcp_abilities_package_no_hash',
        'mcp_abilities_package_unlisted',
    ];

    /**
     * Record a refusal for the notice, or leave it: only the CODES, and only for a
     * version newer than the installed one. A record of the same plugin whose version
     * the installed one has reached is dropped.
     *
     * @param string    $slug      The plugin's slug in the channel.
     * @param string    $basename  Its plugin file (folder/file.php).
     * @param string    $installed The installed version.
     * @param string    $version   The refused version, as the channel gives it.
     * @param \WP_Error $error     Package_Guard's refusal.
     */
    public static function record( string $slug, string $basename, string $installed, string $version, \WP_Error $error ): void {
        try {
            $records = self::all();
            $changed = self::drop_reached( $records, $slug, $installed );
            $code    = (string) $error->get_error_code();
            if ( '' !== $slug && '' !== $basename && '' !== $version && in_array( $code, self::CODES, true )
                && ( '' === $installed || version_compare( $version, $installed, '>' ) ) ) {
                $data = $error->get_error_data( $code );
                $new  = [
                    'basename' => self::clip( $basename, 200 ),
                    'version'  => self::clip( $version, 40 ),
                    'code'     => $code,
                    'detail'   => self::clip( is_array( $data ) && is_string( $data['detail'] ?? null ) ? $data['detail'] : '', 200 ),
                    'time'     => time(),
                ];
                $old = $records[ $slug ] ?? null;
                if ( ! is_array( $old ) || array_diff_key( $old, [ 'time' => 0 ] ) !== array_diff_key( $new, [ 'time' => 0 ] ) ) {
                    unset( $records[ $slug ] );
                    $records[ $slug ] = $new;
                    if ( count( $records ) > self::MAX ) {
                        uasort( $records, static fn( $a, $b ) => $a['time'] <=> $b['time'] );
                        $records = array_slice( $records, -self::MAX, null, true );
                    }
                    $changed = true;
                }
            }
            if ( $changed ) {
                self::save( $records );
            }
        } catch ( \Throwable $e ) {
            // Saying it on screen must never be what breaks the check.
        }
    }

    /** The plugin's entry was accepted: its record, if any, goes. */
    public static function clear( string $slug ): void {
        try {
            $records = self::all();
            if ( isset( $records[ $slug ] ) ) {
                unset( $records[ $slug ] );
                self::save( $records );
            }
        } catch ( \Throwable $e ) {
            // As in record().
        }
    }

    /**
     * The records kept now (none older than a week), slug => record with the keys
     * basename, version, code, detail and time.
     *
     * @return array<string,array{basename:string,version:string,code:string,detail:string,time:int}>
     */
    public static function all(): array {
        $stored = get_site_transient( self::STORE );
        $out    = [];
        foreach ( is_array( $stored ) ? $stored : [] as $slug => $r ) {
            if ( ! is_string( $slug ) || '' === $slug || ! is_array( $r )
                || ! is_string( $r['basename'] ?? null ) || ! is_string( $r['version'] ?? null )
                || ! in_array( $r['code'] ?? null, self::CODES, true ) || ! is_int( $r['time'] ?? null )
                || $r['time'] <= time() - self::TTL ) {
                continue;
            }
            $out[ $slug ] = [
                'basename' => $r['basename'],
                'version'  => $r['version'],
                'code'     => $r['code'],
                'detail'   => is_string( $r['detail'] ?? null ) ? $r['detail'] : '',
                'time'     => $r['time'],
            ];
        }
        return array_slice( $out, -self::MAX, null, true );
    }

    /**
     * admin_notices / network_admin_notices: the refused updates, for whoever may
     * update plugins, on the Plugins and Updates screens and AI Nexus's own pages.
     */
    public static function admin_notice(): void {
        try {
            $html = self::notice_html();
        } catch ( \Throwable $e ) {
            return;
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in notice_html().
    }

    /** The notice for this screen and user, or ''. */
    public static function notice_html(): string {
        if ( ! is_admin() || ! self::on_its_screens() || ! self::user_may_update() ) {
            return '';
        }
        $records = self::all();
        if ( ! $records ) {
            return '';
        }
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $lines   = '';
        foreach ( $records as $slug => $r ) {
            $plugin = $plugins[ $r['basename'] ] ?? null;
            if ( ! is_array( $plugin ) ) {
                continue; // Not installed (any more).
            }
            $installed = (string) ( $plugin['Version'] ?? '' );
            if ( '' !== $installed && version_compare( $installed, $r['version'], '>=' ) ) {
                continue; // The installed version has reached it.
            }
            $name   = (string) ( $plugin['Name'] ?? '' );
            $lines .= '<p><strong>VGC AI Nexus:</strong> '
                . sprintf(
                    /* translators: 1: plugin name, 2: the version that was refused, 3: why (for example "its entry in the VGC update channel is not signed") */
                    esc_html__( 'The update of %1$s to version %2$s was refused: %3$s.', 'mcp-abilities' ),
                    '<strong>' . esc_html( '' !== $name ? $name : $slug ) . '</strong>',
                    esc_html( $r['version'] ),
                    esc_html( self::reason( $r['code'], $r['detail'] ) )
                )
                . ' ' . esc_html__( 'Nothing was installed; the site keeps the version it has. Tell VGC, so that they can publish the update again.', 'mcp-abilities' )
                . '</p>';
        }
        return '' === $lines ? '' : '<div class="notice notice-warning mcp-update-refused">' . $lines . '</div>';
    }

    /** Why, in plain words, for a recorded code. */
    public static function reason( string $code, string $detail = '' ): string {
        switch ( $code ) {
            case 'mcp_abilities_package_unsigned':
                return __( 'its entry in the VGC update channel is not signed', 'mcp-abilities' );
            case 'mcp_abilities_package_signature':
                return '' !== $detail
                    /* translators: %s: what is wrong with the signature */
                    ? sprintf( __( 'its entry in the VGC update channel does not carry a valid VGC signature (%s)', 'mcp-abilities' ), $detail )
                    : __( 'its entry in the VGC update channel does not carry a valid VGC signature', 'mcp-abilities' );
            case 'mcp_abilities_package_no_hash':
                return __( 'its entry in the VGC update channel gives no valid SHA-256 checksum', 'mcp-abilities' );
            case 'mcp_abilities_package_unlisted':
                return __( 'the package WordPress was given is not the one the VGC update channel lists for it', 'mcp-abilities' );
        }
        return __( 'its entry in the VGC update channel could not be accepted', 'mcp-abilities' );
    }

    /**
     * The Plugins and Updates screens (on multisite those of the network admin, where
     * updates are made) and AI Nexus's own pages.
     */
    private static function on_its_screens(): bool {
        $pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
        if ( in_array( $pagenow, [ 'plugins.php', 'update-core.php' ], true ) ) {
            return ! is_multisite() || is_network_admin();
        }
        if ( is_network_admin() ) {
            return false;
        }
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return 'mcp-abilities' === $page || 0 === strpos( $page, 'mcp-abilities-' );
    }

    /** Whoever may update plugins: on multisite only who manages the network's plugins. */
    private static function user_may_update(): bool {
        return is_multisite() ? current_user_can( 'manage_network_plugins' ) : current_user_can( 'update_plugins' );
    }

    /**
     * Drop this plugin's record when the installed version has reached it.
     *
     * @param array<string,array> $records
     * @return bool Whether one was dropped.
     */
    private static function drop_reached( array &$records, string $slug, string $installed ): bool {
        if ( '' === $installed || ! isset( $records[ $slug ] ) || version_compare( $records[ $slug ]['version'], $installed, '>' ) ) {
            return false;
        }
        unset( $records[ $slug ] );
        return true;
    }

    /** @param array<string,array> $records */
    private static function save( array $records ): void {
        if ( ! $records ) {
            delete_site_transient( self::STORE );
            return;
        }
        set_site_transient( self::STORE, $records, self::TTL );
    }

    /** At most $max characters; '' when the text is not valid UTF-8 (esc_html() would print nothing of it anyway). */
    private static function clip( string $text, int $max ): string {
        return mb_substr( wp_check_invalid_utf8( $text ), 0, $max );
    }
}
