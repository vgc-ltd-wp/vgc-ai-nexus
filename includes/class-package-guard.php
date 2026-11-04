<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * What AI Nexus accepts as a package from the VGC update channel, for its own
 * update and its one-click extension install. WordPress verifies nothing for a
 * plugin that does not come from wordpress.org.
 *
 *  - The package address must start with the channel's release folder. GitHub then
 *    redirects the download to another host, so it is this first address that is
 *    checked, and the file's hash, not where the file ends up coming from.
 *  - When the manifest gives a sha256, the downloaded file must match it, or it is
 *    deleted and nothing is installed. An entry without a sha256 still installs.
 *
 * What this stops: a poisoned WordPress update cache or stale manifest pointing at
 * another address, a package changed on its way or in storage. What it does not
 * stop: a compromise of the channel repository itself. The hash sits in the same
 * manifest as the address, so whoever can change one can change the other. That
 * would take a signature made with a key the repository does not hold.
 *
 * A bug here would stop updates on every site, with no way to fix it remotely, so
 * only a positive finding refuses: an address off the list, or a hash that does not
 * match. Anything else (no manifest, no hash, a malformed hash) goes ahead as before,
 * and the callers let WordPress do the download itself if this code throws.
 *
 * Filters:
 *  - mcp_abilities_verify_packages (bool, $package, $slug): false skips both checks
 *    for this package. The way out for a site this code wrongly stops.
 *  - mcp_abilities_package_url_prefixes (string[], $package): where packages may
 *    come from, for a site with its own mirror.
 */
final class Package_Guard {

    public const CHANNEL_PREFIX = 'https://github.com/vgc-ltd-wp/vgc-plugin-updates/releases/download/';

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
                $name,
                esc_html( $url ),
                self::CHANNEL_PREFIX
            )
        );
    }

    /**
     * A hash as lower-case hex, or '' when there is none we can use. A malformed one
     * counts as none: refusing it would stop updates over a typo in the manifest, and
     * leaving it out of the manifest would have the same effect anyway.
     *
     * @param mixed $sha256 From the manifest.
     */
    public static function normal_hash( $sha256 ): string {
        if ( ! is_string( $sha256 ) || '' === trim( $sha256 ) ) {
            return '';
        }
        $hash = strtolower( trim( $sha256 ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            error_log( 'AI Nexus: the update channel gives a malformed sha256 (' . substr( $hash, 0, 80 ) . '); going on without it.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return '';
        }
        return $hash;
    }

    /**
     * For upgrader_pre_download: download the package as WordPress would, and hand
     * over the file only if its hash is the expected one.
     *
     * @param string        $url      The package address, already checked.
     * @param string        $expected The manifest's sha256, '' for none.
     * @param string        $name     The plugin's name, for the message.
     * @param mixed         $upgrader The running WP_Upgrader.
     * @param callable|null $refresh  Gives the manifest's sha256 as it is now, tried
     *                                once on a mismatch: a cached hash can be older
     *                                than a package that was published again.
     * @return string|\WP_Error Path of the downloaded file.
     */
    public static function download( string $url, string $expected, string $name, $upgrader = null, ?callable $refresh = null ) {
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

        $expected = self::normal_hash( $expected );
        if ( '' === $expected ) {
            return $file;
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
                $name,
                $expected,
                $actual
            )
        );
    }
}
