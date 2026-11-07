<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight self-hosted plugin updater.
 *
 * Lets a VGC plugin show the normal WordPress "new version available → Update now"
 * button by reading a PUBLIC JSON manifest (so private source repos stay private and
 * no token is needed on the site). The manifest maps each plugin slug to its latest
 * version + a public download URL for a WordPress-ready zip.
 *
 * Manifest entry shape (keyed by slug):
 *   {
 *     "vgc-ai-nexus": {
 *       "name": "VGC AI Nexus",
 *       "version": "2.11.1",
 *       "download_url": "https://.../vgc-ai-nexus-2.11.1.zip",
 *       "sha256": "<hash of the zip, optional>",
 *       "requires": "6.0", "requires_php": "8.0", "tested": "6.7",
 *       "last_updated": "2026-06-30", "homepage": "https://tools.vgc-ltd.com",
 *       "sections": { "changelog": "<h4>2.11.1</h4>..." }
 *     }
 *   }
 *
 * Usage (from the plugin's main file):
 *   new \MCP_Abilities\VGC_Plugin_Updater( __FILE__, 'vgc-ai-nexus', '2.11.1', $manifest_url );
 */
class VGC_Plugin_Updater {

    private string $file;
    private string $slug;
    private string $basename;
    private string $version;
    private string $manifest_url;
    private string $cache_key;
    /** @var array|null */
    private $entry = null;

    public function __construct( string $file, string $slug, string $version, string $manifest_url ) {
        $this->file         = $file;
        $this->slug         = $slug;
        $this->basename     = plugin_basename( $file );
        $this->version      = $version;
        $this->manifest_url = $manifest_url;
        $this->cache_key    = 'vgc_upd_' . md5( $this->basename );

        // Inject on both the SET (after a check) and the GET (every read) so detection
        // doesn't depend on when WordPress last refreshed its update cache.
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
        add_filter( 'upgrader_pre_download', [ $this, 'guard_package' ], 10, 4 );
        add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 0 );
    }

    /**
     * upgrader_pre_download: an update of THIS plugin only comes from the update
     * channel, and matches the manifest's sha256 when it gives one (Package_Guard).
     * Any other plugin, and an uploaded zip, go past untouched.
     *
     * @param mixed $reply      false, or what an earlier filter decided.
     * @param mixed $package    The package WordPress is about to download.
     * @param mixed $upgrader   The running WP_Upgrader.
     * @param mixed $hook_extra 'plugin' names the plugin being updated.
     * @return mixed false to let WordPress download it, a file path, or a WP_Error.
     */
    public function guard_package( $reply, $package, $upgrader = null, $hook_extra = [] ) {
        if ( false !== $reply || ! is_string( $package ) || '' === $package
            || ! is_array( $hook_extra ) || ( $hook_extra['plugin'] ?? '' ) !== $this->basename ) {
            return $reply;
        }
        try {
            if ( ! Package_Guard::enabled( $package, $this->slug ) ) {
                return $reply;
            }
            $info = $this->entry();
            $name = isset( $info['name'] ) && is_string( $info['name'] ) ? $info['name'] : $this->slug;

            $refused = Package_Guard::url_error( $package, $name );
            if ( null !== $refused ) {
                return $refused;
            }

            // The hash goes with the address it was published for. A cached entry that
            // names another address is older than the package: ask the manifest.
            if ( ! $this->entry_is_for( $info, $package ) ) {
                $info = $this->fresh_entry();
            }
            $expected = $this->entry_is_for( $info, $package ) ? (string) ( $info['sha256'] ?? '' ) : '';

            return Package_Guard::download(
                $package,
                $expected,
                $name,
                $upgrader,
                function () use ( $package ) {
                    $now = $this->fresh_entry();
                    return $this->entry_is_for( $now, $package ) ? (string) ( $now['sha256'] ?? '' ) : '';
                }
            );
        } catch ( \Throwable $e ) {
            // Never let this check be what stops every site updating.
            error_log( 'AI Nexus: the package check stopped with ' . get_class( $e ) . ': ' . $e->getMessage() . '; WordPress downloads the update itself.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return $reply;
        }
    }

    /** @param array<string,mixed> $info A manifest entry. */
    private function entry_is_for( array $info, string $package ): bool {
        return ! empty( $info['download_url'] ) && esc_url_raw( (string) $info['download_url'] ) === $package;
    }

    /**
     * The manifest entry as it is now, skipping the cache. A failed fetch leaves the
     * cached entry alone.
     *
     * @return array<string,mixed>
     */
    private function fresh_entry(): array {
        $entry = $this->fetch_entry();
        if ( null === $entry ) {
            return is_array( $this->entry ) ? $this->entry : [];
        }
        set_transient( $this->cache_key, $entry, HOUR_IN_SECONDS );
        return $this->entry = $entry;
    }

    /**
     * This plugin's entry in the manifest.
     *
     * @return array<string,mixed>|null Null when the manifest could not be read.
     */
    private function fetch_entry(): ?array {
        $res = wp_remote_get( $this->manifest_url, [
            'timeout' => 15,
            'headers' => [ 'Accept' => 'application/json' ],
        ] );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
            return null;
        }
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        return ( is_array( $data ) && isset( $data[ $this->slug ] ) && is_array( $data[ $this->slug ] ) )
            ? $data[ $this->slug ]
            : [];
    }

    /** Fetch this plugin's manifest entry (cached; bypassed on a manual "Check again"). */
    private function entry(): array {
        if ( is_array( $this->entry ) ) {
            return $this->entry;
        }
        // WordPress appends ?force-check=1 when the admin clicks "Check again".
        if ( $this->force_check() ) {
            delete_transient( $this->cache_key );
        } else {
            $cached = get_transient( $this->cache_key );
            if ( is_array( $cached ) ) {
                return $this->entry = $cached;
            }
        }

        $entry = $this->fetch_entry();
        if ( null === $entry ) {
            // Cache an empty result briefly so a flaky network doesn't stall every page load.
            set_transient( $this->cache_key, [], 15 * MINUTE_IN_SECONDS );
            return $this->entry = [];
        }

        set_transient( $this->cache_key, $entry, HOUR_IN_SECONDS );
        return $this->entry = $entry;
    }

    /**
     * Whether this request asks to skip the cache: "Check again" (?force-check=1)
     * on the Updates or Plugins screen, by someone who may update plugins.
     * Before 2.39.0 any visitor could add it to a request that runs admin_init
     * (admin-post.php, admin-ajax.php) and make the site fetch the manifest on
     * every hit (access analysis L3).
     */
    private function force_check(): bool {
        if ( empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return false;
        }
        if ( ! function_exists( 'current_user_can' ) || ! function_exists( 'wp_get_current_user' ) || ! current_user_can( 'update_plugins' ) ) {
            return false;
        }
        $screen = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
        return in_array( $screen, [ 'update-core.php', 'plugins.php', 'update.php' ], true );
    }

    /** Add this plugin to the update transient when a newer version is published. */
    public function inject_update( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }
        $info = $this->entry();
        if ( empty( $info['version'] ) || empty( $info['download_url'] ) ) {
            return $transient;
        }

        $item = (object) [
            'slug'         => $this->slug,
            'plugin'       => $this->basename,
            'new_version'  => (string) $info['version'],
            'package'      => esc_url_raw( $info['download_url'] ),
            'url'          => $info['homepage'] ?? '',
            'tested'       => $info['tested'] ?? '',
            'requires'     => $info['requires'] ?? '',
            'requires_php' => $info['requires_php'] ?? '',
            'icons'        => [],
            'banners'      => [],
        ];

        if ( version_compare( (string) $info['version'], $this->version, '>' ) ) {
            $transient->response[ $this->basename ] = $item;
            unset( $transient->no_update[ $this->basename ] );
        } else {
            // Reported as up to date — keeps the "View details" link working.
            $transient->no_update[ $this->basename ] = $item;
            unset( $transient->response[ $this->basename ] );
        }
        return $transient;
    }

    /** Provide the "View details" modal content. */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }
        $info = $this->entry();
        if ( empty( $info ) ) {
            return $result;
        }
        $sections = (array) ( $info['sections'] ?? [ 'description' => '' ] );

        /**
         * Let the plugin enrich its own "View details" modal — e.g. inject a
         * live summary of what it currently provides. Receives the manifest
         * sections, the plugin slug and the full manifest entry.
         */
        $sections = (array) apply_filters( 'vgc_plugin_updater_sections', $sections, $this->slug, $info );

        return (object) [
            'name'          => $info['name'] ?? $this->slug,
            'slug'          => $this->slug,
            'version'       => $info['version'] ?? $this->version,
            'author'        => $info['author'] ?? 'VGC',
            'homepage'      => $info['homepage'] ?? '',
            'requires'      => $info['requires'] ?? '',
            'requires_php'  => $info['requires_php'] ?? '',
            'tested'        => $info['tested'] ?? '',
            'last_updated'  => $info['last_updated'] ?? '',
            'sections'      => $sections,
            'download_link' => esc_url_raw( $info['download_url'] ?? '' ),
        ];
    }

    /**
     * Ensure the extracted folder is named exactly like the plugin slug.
     * Our published zips already use the correct top folder, so this is a
     * defensive no-op in the normal case (and only ever touches OUR plugin).
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $args = [] ) {
        global $wp_filesystem;
        if ( empty( $args['plugin'] ) || $args['plugin'] !== $this->basename ) {
            return $source;
        }
        $desired = trailingslashit( $remote_source ) . $this->slug;
        if ( untrailingslashit( $source ) === $desired ) {
            return $source; // already correct
        }
        if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
            return trailingslashit( $desired );
        }
        return $source;
    }

    public function flush_cache(): void {
        delete_transient( $this->cache_key );
    }
}
