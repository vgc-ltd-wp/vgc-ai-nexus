<?php
/**
 * OAuth 2.0 client management.
 *
 * Handles both manually-created clients (admin UI) and dynamically-registered
 * clients (RFC 7591).  Dynamic clients are stored in the same option as manual
 * ones but are marked with 'dynamic': true so they can be distinguished and
 * optionally auto-expired.
 *
 * @package mcp-abilities
 */

declare( strict_types=1 );

namespace MCP_Abilities\OAuth;

final class Client_Manager {

	const ID_BYTES     = 16;
	const SECRET_BYTES = 32;

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
		$client_id     = self::generate_token( self::ID_BYTES );
		$client_secret = self::generate_token( self::SECRET_BYTES );

		$clients = self::get_all();
		$clients[ $client_id ] = array(
			'name'          => sanitize_text_field( $name ),
			'secret_hash'   => hash( 'sha256', $client_secret ),
			'redirect_uris' => array_values( array_filter( array_map( 'esc_url_raw', $redirect_uris ) ) ),
			'user_id'       => (int) $user_id,
			'dynamic'       => $dynamic,
			'created_at'    => time(),
		);
		update_option( Options::CLIENTS, $clients, false );

		return array( 'client_id' => $client_id, 'client_secret' => $client_secret );
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

		$client_name = isset( $registration_request['client_name'] )
			? sanitize_text_field( $registration_request['client_name'] )
			: __( 'Dynamic Client', 'mcp-abilities' );

		// Echo back the client's requested token endpoint auth method.  Claude
		// registers as a public client using PKCE ("none"); forcing
		// "client_secret_post" can make the client reject the registration.
		$auth_method = isset( $registration_request['token_endpoint_auth_method'] )
			? sanitize_text_field( (string) $registration_request['token_endpoint_auth_method'] )
			: 'none';
		if ( ! in_array( $auth_method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			$auth_method = 'none';
		}

		$credentials = self::create( $client_name, $redirect_uris, $default_user_id, true );

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
