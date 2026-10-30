<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single MCP tool (one callable ability).
 *
 * Subclasses must implement:
 *   - define_meta()  – set $this->key, $this->label, $this->description, $this->input_schema
 *   - execute()      – run the ability given validated $params, return mixed result
 *
 * The JSON Schema in $input_schema follows the MCP tool input schema spec
 * (JSON Schema draft-07 subset).
 */
abstract class Ability {

    protected string $key;
    protected string $label;
    protected string $description     = '';
    protected string $required_cap    = 'manage_options';
    protected bool   $enabled         = true;

    /**
     * JSON Schema object describing the tool's input parameters.
     * Example:
     *   [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer' ] ], 'required' => ['post_id'] ]
     */
    protected array $input_schema = [ 'type' => 'object', 'properties' => [] ];

    public function __construct() {
        $this->define_meta();
    }

    // ── Abstract ────────────────────────────────────────────────────────────

    abstract protected function define_meta(): void;

    /**
     * Execute the ability.
     *
     * @param array $params Validated input parameters.
     * @return array        MCP-compatible result array, e.g. ['content' => [['type'=>'text','text'=>'...']]]
     */
    abstract public function execute( array $params ): array;

    // ── MCP helpers ─────────────────────────────────────────────────────────

    /**
     * Build a successful plain-data result.
     * The MCP Adapter wraps this in the appropriate MCP content block automatically.
     */
    protected function success( string $text, array $extra = [] ): array {
        return array_merge( [ 'message' => $text ], $extra );
    }

    /**
     * Build an error result.
     * The MCP Adapter's ToolsHandler recognises {success:false, error:string} and
     * marks the tool call as an error for the LLM.
     */
    protected function error( string $message ): array {
        return [ 'success' => false, 'error' => $message ];
    }

    /**
     * Return structured data as the tool result.
     * The MCP Adapter serialises this as both text content and structuredContent.
     */
    protected function json_result( $data ): array {
        return is_array( $data ) ? $data : [ 'result' => $data ];
    }

    // ── Publish date of existing posts ───────────────────────────────────────

    /**
     * Schema text shared by every update ability that accepts a date.
     */
    protected const POST_DATE_DESCRIPTION = 'Publish (created) date in site local time: "Y-m-d H:i:s", "Y-m-d\TH:i:s" or "Y-m-d" (taken as 00:00:00). Changes the date of the existing post; the GMT date is worked out from the site time zone. The modified date cannot be set: WordPress updates it itself on every save. A future date on a published post is refused unless status "future" is sent too (that schedules it, so it leaves the site until then). A past date on a scheduled post publishes it.';

