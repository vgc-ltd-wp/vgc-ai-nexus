<?php
/**
 * OAuth 2.0 client management.
 *
 * Handles both manually-created clients (admin UI) and dynamically-registered
 * clients (RFC 7591).  Dynamic clients are stored in the same option as manual
 * ones but are marked with 'dynamic': true so they can be distinguished and
 * optionally auto-expired.
 *
 * Registration needs no sign-in, so it is bounded (since 2.35.0): a dynamic
 * client starts with 'used_at' => 0 and gets the time when someone first
 * approves it on the consent page. Unused registrations expire after a day
 * and at most MAX_PENDING are kept; claude.ai registers once per "Connect",
 * so a real one is approved within minutes. Clients without 'used_at'
 * (manual ones, and registrations from before 2.35.0) count as used and are
 * never removed automatically.
 *
 * @package mcp-abilities
 */

declare( strict_types=1 );

namespace MCP_Abilities\OAuth;

final class Client_Manager {

	const ID_BYTES     = 16;
	const SECRET_BYTES = 32;

	/** Registrations nobody has approved yet: how many are kept, and for how long. */
	const MAX_PENDING = 50;
	const PENDING_TTL = 86400;

	/**
	 * All dynamic clients together. At this size the oldest ones that hold no
	 * live token make room; if every one is still connected, registration is
	 * refused until an administrator disconnects some on the Connect page.
	 */
	const MAX_DYNAMIC = 200;

	const MAX_REDIRECT_URIS = 10;
	const MAX_URI_LENGTH    = 2000;
	const MAX_NAME_LENGTH   = 100;

	// ── Read ───────────────────────────────────────────────────────────────

	public static function get_all(): array {
		return (array) get_option( Options::CLIENTS, array() );
	}

	public static function get( string $client_id ): ?array {
		return self::get_all()[ $client_id ] ?? null;
	}

	// ── Create ─────────────────────────────────────────────────────────────

	/**
	 * @return array{client_id: string, client_secret: string}
	 */
	public static function create( string $name, array $redirect_uris, int $user_id, bool $dynamic = false ): array {
		return self::insert( self::get_all(), $name, $redirect_uris, $user_id, $dynamic );
	}

	/**
	 * @param array<string, array<string, mixed>> $clients Every client, as it is to be stored.
	 * @return array{client_id: string, client_secret: string}
	 */
	private static function insert( array $clients, string $name, array $redirect_uris, int $user_id, bool $dynamic ): array {
		$client_id     = self::generate_token( self::ID_BYTES );
		$client_secret = self::generate_token( self::SECRET_BYTES );

		$clients[ $client_id ] = array(
			'name'          => sanitize_text_field( $name ),
			'secret_hash'   => hash( 'sha256', $client_secret ),
			'redirect_uris' => array_values( array_filter( array_map( 'esc_url_raw', $redirect_uris ) ) ),
			'user_id'       => (int) $user_id,
			'dynamic'       => $dynamic,
			'created_at'    => time(),
		);
		if ( $dynamic ) {
			$clients[ $client_id ]['used_at'] = 0;
		}
		update_option( Options::CLIENTS, $clients, false );

		return array( 'client_id' => $client_id, 'client_secret' => $client_secret );
	}

	/**
	 * Note that someone approved this client on the consent page. Written once:
	 * from then on the registration is never removed automatically.
	 */
	public static function mark_used( string $client_id ): void {
		$clients = self::get_all();
		if ( ! isset( $clients[ $client_id ] ) || ! self::is_pending( $clients[ $client_id ] ) ) {
			return;
		}
		$clients[ $client_id ]['used_at'] = time();
		update_option( Options::CLIENTS, $clients, false );
	}

	/** A dynamic registration nobody has approved yet. */
	public static function is_pending( array $client ): bool {
		return ! empty( $client['dynamic'] ) && array_key_exists( 'used_at', $client ) && empty( $client['used_at'] );
	}

