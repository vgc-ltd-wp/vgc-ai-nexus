<?php
/**
 * Decides who serves OAuth on this site, and starts the built-in server.
 *
 * @package mcp-abilities
 */

namespace MCP_Abilities\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * AI Nexus serves the OAuth sign-in that claude.ai (web, mobile, scheduled
 * tasks) needs to reach the MCP endpoint with no local bridge.
 *
 * It used to live in the separate VGC MCP Claude Connector (OAuth) plugin.
 * The built-in server uses the SAME addresses (/wp-json/vgc-cc/v1/…,
 * /authorize) and the SAME stored clients and tokens, so either plugin can
 * serve a connection the other one made. Exactly one may serve at a time:
 *
 *  - connector 1.3.x or older active → it serves; AI Nexus stands back;
 *  - connector 1.4.0+ active        → it stands back (it sees MCP_ABILITIES_OAUTH);
 *  - no connector                   → AI Nexus serves.
 *
 * Runs on plugins_loaded:20, after every plugin file has loaded, so the
 * connector's VGC_CC_VERSION is known whichever loads first.
 */
final class Bootstrap {

	private static string $mode = 'off';

	/**
	 * The active VGC MCP Claude Connector (OAuth)'s version, or null when it is
	 * not active.
	 *
	 * VGC_CC_VERSION alone does not identify it: VGC MCP Claude Desktop (no
	 * OAuth server) and VGC Cookie Compliance define the same constant, and on
	 * vibrantcolorink.com Cookie Compliance 1.1.0 made AI Nexus stand back for
	 * a connector that was not there, so nobody served claude.ai sign-in. The
	 * connector is recognised by its OAuth server file, present in every
	 * version of it and in no other plugin.
	 */
	public static function connector_version(): ?string {
		if ( ! defined( 'VGC_CC_VERSION' ) || ! defined( 'VGC_CC_PLUGIN_DIR' ) ) {
			return null;
		}
		if ( ! file_exists( trailingslashit( (string) VGC_CC_PLUGIN_DIR ) . 'includes/class-oauth-server.php' ) ) {
			return null;
		}
		return (string) VGC_CC_VERSION;
	}

	public static function boot(): void {
		$connector = self::connector_version();
		if ( null !== $connector && version_compare( $connector, '1.4.0', '<' ) ) {
			self::$mode = 'connector';
			return;
		}
		if ( ! (bool) apply_filters( 'mcp_abilities_oauth_enabled', true ) ) {
			self::$mode = 'off';
			return;
		}
		self::$mode = 'core';
		Server::init();
		Bearer_Authenticator::init();
		add_action( 'init', array( self::class, 'maybe_flush_rules' ), 99 );

		// WordPress 7's REST validator warns ("Undefined array key 'type'") on MCP
		// route args without a type; with display_errors on, the warning lands in
		// the JSON body and the client rejects the response.
		add_filter( 'rest_endpoints', array( self::class, 'patch_mcp_arg_types' ), 999 );
		add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
			if ( str_starts_with( (string) $request->get_route(), '/mcp/' ) ) {
				@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.NoSilencedErrors
			}
			return $result;
		}, 1, 3 );
	}

	/**
	 * Sign-in and MCP responses must be clean JSON or a clean redirect: with
	 * display_errors on, any plugin's notice printed during load is prepended to
	 * the body and claude.ai reports "authorization failed". Called at file load,
	 * before other plugins run, so notices raised during init are caught too.
	 * Errors still reach the log via WP_DEBUG_LOG.
	 */
	public static function silence_display_errors_for_machine_requests(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security
		if ( '' === $uri ) {
			return;
		}
		if ( preg_match( '#(?:^|/)(authorize|token|register)(?:[/?]|$)#', $uri )
			|| str_contains( $uri, '/.well-known/oauth-' )
			|| str_contains( $uri, '/mcp/' )
			|| str_contains( $uri, '/vgc-cc/' )
			|| str_contains( $uri, 'rest_route=/mcp/' )
			|| str_contains( $uri, 'rest_route=/vgc-cc/' ) ) {
			@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.NoSilencedErrors
		}
	}

	/** @param array<string, mixed> $endpoints */
	public static function patch_mcp_arg_types( array $endpoints ): array {
		foreach ( $endpoints as $route => &$handlers ) {
			if ( ! is_string( $route ) || ! str_starts_with( $route, '/mcp/' ) || ! is_array( $handlers ) ) {
				continue;
			}
			foreach ( $handlers as &$handler ) {
				if ( ! is_array( $handler ) || empty( $handler['args'] ) || ! is_array( $handler['args'] ) ) {
					continue;
				}
				foreach ( $handler['args'] as &$arg ) {
					if ( is_array( $arg ) && ! isset( $arg['type'] ) ) {
						$arg['type'] = 'string';
					}
				}
			}
		}
		return $endpoints;
	}

	/** 'core' (AI Nexus serves), 'connector' (an older connector plugin serves) or 'off'. */
	public static function mode(): string {
		return self::$mode;
	}

	/**
	 * The /authorize and /.well-known addresses are rewrite rules: rebuild them
	 * once per AI Nexus version, after Server registered them on init.
	 */
	public static function maybe_flush_rules(): void {
		if ( get_option( 'mcp_abilities_oauth_rules' ) === MCP_ABILITIES_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'mcp_abilities_oauth_rules', MCP_ABILITIES_VERSION, true );
	}

	/**
	 * Registered apps with their connection state, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function connected_apps(): array {
		$usage = Token_Store::summary_by_client();
		$apps  = array();
		foreach ( Client_Manager::get_all() as $client_id => $c ) {
			$u      = $usage[ $client_id ] ?? array( 'users' => array(), 'refresh_until' => 0 );
			$hosts  = array_values( array_unique( array_filter( array_map(
				static fn( $uri ) => (string) wp_parse_url( (string) $uri, PHP_URL_HOST ),
				(array) ( $c['redirect_uris'] ?? array() )
			) ) ) );
			$apps[] = array(
				'client_id'     => (string) $client_id,
				'name'          => (string) ( $c['name'] ?? '' ),
				'hosts'         => $hosts,
				'created_at'    => (int) ( $c['created_at'] ?? 0 ),
				'connected'     => $u['refresh_until'] > time(),
				'refresh_until' => (int) $u['refresh_until'],
				'users'         => array_values( array_filter( array_map(
					static function ( $id ) {
						$user = get_user_by( 'id', (int) $id );
						return $user ? $user->user_login : null;
					},
					$u['users']
				) ) ),
				// Per signed-in user: login + "who is connecting" (Operators).
				'people'        => array_values( array_filter( array_map(
					static function ( $id ) use ( $client_id ) {
						$user = get_user_by( 'id', (int) $id );
						return $user ? array(
							'user_id'  => (int) $user->ID,
							'login'    => (string) $user->user_login,
							'operator' => Operators::get( (string) $client_id, (int) $user->ID ),
						) : null;
					},
					$u['users']
				) ) ),
			);
		}
		usort( $apps, static fn( $a, $b ) => $b['created_at'] <=> $a['created_at'] );
		return $apps;
	}

	/** Delete an app's registration and every token it holds. */
	public static function revoke( string $client_id ): bool {
		if ( null === Client_Manager::get( $client_id ) ) {
			return false;
		}
		Token_Store::delete_for_client( $client_id );
		Client_Manager::delete( $client_id );
		Operators::delete_for_client( $client_id );
		Server::log( sprintf( 'app revoked by admin user_id=%d client=%s', get_current_user_id(), $client_id ) );
		return true;
	}
}