    /**
     * Parse a caller's date into a MySQL local datetime, or null when it is not
     * a real calendar date/time in one of the accepted formats.
     */
    protected static function parse_post_date( $raw ): ?string {
        if ( ! is_string( $raw ) ) {
            return null;
        }
        $raw = trim( $raw );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m ) ) {
            return null;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        $h = isset( $m[4] ) ? (int) $m[4] : 0;
        $i = isset( $m[5] ) ? (int) $m[5] : 0;
        $s = isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0;
        if ( $y < 1000 || ! checkdate( $mo, $d, $y ) || $h > 23 || $i > 59 || $s > 59 ) {
            return null;
        }
        return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $i, $s );
    }

    /**
     * Work out the date/status part of a wp_update_post() call for an existing post.
     *
     * Reads the post fresh, validates the date and the scheduling rules, and on
     * success adds post_date, post_date_gmt and edit_date to $update.
     *
     * @param int                 $post_id
     * @param array<string,mixed> $params  Caller's params (uses 'date' and 'status').
     * @param array<string,mixed> $update  wp_update_post() array, changed in place.
     * @return string|null Error message, or null when the update may go ahead.
     */
    protected function prepare_post_date( int $post_id, array $params, array &$update ): ?string {
        $has_date = isset( $params['date'] ) && '' !== $params['date'];
        $status   = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : null;

        if ( ! $has_date && 'future' !== $status ) {
            return null;
        }

        clean_post_cache( $post_id );
        $post = get_post( $post_id );
        if ( ! $post ) {
            return "Post {$post_id} not found.";
        }

        if ( $has_date ) {
            $local = self::parse_post_date( $params['date'] );
            if ( null === $local ) {
                return 'Invalid date "' . sanitize_text_field( (string) $params['date'] ) . '". Send a real calendar date in site local time as "Y-m-d H:i:s" (e.g. 2024-03-15 09:30:00), "Y-m-d\TH:i:s" or "Y-m-d".';
            }
            $gmt = get_gmt_from_date( $local );
        } else {
            $local = $post->post_date;
            $gmt   = '0000-00-00 00:00:00' === $post->post_date_gmt ? get_gmt_from_date( $local ) : $post->post_date_gmt;
        }

        $is_future  = strtotime( $gmt . ' UTC' ) > time();
        $new_status = $status ?? $post->post_status;

        if ( 'future' === $new_status && 'future' === $status && ! $is_future ) {
            return $has_date
                ? "Status \"future\" (scheduled) needs a date in the future; {$local} (site time) is not. Send status \"publish\" for a past date."
                : "Status \"future\" (scheduled) needs a date in the future; the post's date {$local} (site time) is not. Send a future \"date\" too.";
        }
        if ( $is_future && 'publish' === $new_status ) {
            return "{$local} (site time) is in the future. On a published post WordPress would turn it into a scheduled post and take it off the site until then, so it was not changed. To do that on purpose, send status \"future\" with the date; otherwise send a date that is not in the future.";
        }

        if ( $has_date ) {
            $update['post_date']     = $local;
            $update['post_date_gmt'] = $gmt;
            $update['edit_date']     = true;
        }
        return null;
    }

    /**
     * Date/status fields read back from the database after an update, plus a
     * note when WordPress changed the status because of the date.
     *
     * @param int    $post_id
     * @param string $old_status Status before the update.
     * @param string $old_date   post_date before the update.
     * @return array<string,mixed>
     */
    protected static function post_date_readback( int $post_id, string $old_status, string $old_date ): array {
        clean_post_cache( $post_id );
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [];
        }
        $out = [
            'date'     => $post->post_date,
            'date_gmt' => $post->post_date_gmt,
            'modified' => $post->post_modified,
            'status'   => $post->post_status,
        ];
        if ( $old_date !== $post->post_date ) {
            $out['previous_date'] = $old_date;
        }
        if ( $old_status !== $post->post_status ) {
            $out['previous_status'] = $old_status;
            if ( 'future' === $old_status && 'publish' === $post->post_status ) {
                $out['note'] = 'The post was scheduled; its date is no longer in the future, so WordPress published it.';
            } elseif ( 'future' === $post->post_status ) {
                $out['note'] = 'The post is now scheduled: it is off the site until ' . $post->post_date . ' (site time), when WordPress publishes it.';
            }
        }
        return $out;
    }

    // ── Input validation ─────────────────────────────────────────────────────

    /** JSON Schema types rest_validate_value_from_schema() understands. */
    private const SCHEMA_TYPES = [ 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' ];

    /**
     * Check the caller's parameters against this ability's input schema and
     * normalise them, before execute().
     *
     * The bundled MCP adapter calls tools through a handler, not through
     * WP_Ability::execute(), so nothing else validates on that path. Checked:
     * required fields, and the type and enum of every declared parameter (also
     * of array items and object properties). Not checked: minimum/maximum,
     * lengths, patterns and formats, which the abilities clamp or clean
     * themselves. Lenient where callers commonly differ from the schema
     * without meaning anything else: null, or "" for a number/boolean, counts
     * as not sent; a number is accepted for a string; "5" and "true" are
     * accepted for integers and booleans and passed on as 5 and true.
     *
     * Enums are enforced for AI Nexus's own abilities. An extension's enum can
     * be a static sample of values its code takes from the site (WooCommerce
     * order statuses include a shop's custom ones), so for abilities defined
     * outside this plugin only types are checked, unless the filter
     * `mcp_abilities_validate_enums` (bool, Ability) says otherwise. Filter
     * `mcp_abilities_validate_input` (bool, Ability) turns the whole check off
     * for one ability.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|string Normalised params, or an error message.
     */
    public function validate_params( array $params ) {
        if ( ! apply_filters( 'mcp_abilities_validate_input', true, $this ) ) {
            return $params;
        }
        $enums = (bool) apply_filters( 'mcp_abilities_validate_enums', $this->is_core_ability(), $this );

        $props = (array) ( $this->input_schema['properties'] ?? [] );
        foreach ( $params as $name => $value ) {
            $def = $props[ $name ] ?? null;
            if ( ! is_array( $def ) ) {
                continue;
            }
            $schema = self::validation_schema( $def, $enums );
            if ( null === $schema ) {
                continue;
            }
            $types = (array) $schema['type'];
            if ( null === $value && ! in_array( 'null', $types, true ) ) {
                unset( $params[ $name ] );
                continue;
            }
            if ( '' === $value && ! array_intersect( $types, [ 'string', 'array', 'object', 'null' ] ) ) {
                unset( $params[ $name ] );
                continue;
            }
            $value = self::coerce_for_schema( $value, $schema );
            $valid = rest_validate_value_from_schema( $value, $schema, (string) $name );
            if ( is_wp_error( $valid ) ) {
                return sprintf( 'Invalid parameter "%s": %s', $name, $valid->get_error_message() );
            }
            $params[ $name ] = rest_sanitize_value_from_schema( $value, $schema, (string) $name );
        }

        foreach ( (array) ( $this->input_schema['required'] ?? [] ) as $field ) {
            if ( 'action' !== $field && ( ! isset( $params[ $field ] ) || '' === $params[ $field ] ) ) {
                return sprintf( 'Missing required parameter "%s".', $field );
            }
        }
        return $params;
    }

    /** Whether this ability's class is defined by AI Nexus itself (not an extension). */
    private function is_core_ability(): bool {
        static $core = [];
        $class = static::class;
        if ( ! isset( $core[ $class ] ) ) {
            $file           = ( new \ReflectionClass( $this ) )->getFileName();
            $core[ $class ] = is_string( $file ) && str_starts_with( wp_normalize_path( $file ), wp_normalize_path( MCP_ABILITIES_DIR ) );
        }
        return $core[ $class ];
    }

    /**
     * The part of a property schema that is enforced: type, enum (when
     * $enums), items, properties (nested the same way). Null when it has no
     * usable type.
     *
     * @param array<string,mixed> $def
     * @return array<string,mixed>|null
     */
    private static function validation_schema( array $def, bool $enums = true ): ?array {
        $types = array_values( array_intersect( (array) ( $def['type'] ?? [] ), self::SCHEMA_TYPES ) );
        if ( ! $types ) {
            return null;
        }
        $out = [ 'type' => 1 === count( $types ) ? $types[0] : $types ];
        if ( $enums && isset( $def['enum'] ) && is_array( $def['enum'] ) && $def['enum'] ) {
            $out['enum'] = array_values( $def['enum'] );
        }
        if ( in_array( 'array', $types, true ) && isset( $def['items'] ) && is_array( $def['items'] ) ) {
            $items = self::validation_schema( $def['items'], $enums );
            if ( null !== $items ) {
                $out['items'] = $items;
            }
        }
        if ( in_array( 'object', $types, true ) && isset( $def['properties'] ) && is_array( $def['properties'] ) ) {
            $nested = [];
            foreach ( $def['properties'] as $key => $sub ) {
                $sub = is_array( $sub ) ? self::validation_schema( $sub, $enums ) : null;
                if ( null !== $sub ) {
                    $nested[ $key ] = $sub;
                }
            }
            if ( $nested ) {
                $out['properties'] = $nested;
            }
        }
        return $out;
    }

    /**
     * Accept numbers where the schema wants a string, and an enum value in
     * other letter case ("asc" for "ASC"), also inside arrays and objects;
     * leave everything else for the validator to judge.
     *
     * @param mixed               $value
     * @param array<string,mixed> $schema From validation_schema().
     * @return mixed
     */
    private static function coerce_for_schema( $value, array $schema ) {
        if ( is_string( $value ) && isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
            foreach ( $schema['enum'] as $allowed ) {
                if ( is_string( $allowed ) && 0 === strcasecmp( $allowed, $value ) ) {
                    return $allowed;
                }
            }
        }
        $types = (array) $schema['type'];
        if ( ( is_int( $value ) || is_float( $value ) ) && in_array( 'string', $types, true )
            && ! array_intersect( $types, [ 'integer', 'number' ] ) ) {
            return (string) $value;
        }
        if ( is_array( $value ) && isset( $schema['items'] ) && in_array( 'array', $types, true ) && wp_is_numeric_array( $value ) ) {
            return array_map( fn( $item ) => self::coerce_for_schema( $item, $schema['items'] ), $value );
        }
        if ( is_array( $value ) && isset( $schema['properties'] ) && in_array( 'object', $types, true ) ) {
            foreach ( $schema['properties'] as $key => $sub ) {
                if ( array_key_exists( $key, $value ) ) {
                    $value[ $key ] = self::coerce_for_schema( $value[ $key ], $sub );
                }
            }
        }
        return $value;
    }

    // ── Ignored-parameter surfacing ──────────────────────────────────────────

    /**
     * Parameters the caller sent that this ability does not declare.
     *
     * WordPress schema validation silently DROPS unknown properties, so a caller
     * that guesses a plausible-but-wrong parameter name (e.g. "portfolio_category"
     * instead of taxonomy+terms) gets a confident, wrong-looking-right result and
     * no signal that its filter did nothing. Surfacing the dropped keys turns
     * "silently wrong" into "wrong and clearly labelled", which lets an AI caller
     * self-correct in one turn instead of theorising about the cause.
     *
     * @param array<string,mixed> $params Raw parameters as received.
     * @return string[] Unknown parameter names.
     */
    public function unknown_params( array $params ): array {
        $declared = array_keys( (array) ( $this->input_schema['properties'] ?? [] ) );
        if ( ! $declared ) {
            return [];
        }
        // 'action' is the consolidated-dispatcher selector, always legitimate.
        $declared[] = 'action';
        return array_values( array_diff( array_keys( $params ), $declared ) );
    }

    /**
     * Attach an ignored-parameter notice to a result, when applicable.
     *
     * Deliberately non-fatal: the call still returns its data. Errors are left
     * untouched so the original failure stays the headline.
     *
     * @param array<string,mixed> $result Ability result.
     * @param array<string,mixed> $params Raw parameters as received.
     * @return array<string,mixed>
     */
    public function annotate_ignored_params( array $result, array $params ): array {
        $unknown = $this->unknown_params( $params );
        if ( ! $unknown || isset( $result['success'] ) && false === $result['success'] ) {
            return $result;
        }
        $declared = array_keys( (array) ( $this->input_schema['properties'] ?? [] ) );
        $result['ignored_parameters'] = $unknown;
        $result['warning'] = sprintf(
            'These parameters are not supported by "%s" and had NO effect: %s. The results above are UNFILTERED with respect to them. Supported parameters: %s.',
            $this->key,
            implode( ', ', $unknown ),
            $declared ? implode( ', ', $declared ) : '(none)'
        );
        return $result;
    }

    // ── Read-only mode ───────────────────────────────────────────────────────

    /**
     * Whether this ability changes the site. Null = classify by key (deny by
     * default — see Read_Only::is_read_key()); subclasses may set it explicitly.
     */
    protected ?bool $writes = null;

    public function is_write(): bool {
        return $this->writes ?? ! Read_Only::is_read_key( $this->key );
    }

    public function blocked_by_read_only(): bool {
        return $this->is_write() && Read_Only::active();
    }

    // ── Authorization ────────────────────────────────────────────────────────

    /**
     * Every execution path — WordPress ability permission callbacks, MCP tool
     * permission closures, in core and in every extension — asks this method,
     * which is why read-only mode is enforced here.
     */
    public function current_user_can(): bool {
        if ( $this->blocked_by_read_only() ) {
            return false;
        }
        // A connection waiting for (or refused) an administrator's approval has
        // no abilities at all - core's and every extension's, whatever serves them.
        if ( Connection_Approval::is_held() ) {
            return false;
        }
        return \current_user_can( $this->required_cap );
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function get_key(): string          { return $this->key; }
    public function get_label(): string        { return $this->label; }
    public function get_description(): string  { return $this->description; }
    public function get_required_cap(): string { return $this->required_cap; }
    public function get_input_schema(): array  { return $this->input_schema; }
    public function is_enabled(): bool         { return $this->enabled; }
    public function set_enabled( bool $v ): void { $this->enabled = $v; }

    /**
     * Returns the MCP tool definition object.
     */
    public function to_mcp_tool(): array {
        return [
            'name'        => $this->key,
            'description' => $this->description,
            'inputSchema' => $this->input_schema,
        ];
    }
}
