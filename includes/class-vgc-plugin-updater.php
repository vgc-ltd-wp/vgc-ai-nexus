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

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
        add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 0 );
    }

    /** Fetch this plugin's manifest entry (cached; bypassed on a manual "Check again"). */
    private function entry(): array {
        if ( is_array( $this->entry ) ) {
            return $this->entry;
        }
        // WordPress appends ?force-check=1 when the admin clicks "Check again".
        if ( ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            delete_transient( $this->cache_key );
        } else {
            $cached = get_transient( $this->cache_key );
            if ( is_array( $cached ) ) {
                return $this->entry = $cached;
            }
        }

        $res = wp_remote_get( $this->manifest_url, [
            'timeout' => 15,
            'headers' => [ 'Accept' => 'application/json' ],
        ] );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
            // Cache an empty result briefly so a flaky network doesn't stall every page load.
            set_transient( $this->cache_key, [], 15 * MINUTE_IN_SECONDS );
            return $this->entry = [];
        }
        $data  = json_decode( wp_remote_retrieve_body( $res ), true );
        $entry = ( is_array( $data ) && isset( $data[ $this->slug ] ) && is_array( $data[ $this->slug ] ) )
            ? $data[ $this->slug ]
            : [];

        set_transient( $this->cache_key, $entry, 6 * HOUR_IN_SECONDS );
        return $this->entry = $entry;
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
            'sections'      => (array) ( $info['sections'] ?? [ 'description' => '' ] ),
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
