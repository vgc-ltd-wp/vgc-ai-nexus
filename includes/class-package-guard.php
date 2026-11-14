<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * What AI Nexus accepts as a package from the VGC update channel, for its own
 * update, its extensions' updates (they use this plugin's updater when it is
 * active) and its one-click extension install. WordPress verifies nothing for a
 * plugin that does not come from wordpress.org.
 *
 *  - The package address must start with the channel's release folder. GitHub then
 *    redirects the download to another host, so it is this first address that is
 *    checked, and the file's hash, not where the file ends up coming from.
 *  - The package must be the one the channel's entry for the plugin names, and the
 *    entry must give its sha256 (since 2.43.0; an entry without one used to install).
 *  - The entry's signature (since 2.43.0, security review 2026-11-10 C4). An entry
 *    carries "sig": { "v": 1, "alg": "ed25519", "kid": "<key id>", "value": "<base64>" },
 *    a detached Ed25519 signature made with VGC's update-signing key over
 *      "vgc-plugin-update\nv1\n" . slug . "\n" . version . "\n" . download_url . "\n" . lower-case sha256
 *    (UTF-8, no trailing newline). That key is not kept in the channel repository and
 *    its public half is pinned below, so whoever can change the repository can no
 *    longer put a package on a site. An entry with a signature that does not verify
 *    (a key this site does not know, a changed slug, version, address or hash, another
 *    format) is not offered as an update, gets no download link and is not downloaded.
 *  - An entry without a signature is the transition. 2.43.0 still accepts it (and logs
 *    that once per plugin and version) unless MCP_ABILITIES_REQUIRE_SIGNED_UPDATES is
 *    true. 2.44.0 sets REQUIRE_SIGNATURE to true and refuses every unsigned entry.
 *  - The downloaded file must match the sha256, or it is deleted and nothing is installed.
 *
 * Fail closed (since 2.43.0): only a check that passes lets a package through. When
 * the check itself fails (this code throws, sodium is missing), the download is
 * refused with an error and the log says why. Before 2.43.0 WordPress then downloaded
 * the package unchecked. A site this code wrongly stops has the escape filter below,
 * which an administrator sets on purpose.
 *
 * Filters:
 *  - mcp_abilities_verify_packages (bool, $package, $slug): false skips every check
 *    here for this package: address, entry, hash and signature. The way out.
 *  - mcp_abilities_package_url_prefixes (string[], $package): where packages may
 *    come from, for a site with its own mirror.
 *  - mcp_abilities_update_signing_keys (array, $pinned): for tests. Return key id =>
 *    base64 public key pairs to ADD; a key counts only when its id is the first 16 hex
 *    characters of the sha256 of the raw 32-byte key. It cannot remove or replace a
 *    pinned key. It weakens nothing that was not open already: code that can add a
 *    filter can run any PHP, and can switch the checks off with the escape filter.
 */
final class Package_Guard {

    public const CHANNEL_PREFIX = 'https://github.com/vgc-ltd-wp/vgc-plugin-updates/releases/download/';

    /**
     * VGC's update-signing public keys, pinned: key id (the first 16 hex characters
     * of the sha256 of the raw 32-byte key) => the key in base64. A new key is added
     * here in a release before the channel signs with it.
     */
    public const SIGNING_KEYS = [
        '2eb03b18afeb32d2' => '8cRD3wV+BZmuUxdwPB2PNbKJiAl9vLYvNEJll2w1HCQ=',
    ];

    /**
     * Whether an entry without a signature is refused. False in 2.43.0, the first
     * release of the transition: unsigned entries are still accepted and logged.
     * 2.44.0 flips this to true. A site can refuse them before that with
     * define( 'MCP_ABILITIES_REQUIRE_SIGNED_UPDATES', true ).
     */
    public const REQUIRE_SIGNATURE = false;

    /** What has been logged lately (key => time), so each finding is logged once. */
    public const NOTES = 'mcp_abilities_update_notes';

    /** @var array<string,bool> Signatures checked in this request (each entry is read many times). */
    private static array $verified = [];

    /** @var array<string,bool> What was logged in this request. */
    private static array $noted = [];

    /** Whether the checks apply to this package. */
    public static function enabled( string $package, string $slug ): bool {
        return (bool) apply_filters( 'mcp_abilities_verify_packages', true, $package, $slug );
    }

