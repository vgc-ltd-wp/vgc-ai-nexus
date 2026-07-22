<?php
/**
 * Plugin Name:       VGC AI Nexus
 * Plugin URI:        https://tools.vgc-ltd.com
 * Description:       The AI management layer for WordPress. A single plugin: it bundles the MCP (Model Context Protocol) server and exposes your site's content, users, settings and menus as tools so AI agents can read, create, update and delete data through a secure, permission-controlled interface — connect Claude with an Application Password. Extend with VGC AI Nexus add-ons for WooCommerce, WPML, Avada and more.
 * Version:           2.17.0
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
define( 'MCP_ABILITIES_VERSION',     '2.17.0' );
define( 'MCP_ABILITIES_FILE',        __FILE__ );
define( 'MCP_ABILITIES_DIR',         plugin_dir_path( __FILE__ ) );
define( 'MCP_ABILITIES_URL',         plugin_dir_url( __FILE__ ) );
define( 'MCP_ABILITIES_ABILITIES_DIR', MCP_ABILITIES_DIR . 'abilities/' );
define( 'MCP_ABILITIES_OPTION_KEY',  'mcp_abilities_settings' );

// ── Bundled MCP Adapter ──────────────────────────────────────────────────────
// AI Nexus ships the MCP Adapter so it works as a SINGLE plugin (connection +
// abilities). Other plugins bundle the same library as a dependency (WooCommerce
// 10.9+ ships it; AIOSEO can install it), and WordPress has no way to arbitrate:
// whichever copy defines the WP\MCP\* classes first wins for the whole request.
//
// We therefore load ours as early as we can — at file level, before any hook —
// and RECORD what actually happened. A silent deferral used to be invisible:
// core would bind to a foreign adapter and contribute no tools at all, which is
// exactly how an entire toolset can disappear without an error anywhere.
$GLOBALS['mcp_abilities_adapter'] = [
    'ours'            => false,
    'preloaded_by'    => null,   // adapter version already present when we loaded
    'autoload_failed' => false,
];

if ( defined( 'WP_MCP_VERSION' ) ) {
    // Someone else got here first — record it so it can be reported, not guessed.
    $GLOBALS['mcp_abilities_adapter']['preloaded_by'] = WP_MCP_VERSION;
} else {
    if ( ! defined( 'WP_MCP_DIR' ) ) {
        define( 'WP_MCP_DIR', MCP_ABILITIES_DIR . 'mcp' );
    }
    $mcp_autoloader = WP_MCP_DIR . '/includes/Autoloader.php';
    if ( is_readable( $mcp_autoloader ) ) {
        require_once $mcp_autoloader;
        if ( class_exists( '\WP\MCP\Autoloader' ) && \WP\MCP\Autoloader::autoload() ) {
            define( 'WP_MCP_VERSION', '0.5.1-vgc' );
            $GLOBALS['mcp_abilities_adapter']['ours'] = true;
            if ( class_exists( \WP\MCP\Plugin::class ) ) {
                \WP\MCP\Plugin::instance();
            }
        } else {
            // The bundled adapter is present but unusable. Previously this failed
            // silently and left the door open for another plugin's copy.
            $GLOBALS['mcp_abilities_adapter']['autoload_failed'] = true;
        }
    } else {
        $GLOBALS['mcp_abilities_adapter']['autoload_failed'] = true;
    }
}

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

// ── OPcache self-heal ────────────────────────────────────────────────────────
// Some hosts (SiteGround) run OPcache without timestamp validation, so after an
// in-place plugin update PHP keeps executing STALE bytecode. For AI Nexus that
// regresses the bundled MCP adapter's tools/list serialization, and strict MCP
// clients (Claude Desktop 1.17377+) then silently drop the whole connector.
// Reset OPcache whenever any plugin is installed/updated/activated, and when
// the running core version differs from the last one recorded (covers manual
// zip installs where the upgrader hooks may run on stale code).
function mcp_abilities_opcache_reset(): void {
    if ( function_exists( 'opcache_reset' ) ) {
        @opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- may be blocked by opcache.restrict_api
    }
}
add_action( 'upgrader_process_complete', 'mcp_abilities_opcache_reset', 20 );
add_action( 'activated_plugin', 'mcp_abilities_opcache_reset', 20 );
add_action( 'plugins_loaded', function (): void {
    if ( get_option( 'mcp_abilities_loaded_version' ) !== MCP_ABILITIES_VERSION ) {
        update_option( 'mcp_abilities_loaded_version', MCP_ABILITIES_VERSION, false );
        mcp_abilities_opcache_reset();
    }
}, 1 );

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
