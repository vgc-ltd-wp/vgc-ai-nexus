<?php
/**
 * MCP Session Manager using User Meta
 *
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP_Error;

/**
 * MCP Session Manager
 *
 * Handles session creation, validation, and cleanup using user meta storage.
 * Sessions are tied to authenticated users to prevent anonymous session flooding.
 */
final class SessionManager {

	/**
	 * User meta key for storing sessions
	 *
	 * @var string
	 */
	private const SESSION_META_KEY = 'mcp_adapter_sessions';

	/**
	 * Maximum sessions per user.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_SESSIONS = 32;

	/**
	 * Session inactivity timeout in seconds (7 days).
	 *
	 * Raised from 24 h to 7 days: AI desktop clients (Claude Desktop, Cursor, etc.)
	 * are long-running processes that frequently idle for days between uses.
	 * A 24-hour hard cut-off forced unnecessary re-initialise cycles.
	 * The value is still filterable via mcp_adapter_session_inactivity_timeout.
	 *
	 * @var int
	 */
	private const DEFAULT_INACTIVITY_TIMEOUT = WEEK_IN_SECONDS;

	/**
	 * Minimum interval between last_activity writes in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_ACTIVITY_UPDATE_INTERVAL = 60;

	/**
	 * Create a new session for a user.
	 *
	 * Uses a wp_cache-based spinlock to serialise concurrent creates for the
	 * same user, eliminating the read-modify-write race condition on the
	 * session meta array.  On sites with a persistent object cache (Redis /
	 * Memcached) the lock is guaranteed atomic.  On the default runtime cache
	 * the lock is in-process only, which is sufficient because concurrent
	 * PHP processes initialising as the same user simultaneously is
	 * exceptionally rare.
	 *
	 * @param int         $user_id            The user ID.
	 * @param array       $params             Client parameters from initialize request.
	 * @param string|null $negotiated_version Negotiated MCP protocol version.
	 * @param array|null  $client_info        clientInfo from the initialize request.
	 *
	 * @return string|false The session ID on success, false on failure.
	 */
	public static function create_session( int $user_id, array $params = array(), ?string $negotiated_version = null, ?array $client_info = null ) {
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}

