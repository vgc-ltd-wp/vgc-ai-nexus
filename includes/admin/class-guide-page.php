<?php
namespace MCP_Abilities\Admin;

use MCP_Abilities\Guide;
use MCP_Abilities\Adapter_Status;

defined( 'ABSPATH' ) || exit;

/**
 * AI Nexus → Guide.
 *
 * Two jobs:
 *   1. Let the site owner write house rules that are merged into the guide every
 *      AI assistant reads. This is what turns generic documentation into "how WE
 *      work here" — the part no shipped text can know.
 *   2. Hand over the compact block for pasting into a Claude Project, which is
 *      the reliable way to make the rules apply to every conversation.
 */
class Guide_Page {

    const PAGE_SLUG = 'mcp-abilities-guide';

    private string $hook = '';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_submenu(): void {
        $this->hook = add_submenu_page(
            'mcp-abilities',
            __( 'Guide – VGC AI Nexus', 'mcp-abilities' ),
            __( 'Guide', 'mcp-abilities' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->hook ) {
            return;
        }
        wp_enqueue_style( 'mcp-abilities-admin', MCP_ABILITIES_URL . 'admin/css/admin.css', [], MCP_ABILITIES_VERSION );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ) );
        }

        $saved = false;
        if ( isset( $_POST['mcp_guide_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_guide_nonce'] ) ), 'mcp_save_guide' ) ) {
            $conventions = isset( $_POST['mcp_conventions'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['mcp_conventions'] ) )
                : '';
            update_option( Guide::CONVENTIONS_OPTION, $conventions, false );
            $saved = true;
        }

        $conventions = (string) get_option( Guide::CONVENTIONS_OPTION, '' );
        $compact     = Guide::compact();

        include MCP_ABILITIES_DIR . 'admin/views/guide-page.php';
    }
}