	/**
	 * Make room for one more dynamic registration.
	 *
	 * @param array<string, array<string, mixed>> $clients
	 * @return array{0: array<string, array<string, mixed>>, 1: string[]} The clients to keep, the ids removed.
	 */
	private static function make_room( array $clients ): array {
		$removed = array();
		$drop    = static function ( string $id ) use ( &$clients, &$removed ): void {
			unset( $clients[ $id ] );
			$removed[] = $id;
		};

		// 1. Unused registrations older than a day.
		foreach ( $clients as $id => $c ) {
			if ( self::is_pending( (array) $c ) && (int) ( $c['created_at'] ?? 0 ) < time() - self::PENDING_TTL ) {
				$drop( (string) $id );
			}
		}

		// 2. At most MAX_PENDING unused ones, the new one included: the oldest go.
		$pending = array_keys( array_filter( $clients, static fn( $c ) => self::is_pending( (array) $c ) ) );
		foreach ( array_slice( self::sort_by_age( $clients, $pending ), 0, max( 0, count( $pending ) - self::MAX_PENDING + 1 ) ) as $id ) {
			$drop( (string) $id );
		}

		// 3. The ceiling: the oldest dynamic clients that no live token uses.
		$dynamic = array_keys( array_filter( $clients, static fn( $c ) => ! empty( $c['dynamic'] ) ) );
		$excess  = count( $dynamic ) - self::MAX_DYNAMIC + 1;
		if ( $excess > 0 ) {
			$live = Token_Store::summary_by_client();
			$idle = array_values( array_filter( $dynamic, static fn( $id ) => empty( $live[ (string) $id ] ) ) );
			foreach ( array_slice( self::sort_by_age( $clients, $idle ), 0, $excess ) as $id ) {
				$drop( (string) $id );
				Operators::delete_for_client( (string) $id );
			}
		}

		return array( $clients, $removed );
	}

	/**
	 * @param array<string, array<string, mixed>> $clients
	 * @param array<int, string|int>              $ids
	 * @return string[]
	 */
	private static function sort_by_age( array $clients, array $ids ): array {
		$ids = array_map( 'strval', $ids );
		usort( $ids, static fn( $a, $b ) => (int) ( $clients[ $a ]['created_at'] ?? 0 ) <=> (int) ( $clients[ $b ]['created_at'] ?? 0 ) );
		return $ids;
	}

	// ── RFC 7591 — Dynamic Client Registration ─────────────────────────────

