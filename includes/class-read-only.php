<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide read-only mode for every AI Nexus ability — core and extensions.
 *
 * One switch: the toggle on AI Nexus → Abilities, or the
 * VGC_AI_NEXUS_READONLY constant in wp-config.php (which forces it on and locks
 * the toggle). Enforced in the base Ability class, which every extension
 * inherits, so it also covers extension versions released before this mode.
 *
 * Classification is deny-by-default: an ability counts as a read only if its
 * key is a known read verb. Anything unrecognised — including abilities added
 * in later releases — is treated as a write, so a read-only site can never
 * silently gain a write tool through an update.
 */
final class Read_Only {

    public const OPTION   = 'mcp_abilities_read_only';
    public const CONSTANT = 'VGC_AI_NEXUS_READONLY';

    /** Key prefixes that only read. Order-insensitive. */
    private const READ_PREFIXES = [
        'list_', 'get_', 'search_', 'diff_',
        'wc_list_', 'wc_get_',
    ];

    /** Read abilities whose key does not follow a read prefix. */
    private const READ_KEYS = [
        'usage_guide',
        'status',
        // Analytics (Google APIs — reports only, nothing written to the site).
        'analyze_url', 'inspect_url', 'realtime_report', 'run_report',
        'search_analytics', 'top_pages',
        // Avada.
        'export_options', 'bulk_audit_log', 'bulk_list_batches',
    ];

    public static function active(): bool {
        if ( self::forced() ) {
            return true;
        }
        return (bool) apply_filters( 'mcp_abilities_read_only', (bool) get_option( self::OPTION, false ) );
    }

    /** True when wp-config.php forces read-only on (the toggle is then locked). */
    public static function forced(): bool {
        return defined( self::CONSTANT ) && constant( self::CONSTANT );
    }

    public static function set( bool $on ): void {
        update_option( self::OPTION, $on, true );
    }

    public static function is_read_key( string $key ): bool {
        if ( in_array( $key, self::READ_KEYS, true ) ) {
            return true;
        }
        foreach ( self::READ_PREFIXES as $prefix ) {
            if ( str_starts_with( $key, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    public static function error_message( string $key ): string {
        return sprintf(
            'AI Nexus is in READ-ONLY mode on this site, so "%s" (a write) is not allowed. Reading still works. A site administrator can switch read-only mode off under WordPress Admin → AI Nexus → Abilities%s.',
            $key,
            self::forced() ? ' after removing the VGC_AI_NEXUS_READONLY constant from wp-config.php' : ''
        );
    }

    /** Shown in the usage guide and the MCP handshake orientation. */
    public static function orientation_line(): string {
        return 'READ-ONLY MODE IS ON for this site: every write tool (create, update, delete, upload, publish, settings changes) is disabled and will be refused. Only read, list and search. Do not try workarounds — tell the user writes need an administrator to switch read-only mode off.';
    }

    public static function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) || ! self::active() ) {
            return;
        }
        echo '<div class="notice notice-info"><p><strong>VGC AI Nexus:</strong> '
            . esc_html__( 'Read-only mode is on — AI assistants can read this site but cannot change anything.', 'mcp-abilities' )
            . ( self::forced()
                ? ' ' . esc_html__( '(Locked by VGC_AI_NEXUS_READONLY in wp-config.php.)', 'mcp-abilities' )
                : ' <a href="' . esc_url( admin_url( 'admin.php?page=mcp-abilities' ) ) . '">' . esc_html__( 'Change', 'mcp-abilities' ) . '</a>' )
            . '</p></div>';
    }
}
