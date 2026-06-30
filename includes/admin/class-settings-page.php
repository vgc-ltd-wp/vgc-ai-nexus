<?php
namespace MCP_Abilities\Admin;

use MCP_Abilities\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Pages
 *
 * Adds "Abilities" as a top-level sidebar menu with two subpages:
 *   – Abilities   : group/ability enable-disable (main settings)
 *   – Extensions  : enable-disable for sub-plugins (WooCommerce etc.)
 */
class Settings_Page {

    private const MENU_SLUG = 'mcp-abilities';
    private const EXTS_SLUG = 'mcp-abilities-extensions';

    /** Hook suffixes returned by add_*_page() — used for asset gating. */
    private string $abilities_hook   = '';
    private string $extensions_hook  = '';

    /** Hook suffix => extension id, for the per-extension subpages. */
    private array $subpage_hooks = [];

    /** Cached result of the mcp_abilities_extensions filter. */
    private ?array $extensions = null;

    public function __construct( private Registry $registry ) {}

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_mcp_save_settings',           [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_mcp_save_extension_settings', [ $this, 'ajax_save_extension_settings' ] );
        // Keep the AI Nexus → Extensions menu highlighted while on a hidden subpage.
        add_filter( 'parent_file',  [ $this, 'highlight_parent_menu' ] );
        add_filter( 'submenu_file', [ $this, 'highlight_submenu' ] );
    }

    /** @param string $parent_file */
    public function highlight_parent_menu( $parent_file ): string {
        return $this->on_extension_subpage() ? self::MENU_SLUG : (string) $parent_file;
    }

    /** @param string|null $submenu_file */
    public function highlight_submenu( $submenu_file ): ?string {
        return $this->on_extension_subpage() ? self::EXTS_SLUG : $submenu_file;
    }

    private function on_extension_subpage(): bool {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return '' !== $page && 0 === strpos( $page, self::EXTS_SLUG . '-' );
    }

    // ── Menu registration ───────────────────────────────────────────────────

    public function add_menu(): void {
        // Top-level entry (renders the Abilities page).
        $this->abilities_hook = add_menu_page(
            __( 'VGC AI Nexus', 'mcp-abilities' ),
            __( 'AI Nexus', 'mcp-abilities' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_abilities_page' ],
            $this->menu_icon(),
            82
        );

        // Rename the auto-created first submenu item.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'VGC AI Nexus', 'mcp-abilities' ),
            __( 'Abilities', 'mcp-abilities' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_abilities_page' ]
        );

        // Extensions overview sub-page.
        $this->extensions_hook = add_submenu_page(
            self::MENU_SLUG,
            __( 'Extensions – VGC AI Nexus', 'mcp-abilities' ),
            __( 'Extensions', 'mcp-abilities' ),
            'manage_options',
            self::EXTS_SLUG,
            [ $this, 'render_extensions_page' ]
        );

        // One subpage per installed extension. Hidden from the menu (parent null)
        // but reachable from the overview cards; each shows that extension's
        // usage guide + toggles.
        foreach ( $this->get_extensions() as $extension ) {
            $ext_id = sanitize_key( $extension['id'] ?? '' );
            if ( '' === $ext_id ) {
                continue;
            }
            $hook = add_submenu_page(
                null, // hidden from sidebar — linked from the overview
                sprintf( /* translators: %s: extension name */ __( '%s – VGC AI Nexus', 'mcp-abilities' ), $extension['label'] ?? $ext_id ),
                $extension['label'] ?? $ext_id,
                'manage_options',
                self::EXTS_SLUG . '-' . $ext_id,
                [ $this, 'render_extension_subpage' ]
            );
            if ( $hook ) {
                $this->subpage_hooks[ $hook ] = $ext_id;
            }
        }
    }

    /** Admin URL for an extension's own subpage. */
    public static function subpage_url( string $ext_id ): string {
        return admin_url( 'admin.php?page=' . self::EXTS_SLUG . '-' . sanitize_key( $ext_id ) );
    }

    /** Admin URL for the extensions overview. */
    public static function overview_url(): string {
        return admin_url( 'admin.php?page=' . self::EXTS_SLUG );
    }

    private function menu_icon(): string {
        // Simple diamond with a centre dot — works as a white icon against WP's dark sidebar.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
             . '<path d="M10 1L19 10L10 19L1 10Z" fill="black"/>'
             . '<circle cx="10" cy="10" r="2.8" fill="white"/>'
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    // ── Asset enqueuing ─────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->abilities_hook
            && $hook !== $this->extensions_hook
            && ! isset( $this->subpage_hooks[ $hook ] ) ) {
            return;
        }

        wp_enqueue_style(
            'mcp-abilities-admin',
            MCP_ABILITIES_URL . 'admin/css/admin.css',
            [],
            MCP_ABILITIES_VERSION
        );
        wp_enqueue_script(
            'mcp-abilities-admin',
            MCP_ABILITIES_URL . 'admin/js/admin.js',
            [ 'wp-util', 'jquery' ],
            MCP_ABILITIES_VERSION,
            true
        );
        wp_localize_script( 'mcp-abilities-admin', 'mcpAbilities', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mcp_abilities_nonce' ),
            'i18n'    => [
                'saving' => __( 'Saving…',               'mcp-abilities' ),
                'saved'  => __( 'Saved!',                'mcp-abilities' ),
                'error'  => __( 'Error saving settings.', 'mcp-abilities' ),
                'save'   => __( 'Save Settings',         'mcp-abilities' ),
            ],
        ] );
    }

    // ── Page renderers ──────────────────────────────────────────────────────

    public function render_abilities_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ) );
        }
        $groups = $this->registry->get_groups();
        include MCP_ABILITIES_DIR . 'admin/views/settings-page.php';
    }

    public function render_extensions_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ) );
        }
        $extensions = $this->get_extensions();
        include MCP_ABILITIES_DIR . 'admin/views/extensions-page.php';
    }

    public function render_extension_subpage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ) );
        }

        $page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $ext_id = str_replace( self::EXTS_SLUG . '-', '', $page );

        $extension = null;
        foreach ( $this->get_extensions() as $ext ) {
            if ( sanitize_key( $ext['id'] ?? '' ) === $ext_id ) {
                $extension = $ext;
                break;
            }
        }

        if ( ! $extension ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Extension not found', 'mcp-abilities' ) . '</h1><p>'
                . '<a href="' . esc_url( self::overview_url() ) . '">' . esc_html__( '← Back to Extensions', 'mcp-abilities' ) . '</a></p></div>';
            return;
        }

        include MCP_ABILITIES_DIR . 'admin/views/extension-subpage.php';
    }

    // ── AJAX handlers ───────────────────────────────────────────────────────

    public function ajax_save_settings(): void {
        check_ajax_referer( 'mcp_abilities_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $raw = isset( $_POST['settings'] )
            ? (array) json_decode( stripslashes( $_POST['settings'] ), true )
            : [];
        $this->registry->save_settings( $raw );

        // Site-wide consolidation toggle (collapse each group into one tool).
        if ( isset( $_POST['consolidate'] ) ) {
            update_option( 'mcp_abilities_consolidate', '1' === (string) $_POST['consolidate'] );
        }

        wp_send_json_success( [ 'message' => __( 'Settings saved.', 'mcp-abilities' ) ] );
    }

    public function ajax_save_extension_settings(): void {
        check_ajax_referer( 'mcp_abilities_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $ext_id = sanitize_key( $_POST['extension_id'] ?? '' );
        $raw    = isset( $_POST['settings'] )
            ? (array) json_decode( stripslashes( $_POST['settings'] ), true )
            : [];

        // Verify the extension is registered and retrieve its option key.
        $extension = null;
        foreach ( $this->get_extensions() as $ext ) {
            if ( ( $ext['id'] ?? '' ) === $ext_id ) {
                $extension = $ext;
                break;
            }
        }

        if ( ! $extension || empty( $extension['option_key'] ) ) {
            wp_send_json_error( 'Unknown extension.' );
        }

        // Reject option keys that could be used to overwrite sensitive WordPress
        // core options, even if a filter-registered extension tried to set one.
        $option_key = sanitize_key( $extension['option_key'] );
        $core_protected = [
            'active_plugins', 'active_sitewide_plugins', 'siteurl', 'home',
            'admin_email', 'template', 'stylesheet', 'current_theme',
            'default_role', 'users_can_register', 'upload_path', 'upload_url_path',
            'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
            'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
            'cron', 'doing_cron', 'mcp_abilities_settings',
        ];
        if ( in_array( $option_key, $core_protected, true ) ) {
            wp_send_json_error( 'Option key is not allowed.' );
        }

        update_option( $option_key, $raw );
        wp_send_json_success( [ 'message' => __( 'Settings saved.', 'mcp-abilities' ) ] );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Returns all registered extensions via the mcp_abilities_extensions filter.
     * Result is cached for the lifetime of the request.
     *
     * Each extension must be an array with keys:
     *   id          (string)  — unique slug, e.g. 'woocommerce'
     *   label       (string)  — display name
     *   description (string)
     *   icon        (string)  — dashicons class, e.g. 'dashicons-cart'
     *   groups      (Ability_Group[])
     *   option_key  (string)  — WP option name for this extension's settings
     *   guide       (string)  — OPTIONAL intro shown atop the extension subpage
     *   examples    (string[])— OPTIONAL example prompts shown under the guide
     *
     * Per-group usage content is read from each Ability_Group via get_guide()
     * and get_examples(); set $this->guide / $this->examples in the group's
     * define_meta() to populate the "What you can do" panel.
     *
     * @return array[]
     */
    private function get_extensions(): array {
        if ( null === $this->extensions ) {
            $this->extensions = (array) apply_filters( 'mcp_abilities_extensions', [] );
        }
        return $this->extensions;
    }
}
