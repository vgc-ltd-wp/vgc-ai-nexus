<?php
/**
 * Tool schemas in the shape every client accepts (2.53.2).
 *
 * A JSON Schema is an object (or true/false). In PHP an empty array is
 * written as JSON `[]`, which is neither. A nested `'value' => []` (meant:
 * "any value") therefore reaches the client as `"value": []`, and the
 * Claude API refuses the whole tool: "…/properties/value must be
 * object,boolean". The tool silently disappears from the connector (seen
 * 2026-11-25 with the Avada extension's theme-options tool, broken since
 * Avada 1.2.0). An empty `properties` written as `[]` breaks strict clients
 * the same way.
 *
 * repair() runs on every schema core registers (single tools and grouped
 * tools, core's and every extension's), at any depth:
 * - a subschema that is an empty array or a JSON list becomes "any value"
 *   (a type list of all JSON types, so WordPress's own validator has a type);
 * - an empty or list-shaped `properties` inside the schema is dropped (an
 *   object with no declared properties); the top level keeps core's
 *   long-standing rule in Plugin::register_wp_abilities();
 * - `required` that is not a list of strings is dropped.
 * problems() lists what repair() would change, for tests and diagnostics.
 *
 * @package mcp-abilities
 */

declare( strict_types=1 );

namespace MCP_Abilities;

final class Schema_Shape {

