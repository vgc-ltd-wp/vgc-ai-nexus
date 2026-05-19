<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-discovers and manages all Ability_Group instances.
 *
 * Discovery algorithm:
 *   1. Scans /abilities/ for subdirectories (each = a group slug).
 *   2. Inside each subdir, looks for a file named class-group-{slug}.php
 *      which must return an Ability_Group instance.
 *   3. Third-party plugins can inject additional groups via the
 *      `mcp_abilities_register_group` action or the static helper.
 */
class Registry {

    /** @var Ability_Group[] keyed by group slug */
    private array $groups = [];

    // ── Discovery ───────────────────────────────────────────────────────────

    public function auto_discover(): void {
        $settings = get_option( MCP_ABILITIES_OPTION_KEY, [] );

        // Scan the core abilities directory
        $this->scan_directory( MCP_ABILITIES_ABILITIES_DIR, $settings );

        // Allow external plugins to register additional directories
        $extra_dirs = apply_filters( 'mcp_abilities_extra_dirs', [] );
        foreach ( (array) $extra_dirs as $dir ) {
            if ( is_dir( $dir ) ) {
                $this->scan_directory( $dir, $settings );
            }
        }

        // Allow programmatic group registration
        do_action( 'mcp_abilities_register_groups', $this );

        ksort( $this->groups );
    }

    private function scan_directory( string $base_dir, array $settings ): void {
        if ( ! is_dir( $base_dir ) ) {
            return;
        }

        $dirs = glob( trailingslashit( $base_dir ) . '*', GLOB_ONLYDIR );
        if ( ! $dirs ) {
            return;
        }

        foreach ( $dirs as $dir ) {
            $slug      = basename( $dir );
            $file      = trailingslashit( $dir ) . 'class-group-' . $slug . '.php';

            if ( ! file_exists( $file ) ) {
                continue; // skip incomplete group directories
            }

            require_once $file;

            // Derive class name from slug: "post-management" → Post_Management_Group
            $class_suffix = str_replace( '-', '_', $slug );
            $class_name   = 'MCP_Abilities\\Groups\\' . ucwords( $class_suffix, '_' ) . '_Group';

            if ( ! class_exists( $class_name ) ) {
                // Fallback: try without namespace
                $class_name = ucwords( $class_suffix, '_' ) . '_Group';
            }

            if ( ! class_exists( $class_name ) ) {
                continue;
            }

            /** @var Ability_Group $group */
            $group = new $class_name();

            // Restore persisted enabled state (falls back to the group's own default).
            $group_settings = $settings[ $group->get_slug() ] ?? [];
            $group->set_enabled( $group_settings['enabled'] ?? $group->get_default_enabled() );
            foreach ( $group->get_abilities() as $ability ) {
                $ab_key = $ability->get_key();
                $ability->set_enabled( $group_settings['abilities'][ $ab_key ] ?? true );
            }

            $this->groups[ $group->get_slug() ] = $group;
        }
    }

    // ── Public API ──────────────────────────────────────────────────────────

    public function register_group( Ability_Group $group ): void {
        $this->groups[ $group->get_slug() ] = $group;
    }

    /** @return Ability_Group[] */
    public function get_groups(): array {
        return $this->groups;
    }

    public function get_group( string $slug ): ?Ability_Group {
        return $this->groups[ $slug ] ?? null;
    }

    /**
     * Returns all currently enabled Ability objects.
     *
     * @return Ability[]
     */
    public function get_enabled_abilities(): array {
        $abilities = [];
        foreach ( $this->groups as $group ) {
            if ( ! $group->is_enabled() ) {
                continue;
            }
            foreach ( $group->get_abilities() as $ability ) {
                if ( $ability->is_enabled() ) {
                    $abilities[] = $ability;
                }
            }
        }
        return $abilities;
    }

    /**
     * Lookup a single ability by its key (tool name).
     */
    public function get_ability( string $key ): ?Ability {
        foreach ( $this->groups as $group ) {
            foreach ( $group->get_abilities() as $ability ) {
                if ( $ability->get_key() === $key ) {
                    return $ability;
                }
            }
        }
        return null;
    }

    /**
     * Persist current enabled states to the database.
     */
    public function save_settings( array $raw_settings ): void {
        $clean = [];
        foreach ( $this->groups as $slug => $group ) {
            $clean[ $slug ]['enabled'] = ! empty( $raw_settings[ $slug ]['enabled'] );
            foreach ( $group->get_abilities() as $ability ) {
                $ab_key                                  = $ability->get_key();
                $clean[ $slug ]['abilities'][ $ab_key ]  =
                    ! empty( $raw_settings[ $slug ]['abilities'][ $ab_key ] );
            }
        }
        update_option( MCP_ABILITIES_OPTION_KEY, $clean );
    }
}
