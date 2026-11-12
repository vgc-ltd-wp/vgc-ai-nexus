<?php
/**
 * How long an OAuth connection lives, and what ends it (2.39.0).
 *
 * @package mcp-abilities
 */

namespace MCP_Abilities\OAuth;

use MCP_Abilities\Connection_Approval;
use MCP_Abilities\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * A connection made through the consent page must not outlive the account
 * that made it (access analysis 2026-11-06, H2 and L2), nor stay on for
 * ever when nobody uses it (the owner's decision 2):
 *
 *  - Every code and token carries a fingerprint of the user's password hash
 *    (fingerprint()). A token whose fingerprint no longer matches is refused
 *    and deleted, so any password change ends the connection: a reset, the
 *    profile screen, WP-CLI, wp_set_password(), another plugin, even a direct
 *    database write. A row from before 2.39.0 gets the fingerprint of the
 *    password it is first used with.
 *  - A token whose user or app is gone is refused and deleted too, and a
 *    user's codes and tokens are deleted as soon as the user is deleted,
 *    removed from the site, or given another role (they connect again).
 *    Since 2.42.0 also when a role is added or removed (add_user_role,
 *    remove_user_role: WP_User::add_role(), remove_role() and other plugins
 *    that never call set_role()), and whatever ends a connection here also
 *    resets its "require admin approval" record, so connecting again waits
 *    for approval again while approval is on (a rejection stays).
 *  - An unused refresh token lives idle_days() (14, filter
 *    mcp_abilities_idle_days, 1–365), pushed forward on each use.
 *
 * Runs whoever serves the sign-in (AI Nexus or an older connector): the rows
 * are the same.
 *
 * At upgrade, once (option UPGRADES):
 *  - a stored registration policy "open" (written by the old connector) is set
 *    to "restricted", with a notice on AI Nexus pages; an administrator may
 *    set "open" again, and the step never runs again;
 *  - every refresh token that ran longer is cut to now + idle_days(), so
 *    nobody is cut off on the spot.
 */
final class Lifecycle {

	const IDLE_DAYS      = 14;
	const UPGRADES       = 'mcp_abilities_oauth_upgrades';
	const POLICY_NOTICE  = 'mcp_abilities_policy_notice';
	const NOTICE_ACTION  = 'mcp_abilities_dismiss_policy_notice';

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'deleted_user', array( self::class, 'on_deleted_user' ), 10, 1 );
		add_action( 'remove_user_from_blog', array( self::class, 'on_removed_from_site' ), 10, 1 );
		add_action( 'set_user_role', array( self::class, 'on_role_changed' ), 10, 3 );
		// 2.42.0: a role added or taken away is a new role too. set_role() fires
		// these as well; the second and third call find nothing left to remove.
		add_action( 'add_user_role', array( self::class, 'on_role_added' ), 10, 2 );
		add_action( 'remove_user_role', array( self::class, 'on_role_removed' ), 10, 2 );

		if ( did_action( 'init' ) ) {
			self::maybe_upgrade();
		} else {
			add_action( 'init', array( self::class, 'maybe_upgrade' ), 1 );
		}
		add_action( 'admin_notices', array( self::class, 'policy_notice' ) );
		add_action( 'admin_post_' . self::NOTICE_ACTION, array( self::class, 'dismiss_policy_notice' ) );
	}

	// ── Lifetime ─────────────────────────────────────────────────────────────

	/** Days an unused connection stays on (filter mcp_abilities_idle_days, 1–365). */
	public static function idle_days(): int {
		/**
		 * After how many days without use a connection is switched off: an
		 * OAuth refresh token, and an Application Password made on AI Nexus's
		 * Connect page.
		 *
		 * @param int $days Default 14. Kept between 1 and 365.
		 */
		$days = (int) apply_filters( 'mcp_abilities_idle_days', self::IDLE_DAYS );
		return max( 1, min( 365, $days ) );
	}

	public static function idle_seconds(): int {
		return self::idle_days() * DAY_IN_SECONDS;
	}

	// ── Password fingerprint ─────────────────────────────────────────────────

	/**
	 * 16 hex characters derived from the user's stored password hash with the
	 * site's auth salt. Reveals nothing about the password; changes whenever
	 * the hash does. '' when the user does not exist.
	 *
	 * @param \WP_User|int $user
	 */
	public static function fingerprint( $user ): string {
		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', (string) $user->user_pass, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * What is wrong with a stored code or token on use: '' (nothing), 'user'
	 * (the user is gone), 'client' (the app is no longer registered) or
	 * 'password' (the password changed since it was issued).
	 *
	 * @param array<string, mixed> $data A Token_Store row.
	 */
	public static function row_problem( array $data ): string {
		$user = get_userdata( (int) ( $data['user_id'] ?? 0 ) );
		if ( ! $user instanceof \WP_User ) {
			return 'user';
		}
		if ( null === Client_Manager::get( (string) ( $data['client_id'] ?? '' ) ) ) {
			return 'client';
		}
		if ( ! empty( $data['fp'] ) && ! hash_equals( self::fingerprint( $user ), (string) $data['fp'] ) ) {
			return 'password';
		}
		return '';
	}

	/**
	 * Whether a row still holds without using it: not expired, its user exists
	 * and has the password it was issued with (or it predates fingerprints).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function row_is_live( array $data ): bool {
		if ( (int) ( $data['expires'] ?? 0 ) < time() ) {
			return false;
		}
		$user = get_userdata( (int) ( $data['user_id'] ?? 0 ) );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		return empty( $data['fp'] ) || hash_equals( self::fingerprint( $user ), (string) $data['fp'] );
	}

	/**
	 * A row was refused on use: delete everything that is dead for the same
	 * reason (all of the user's rows; the app's rows; the user's rows issued
	 * under an older password) and log it once.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function on_dead_row( array $data, string $problem ): void {
		$user_id   = (int) ( $data['user_id'] ?? 0 );
		$client_id = (string) ( $data['client_id'] ?? '' );
		switch ( $problem ) {
			case 'user':
				self::revoke( $user_id, null, 'the user no longer exists' );
				break;
			case 'client':
				$n = Token_Store::delete_for_client( $client_id );
				if ( class_exists( Connections::class ) ) {
					Connections::forget_client( $client_id );
				}
				Server::log( sprintf( 'app client=%s is no longer registered: its %d code(s)/token(s) removed', $client_id, $n ) );
				break;
			case 'password':
				self::revoke_stale( $user_id );
				break;
		}
	}

	// ── Revoking ─────────────────────────────────────────────────────────────

	/**
	 * Delete a user's codes and tokens (of one app, or all), their entries in
	 * the list of connections and (2.42.0) their approval records, except a
	 * rejection. Logged when anything was removed.
	 *
	 * @return int Rows removed.
	 */
	public static function revoke( int $user_id, ?string $client_id, string $why ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$n = Token_Store::delete_for_user( $user_id, $client_id );
		if ( class_exists( Connections::class ) ) {
			Connections::forget_oauth( $user_id, $client_id );
		}
		// Whether or not a token was still there: an "approved" record left
		// behind would let the next sign-in of this app and user straight through.
		$a = class_exists( Connection_Approval::class ) ? Connection_Approval::forget_oauth( $user_id, $client_id ) : 0;
		if ( $n > 0 ) {
			Server::log( sprintf( 'removed %d code(s)/token(s) of user_id=%d%s: %s', $n, $user_id, null !== $client_id ? ' client=' . $client_id : '', $why ) );
		}
		if ( $a > 0 ) {
			Server::log( sprintf( 'reset the approval of %d connection(s) of user_id=%d%s: %s; connecting again waits for approval while it is required', $a, $user_id, null !== $client_id ? ' client=' . $client_id : '', $why ) );
		}
		return $n;
	}

	/** The user's rows issued under another password than the current one. */
	public static function revoke_stale( int $user_id ): int {
		$fp = self::fingerprint( $user_id );
		if ( '' === $fp ) {
			return self::revoke( $user_id, null, 'the user no longer exists' );
		}
		$n = Token_Store::delete_where( static function ( string $type, array $data ) use ( $user_id, $fp ): bool {
			return (int) ( $data['user_id'] ?? 0 ) === $user_id && ! empty( $data['fp'] ) && ! hash_equals( $fp, (string) $data['fp'] );
		} );
		if ( class_exists( Connections::class ) ) {
			Connections::prune_oauth( $user_id );
		}
		if ( $n > 0 ) {
			Server::log( sprintf( 'removed %d code(s)/token(s) of user_id=%d: the password changed after they were issued', $n, $user_id ) );
		}
		return $n;
	}

	/** deleted_user */
	public static function on_deleted_user( $user_id ): void {
		self::revoke( (int) $user_id, null, 'user deleted' );
		Operators::delete_for_user( (int) $user_id );
	}

	/** remove_user_from_blog: WordPress has switched to that site already. */
	public static function on_removed_from_site( $user_id ): void {
		self::revoke( (int) $user_id, null, 'user removed from the site' );
	}

	/**
	 * set_user_role: a new role means approving again. Not for a new account
	 * (no role before), which has nothing to revoke.
	 *
	 * @param int      $user_id
	 * @param string   $role
	 * @param string[] $old_roles
	 */
	public static function on_role_changed( $user_id, $role = '', $old_roles = array() ): void {
		$old_roles = array_values( (array) $old_roles );
		if ( ! $old_roles || array( (string) $role ) === $old_roles ) {
			return;
		}
		self::revoke( (int) $user_id, null, sprintf( 'role changed from %s to %s', implode( ',', $old_roles ), (string) $role ) );
	}

	/**
	 * add_user_role (2.42.0). A new account gets its first role this way too;
	 * it has nothing to revoke, so nothing is logged for it.
	 *
	 * @param int    $user_id
	 * @param string $role
	 */
	public static function on_role_added( $user_id, $role = '' ): void {
		self::revoke( (int) $user_id, null, sprintf( 'role %s added', (string) $role ) );
	}

	/**
	 * remove_user_role (2.42.0).
	 *
	 * @param int    $user_id
	 * @param string $role
	 */
	public static function on_role_removed( $user_id, $role = '' ): void {
		self::revoke( (int) $user_id, null, sprintf( 'role %s removed', (string) $role ) );
	}

	// ── Upgrade steps ────────────────────────────────────────────────────────

	/** The one-time steps of 2.39.0 (each runs once; see the class comment). */
	public static function maybe_upgrade(): void {
		$done = get_option( self::UPGRADES, array() );
		$done = is_array( $done ) ? $done : array();
		if ( ! empty( $done['policy_239'] ) && ! empty( $done['refresh_cap_239'] ) ) {
			return;
		}

		if ( empty( $done['policy_239'] ) ) {
			if ( 'open' === get_option( Options::REG_POLICY ) ) {
				update_option( Options::REG_POLICY, 'restricted', false );
				update_option( self::POLICY_NOTICE, time(), false );
				Server::log( 'upgrade: the registration policy "open" (written by the old connector) is now "restricted"; an administrator can choose "Any app" again on AI Nexus → Connect' );
			}
			$done['policy_239'] = time();
		}

		if ( empty( $done['refresh_cap_239'] ) ) {
			$n = Token_Store::cap_refresh( time() + self::idle_seconds() );
			if ( $n > 0 ) {
				Server::log( sprintf( 'upgrade: %d refresh token(s) now end after %d days unused instead of 30', $n, self::idle_days() ) );
			}
			$done['refresh_cap_239'] = time();
		}

		update_option( self::UPGRADES, $done, true );
	}

	/** What changed, on AI Nexus's pages, until an administrator dismisses it. */
	public static function policy_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( self::POLICY_NOTICE ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'mcp-abilities' !== $page && 0 !== strpos( $page, 'mcp-abilities-' ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::NOTICE_ACTION ), self::NOTICE_ACTION );
		echo '<div class="notice notice-warning"><p><strong>VGC AI Nexus:</strong> '
			. esc_html__( '“Which apps may connect” was set to “Any app” and is now “Claude only”. Connections made before keep working. New sign-ins are accepted from Claude (claude.ai and claude.com) and from apps on your own computer that use PKCE. To let another app connect, add its host under “Other apps allowed to connect”, or choose “Any app” again.', 'mcp-abilities' )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=mcp-abilities-connect#mcp-oauth' ) ) . '">' . esc_html__( 'Open the setting', 'mcp-abilities' ) . '</a>'
			. ' · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'mcp-abilities' ) . '</a></p></div>';
	}

	/** admin-post: dismiss the notice (nonce + manage_options). */
	public static function dismiss_policy_notice(): void {
		check_admin_referer( self::NOTICE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mcp-abilities' ), 403 );
		}
		delete_option( self::POLICY_NOTICE );
		wp_safe_redirect( admin_url( 'admin.php?page=mcp-abilities-connect' ) );
		exit;
	}
}
