<?php
/**
 * Who is at the keyboard of an OAuth connection ("operator").
 *
 * Claude never tells the site which Claude account is connecting, so when
 * several people share one WordPress account, "Admin — via MCP (Claude)"
 * cannot tell them apart. The consent page asks, optionally, "Who is
 * connecting?"; an administrator can add or change the answer later on the
 * Connect page. It is NOT checked: it only labels the changes made through
 * that connection, e.g. "Admin (maria@company.com) — via MCP (Claude)".
 *
 * Stored in one non-autoloaded option, a map keyed by connection:
 *   "<client_id>:<user_id>" => { name, set_at, set_by }
 * Keyed by client + user (not by token), so a refresh keeps it and Disconnect
 * can drop it. A change applies to later calls only: revisions, comments and
 * orders keep the snapshot they were labelled with.
 *
 * @package mcp-abilities
 */

namespace MCP_Abilities\OAuth;

defined( 'ABSPATH' ) || exit;

final class Operators {

	const OPTION = 'mcp_abilities_oauth_operators';
	const MAX    = 100;

	/**
	 * A name as typed: plain text, one line, no control characters, at most
	 * MAX characters.
	 *
	 * @param mixed $raw Unslashed input.
	 */
	public static function sanitize( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$name = sanitize_text_field( (string) $raw );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', '', $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, self::MAX );
		} else {
			$name = substr( $name, 0, self::MAX );
		}
		return trim( $name );
	}

	/** Whether the consent page insists on a name (filter, default false). */
	public static function required(): bool {
		/**
		 * Whether "Who is connecting?" must be filled in before Approve.
		 *
		 * @param bool $required Default false.
		 */
		return (bool) apply_filters( 'mcp_abilities_oauth_operator_required', false );
	}

	private static function key( string $client_id, int $user_id ): string {
		return $client_id . ':' . $user_id;
	}

	/** @return array<string, array{name:string,set_at:int,set_by:int}> */
	public static function all(): array {
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/** The name for a connection, or ''. */
	public static function get( string $client_id, int $user_id ): string {
		if ( '' === $client_id || $user_id <= 0 ) {
			return '';
		}
		$entry = self::all()[ self::key( $client_id, $user_id ) ] ?? null;
		return is_array( $entry ) ? (string) ( $entry['name'] ?? '' ) : '';
	}

	/**
	 * Set (or, with '', remove) the name for a connection.
	 *
	 * @param string $name   Already sanitized; sanitized again here.
	 * @param int    $set_by The user who set it (the consenting user or an admin).
	 */
	public static function set( string $client_id, int $user_id, string $name, int $set_by ): void {
		if ( '' === $client_id || $user_id <= 0 ) {
			return;
		}
		$map  = self::all();
		$key  = self::key( $client_id, $user_id );
		$name = self::sanitize( $name );
		if ( '' === $name ) {
			unset( $map[ $key ] );
		} else {
			$map[ $key ] = array(
				'name'   => $name,
				'set_at' => time(),
				'set_by' => $set_by,
			);
		}
		self::save( $map );
	}

	/** Disconnect: forget every name stored for a client. */
	public static function delete_for_client( string $client_id ): void {
		$map    = self::all();
		$prefix = $client_id . ':';
		foreach ( array_keys( $map ) as $key ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				unset( $map[ $key ] );
			}
		}
		self::save( $map );
	}

	private static function save( array $map ): void {
		if ( ! $map ) {
			delete_option( self::OPTION );
			return;
		}
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $map, '', false );
		} else {
			update_option( self::OPTION, $map, false );
		}
	}
}
