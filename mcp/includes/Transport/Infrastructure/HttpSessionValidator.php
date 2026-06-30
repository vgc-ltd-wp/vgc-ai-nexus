<?php
/**
 * HTTP Session Validator for MCP Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles HTTP-specific session validation logic for MCP transports.
 *
 * Centralizes HTTP request context validation and session management coordination
 * to eliminate duplication across transport implementations.
 */
class HttpSessionValidator {

	/**
	 * Validate session for MCP HTTP requests.
	 *
	 * Performs complete session validation including HTTP headers, user
	 * authentication, and session validity.  Distinguishes between an expired
	 * session and a session that was never found so callers can surface
	 * actionable recovery guidance to the client.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array|true Returns true if valid, error array if invalid.
	 */
	public static function validate_session( HttpRequestContext $context ) {
		// Check session header presence.
		$session_id = $context->session_id;
		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( null, 'Missing Mcp-Session-Id header' )->toArray();
		}

		// Check user authentication.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( null, 'User not authenticated' )->toArray();
		}

		// Validate session — SessionManager now returns WP_Error on expiry, false on not-found.
		$valid = SessionManager::validate_session( $user_id, $session_id );

		if ( true === $valid ) {
			return true;
		}

		if ( is_wp_error( $valid ) && 'mcp_session_expired' === $valid->get_error_code() ) {
			// Session existed but timed out — tell the client exactly what to do next.
			return McpErrorFactory::session_not_found(
				null,
				'Session expired due to inactivity. Send a new initialize request to create a new session.'
			)->toArray();
		}

		// Session not found (never existed or already cleaned up).
		return McpErrorFactory::session_not_found(
			null,
			'Session not found. Send a new initialize request to create a session.'
		)->toArray();
	}

	/**
	 * Validate session header presence in HTTP request.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return string|array Session ID on success, error array on failure.
	 */
	public static function validate_session_header( HttpRequestContext $context ) {
		$session_id = $context->session_id;

		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( null, 'Missing Mcp-Session-Id header' )->toArray();
		}

		return $session_id;
	}

	/**
	 * Create a new session for the current user with HTTP context awareness.
	 *
	 * Validates user authentication and creates a session, storing the
	 * negotiated protocol version and clientInfo so they are available for
	 * the lifetime of the session (e.g. for activity logging).
	 *
	 * @param array       $params             Client parameters from initialize request.
	 * @param string|null $negotiated_version The protocol version agreed during handshake.
	 * @param array|null  $client_info        clientInfo block from the initialize request.
	 *
	 * @return string|array Session ID on success, error array on failure.
	 */
	public static function create_session( array $params = array(), ?string $negotiated_version = null, ?array $client_info = null ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( null, 'User authentication required for session creation' )->toArray();
		}

		$session_id = SessionManager::create_session( $user_id, $params, $negotiated_version, $client_info );

		if ( ! $session_id ) {
			return McpErrorFactory::internal_error( null, 'Failed to create session' )->toArray();
		}

		return $session_id;
	}

	/**
	 * Renew (heartbeat) an active session to prevent inactivity expiry.
	 *
	 * Called by the PATCH endpoint so long-running clients can keep a session
	 * alive without performing a full tool call.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array|true True on success, error array on failure.
	 */
	public static function renew_session( HttpRequestContext $context ) {
		$session_id = $context->session_id;
		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( null, 'Missing Mcp-Session-Id header' )->toArray();
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( null, 'User not authenticated' )->toArray();
		}

		if ( ! SessionManager::renew_session( $user_id, $session_id ) ) {
			return McpErrorFactory::session_not_found(
				null,
				'Session not found or already expired. Send a new initialize request.'
			)->toArray();
		}

		return true;
	}

	/**
	 * Terminate a session with full HTTP context validation.
	 *
	 * Performs complete validation workflow for session termination including
	 * header validation, user authentication, and session cleanup.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array|true Returns true on success, error array on failure.
	 */
	public static function terminate_session( HttpRequestContext $context ) {
		// Validate session header
		$session_id = $context->session_id;
		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( null, 'Missing Mcp-Session-Id header' )->toArray();
		}

		// Validate user authentication
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( null, 'User not authenticated' )->toArray();
		}

		// Terminate the session
		SessionManager::delete_session( $user_id, $session_id );

		return true;
	}
}
