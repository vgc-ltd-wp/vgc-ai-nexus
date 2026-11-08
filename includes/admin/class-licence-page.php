<?php
namespace MCP_Abilities\Admin;

use MCP_Abilities\Licence;

defined( 'ABSPATH' ) || exit;

/**
 * AI Nexus → Licence: enter, check and remove this site's key.
 *
 * Plain forms that post to the page and come back with a fixed result code, so
 * reloading never sends anything twice and no text from a request reaches the page.
 */
class Licence_Page {

    private const ACTIONS = [ 'activate', 'check', 'remove' ];

    private string $hook = '';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_submenu(): void {
        $this->hook = (string) add_submenu_page(
            'mcp-abilities',
            __( 'Licence – VGC AI Nexus', 'mcp-abilities' ),
            __( 'Licence', 'mcp-abilities' ),
            'manage_options',
            Licence::PAGE_SLUG,
            [ $this, 'render' ]
        );
        if ( '' !== $this->hook ) {
            add_action( 'load-' . $this->hook, [ $this, 'on_load' ] );
        }
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook === $this->hook ) {
            wp_enqueue_style( 'mcp-abilities-admin', MCP_ABILITIES_URL . 'admin/css/admin.css', [], MCP_ABILITIES_VERSION );
        }
    }

    /** Before anything is printed: a form was sent, or the page is simply being opened. */
    public function on_load(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ), '', [ 'response' => 403 ] );
        }
        $action = isset( $_POST['mcp_licence_action'] ) ? sanitize_key( wp_unslash( $_POST['mcp_licence_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below, before anything is done
        if ( '' === $action ) {
            Licence::check_for_page();
            return;
        }
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            wp_die( esc_html__( 'Unknown action.', 'mcp-abilities' ), '', [ 'response' => 400 ] );
        }
        check_admin_referer( 'mcp_licence_' . $action );

        switch ( $action ) {
            case 'activate':
                $typed  = isset( $_POST['licence_key'] ) ? sanitize_text_field( wp_unslash( $_POST['licence_key'] ) ) : '';
                $result = Licence::activate( $typed )['result'];
                break;
            case 'check':
                $result = Licence::check()['result'];
                break;
            default: // remove
                if ( empty( $_POST['confirm'] ) ) {
                    $result = 'confirm';
                    break;
                }
                $result = Licence::remove() ? 'removed' : 'removed_not_released';
        }
        wp_safe_redirect( add_query_arg( 'result', $result, Licence::page_url() ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-abilities' ) );
        }
        $state       = Licence::state();
        $server_host = Licence::server_host();
        $result      = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a fixed code
        $notice      = self::result_notice( $result );
        $extensions  = $this->extension_names();
        include MCP_ABILITIES_DIR . 'admin/views/licence-page.php';
    }

    /**
     * The line for a result code.
     *
     * @return array{0:string, 1:string}|null [ success|warning|error, text ]
     */
    private static function result_notice( string $result ): ?array {
        $network = __( 'The licence server could not be reached, so nothing was changed. Try again in a few minutes.', 'mcp-abilities' );
        $map     = [
            'valid'                => [ 'success', __( 'The licence is active on this site.', 'mcp-abilities' ) ],
            'expired'              => [ 'warning', __( 'The key is on this site, but the licence has expired.', 'mcp-abilities' ) ],
            'seat_taken'           => [ 'warning', __( 'The key is on this site, but it does not cover it yet. The reason is below.', 'mcp-abilities' ) ],
            'revoked'              => [ 'error', __( 'This licence key is no longer valid.', 'mcp-abilities' ) ],
            'unknown'              => [ 'error', __( 'The licence server does not know this key. Check that it was typed correctly.', 'mcp-abilities' ) ],
            'bad_key'              => [ 'error', __( 'Enter a licence key.', 'mcp-abilities' ) ],
            'not_activated'        => [ 'error', __( 'This site could not be activated with this key.', 'mcp-abilities' ) ],
            'released'             => [ 'error', __( 'This site could not be activated with this key.', 'mcp-abilities' ) ],
            'network'              => [ 'error', $network ],
            'server'               => [ 'error', $network ],
            'rate_limited'         => [ 'error', __( 'The licence server asked this site to wait, so nothing was changed. Try again later.', 'mcp-abilities' ) ],
            'refused'              => [ 'error', __( 'The licence server did not accept the request, so nothing was changed.', 'mcp-abilities' ) ],
            'not_verified'         => [ 'error', __( 'The answer from the licence server could not be verified, so nothing was changed.', 'mcp-abilities' ) ],
            'no_key'               => [ 'error', __( 'There is no key on this site.', 'mcp-abilities' ) ],
            'confirm'              => [ 'error', __( 'Nothing was changed. Tick the box to confirm.', 'mcp-abilities' ) ],
            'removed'              => [ 'success', __( 'The key was removed from this site, and the licence server has let the site go.', 'mcp-abilities' ) ],
            'removed_not_released' => [ 'warning', __( 'The key was removed from this site, but the licence server could not be told. It may still count this site until VGC releases it.', 'mcp-abilities' ) ],
        ];
        return $map[ $result ] ?? null;
    }

    /**
     * Name and version of each active extension a key is for.
     *
     * @return string[]
     */
    private function extension_names(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $out     = [];
        foreach ( Licence::extensions() as $file ) {
            $name  = (string) ( $plugins[ $file ]['Name'] ?? dirname( $file ) );
            $ver   = (string) ( $plugins[ $file ]['Version'] ?? '' );
            $out[] = '' !== $ver ? $name . ' ' . $ver : $name;
        }
        return $out;
    }
}