    /** Whether the address is under an allowed prefix, with nothing that could climb out of it. */
    public static function url_allowed( string $url ): bool {
        // cURL resolves /../ before it asks, so a prefix alone would let
        // .../releases/download/../../../../other-account/ through.
        if ( preg_match( '#/\.\.?(/|$)|%2e|%2f|%5c|\\\\|[\x00-\x20]#i', $url ) ) {
            return false;
        }
        foreach ( (array) apply_filters( 'mcp_abilities_package_url_prefixes', [ self::CHANNEL_PREFIX ], $url ) as $prefix ) {
            // Ending in a slash, so that https://host cannot match https://host.example.net.
            if ( is_string( $prefix ) && '' !== $prefix && 0 === strpos( $url, rtrim( $prefix, '/' ) . '/' ) ) {
                return true;
            }
        }
        return false;
    }

    /** The refusal for an address off the list, or null when it is fine. */
    public static function url_error( string $url, string $name ): ?\WP_Error {
        if ( self::url_allowed( $url ) ) {
            return null;
        }
        return new \WP_Error(
            'mcp_abilities_package_host',
            sprintf(
                /* translators: 1: plugin name, 2: package address, 3: the allowed address */
                __( '%1$s was not installed: its package address (%2$s) is not in the VGC update channel (%3$s). Nothing on the site was changed.', 'mcp-abilities' ),
                esc_html( $name ),
                esc_html( $url ),
                self::CHANNEL_PREFIX
            )
        );
    }

