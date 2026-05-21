<?php
namespace MCP_Abilities\Admin;

use MCP_Abilities\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Connections admin page — shows the AI activity log.
 *
 * Registered as a submenu under the main AI Nexus menu by Settings_Page.
 * This class only handles its own rendering and asset loading.
 */
class Connections_Page {

    const PAGE_SLUG = 'mcp-abilities-connections';

    private string $hook = '';

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'add_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_submenu(): void {
        $this->hook = add_submenu_page(
            'mcp-abilities',                                      // parent slug
            __( 'Connections – VGC AI Nexus', 'mcp-abilities' ), // page title
            __( 'Connections', 'mcp-abilities' ),                 // menu label
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->hook ) {
            return;
        }
        // Reuse the shared admin stylesheet (already enqueued by Settings_Page on its own pages).
        // We also need it here on the Connections page.
        wp_enqueue_style(
            'mcp-abilities-admin',
            MCP_ABILITIES_URL . 'admin/css/admin.css',
            [],
            MCP_ABILITIES_VERSION
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ) );
        }

        // ── Sanitize filter inputs ────────────────────────────────────────────
        $filter_tool      = isset( $_GET['filter_tool'] )      ? sanitize_text_field( wp_unslash( $_GET['filter_tool'] ) )      : '';
        $filter_user      = isset( $_GET['filter_user'] )      ? absint( $_GET['filter_user'] )                                 : 0;
        $filter_status    = isset( $_GET['filter_status'] )    ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) )    : '';
        $filter_date_from = isset( $_GET['filter_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_date_from'] ) ) : '';
        $filter_date_to   = isset( $_GET['filter_date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['filter_date_to'] ) )   : '';
        $paged            = isset( $_GET['paged'] )            ? max( 1, absint( $_GET['paged'] ) )                             : 1;

        // ── Fetch data ────────────────────────────────────────────────────────
        $summary = Logger::get_summary();
        $result  = Logger::get_log( [
            'per_page'  => 50,
            'page'      => $paged,
            'tool'      => $filter_tool,
            'user_id'   => $filter_user,
            'status'    => $filter_status,
            'date_from' => $filter_date_from,
            'date_to'   => $filter_date_to,
        ] );
        $all_tools = Logger::get_all_tools();

        include MCP_ABILITIES_DIR . 'admin/views/connections-page.php';
    }
}
