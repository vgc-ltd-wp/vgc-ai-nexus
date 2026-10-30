<?php
/**
 * Uninstall VGC AI Nexus (Plugins → Delete).
 *
 * WordPress runs this file on its own, with WP_UNINSTALL_PLUGIN defined; the
 * plugin is inactive by then, so its main file is not loaded. This loads only
 * what Installer::uninstall() needs and runs it. What goes and what stays is
 * decided there:
 *   - removed: the activity log table, AI Nexus settings, one-time upload
 *     links, and the OAuth apps and tokens — but only when no VGC MCP Claude
 *     Connector is active, because that plugin serves the same connections;
 *   - kept: the MCP attribution meta on revisions, posts, comments, order
 *     notes and orders (_mcp_abilities_actor, _mcp_abilities_last_change):
 *     it is the audit trail of what an AI changed.
 *
 * @package mcp-abilities
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! defined( 'MCP_ABILITIES_DIR' ) ) {
    define( 'MCP_ABILITIES_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'MCP_ABILITIES_OPTION_KEY' ) ) {
    define( 'MCP_ABILITIES_OPTION_KEY', 'mcp_abilities_settings' );
}

// The plugin's own class loader (the same rule as in vgc-ai-nexus.php).
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'MCP_Abilities\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
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

\MCP_Abilities\Installer::uninstall();
