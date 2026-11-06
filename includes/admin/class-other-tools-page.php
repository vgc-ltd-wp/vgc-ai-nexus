<?php
namespace MCP_Abilities\Admin;

use MCP_Abilities\Other_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * AI Nexus → Other AI tools: the MCP servers on this site, other plugins' tools
 * an AI can reach, other MCP-like addresses, and what happens to new ones. Each
 * can be turned off for AI assistants. See MCP_Abilities\Other_Tools.
 */
class Other_Tools_Page {

    const PAGE_SLUG = Other_Tools::PAGE_SLUG;

    private string $hook = '';

    /** @var array|null Other_Tools::inventory() for this page view. */
    private ?array $inventory = null;

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_mcp_save_other_tools', [ $this, 'ajax_save' ] );
    }

    public function add_submenu(): void {
        $hook       = add_submenu_page(
            'mcp-abilities',
            __( 'Other AI tools – VGC AI Nexus', 'mcp-abilities' ),
            __( 'Other AI tools', 'mcp-abilities' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render' ]
        );
        $this->hook = is_string( $hook ) ? $hook : '';
        if ( '' !== $this->hook ) {
            add_action( 'load-' . $this->hook, [ $this, 'prepare' ] );
        }
    }

    /**
     * Before any output: the adapter builds its servers only when the REST API
     * starts, so it is started here, on this page only. What is shown counts as
     * seen (see Other_Tools::mark_seen()).
     */
    public function prepare(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $this->inventory = Other_Tools::inventory();
        Other_Tools::mark_seen( $this->inventory );
    }

    public function enqueue_assets( string $hook ): void {
        if ( '' === $this->hook || $hook !== $this->hook ) {
            return;
        }
        wp_enqueue_style( 'mcp-abilities-admin', MCP_ABILITIES_URL . 'admin/css/admin.css', [], MCP_ABILITIES_VERSION );
        wp_enqueue_script( 'mcp-abilities-admin', MCP_ABILITIES_URL . 'admin/js/admin.js', [ 'jquery' ], MCP_ABILITIES_VERSION, true );
        wp_localize_script( 'mcp-abilities-admin', 'mcpAbilities', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mcp_abilities_nonce' ),
            'i18n'    => [
                'saving' => __( 'Saving…', 'mcp-abilities' ),
                'saved'  => __( 'Saved!', 'mcp-abilities' ),
                'error'  => __( 'Error saving settings.', 'mcp-abilities' ),
                'save'   => __( 'Save Settings', 'mcp-abilities' ),
                'on'     => __( 'On', 'mcp-abilities' ),
                'off'    => __( 'Off', 'mcp-abilities' ),
            ],
        ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ) );
        }
        $inventory = $this->inventory ?? Other_Tools::inventory();
        $settings  = Other_Tools::settings();
        $read_only = \MCP_Abilities\Read_Only::active();
        $security  = \MCP_Abilities\Security_Status::other_ai_lines( true );
        include MCP_ABILITIES_DIR . 'admin/views/other-tools-page.php';
    }

    /** Save the page's switches. Administrators only, with the page's nonce. */
    public function ajax_save(): void {
        check_ajax_referer( 'mcp_abilities_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'mcp-abilities' ) ], 403 );
        }
        $raw = isset( $_POST['settings'] ) && is_string( $_POST['settings'] ) ? json_decode( wp_unslash( $_POST['settings'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, checked item by item against the inventory
        if ( ! is_array( $raw ) ) {
            wp_send_json_error( [ 'message' => __( 'Nothing to save.', 'mcp-abilities' ) ], 400 );
        }
        Other_Tools::apply_admin_changes( $raw, Other_Tools::inventory() );
        wp_send_json_success( [ 'message' => __( 'Saved.', 'mcp-abilities' ) ] );
    }

    // ── Words for the view ───────────────────────────────────────────────────

    /** How an AI reaches a tool, in plain words. */
    public static function way_label( array $way ): string {
        $server = (string) ( $way['server'] ?? '' );
        switch ( $way['kind'] ?? '' ) {
            case 'execute':
                return __( "through AI Nexus's execute tool", 'mcp-abilities' );
            case 'direct':
                return __( "a tool on AI Nexus's server", 'mcp-abilities' );
            case 'server':
                /* translators: %s: MCP server name */
                return sprintf( __( 'on the server %s', 'mcp-abilities' ), $server );
            case 'resource':
                /* translators: %s: MCP server name */
                return sprintf( __( 'a resource on %s', 'mcp-abilities' ), $server );
            case 'prompt':
                /* translators: %s: MCP server name */
                return sprintf( __( 'a prompt on %s', 'mcp-abilities' ), $server );
            case 'rest':
                return __( 'the Abilities REST route', 'mcp-abilities' );
        }
        return '';
    }

    /** "Reads", "Changes" or "Changes (not marked)". */
    public static function marked_label( string $marked ): string {
        if ( 'read' === $marked ) {
            return __( 'Reads', 'mcp-abilities' );
        }
        return 'write' === $marked ? __( 'Changes', 'mcp-abilities' ) : __( 'Changes (not marked)', 'mcp-abilities' );
    }
}
