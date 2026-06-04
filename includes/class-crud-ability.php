<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * A single consolidated "dispatcher" tool that fronts every ability in a group.
 *
 * Instead of exposing N separate tools (list_posts, create_post, update_post …)
 * the whole group is exposed as ONE tool whose required `action` parameter
 * selects the operation. The underlying per-operation Ability objects are reused
 * verbatim — this class only routes to them — so all existing logic, schemas and
 * capability checks are preserved.
 *
 * IMPORTANT: collapsing the tools must never collapse the permission model.
 * Each underlying ability declares its own required capability; this dispatcher
 * re-checks that capability for the requested action *before* delegating, so a
 * user who could previously only `list` cannot suddenly `delete`.
 */
final class Crud_Ability extends Ability {

	/** @var array<string,Ability> action value => underlying ability */
	private array $ops = [];

	private string $group_slug;
	private string $group_label;
	private string $group_description;

	/**
	 * @param string    $group_slug        e.g. "post-management"
	 * @param string    $group_label       e.g. "Post Management"
	 * @param string    $group_description Group description (used as the tool intro).
	 * @param Ability[] $abilities         The group's individual abilities.
	 */
	public function __construct( string $group_slug, string $group_label, string $group_description, array $abilities ) {
		$this->group_slug        = $group_slug;
		$this->group_label       = $group_label;
		$this->group_description = $group_description;

		foreach ( $abilities as $ability ) {
			// The action value is the original ability key, e.g. "create_post".
			$this->ops[ $ability->get_key() ] = $ability;
		}

		parent::__construct(); // triggers define_meta()
	}

	protected function define_meta(): void {
		// Tool key derived from the group slug: "post-management" → "post_management".
		$this->key   = str_replace( '-', '_', $this->group_slug );
		$this->label = $this->group_label;

		$actions = array_keys( $this->ops );

		// Per-action help so the model knows which params each operation needs.
		$lines = [];
		foreach ( $this->ops as $action => $op ) {
			$req     = $op->get_input_schema()['required'] ?? [];
			$req     = array_values( array_diff( (array) $req, [ 'action' ] ) );
			$req_txt = $req ? ' [requires: ' . implode( ', ', $req ) . ']' : '';
			$lines[] = sprintf( '- %s: %s%s', $action, $op->get_description(), $req_txt );
		}

		$this->description = trim( $this->group_description )
			. ' One tool for this group: set "action" to one of the operations below,'
			. ' then provide that operation\'s parameters. Actions:' . "\n"
			. implode( "\n", $lines );

		// Coarse gate = least-privileged capability among the ops. The precise
		// per-action capability is enforced in execute().
		$this->required_cap = $this->gate_cap();

		// Union of every op's properties; only "action" is required at this level.
		// Each underlying op (and execute() below) still enforces its own required
		// fields, so per-action validation is preserved.
		$properties = [
			'action' => [
				'type'        => 'string',
				'enum'        => $actions,
				'description' => 'The operation to perform. See the tool description for each action\'s parameters.',
			],
		];

		foreach ( $this->ops as $op ) {
			$schema = $op->get_input_schema();
			foreach ( (array) ( $schema['properties'] ?? [] ) as $name => $def ) {
				if ( 'action' === $name || isset( $properties[ $name ] ) ) {
					continue; // first definition wins on name collision
				}
				$properties[ $name ] = $def;
			}
		}

		$this->input_schema = [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'action' ],
		];
	}

	public function execute( array $params ): array {
		$action = isset( $params['action'] ) ? sanitize_key( (string) $params['action'] ) : '';

		if ( '' === $action || ! isset( $this->ops[ $action ] ) ) {
			return $this->error( sprintf(
				'Unknown or missing "action". Valid actions: %s.',
				implode( ', ', array_keys( $this->ops ) )
			) );
		}

		$op = $this->ops[ $action ];

		// Respect the per-ability enable state set in AI Nexus → Abilities.
		if ( ! $op->is_enabled() ) {
			return $this->error( sprintf(
				'The "%s" action is currently disabled. Enable it in WordPress Admin → AI Nexus → Abilities.',
				$action
			) );
		}

		// Enforce the operation's OWN capability before delegating. This is what
		// preserves the per-operation permission model after consolidation.
		if ( ! current_user_can( $op->get_required_cap() ) ) {
			return $this->error( sprintf(
				'You do not have permission to perform "%s" (requires the "%s" capability).',
				$action,
				$op->get_required_cap()
			) );
		}

		// Enforce the operation's required fields so logic that assumes they are
		// present (e.g. create requires title) never receives a half-formed call.
		foreach ( (array) ( $op->get_input_schema()['required'] ?? [] ) as $field ) {
			if ( 'action' === $field ) {
				continue;
			}
			if ( ! isset( $params[ $field ] ) || '' === $params[ $field ] ) {
				return $this->error( sprintf(
					'Action "%s" requires the "%s" parameter.',
					$action,
					$field
				) );
			}
		}

		return $op->execute( $params );
	}

	/**
	 * Pick the least-privileged capability among the ops to use as the coarse
	 * gate. The exact per-action capability is still enforced in execute(), so
	 * this only governs whether the tool is reachable at all.
	 */
	private function gate_cap(): string {
		static $rank = [
			'read'               => 0,
			'upload_files'       => 10,
			'edit_posts'         => 20,
			'edit_pages'         => 25,
			'edit_others_posts'  => 30,
			'publish_posts'      => 35,
			'delete_posts'       => 40,
			'moderate_comments'  => 45,
			'manage_categories'  => 50,
			'list_users'         => 55,
			'edit_theme_options' => 60,
			'edit_users'         => 70,
			'manage_options'     => 100,
		];

		$best     = 'manage_options';
		$best_val = PHP_INT_MAX;
		foreach ( $this->ops as $op ) {
			$cap = $op->get_required_cap();
			$val = $rank[ $cap ] ?? 90; // unknown caps rank high (treated as restrictive)
			if ( $val < $best_val ) {
				$best_val = $val;
				$best     = $cap;
			}
		}
		return $best;
	}
}