    /**
     * A hash as lower-case hex, or '' when there is none we can use. Since 2.43.0 an
     * entry without a usable hash is refused (entry_error()), a malformed one too.
     *
     * @param mixed $sha256 From the manifest.
     */
    public static function normal_hash( $sha256 ): string {
        if ( ! is_string( $sha256 ) || '' === trim( $sha256 ) ) {
            return '';
        }
        $hash = strtolower( trim( $sha256 ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            error_log( 'AI Nexus: the update channel gives a malformed sha256 (' . substr( $hash, 0, 80 ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return '';
        }
        return $hash;
    }

    /**
     * The keys a signature may be made with: the pinned ones, plus any a test adds
     * through mcp_abilities_update_signing_keys. A pinned key id always keeps its
     * pinned key.
     *
     * @return array<string,string> key id => base64 public key.
     */
    public static function signing_keys(): array {
        $keys  = self::SIGNING_KEYS;
        $added = apply_filters( 'mcp_abilities_update_signing_keys', [], self::SIGNING_KEYS );
        foreach ( is_array( $added ) ? $added : [] as $kid => $public ) {
            $kid = (string) $kid;
            if ( isset( $keys[ $kid ] ) || ! is_string( $public ) ) {
                continue;
            }
            $raw = base64_decode( $public, true );
            if ( is_string( $raw ) && 32 === strlen( $raw ) && hash_equals( substr( hash( 'sha256', $raw ), 0, 16 ), $kid ) ) {
                $keys[ $kid ] = $public;
            }
        }
        return $keys;
    }

    /** Whether an entry without a signature is refused (see REQUIRE_SIGNATURE). */
    public static function signature_required(): bool {
        return self::REQUIRE_SIGNATURE || ( defined( 'MCP_ABILITIES_REQUIRE_SIGNED_UPDATES' ) && MCP_ABILITIES_REQUIRE_SIGNED_UPDATES );
    }

    /** The exact bytes VGC signs for one entry. */
    public static function message( string $slug, string $version, string $download_url, string $sha256 ): string {
        return "vgc-plugin-update\nv1\n" . $slug . "\n" . $version . "\n" . $download_url . "\n" . strtolower( $sha256 );
    }

    /**
     * Why an entry's signature does not verify, or null when it does. Only for an
     * entry that has a "sig". The fields are signed as the manifest gives them.
     *
     * @param array<string,mixed> $entry The manifest entry.
     */
    public static function signature_problem( string $slug, array $entry ): ?string {
        $sig = $entry['sig'] ?? null;
        if ( ! is_array( $sig ) || 1 !== ( $sig['v'] ?? null ) || 'ed25519' !== ( $sig['alg'] ?? null ) ) {
            return __( 'the signature is not in a form this version of AI Nexus knows (v 1, ed25519)', 'mcp-abilities' );
        }
        $kid  = $sig['kid'] ?? null;
        $keys = self::signing_keys();
        if ( ! is_string( $kid ) || '' === $kid || ! isset( $keys[ $kid ] ) ) {
            return sprintf(
                /* translators: %s: the key id the entry names */
                __( 'it is signed with a key this site does not know (%s)', 'mcp-abilities' ),
                is_string( $kid ) ? substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $kid ), 0, 40 ) : gettype( $kid )
            );
        }
        $version = $entry['version'] ?? null;
        $url     = $entry['download_url'] ?? null;
        $sha256  = $entry['sha256'] ?? null;
        if ( ! is_string( $version ) || '' === $version || ! is_string( $url ) || '' === $url || ! is_string( $sha256 ) || '' === $sha256 ) {
            return __( 'the version, address or checksum it signs is missing', 'mcp-abilities' );
        }
        $raw    = is_string( $sig['value'] ?? null ) ? base64_decode( $sig['value'], true ) : false;
        $public = base64_decode( $keys[ $kid ], true );
        if ( ! is_string( $raw ) || 64 !== strlen( $raw ) || ! is_string( $public ) || 32 !== strlen( $public ) ) {
            return __( 'the signature is malformed', 'mcp-abilities' );
        }
        $message = self::message( $slug, $version, $url, $sha256 );
        $memo    = hash( 'sha256', $message . "\n" . $raw . "\n" . $public );
        if ( ! isset( self::$verified[ $memo ] ) ) {
            // Throws when sodium is missing: the caller refuses then.
            self::$verified[ $memo ] = sodium_crypto_sign_verify_detached( $raw, $message, $public );
        }
        return self::$verified[ $memo ] ? null : __( 'the signature does not match the entry', 'mcp-abilities' );
    }

    /**
     * Whether a manifest entry may give this package, or why not: the address is on
     * the channel and is the entry's own, the entry gives a sha256, and its signature
     * verifies (or, during the transition, it has none). Refusals are logged once.
     *
     * @param string $slug    The plugin's slug (its key in the manifest).
     * @param mixed  $entry   The plugin's manifest entry.
     * @param string $package The package address WordPress would download.
     * @param string $name    The plugin's name, for the message.
     */
    public static function entry_error( string $slug, $entry, string $package, string $name ): ?\WP_Error {
        $entry   = is_array( $entry ) ? $entry : [];
        $url     = $entry['download_url'] ?? null;
        $version = is_scalar( $entry['version'] ?? null ) ? (string) $entry['version'] : '';
        $label   = esc_html( $name );

        $refused = self::url_error( $package, $name );
        if ( null !== $refused ) {
            return self::refuse( (string) $refused->get_error_code(), $slug, $version, $refused->get_error_message() );
        }

        if ( ! is_string( $url ) || '' === $url || esc_url_raw( $url ) !== $package ) {
            return self::refuse( 'mcp_abilities_package_unlisted', $slug, $version, sprintf(
                /* translators: 1: plugin name, 2: package address */
                __( '%1$s was not installed: the VGC update channel does not list this package (%2$s) for it, so it cannot be checked. Nothing on the site was changed. Check for updates again, then retry.', 'mcp-abilities' ),
                $label,
                esc_html( $package )
            ) );
        }
        if ( '' === self::normal_hash( $entry['sha256'] ?? null ) ) {
            return self::refuse( 'mcp_abilities_package_no_hash', $slug, $version, sprintf(
                /* translators: %s: plugin name */
                __( '%s was not installed: its entry in the VGC update channel gives no valid SHA-256 checksum, so the download cannot be checked. Nothing on the site was changed. Tell VGC if it keeps happening.', 'mcp-abilities' ),
                $label
            ) );
        }
        if ( null === ( $entry['sig'] ?? null ) ) {
            if ( self::signature_required() ) {
                return self::refuse( 'mcp_abilities_package_unsigned', $slug, $version, sprintf(
                    /* translators: %s: plugin name */
                    __( '%s was not installed: its entry in the VGC update channel is not signed, and this site accepts only signed updates. Nothing on the site was changed. Tell VGC.', 'mcp-abilities' ),
                    $label
                ) );
            }
            self::note_once( 'unsigned|' . $slug . '|' . $version, sprintf( 'the VGC update channel\'s entry for %s %s is not signed; accepted during the transition (AI Nexus 2.44.0 refuses unsigned entries).', $slug, $version ) );
            return null;
        }
        $problem = self::signature_problem( $slug, $entry );
        if ( null !== $problem ) {
            return self::refuse( 'mcp_abilities_package_signature', $slug, $version, sprintf(
                /* translators: 1: plugin name, 2: what is wrong with the signature */
                __( '%1$s was not installed: its entry in the VGC update channel does not carry a valid VGC signature (%2$s). Nothing on the site was changed. Tell VGC.', 'mcp-abilities' ),
                $label,
                $problem
            ) );
        }
        return null;
    }

    /**
     * The refusal when the check itself failed: nothing is downloaded unchecked.
     */
    public static function check_failed( \Throwable $e, string $slug, string $name ): \WP_Error {
        self::note_once( 'threw|' . $slug . '|' . get_class( $e ) . '|' . $e->getMessage(), sprintf( 'the package check for %s stopped with %s: %s; the package is refused.', $slug, get_class( $e ), $e->getMessage() ) );
        return new \WP_Error(
            'mcp_abilities_package_check',
            sprintf(
                /* translators: %s: plugin name */
                __( '%s was not installed: the package check could not be completed, so nothing was downloaded. Nothing on the site was changed. The PHP error log has the details.', 'mcp-abilities' ),
                esc_html( $name )
            )
        );
    }

    /**
     * For upgrader_pre_download: download the package as WordPress would, and hand
     * over the file only if its hash is the expected one. Without a usable hash
     * nothing is downloaded.
     *
     * @param string        $url      The package address, already checked.
     * @param string        $expected The sha256 of an entry entry_error() accepted.
     * @param string        $name     The plugin's name, for the message.
     * @param mixed         $upgrader The running WP_Upgrader.
     * @param callable|null $refresh  Gives the sha256 of the manifest's entry as it is
     *                                now, '' unless entry_error() accepts that entry;
     *                                tried once on a mismatch: a cached hash can be
     *                                older than a package that was published again.
     * @return string|\WP_Error Path of the downloaded file.
     */
    public static function download( string $url, string $expected, string $name, $upgrader = null, ?callable $refresh = null ) {
        $expected = self::normal_hash( $expected );
        if ( '' === $expected ) {
            return new \WP_Error(
                'mcp_abilities_package_no_hash',
                sprintf(
                    /* translators: %s: plugin name */
                    __( '%s was not installed: its entry in the VGC update channel gives no valid SHA-256 checksum, so the download cannot be checked. Nothing on the site was changed. Tell VGC if it keeps happening.', 'mcp-abilities' ),
                    esc_html( $name )
                )
            );
        }
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) ) {
            $upgrader->skin->feedback( 'downloading_package', $url );
        }

