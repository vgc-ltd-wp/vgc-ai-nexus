<?php
/**
 * Plugin Name:       VGC AI Nexus
 * Plugin URI:        https://tools.vgc-ltd.com
 * Description:       The AI management layer for WordPress. Exposes your site's content, users, settings and menus as MCP (Model Context Protocol) tools so AI agents can read, create, update and delete data through a secure, permission-controlled interface. Requires the MCP Adapter plugin to be installed and active. Extend capabilities with VGC AI Nexus add-ons for WooCommerce and more.
 * Version:           2.12.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            VGC
 * Author URI:        https://tools.vgc-ltd.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcp-abilities
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────
define( 'MCP_ABILITIES_VERSION',     '2.12.0' );
define( 'MCP_ABILITIES_FILE',        __FILE__ );
define( 'MCP_ABILITIES_DIR',         plugin_dir_path( __FILE__ ) );
define( 'MCP_ABILITIES_URL',         plugin_dir_url( __FILE__ ) );
define( 'MCP_ABILITIES_ABILITIES_DIR', MCP_ABILITIES_DIR . 'abilities/' );
define( 'MCP_ABILITIES_OPTION_KEY',  'mcp_abilities_settings' );

// ── Autoloader ─────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
    $prefix = 'MCP_Abilities\\';
    if ( ! str_starts_with( $class, $prefix ) ) {
        return;
    }
    $parts    = explode( '\\', substr( $class, strlen( $prefix ) ) );
    $basename = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
    $subdir   = $parts ? strtolower( implode( DIRECTORY_SEPARATOR, $parts ) ) . DIRECTORY_SEPARATOR : '';
    $file     = MCP_ABILITIES_DIR . 'includes/' . $subdir . $basename;
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ── Self-hosted updates ──────────────────────────────────────────────────────
// Reads a PUBLIC manifest so the normal WordPress "Update now" button works even
// though the source repo is private. No token needed on the site.
new \MCP_Abilities\VGC_Plugin_Updater(
    MCP_ABILITIES_FILE,
    'vgc-ai-nexus',
    MCP_ABILITIES_VERSION,
    'https://raw.githubusercontent.com/vgc-ltd-wp/vgc-plugin-updates/main/plugins.json'
);

// Enrich this plugin's "View details" modal with a live summary of its abilities.
add_filter( 'vgc_plugin_updater_sections', function ( array $sections, string $slug ): array {
    if ( 'vgc-ai-nexus' !== $slug || ! function_exists( 'mcp_abilities' ) ) {
        return $sections;
    }
    $groups = mcp_abilities()->registry->get_groups();
    if ( empty( $groups ) ) {
        return $sections;
    }
    $total = 0;
    $items = '';
    foreach ( $groups as $group ) {
        $count  = count( $group->get_abilities() );
        $total += $count;
        $items .= '<li><strong>' . esc_html( $group->get_label() ) . '</strong> — '
            . sprintf( /* translators: %d: ability count */ esc_html( _n( '%d ability', '%d abilities', $count, 'mcp-abilities' ) ), (int) $count )
            . '</li>';
    }
    $summary = '<p><strong>'
        . sprintf(
            /* translators: 1: total abilities, 2: group count */
            esc_html__( 'This plugin currently provides %1$d abilities across %2$d groups:', 'mcp-abilities' ),
            (int) $total,
            count( $groups )
        )
        . '</strong></p><ul style="list-style:disc;padding-left:20px;">' . $items . '</ul><hr />';

    $sections['description'] = $summary . ( $sections['description'] ?? '' );
    return $sections;
}, 10, 2 );

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once MCP_ABILITIES_DIR . 'includes/class-plugin.php';

function mcp_abilities(): \MCP_Abilities\Plugin {
    return \MCP_Abilities\Plugin::instance();
}

add_action( 'plugins_loaded', function (): void {
    if ( ! defined( 'WP_MCP_VERSION' ) ) {
        add_action( 'admin_notices', 'mcp_abilities_missing_adapter_notice' );
        return;
    }
    mcp_abilities()->init();
} );

function mcp_abilities_missing_adapter_notice(): void {
    echo '<div class="notice notice-error"><p>' .
         esc_html__( 'MCP Abilities requires the MCP Adapter plugin to be installed and active.', 'mcp-abilities' ) .
         '</p></div>';
}

// ── Activation / Deactivation ──────────────────────────────────────────────
register_activation_hook(   __FILE__, [ 'MCP_Abilities\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'MCP_Abilities\Installer', 'deactivate' ] );