		return self::with_session_lock(
			$user_id,
			static function () use ( $user_id, $params, $negotiated_version, $client_info ) {
				// Cleanup inactive sessions first (inside the lock).
				self::cleanup_expired_sessions( $user_id );

				// Get current sessions (fresh read inside the lock).
				$sessions = self::get_all_user_sessions( $user_id );

				// Check session limit - remove oldest if over limit.
				$config       = self::get_config();
				$max_sessions = $config['max_sessions'];
				if ( count( $sessions ) >= $max_sessions ) {
					uasort(
						$sessions,
						static function ( $a, $b ) {
							return $a['created_at'] <=> $b['created_at'];
						}
					);
					array_shift( $sessions );
				}

				// Create a new session.
				$session_id = wp_generate_uuid4();
				$now        = time();

				$sessions[ $session_id ] = array(
					'created_at'         => $now,
					'last_activity'      => $now,
					'client_params'      => $params,
					'negotiated_version' => $negotiated_version,
					'client_name'        => $client_info['name']    ?? null,
					'client_version'     => $client_info['version'] ?? null,
				);

				update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );

				return $session_id;
			}
		);
	}

	/**
	 * Cleanup inactive sessions for a user
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return int Number of sessions removed.
	 */
	public static function cleanup_expired_sessions( int $user_id ): int {
		if ( ! $user_id ) {
			return 0;
		}

		$sessions = self::get_all_user_sessions( $user_id );
		$now      = time();
		$removed  = 0;

		$config             = self::get_config();
		$inactivity_timeout = $config['inactivity_timeout'];

		foreach ( $sessions as $session_id => $session ) {
			// Check if still active - skip if valid
			if ( $session['last_activity'] + $inactivity_timeout >= $now ) {
				continue;
			}

			// Session is inactive - remove it
			unset( $sessions[ $session_id ] );
			++$removed;
		}

		if ( $removed > 0 ) {
			if ( empty( $sessions ) ) {
				delete_user_meta( $user_id, self::SESSION_META_KEY );
			} else {
				update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );
			}
		}

		return $removed;
	}

	/**
	 * Get all sessions for a user
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array Array of sessions.
	 */
	public static function get_all_user_sessions( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}

		$sessions = get_user_meta( $user_id, self::SESSION_META_KEY, true );

		if ( ! is_array( $sessions ) ) {
			return array();
		}

		return $sessions;
	}

	/**
	 * Get configuration values.
	 *
	 * @return array{max_sessions: int, inactivity_timeout: int, activity_update_interval: int} Configuration array.
	 */
	private static function get_config(): array {
		/**
		 * Filters the maximum number of MCP sessions allowed per user.
		 *
		 * When a user exceeds this limit, the oldest inactive session is
		 * automatically removed to make room for new sessions.
		 *
		 * @since 0.3.0
		 *
		 * @param int $max_sessions Maximum sessions per user. Default 32.
		 */
		$max_sessions = (int) apply_filters( 'mcp_adapter_session_max_per_user', self::DEFAULT_MAX_SESSIONS );

		/**
		 * Filters the session inactivity timeout in seconds.
		 *
		 * Sessions that have been inactive longer than this duration are
		 * considered expired and may be cleaned up automatically.
		 *
		 * @since 0.3.0
		 *
		 * @param int $timeout Inactivity timeout in seconds. Default DAY_IN_SECONDS (86400 / 24 hours).
		 */
		$inactivity_timeout = (int) apply_filters( 'mcp_adapter_session_inactivity_timeout', self::DEFAULT_INACTIVITY_TIMEOUT );

		/**
		 * Filters the minimum interval between session last_activity writes.
		 *
		 * To reduce write amplification, the session manager only updates
		 * `last_activity` if at least this many seconds have elapsed since
		 * the last write.
		 *
		 * @since 0.5.0
		 *
		 * @param int $interval Minimum seconds between writes. Default 60.
		 */
		$activity_update_interval = (int) apply_filters( 'mcp_adapter_session_activity_update_interval', self::DEFAULT_ACTIVITY_UPDATE_INTERVAL );

		// Clamp: interval must be less than inactivity timeout to prevent
		// sessions from expiring despite active use.
		if ( $activity_update_interval >= $inactivity_timeout ) {
			$activity_update_interval = (int) ( $inactivity_timeout / 2 );
		}

		return array(
			'max_sessions'             => $max_sessions,
			'inactivity_timeout'       => $inactivity_timeout,
			'activity_update_interval' => max( 0, $activity_update_interval ),
		);
	}

	/**
	 * Get a specific session for a user
	 *
	 * @param int $user_id The user ID.
	 * @param string $session_id The session ID.
	 *
	 * @return array|\WP_Error|false Session data on success, WP_Error on invalid input, false if not found or inactive.
	 */
	public static function get_session( int $user_id, string $session_id ) {
		if ( ! $user_id || ! $session_id ) {
			return new WP_Error( 'mcp_session_invalid_input', 'Invalid user ID or session ID.' );
		}

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		$session = $sessions[ $session_id ];

		// Check inactivity timeout
		$config             = self::get_config();
		$inactivity_timeout = $config['inactivity_timeout'];
		if ( $session['last_activity'] + $inactivity_timeout < time() ) {
			self::clear_session( $user_id, $session_id );

			return false;
		}

		return $session;
	}

	/**
	 * Clear an inactive session (internal cleanup).
	 *
	 * @param int $user_id The user ID.
	 * @param string $session_id The session ID to clear.
	 *
	 * @return void
	 */
	private static function clear_session( int $user_id, string $session_id ): void {
		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return;
		}

		unset( $sessions[ $session_id ] );
		update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );
	}

	/**
	 * Validate a session and update last activity.
	 *
	 * Returns true when the session is valid and active.
	 * Returns WP_Error( 'mcp_session_expired' ) when the session existed but
	 * has exceeded the inactivity timeout — callers can use this distinction
	 * to return a recovery-oriented error message to the client.
	 * Returns false when the session ID is simply not found (never existed or
	 * was already cleaned up).
	 *
	 * @param int    $user_id    The user ID.
	 * @param string $session_id The session ID.
	 *
	 * @return true|\WP_Error|false True if valid; WP_Error on timeout; false if not found.
	 */
	public static function validate_session( int $user_id, string $session_id ) {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		$session = $sessions[ $session_id ];

		// Check inactivity timeout — distinguish expired from not-found.
		$config             = self::get_config();
		$inactivity_timeout = $config['inactivity_timeout'];
		if ( $session['last_activity'] + $inactivity_timeout < time() ) {
			self::clear_session( $user_id, $session_id );

			return new WP_Error(
				'mcp_session_expired',
				sprintf(
					'Session expired after %d seconds of inactivity.',
					$inactivity_timeout
				)
			);
		}

		// Throttle last_activity writes to reduce write amplification.
		$activity_update_interval = $config['activity_update_interval'];
		if ( time() - $session['last_activity'] >= $activity_update_interval ) {
			$sessions[ $session_id ]['last_activity'] = time();
			update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );
		}

		return true;
	}

	/**
	 * Unconditionally bump last_activity for an active session.
	 *
	 * Used by the session-renewal (heartbeat) endpoint so long-running clients
	 * can keep a session alive without making a full tool call.
	 *
	 * @param int    $user_id    The user ID.
	 * @param string $session_id The session ID.
	 *
	 * @return bool True if the session exists and was updated, false otherwise.
	 */
	public static function renew_session( int $user_id, string $session_id ): bool {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		$config             = self::get_config();
		$inactivity_timeout = $config['inactivity_timeout'];
		if ( $sessions[ $session_id ]['last_activity'] + $inactivity_timeout < time() ) {
			// Already expired — clean up and report failure.
			self::clear_session( $user_id, $session_id );
			return false;
		}

		$sessions[ $session_id ]['last_activity'] = time();
		update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );

		return true;
	}

	/**
	 * Return the Unix timestamp when a session will expire.
	 *
	 * Used to populate the Mcp-Session-Expires response header so clients
	 * know when to schedule a renewal or re-initialise.
	 *
	 * @param int    $user_id    The user ID.
	 * @param string $session_id The session ID.
	 *
	 * @return int|false Expiry timestamp, or false if session not found.
	 */
	public static function get_session_expiry_time( int $user_id, string $session_id ) {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		$config = self::get_config();
		return $sessions[ $session_id ]['last_activity'] + $config['inactivity_timeout'];
	}

	/**
	 * Execute a callable while holding a per-user spinlock.
	 *
	 * Serialises concurrent session writes for the same user.  Uses
	 * wp_cache_add() as the locking primitive — this is a true atomic
	 * test-and-set on sites with a persistent object cache (Redis / Memcached).
	 * On the default in-memory runtime cache the lock is process-scoped, which
	 * is still effective against concurrent coroutines or fibers within the
	 * same PHP process.
	 *
	 * @template T
	 *
	 * @param int      $user_id The user whose session meta is being modified.
	 * @param callable $fn      The operation to run while the lock is held.
	 *                          Its return value is forwarded to the caller.
	 *
	 * @return mixed Return value of $fn.
	 */
	private static function with_session_lock( int $user_id, callable $fn ) {
		$lock_key   = 'mcp_sess_lk_' . $user_id;
		$lock_group = 'mcp_session_locks';
		$lock_ttl   = 5; // Seconds before the lock auto-expires (safety net).
		$acquired   = false;

		for ( $i = 0; $i < 10; $i++ ) {
			if ( wp_cache_add( $lock_key, 1, $lock_group, $lock_ttl ) ) {
				$acquired = true;
				break;
			}
			usleep( 50000 ); // 50 ms back-off between attempts.
		}

		try {
			// Do NOT run the callable if the lock was never acquired.
			// The original code ran $fn() unconditionally, defeating the entire
			// purpose of the lock: two concurrent processes could both enter the
			// critical section simultaneously and create duplicate sessions or
			// corrupt the session store (Issue 13).
			if ( ! $acquired ) {
				// Log the contention so it's visible in WP_DEBUG_LOG.
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'MCP SessionManager: session lock for user %d could not be acquired after 10 attempts — skipping operation.',
						$user_id
					)
				);
				return false;
			}
			return $fn();
		} finally {
			if ( $acquired ) {
				wp_cache_delete( $lock_key, $lock_group );
			}
		}
	}

	/**
	 * Delete a specific session
	 *
	 * @param int $user_id The user ID.
	 * @param string $session_id The session ID.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function delete_session( int $user_id, string $session_id ): bool {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		unset( $sessions[ $session_id ] );

		if ( empty( $sessions ) ) {
			delete_user_meta( $user_id, self::SESSION_META_KEY );
		} else {
			update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );
		}

		return true;
	}
}