	/**
	 * Registers a client dynamically per RFC 7591.
	 *
	 * Returns a WP_Error when registration is not permitted by the admin policy
	 * or when the submitted redirect_uris don't match the allowed origins.
	 *
	 * @param array  $registration_request  Decoded request body.
	 * @param int    $default_user_id       WordPress user ID to map the client to.
	 * @return array{client_id:string,client_secret:string,...}|\WP_Error
	 */
	public static function dynamic_register( array $registration_request, int $default_user_id ) {
		$policy = (string) get_option( Options::REG_POLICY, 'restricted' );

		if ( 'closed' === $policy ) {
			return new \WP_Error(
				'registration_not_supported',
				__( 'Dynamic client registration is disabled on this server.', 'mcp-abilities' ),
				array( 'status' => 403 )
			);
		}

		$redirect_uris = isset( $registration_request['redirect_uris'] ) && is_array( $registration_request['redirect_uris'] )
			? $registration_request['redirect_uris']
			: array();

		if ( empty( $redirect_uris ) ) {
			return new \WP_Error( 'invalid_redirect_uri', __( 'At least one redirect_uri is required.', 'mcp-abilities' ), array( 'status' => 400 ) );
		}
		// Everything below is stored, and registering needs no sign-in.
		if ( count( $redirect_uris ) > self::MAX_REDIRECT_URIS ) {
			/* translators: %d: maximum number of redirect URIs */
			return new \WP_Error( 'invalid_redirect_uri', sprintf( __( 'At most %d redirect_uris are accepted.', 'mcp-abilities' ), self::MAX_REDIRECT_URIS ), array( 'status' => 400 ) );
		}
		foreach ( $redirect_uris as $uri ) {
			if ( ! is_string( $uri ) || '' === $uri || strlen( $uri ) > self::MAX_URI_LENGTH ) {
				/* translators: %d: maximum length of a redirect URI */
				return new \WP_Error( 'invalid_redirect_uri', sprintf( __( 'Each redirect_uri must be an address of at most %d characters.', 'mcp-abilities' ), self::MAX_URI_LENGTH ), array( 'status' => 400 ) );
			}
		}

		// Validate redirect URIs against policy.
		if ( 'restricted' === $policy ) {
			$allowed_origins = (array) get_option( Options::ALLOWED_ORIGINS, Options::DEFAULT_ORIGINS );
			foreach ( $redirect_uris as $uri ) {
				if ( ! self::uri_matches_origins( (string) $uri, $allowed_origins ) ) {
					return new \WP_Error(
						'invalid_redirect_uri',
						sprintf(
							/* translators: %s: rejected redirect URI */
							__( 'redirect_uri "%s" is not permitted by the server registration policy.', 'mcp-abilities' ),
							esc_url( $uri )
						),
						array( 'status' => 400 )
					);
				}
			}
		}

		$client_name = isset( $registration_request['client_name'] ) && is_string( $registration_request['client_name'] )
			? mb_substr( sanitize_text_field( $registration_request['client_name'] ), 0, self::MAX_NAME_LENGTH )
			: '';
		if ( '' === $client_name ) {
			$client_name = __( 'Dynamic Client', 'mcp-abilities' );
		}

		// Echo back the client's requested token endpoint auth method.  Claude
		// registers as a public client using PKCE ("none"); forcing
		// "client_secret_post" can make the client reject the registration.
		$auth_method = isset( $registration_request['token_endpoint_auth_method'] )
			? sanitize_text_field( (string) $registration_request['token_endpoint_auth_method'] )
			: 'none';
		if ( ! in_array( $auth_method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			$auth_method = 'none';
		}

		[ $clients, $removed ] = self::make_room( self::get_all() );
		if ( $removed ) {
			Server::log( sprintf( 'removed %d app registration(s) nobody uses, to make room', count( $removed ) ) );
		}
		if ( count( array_filter( $clients, static fn( $c ) => ! empty( $c['dynamic'] ) ) ) >= self::MAX_DYNAMIC ) {
			if ( $removed ) {
				update_option( Options::CLIENTS, $clients, false );
			}
			return new \WP_Error(
				'too_many_clients',
				__( 'This site has too many connected apps. An administrator can disconnect unused ones on the AI Nexus Connect page.', 'mcp-abilities' ),
				array( 'status' => 503 )
			);
		}

		$credentials = self::insert( $clients, $client_name, $redirect_uris, $default_user_id, true );

		// RFC 7591 §3.2.1 response fields.  Always include client_secret so a
		// confidential client can use it; a public client (auth_method=none)
		// simply ignores it and uses PKCE.
		return array_merge(
			$credentials,
			array(
				'client_name'                => $client_name,
				'redirect_uris'              => $redirect_uris,
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => $auth_method,
				'client_id_issued_at'        => time(),
				// No expiry — clients are valid until manually deleted.
				'client_secret_expires_at'   => 0,
			)
		);
	}

	// ── Delete ─────────────────────────────────────────────────────────────

	public static function delete( string $client_id ): void {
		$clients = self::get_all();
		unset( $clients[ $client_id ] );
		update_option( Options::CLIENTS, $clients, false );
	}

	// ── Validation ─────────────────────────────────────────────────────────

	public static function verify_secret( string $client_id, string $client_secret ): bool {
		$client = self::get( $client_id );
		return null !== $client && hash_equals( $client['secret_hash'], hash( 'sha256', $client_secret ) );
	}

	public static function is_valid_redirect_uri( string $client_id, string $redirect_uri ): bool {
		$client = self::get( $client_id );
		return null !== $client && in_array( $redirect_uri, (array) $client['redirect_uris'], true );
	}

	// ── Display data ───────────────────────────────────────────────────────

	public static function get_display_data(): array {
		$out = array();
		foreach ( self::get_all() as $client_id => $data ) {
			$user  = get_user_by( 'id', (int) ( $data['user_id'] ?? 0 ) );
			$out[] = array_merge( $data, array( 'client_id' => $client_id, 'user' => $user instanceof \WP_User ? $user : null ) );
		}
		return $out;
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	public static function generate_token( int $bytes = 32 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	/** Whether a redirect address is on one of Claude's own hosts (claude.ai, claude.com, anthropic.com). */
	public static function is_claude_redirect( string $uri ): bool {
		return self::uri_matches_origins( $uri, Options::DEFAULT_ORIGINS );
	}

	/**
	 * Check whether a redirect URI's host matches any of the allowed origin patterns.
	 * Supports wildcards: '*.claude.ai' matches 'claude.ai' and 'app.claude.ai'.
	 */
	private static function uri_matches_origins( string $uri, array $patterns ): bool {
		$host = (string) ( wp_parse_url( $uri, PHP_URL_HOST ) ?? '' );
		if ( '' === $host ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( str_starts_with( $pattern, '*.' ) ) {
				$base = substr( $pattern, 2 );
				if ( $host === $base || str_ends_with( $host, '.' . $base ) ) {
					return true;
				}
			} elseif ( $host === $pattern ) {
				return true;
			}
		}
		return false;
	}
}