	/** "Any JSON value", with a type WordPress's validator accepts. */
	public const ANY = [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'object', 'array', 'null' ] ];

	/** Keys whose value is one subschema. */
	private const ONE = [ 'items', 'additionalProperties', 'not', 'contains', 'propertyNames', 'if', 'then', 'else' ];

	/** Keys whose value is a list of subschemas. */
	private const MANY = [ 'anyOf', 'oneOf', 'allOf', 'prefixItems' ];

	/** Keys whose value is a map of name => subschema. */
	private const MAP = [ 'properties', 'patternProperties', '$defs', 'definitions' ];

	public static function init(): void {
		add_filter( 'mcp_adapter_tools_list', [ self::class, 'filter_tools_list' ], 1000, 2 );
	}

	/**
	 * mcp_adapter_tools_list, last: a tool whose input schema has a problem
	 * goes out repaired. Extensions add their tools to the server themselves
	 * (mcp_adapter_default_server_config), past core's registration, so this
	 * is the one place every listed tool passes. A well-formed tool is left
	 * exactly as it was; only the listing changes, never what a call accepts.
	 *
	 * @param mixed $tools
	 * @param mixed $server
	 * @return mixed
	 */
	public static function filter_tools_list( $tools, $server = null ) {
		$dto = '\WP\McpSchema\Server\Tools\DTO\Tool';
		if ( ! is_array( $tools ) || ! class_exists( $dto ) ) {
			return $tools;
		}
		foreach ( $tools as $i => $tool ) {
			if ( ! $tool instanceof $dto ) {
				continue;
			}
			try {
				$schema = $tool->getInputSchema()->toArray();
				// The adapter writes an empty top-level properties as {}.
				if ( isset( $schema['properties'] ) && is_object( $schema['properties'] ) ) {
					$schema['properties'] = get_object_vars( $schema['properties'] );
				}
				if ( [] === self::problems( $schema ) ) {
					continue;
				}
				$data                = $tool->toArray();
				$data['inputSchema'] = self::repair( $schema );
				$tools[ $i ]         = $dto::fromArray( $data );
			} catch ( \Throwable $e ) {
				// Leave the tool as it was rather than lose it from the list.
				continue;
			}
		}
		return $tools;
	}

	/**
	 * @param array<string|int, mixed> $schema A schema as registered.
	 * @return array<string|int, mixed>
	 */
	public static function repair( array $schema ): array {
		return self::walk( $schema, true );
	}

	/**
	 * Where a schema is not in the shape clients accept, as JSON-pointer-like
	 * paths ("properties/options/items/properties/value: empty array").
	 *
	 * @param array<string|int, mixed> $schema
	 * @return string[]
	 */
	public static function problems( array $schema ): array {
		$out = [];
		self::inspect( $schema, '', $out, true );
		return $out;
	}

	// ── Internals ──────────────────────────────────────────────────────────

	/** @param array<string|int, mixed> $schema */
	private static function walk( array $schema, bool $top ): array {
		foreach ( self::MAP as $key ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				continue;
			}
			$map = $schema[ $key ];
			if ( ! is_array( $map ) || [] === $map || self::is_list( $map ) ) {
				if ( $top && 'properties' === $key ) {
					// The top level keeps an empty map: the caller gives it a
					// placeholder property (Plugin::register_wp_abilities()).
					$schema[ $key ] = [];
				} else {
					unset( $schema[ $key ] );
				}
				continue;
			}
			foreach ( $map as $name => $sub ) {
				$map[ $name ] = self::sub( $sub );
			}
			$schema[ $key ] = $map;
		}
		foreach ( self::ONE as $key ) {
			if ( array_key_exists( $key, $schema ) ) {
				$schema[ $key ] = self::sub( $schema[ $key ] );
			}
		}
		foreach ( self::MANY as $key ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				continue;
			}
			if ( ! is_array( $schema[ $key ] ) || [] === $schema[ $key ] || ! self::is_list( $schema[ $key ] ) ) {
				unset( $schema[ $key ] );
				continue;
			}
			$schema[ $key ] = array_map( [ self::class, 'sub' ], $schema[ $key ] );
		}
		if ( array_key_exists( 'required', $schema ) && ! self::is_string_list( $schema['required'] ) ) {
			unset( $schema['required'] );
		}
		return $schema;
	}

	/**
	 * One subschema: true/false stay, an empty array or a list becomes "any
	 * value", anything else that is not an array becomes "any value" too.
	 *
	 * @param mixed $sub
	 * @return array<string|int, mixed>|bool
	 */
	private static function sub( $sub ) {
		if ( is_bool( $sub ) ) {
			return $sub;
		}
		if ( ! is_array( $sub ) || [] === $sub || self::is_list( $sub ) ) {
			return self::ANY;
		}
		return self::walk( $sub, false );
	}

	/**
	 * @param array<string|int, mixed> $schema
	 * @param string[]                 $out
	 */
	private static function inspect( array $schema, string $path, array &$out, bool $top ): void {
		$at = static fn ( string $p ): string => '' === $path ? $p : $path . '/' . $p;
		foreach ( self::MAP as $key ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				continue;
			}
			$map = $schema[ $key ];
			if ( ! is_array( $map ) || [] === $map || self::is_list( $map ) ) {
				if ( ! ( $top && 'properties' === $key && [] === $map ) ) {
					$out[] = $at( $key ) . ': ' . self::kind( $map ) . ' (must be an object of schemas)';
				}
				continue;
			}
			foreach ( $map as $name => $sub ) {
				self::inspect_sub( $sub, $at( $key . '/' . $name ), $out );
			}
		}
		foreach ( self::ONE as $key ) {
			if ( array_key_exists( $key, $schema ) ) {
				self::inspect_sub( $schema[ $key ], $at( $key ), $out );
			}
		}
		foreach ( self::MANY as $key ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				continue;
			}
			if ( ! is_array( $schema[ $key ] ) || [] === $schema[ $key ] || ! self::is_list( $schema[ $key ] ) ) {
				$out[] = $at( $key ) . ': ' . self::kind( $schema[ $key ] ) . ' (must be a non-empty list of schemas)';
				continue;
			}
			foreach ( $schema[ $key ] as $i => $sub ) {
				self::inspect_sub( $sub, $at( $key . '/' . $i ), $out );
			}
		}
		if ( array_key_exists( 'required', $schema ) && ! self::is_string_list( $schema['required'] ) ) {
			$out[] = $at( 'required' ) . ': ' . self::kind( $schema['required'] ) . ' (must be a list of names)';
		}
	}

	/**
	 * @param mixed    $sub
	 * @param string[] $out
	 */
	private static function inspect_sub( $sub, string $path, array &$out ): void {
		if ( is_bool( $sub ) ) {
			return;
		}
		if ( ! is_array( $sub ) || [] === $sub || self::is_list( $sub ) ) {
			$out[] = $path . ': ' . self::kind( $sub ) . ' (must be a schema object or true/false)';
			return;
		}
		self::inspect( $sub, $path, $out, false );
	}

	/** @param mixed $v */
	private static function kind( $v ): string {
		if ( [] === $v ) {
			return 'empty array';
		}
		if ( is_array( $v ) ) {
			return self::is_list( $v ) ? 'a list' : 'an object';
		}
		return gettype( $v );
	}

	/** array_is_list() of PHP 8.1 (AI Nexus runs on 8.0). @param array<mixed> $v */
	private static function is_list( array $v ): bool {
		$i = 0;
		foreach ( $v as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}

	/** @param mixed $v */
	private static function is_string_list( $v ): bool {
		if ( ! is_array( $v ) || ! self::is_list( $v ) ) {
			return false;
		}
		foreach ( $v as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}
}