        $file = download_url( $url, 300 );
        if ( is_wp_error( $file ) ) {
            $text = is_object( $upgrader ) && ! empty( $upgrader->strings['download_failed'] ) ? $upgrader->strings['download_failed'] : __( 'Download failed.', 'mcp-abilities' );
            return new \WP_Error( 'download_failed', $text, $file->get_error_message() );
        }

        $actual = (string) hash_file( 'sha256', $file );
        if ( hash_equals( $expected, $actual ) ) {
            return $file;
        }
        if ( null !== $refresh ) {
            $now = self::normal_hash( $refresh() );
            if ( '' !== $now && hash_equals( $now, $actual ) ) {
                return $file;
            }
        }

        @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
        return new \WP_Error(
            'mcp_abilities_package_hash',
            sprintf(
                /* translators: 1: plugin name, 2: the checksum published, 3: the checksum of the download */
                __( '%1$s was not installed: the download does not match the checksum published in the VGC update channel (published SHA-256 %2$s, downloaded %3$s). Nothing on the site was changed. Try again later, and tell VGC if it keeps happening.', 'mcp-abilities' ),
                esc_html( $name ),
                $expected,
                $actual
            )
        );
    }

    /** A refusal, logged once per plugin, version and reason. */
    private static function refuse( string $code, string $slug, string $version, string $message ): \WP_Error {
        self::note_once( $code . '|' . $slug . '|' . $version, sprintf( 'refused %s %s from the update channel (%s). %s', $slug, $version, $code, wp_strip_all_tags( $message ) ) );
        return new \WP_Error( $code, $message );
    }

    /**
     * One line in the PHP error log, once: the same finding is not logged again for a
     * week. The offer is checked on every read of WordPress's update list, so without
     * this one bad entry would write a line on every admin page.
     */
    private static function note_once( string $key, string $line ): void {
        $key = md5( $key );
        if ( isset( self::$noted[ $key ] ) ) {
            return;
        }
        self::$noted[ $key ] = true;
        try {
            $notes = get_transient( self::NOTES );
            $notes = is_array( $notes ) ? $notes : [];
            if ( isset( $notes[ $key ] ) ) {
                return;
            }
            $notes[ $key ] = time();
            if ( count( $notes ) > 100 ) {
                asort( $notes );
                $notes = array_slice( $notes, -100, null, true );
            }
            set_transient( self::NOTES, $notes, WEEK_IN_SECONDS );
        } catch ( \Throwable $e ) {
            // Logging must never be what stops the check; log without the memory.
        }
        error_log( 'AI Nexus: ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
