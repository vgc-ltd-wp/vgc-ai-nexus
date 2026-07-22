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

    // ── Authorization ────────────────────────────────────────────────────────

    public function current_user_can(): bool {
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
