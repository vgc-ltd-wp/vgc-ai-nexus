<?php
namespace MCP_Abilities\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One-click installer for the curated VGC AI Nexus extensions.
 *
 * Reads a PUBLIC curated list (extensions.json) — only slugs on that list can be
 * installed — and resolves each download URL from the same public update manifest
 * (plugins.json). Installs via WordPress's own Plugin_Upgrader. No arbitrary URLs.
 */
class Extension_Installer {

    const CATALOG_URL  = 'https://raw.githubusercontent.com/vgc-ltd-wp/vgc-plugin-updates/main/extensions.json';
    const MANIFEST_URL = 'https://raw.githubusercontent.com/vgc-ltd-wp/vgc-plugin-updates/main/plugins.json';
    const CACHE_KEY    = 'mcp_abilities_ext_catalog';

    public function init(): void {
        add_action( 'wp_ajax_mcp_install_extension', [ $this, 'ajax_install' ] );
    }

    /** Curated catalog (slug => {name, description, icon, requires}). */
    public function catalog(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $catalog  = (array) $this->fetch_json( self::CATALOG_URL );
        $manifest = (array) $this->fetch_json( self::MANIFEST_URL );

        // Merge in version + download_url from the update manifest.
        foreach ( $catalog as $slug => &$entry ) {
            $entry['version']      = $manifest[ $slug ]['version'] ?? '';
            $entry['download_url'] = $manifest[ $slug ]['download_url'] ?? '';
            $entry['sha256']       = $manifest[ $slug ]['sha256'] ?? '';
        }
        unset( $entry );

        set_transient( self::CACHE_KEY, $catalog, 6 * HOUR_IN_SECONDS );
        return $catalog;
    }

    /**
     * Curated extensions with install state, for rendering. Excludes any that are
     * already active (those already appear as managed extensions).
     *
     * @return array<int,array>
     */
    public function get_installable(): array {
        $out = [];
        foreach ( $this->catalog() as $slug => $entry ) {
            $state = $this->state( $slug );
            if ( 'active' === $state ) {
                continue;
            }
            if ( empty( $entry['download_url'] ) ) {
                continue;
            }
            $out[] = array_merge( [ 'slug' => $slug, 'state' => $state ], $entry );
        }
        return $out;
    }

    /** not_installed | inactive | active */
    public function state( string $slug ): string {
        $file = $this->plugin_file( $slug );
        if ( '' === $file ) {
            return 'not_installed';
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active( $file ) ? 'active' : 'inactive';
    }

    /** The installed plugin file (e.g. "slug/slug.php") for a folder slug, or ''. */
    private function plugin_file( string $slug ): string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ( array_keys( get_plugins() ) as $file ) {
            if ( 0 === strpos( $file, $slug . '/' ) ) {
                return $file;
            }
        }
        return '';
    }

    private function fetch_json( string $url ) {
        $res = wp_remote_get( $url, [ 'timeout' => 15, 'headers' => [ 'Accept' => 'application/json' ] ] );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
            return [];
        }
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        return is_array( $data ) ? $data : [];
    }

    // ── AJAX: install (or activate) a curated extension ───────────────────────

    public function ajax_install(): void {
        check_ajax_referer( 'mcp_abilities_nonce', 'nonce' );
        if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to install plugins.', 'mcp-abilities' ) ] );
        }

        $slug    = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
        $catalog = $this->catalog();
        if ( '' === $slug || ! isset( $catalog[ $slug ] ) ) {
            wp_send_json_error( [ 'message' => __( 'That extension is not in the approved list.', 'mcp-abilities' ) ] );
        }

        // Already installed → just activate.
        $existing = $this->plugin_file( $slug );
        if ( '' !== $existing ) {
            $activated = activate_plugin( $existing );
            if ( is_wp_error( $activated ) ) {
                wp_send_json_error( [ 'message' => $activated->get_error_message() ] );
            }
            wp_send_json_success( [ 'message' => __( 'Extension activated.', 'mcp-abilities' ), 'slug' => $slug ] );
        }

        // The manifest as it is now: the catalog is cached for hours, and a sha256 goes
        // with the address it was published for.
        $fresh   = (array) $this->fetch_json( self::MANIFEST_URL );
        $source  = ! empty( $fresh[ $slug ]['download_url'] ) ? (array) $fresh[ $slug ] : (array) $catalog[ $slug ];
        $package = esc_url_raw( (string) ( $source['download_url'] ?? '' ) );
        if ( '' === $package ) {
            wp_send_json_error( [ 'message' => __( 'No download is available for this extension yet.', 'mcp-abilities' ) ] );
        }
        $name = (string) ( $catalog[ $slug ]['name'] ?? $slug );

        // Only from the update channel, and matching the manifest's sha256 if it gives one.
        $guarded = \MCP_Abilities\Package_Guard::enabled( $package, $slug );
        if ( $guarded ) {
            $refused = \MCP_Abilities\Package_Guard::url_error( $package, $name );
            if ( null !== $refused ) {
                wp_send_json_error( [ 'message' => $refused->get_error_message() ] );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );

        // This one download only. The skin keeps no errors, so the refusal is kept
        // here: the message below would otherwise blame the file system.
        $download_error = null;
        $check          = static function ( $reply, $url, $running = null ) use ( $package, $name, $source, &$download_error ) {
            if ( false !== $reply || $url !== $package ) {
                return $reply;
            }
            try {
                $file = \MCP_Abilities\Package_Guard::download( $package, (string) ( $source['sha256'] ?? '' ), $name, $running );
            } catch ( \Throwable $e ) {
                error_log( 'AI Nexus: the package check stopped with ' . get_class( $e ) . ': ' . $e->getMessage() . '; WordPress downloads the extension itself.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                return $reply;
            }
            if ( is_wp_error( $file ) ) {
                $download_error = $file;
            }
            return $file;
        };
        if ( $guarded ) {
            add_filter( 'upgrader_pre_download', $check, 10, 3 );
        }
        $result = $upgrader->install( $package );
        remove_filter( 'upgrader_pre_download', $check, 10 );

        if ( is_wp_error( $download_error ) ) {
            wp_send_json_error( [ 'message' => $download_error->get_error_message() ] );
        }
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        if ( true !== $result ) {
            $errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
            $msg    = ( is_wp_error( $errors ) && $errors->get_error_message() )
                ? $errors->get_error_message()
                : __( 'Installation failed (the server filesystem may not be writable).', 'mcp-abilities' );
            wp_send_json_error( [ 'message' => $msg ] );
        }

        $file = $this->plugin_file( $slug );
        if ( '' === $file ) {
            wp_send_json_error( [ 'message' => __( 'Installed, but the plugin file could not be located to activate.', 'mcp-abilities' ) ] );
        }
        $activated = activate_plugin( $file );
        if ( is_wp_error( $activated ) ) {
            wp_send_json_error( [ 'message' => __( 'Installed, but activation failed: ', 'mcp-abilities' ) . $activated->get_error_message() ] );
        }

        delete_transient( self::CACHE_KEY );
        wp_send_json_success( [ 'message' => __( 'Extension installed and activated.', 'mcp-abilities' ), 'slug' => $slug ] );
    }
}
