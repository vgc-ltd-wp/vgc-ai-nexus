<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for an ability group (e.g. "Post Management").
 *
 * Concrete group classes extend this, set metadata in the constructor,
 * and register their abilities via register_ability().
 *
 * Each group directory also auto-discovers ability files matching
 * class-ability-*.php inside its own folder.
 */
abstract class Ability_Group {

    protected string $slug;
    protected string $label;
    protected string $description    = '';
    protected string $icon           = 'dashicons-admin-generic';
    protected bool   $enabled        = true;
    protected bool   $default_enabled   = true;
    protected string $security_warning  = '';

    /** @var Ability[] */
    protected array $abilities = [];

    public function __construct() {
        $this->define_meta();
        $this->register_abilities();
        $this->auto_discover_abilities();
    }

    // ── Abstract / Override ─────────────────────────────────────────────────

    /**
     * Set $this->slug, $this->label, $this->description, $this->icon.
     */
    abstract protected function define_meta(): void;

    /**
     * Optionally override to register abilities inline.
     * Most groups use auto-discovery instead.
     */
    protected function register_abilities(): void {}

    // ── Auto-discovery ──────────────────────────────────────────────────────

    /**
     * Scans the group's own directory for class-ability-*.php files.
     */
    private function auto_discover_abilities(): void {
        $ref  = new \ReflectionClass( $this );
        $dir  = dirname( $ref->getFileName() );
        $files = glob( $dir . '/class-ability-*.php' );

        if ( ! $files ) {
            return;
        }

        foreach ( $files as $file ) {
            require_once $file;

            // Derive class name from filename: class-ability-create-post.php
            // → MCP_Abilities\Abilities\Create_Post_Ability (or try without ns)
            $base    = basename( $file, '.php' );               // class-ability-create-post
            $suffix  = substr( $base, strlen( 'class-ability-' ) ); // create-post
            $pascal  = str_replace( '-', '_', $suffix );

            $candidates = [
                'MCP_Abilities\\Abilities\\' . ucwords( $pascal, '_' ) . '_Ability',
                ucwords( $pascal, '_' ) . '_Ability',
            ];

            foreach ( $candidates as $class ) {
                if ( class_exists( $class ) ) {
                    $ability = new $class();
                    if ( $ability instanceof Ability ) {
                        $this->abilities[ $ability->get_key() ] = $ability;
                    }
                    break;
                }
            }
        }
    }

    // ── Registration helpers ────────────────────────────────────────────────

    protected function register_ability( Ability $ability ): void {
        $this->abilities[ $ability->get_key() ] = $ability;
    }

    // ── Getters / Setters ───────────────────────────────────────────────────

    public function get_slug(): string             { return $this->slug; }
    public function get_label(): string            { return $this->label; }
    public function get_description(): string      { return $this->description; }
    public function get_icon(): string             { return $this->icon; }
    public function is_enabled(): bool             { return $this->enabled; }
    public function set_enabled( bool $v ): void   { $this->enabled = $v; }
    public function get_default_enabled(): bool    { return $this->default_enabled; }
    public function get_security_warning(): string { return $this->security_warning; }

    /** @return Ability[] */
    public function get_abilities(): array    { return $this->abilities; }
}
