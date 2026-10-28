<?php
/**
 * OAuth 2.0 Authorization Server.
 *
 * Implements:
 *  - Authorization Code flow with PKCE (S256) — RFC 6749 + RFC 7636
 *  - Authorization Server Metadata               — RFC 8414
 *  - Dynamic Client Registration                 — RFC 7591
 *
 * Endpoints
 * ─────────
 * GET  {site}/.well-known/oauth-authorization-server  RFC 8414 metadata
 * GET  {site}/oauth/authorize                          Consent page (HTML)
 * POST {site}/wp-json/vgc-cc/v1/token                 Token exchange
 * POST {site}/wp-json/vgc-cc/v1/register              Dynamic registration
 * GET  {site}/wp-json/vgc-cc/v1/userinfo              UserInfo
 *
 * @package mcp-abilities
 */

declare( strict_types=1 );

namespace MCP_Abilities\OAuth;

final class Server {

	const QV_ACTION      = 'vgc_cc_action';
	const NONCE_CONSENT  = 'vgc_cc_consent';
	const TTL_CODE       = 600;      // 10 minutes
	const TTL_ACCESS     = 3600;     // 1 hour
	const TTL_REFRESH    = 2592000;  // 30 days
	// A used refresh token stays valid this long, so two runs refreshing at the
	// same moment (or a client retrying after a network error) are not signed out.
	const REFRESH_GRACE  = 120;
	const REST_NAMESPACE = 'vgc-cc/v1';

	// ── Boot ───────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'init',              array( self::class, 'register_rewrite_rules' ) );
		add_filter( 'query_vars',        array( self::class, 'register_query_vars' ) );
		// Trace every relevant request (priority 0, before our own handlers) so a
		// single connection attempt shows Claude's complete request sequence.
		add_action( 'parse_request',     array( self::class, 'trace_request' ), 0 );
		// Intercept all OAuth + discovery requests as early as possible.
		// Priority 1 is REQUIRED: WordPress's REST API claims every /wp-json/
		// path on parse_request at priority 10 and would 404 our namespaced
		// /wp-json/vgc-cc/v1/authorize consent page (no JSON route by that name)
		// before we could render it.  Running at priority 1 lets us serve the
		// HTML consent page first.  (The token/register/discovery REST routes are
		// NOT matched by this handler, so they still fall through to the REST API.)
		add_action( 'parse_request',     array( self::class, 'maybe_handle_oauth_request' ), 1 );
		add_action( 'template_redirect', array( self::class, 'handle_template_redirect' ) );
		add_action( 'rest_api_init',     array( self::class, 'register_rest_routes' ) );

		// Attach the RFC 9728 WWW-Authenticate challenge to unauthenticated MCP
		// responses so claude.ai can discover the OAuth server from the MCP URL
		// alone.  Runs late so it can inspect the final status code.
		add_filter( 'rest_post_dispatch', array( self::class, 'add_resource_challenge' ), 100, 3 );
	}

	/**
	 * Log the request line (method, path, auth scheme) for every OAuth / MCP /
	 * discovery request, so one connection attempt yields the full sequence.
	 */
	public static function trace_request(): void {
		$uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security
		$method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ); // phpcs:ignore WordPress.Security

		$relevant = preg_match( '#/(authorize|token|register|jwks)(?:[/?]|$)#', $uri )
			|| str_contains( $uri, '/.well-known/' )
			|| str_contains( $uri, '/vgc-cc/' )
			|| str_contains( $uri, '/mcp/' );
		if ( ! $relevant ) {
			return;
		}

		// Auth scheme present on the request (never the secret value).
		$auth = '';
		foreach ( $_SERVER as $k => $v ) {
			if ( is_string( $k ) && str_ends_with( $k, 'HTTP_AUTHORIZATION' ) && is_string( $v ) && '' !== $v ) {
				$auth = strtok( $v, ' ' );
				break;
			}
		}

		self::debug( sprintf( '>> REQUEST %s %s auth=%s', $method, $uri, $auth ?: 'none' ) );
	}

	// ── Rewrite rules ──────────────────────────────────────────────────────

	public static function register_rewrite_rules(): void {
		// Both /authorize (default OAuth convention used by claude.ai) and
		// /oauth/authorize (advertised in metadata) point to the same page.
		add_rewrite_rule( '^authorize/?$',                                  'index.php?' . self::QV_ACTION . '=authorize', 'top' );
		add_rewrite_rule( '^oauth/authorize/?$',                            'index.php?' . self::QV_ACTION . '=authorize', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$',    'index.php?' . self::QV_ACTION . '=oauth-as-metadata', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-protected-resource/?',       'index.php?' . self::QV_ACTION . '=oauth-pr-metadata', 'top' );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = self::QV_ACTION;
		return $vars;
	}

	// ── Robust request interception (permalink-independent) ────────────────

	/**
	 * Serve OAuth discovery + flow endpoints directly from the raw request path.
	 *
	 * claude.ai's OAuth client uses the default endpoint convention relative to
	 * the issuer root — /authorize, /token, /register — rather than the paths
	 * advertised in metadata.  We therefore serve every endpoint at BOTH the
	 * root path and the metadata path, and do it on parse_request so it works
	 * regardless of permalink settings or whether rewrite rules were flushed.
	 */
	public static function maybe_handle_oauth_request(): void {
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = '/' . ltrim( (string) $path, '/' );

		// Strip subdirectory-install home path so comparisons are root-relative.
		$home_path = '/' . trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '/' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' );
		}

		// ── Discovery documents ──
		if ( str_contains( $path, '/.well-known/oauth-authorization-server' ) ) {
			self::serve_as_metadata();
			exit;
		}
		if ( str_contains( $path, '/.well-known/oauth-protected-resource' ) ) {
			self::serve_pr_metadata();
			exit;
		}

		// ── OAuth flow endpoints (root convention + metadata paths) ──
		// Strip a trailing slash for exact matching.
		$clean = '/' . trim( $path, '/' );

		// Authorize is served at the root AND under the /wp-json issuer base
		// (…/wp-json/vgc-cc/v1/authorize), so every advertised endpoint shares
		// the issuer's base — required for the client's RFC 9207 / metadata
		// consistency checks.  Match any path ending in /authorize.
		if ( '/authorize' === $clean || '/oauth/authorize' === $clean || str_ends_with( $clean, '/' . self::REST_NAMESPACE . '/authorize' ) ) {
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
				self::handle_consent_post();
			} else {
				self::render_authorize_page();
			}
			exit;
		}

		if ( '/token' === $clean && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::dispatch_token_at_root();
			exit;
		}

		if ( '/register' === $clean && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::dispatch_register_at_root();
			exit;
		}
	}

	/**
	 * Run the token endpoint logic from a root-path (non-REST) request and
	 * emit the JSON response.  OAuth token requests are form-encoded.
	 */
	private static function dispatch_token_at_root(): void {
		$req = new \WP_REST_Request( 'POST', '/' . self::REST_NAMESPACE . '/token' );
		foreach ( self::read_request_params() as $k => $v ) {
			$req->set_param( $k, $v );
		}
		self::emit_rest_response( self::handle_token_request( $req ) );
	}

	/**
	 * Run the dynamic-registration logic from a root-path request and emit JSON.
	 * RFC 7591 registration requests are JSON-encoded.
	 */
	private static function dispatch_register_at_root(): void {
		$raw = (string) file_get_contents( 'php://input' );
		self::log( 'root /register POST received. content-type=' . ( $_SERVER['CONTENT_TYPE'] ?? '?' ) . ' raw_body=' . substr( $raw, 0, 500 ) );

		// Parse from the raw body we just read (php://input may not be re-readable).
		$params = array();
		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$params = $decoded;
			} else {
				parse_str( $raw, $params );
			}
		}
		if ( empty( $params ) && ! empty( $_POST ) ) {
			$params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security
		}

		$req = new \WP_REST_Request( 'POST', '/' . self::REST_NAMESPACE . '/register' );
		$req->set_body( (string) wp_json_encode( $params ) );
		$req->set_header( 'Content-Type', 'application/json' );
		self::emit_rest_response( self::handle_dynamic_registration( $req ) );
	}

	/**
	 * Read request parameters from either a form-encoded ($_POST) or JSON body.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_request_params(): array {
		if ( ! empty( $_POST ) ) {
			return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security
		}
		$raw = file_get_contents( 'php://input' );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			parse_str( $raw, $parsed );
			if ( ! empty( $parsed ) ) {
				return $parsed;
			}
		}
		return array();
	}

	/**
	 * Emit a WP_REST_Response (status, headers, JSON body) as a raw HTTP
	 * response for the root-path OAuth endpoints.
	 *
	 * @param \WP_REST_Response|\WP_Error $response
	 */
	private static function emit_rest_response( $response ): void {
		self::discard_buffered_output();

		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 400 );
			status_header( $status );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => $response->get_error_code(), 'error_description' => $response->get_error_message() ) );
			return;
		}

		status_header( $response->get_status() );
		header( 'Content-Type: application/json; charset=utf-8' );
		foreach ( $response->get_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo wp_json_encode( $response->get_data(), JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Discard any output (PHP notices, whitespace) buffered before we emit a
	 * machine-readable response, so the JSON body is never corrupted.
	 */
	private static function discard_buffered_output(): void {
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	// ── Template redirect dispatcher ───────────────────────────────────────

	public static function handle_template_redirect(): void {
		$action = get_query_var( self::QV_ACTION );

		if ( 'oauth-as-metadata' === $action ) {
			self::serve_as_metadata();
			exit;
		}

		if ( 'oauth-pr-metadata' === $action ) {
			self::serve_pr_metadata();
			exit;
		}

		if ( 'authorize' === $action ) {
			if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
				self::handle_consent_post();
			} else {
				self::render_authorize_page();
			}
			exit;
		}
	}

	// ── Discovery URL helpers ──────────────────────────────────────────────

	/** The MCP server endpoint URL (the OAuth "resource"). */
	public static function mcp_resource_url(): string {
		return rest_url( 'mcp/mcp-adapter-default-server' );
	}

	/**
	 * RFC 9728 Protected Resource Metadata URL.
	 *
	 * Uses the /wp-json/ REST route, NOT /.well-known/, because some hosts
	 * (SiteGround) serve /.well-known/ from the filesystem and never route it to
	 * WordPress.  We control this URL via the WWW-Authenticate challenge, so the
	 * client follows it directly — no /.well-known/ dependency for RFC 9728.
	 */
	public static function pr_metadata_url(): string {
		return rest_url( self::REST_NAMESPACE . '/oauth-protected-resource' );
	}

	// ── RFC 9728 — Protected Resource Metadata ─────────────────────────────

	/**
	 * Serve the OAuth 2.0 Protected Resource Metadata document.
	 *
	 * This is the FIRST document claude.ai fetches when connecting via Remote
	 * MCP.  It tells the client which authorization server protects this MCP
	 * resource.  Without it, claude.ai cannot discover the OAuth flow from the
	 * MCP server URL alone.
	 */
	/**
	 * The OAuth issuer identifier.
	 *
	 * Uses the /wp-json/ REST base rather than the site root because the host
	 * (SiteGround) intercepts any URL whose path STARTS with /.well-known/ and
	 * serves it from the filesystem.  With a /wp-json/ issuer, the RFC 8414
	 * metadata URL ({issuer}/.well-known/oauth-authorization-server) has
	 * /.well-known/ in the middle of the path, so it is NOT intercepted and
	 * reaches WordPress.
	 */
	public static function as_issuer(): string {
		return untrailingslashit( rest_url( self::REST_NAMESPACE ) );
	}

	/** RFC 9728 Protected Resource Metadata as a data array. */
	public static function pr_metadata_data(): array {
		return array(
			'resource'                 => self::mcp_resource_url(),
			'authorization_servers'    => array( self::as_issuer() ),
			'scopes_supported'         => array( 'mcp' ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => \MCP_Abilities\Site_Identity::title(),
		);
	}

	private static function serve_pr_metadata(): void {
		self::emit_json( self::pr_metadata_data() );
	}

	// ── RFC 8414 — Authorization Server Metadata ───────────────────────────

	/** RFC 8414 Authorization Server Metadata as a data array. */
	public static function as_metadata_data(): array {
		$meta = array(
			// issuer MUST equal the authorization_servers entry the client used
			// to fetch this document (RFC 8414 validation).
			'issuer'                                => self::as_issuer(),
			// authorization_endpoint stays at the root: it is a browser-redirect
			// target that renders the HTML consent page (under /wp-json/ the flow
			// stalled at the GET).  token is a server-to-server call under /wp-json/.
			'authorization_endpoint'                => home_url( '/authorize' ),
			'token_endpoint'                        => rest_url( self::REST_NAMESPACE . '/token' ),
			'userinfo_endpoint'                     => rest_url( self::REST_NAMESPACE . '/userinfo' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'client_secret_basic', 'client_secret_post', 'none' ),
			'scopes_supported'                      => array( 'mcp' ),
			// OpenID Connect Discovery REQUIRED fields.  Claude fetches
			// openid-configuration and rejects it (failing before registration)
			// if these are absent.  We never issue id_tokens, so the JWKS is empty
			// and these are advertised only to satisfy the client's schema check.
			'jwks_uri'                                  => rest_url( self::REST_NAMESPACE . '/jwks' ),
			'subject_types_supported'                   => array( 'public' ),
			'id_token_signing_alg_values_supported'     => array( 'RS256' ),
		);

		// Only advertise the registration endpoint when dynamic registration is
		// enabled.  In "manual" mode (policy = 'closed') we omit it so the client
		// must use the pre-created Client ID/Secret entered in its settings
		// instead of auto-registering a different client.
		// Default 'restricted' matches the enforcement default in Client_Manager.
		$policy = (string) get_option( Options::REG_POLICY, 'restricted' );
		if ( 'closed' !== $policy ) {
			$meta['registration_endpoint'] = rest_url( self::REST_NAMESPACE . '/register' );
		}

		return $meta;
	}

	private static function serve_as_metadata(): void {
		self::emit_json( self::as_metadata_data() );
	}

	/** Empty JWKS — we use OAuth (authorization code + PKCE) and never issue id_tokens. */
	public static function rest_jwks( \WP_REST_Request $request ): \WP_REST_Response {
		$r = new \WP_REST_Response( array( 'keys' => array() ), 200 );
		self::add_discovery_headers( $r );
		return $r;
	}

	/**
	 * REST callback for both metadata documents (served under /wp-json/, which
	 * WordPress always handles — unlike /.well-known/, which SiteGround serves
	 * from the filesystem and never routes to WordPress).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function rest_pr_metadata( \WP_REST_Request $request ): \WP_REST_Response {
		$r = new \WP_REST_Response( self::pr_metadata_data(), 200 );
		self::add_discovery_headers( $r );
		return $r;
	}

	public static function rest_as_metadata( \WP_REST_Request $request ): \WP_REST_Response {
		$r = new \WP_REST_Response( self::as_metadata_data(), 200 );
		self::add_discovery_headers( $r );
		return $r;
	}

	/**
	 * CORS + anti-cache headers for discovery documents.
	 *
	 * These documents are dynamic (their contents depend on site URL/issuer) and
	 * are read at connection time, so they must never be cached by the browser,
	 * a CDN, or the host's page cache (SiteGround Dynamic Cache).  A stale cached
	 * copy with an old issuer breaks discovery.
	 *
	 * @param \WP_REST_Response $r
	 */
	private static function add_discovery_headers( \WP_REST_Response $r ): void {
		$r->header( 'Access-Control-Allow-Origin', '*' );
		$r->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$r->header( 'Pragma', 'no-cache' );
	}

	/** Emit a JSON document with CORS + cache headers for discovery clients. */
	private static function emit_json( array $data ): void {
		self::discard_buffered_output();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
		// Discovery docs are dynamic and read at connect time — never cache them.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );

		if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			status_header( 204 );
			return;
		}

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	// ── RFC 9728 — WWW-Authenticate challenge on the MCP endpoint ───────────

	/**
	 * Add a WWW-Authenticate header pointing to the protected-resource metadata
	 * whenever an unauthenticated request hits the MCP endpoint.
	 *
	 * This is the trigger that lets claude.ai bootstrap OAuth from the MCP URL:
	 * it sends an unauthenticated request, receives 401 + this header, then
	 * follows resource_metadata to discover and complete the OAuth flow.
	 *
	 * @param \WP_HTTP_Response $result  The response object.
	 * @param \WP_REST_Server   $server  The REST server instance.
	 * @param \WP_REST_Request  $request The request.
	 * @return \WP_HTTP_Response
	 */
	public static function add_resource_challenge( $result, $server, $request ) {
		if ( ! str_starts_with( $request->get_route(), '/mcp/' ) ) {
			return $result;
		}

		if ( ! ( $result instanceof \WP_HTTP_Response ) ) {
			return $result;
		}

		$status = $result->get_status();
		if ( 401 === $status || 403 === $status ) {
			$result->header(
				'WWW-Authenticate',
				sprintf( 'Bearer resource_metadata="%s"', self::pr_metadata_url() )
			);
		}

		return $result;
	}

	// ── REST routes ────────────────────────────────────────────────────────

	public static function register_rest_routes(): void {
		// Discovery metadata under /wp-json/ (SiteGround-safe; /.well-known/ is
		// served from the filesystem on some hosts and never reaches WordPress).
		register_rest_route( self::REST_NAMESPACE, '/oauth-protected-resource', array(
			'methods'             => 'GET',
			'callback'            => array( self::class, 'rest_pr_metadata' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::REST_NAMESPACE, '/oauth-authorization-server', array(
			'methods'             => 'GET',
			'callback'            => array( self::class, 'rest_as_metadata' ),
			'permission_callback' => '__return_true',
		) );
		// Append-style discovery at {issuer}/.well-known/* where issuer is the
		// /wp-json REST base.  The /.well-known/ segment is mid-path (not at the
		// domain root), so the host does not intercept it.
		//
		// Claude requests .well-known/openid-configuration (OpenID Connect
		// discovery) — confirmed from server logs — so we serve that too, in
		// addition to RFC 8414's oauth-authorization-server.  Both return the
		// same OAuth metadata (endpoints + PKCE), which is all the client needs
		// for the authorization-code + PKCE flow.
		foreach ( array( 'openid-configuration', 'oauth-authorization-server' ) as $doc ) {
			register_rest_route( self::REST_NAMESPACE, '/.well-known/' . $doc, array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_as_metadata' ),
				'permission_callback' => '__return_true',
			) );
		}
		register_rest_route( self::REST_NAMESPACE, '/.well-known/oauth-protected-resource', array(
			'methods'             => 'GET',
			'callback'            => array( self::class, 'rest_pr_metadata' ),
			'permission_callback' => '__return_true',
		) );
		// JWKS endpoint referenced by openid-configuration's jwks_uri (empty set).
		register_rest_route( self::REST_NAMESPACE, '/jwks', array(
			'methods'             => 'GET',
			'callback'            => array( self::class, 'rest_jwks' ),
			'permission_callback' => '__return_true',
		) );

		// Token endpoint.
		register_rest_route( self::REST_NAMESPACE, '/token', array(
			'methods'             => 'POST',
			'callback'            => array( self::class, 'handle_token_request' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'grant_type'    => array( 'type' => 'string', 'required' => true ),
				'code'          => array( 'type' => 'string' ),
				'redirect_uri'  => array( 'type' => 'string' ),
				'client_id'     => array( 'type' => 'string' ),
				'client_secret' => array( 'type' => 'string' ),
				'code_verifier' => array( 'type' => 'string' ),
				'refresh_token' => array( 'type' => 'string' ),
			),
		) );

		// RFC 7591 — Dynamic Client Registration.
		register_rest_route( self::REST_NAMESPACE, '/register', array(
			'methods'             => 'POST',
			'callback'            => array( self::class, 'handle_dynamic_registration' ),
			'permission_callback' => '__return_true',
		) );

		// UserInfo endpoint.
		register_rest_route( self::REST_NAMESPACE, '/userinfo', array(
			'methods'             => 'GET',
			'callback'            => array( self::class, 'handle_userinfo_request' ),
			'permission_callback' => array( self::class, 'require_bearer_auth' ),
		) );

	}

	// ── RFC 7591 — Dynamic Client Registration ─────────────────────────────

	/**
	 * Register a new OAuth client dynamically.
	 *
	 * Any OAuth client (including claude.ai) can POST here without prior admin
	 * setup.  The server checks the redirect_uri against the configured policy
	 * (open / restricted / closed).  On success, returns client credentials the
	 * caller can immediately use in the authorization flow.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_dynamic_registration( \WP_REST_Request $request ) {
		// Map the dynamic client to a configurable default WordPress user.
		// Admins can override this in Settings; defaults to the first admin.
		$default_user_id = (int) get_option( 'vgc_cc_dynamic_reg_user_id', 0 );
		if ( ! $default_user_id ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
			$default_user_id = ! empty( $admins ) ? $admins[0]->ID : 0;
		}

		if ( ! $default_user_id ) {
			return new \WP_Error( 'no_default_user', __( 'No default user configured for dynamic registration.', 'mcp-abilities' ), array( 'status' => 500 ) );
		}

		// Accept JSON body or, as a fallback, already-set params (root dispatch).
		$body = $request->get_json_params();
		if ( empty( $body ) ) {
			$body = $request->get_params() ?: array();
		}

		$result = Client_Manager::dynamic_register( (array) $body, $default_user_id );

		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			// Log the rejection so registration failures are diagnosable in
			// WP_DEBUG_LOG (e.g. a redirect_uri rejected by the policy).
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'AI Nexus OAuth: dynamic registration failed (%s): %s | request=%s',
					$result->get_error_code(),
					$result->get_error_message(),
					wp_json_encode( $body )
				)
			);
			return new \WP_REST_Response(
				array( 'error' => $result->get_error_code(), 'error_description' => $result->get_error_message() ),
				$status
			);
		}

		self::log( sprintf(
			'dynamic registration OK: client_id=%s auth_method=%s redirect_uris=%s',
			$result['client_id'] ?? '?',
			$result['token_endpoint_auth_method'] ?? '?',
			wp_json_encode( $result['redirect_uris'] ?? array() )
		) );

		return new \WP_REST_Response( $result, 201 );
	}

	// ── Token endpoint ─────────────────────────────────────────────────────

	public static function handle_token_request( \WP_REST_Request $request ) {
		$grant_type = $request->get_param( 'grant_type' );
		self::log( sprintf(
			'token endpoint hit: grant_type=%s client_id=%s has_code=%s has_verifier=%s has_secret=%s',
			(string) $grant_type,
			(string) $request->get_param( 'client_id' ),
			$request->get_param( 'code' ) ? 'yes' : 'no',
			$request->get_param( 'code_verifier' ) ? 'yes' : 'no',
			$request->get_param( 'client_secret' ) ? 'yes' : 'no'
		) );

		if ( 'authorization_code' === $grant_type ) {
			return self::grant_authorization_code( $request );
		}

		if ( 'refresh_token' === $grant_type ) {
			return self::grant_refresh_token( $request );
		}

		return self::token_error( 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.' );
	}

	/**
	 * Extract client credentials from the request, supporting BOTH:
	 *  - client_secret_basic: Authorization: Basic base64(client_id:client_secret)
	 *  - client_secret_post / public client: client_id & client_secret in the body
	 *
	 * The Basic header takes precedence (it's how most OAuth clients, including a
	 * manually-configured Claude connector, send confidential client creds).
	 *
	 * @param \WP_REST_Request $request
	 * @return array{client_id:string, client_secret:string, method:string}
	 */
	private static function extract_client_credentials( \WP_REST_Request $request ): array {
		foreach ( $_SERVER as $key => $value ) {
			if ( is_string( $key ) && str_ends_with( $key, 'HTTP_AUTHORIZATION' )
				&& is_string( $value ) && stripos( $value, 'Basic ' ) === 0 ) {
				$decoded = base64_decode( trim( substr( $value, 6 ) ), true );
				if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
					[ $cid, $csec ] = explode( ':', $decoded, 2 );
					return array(
						'client_id'     => urldecode( $cid ),
						'client_secret' => urldecode( $csec ),
						'method'        => 'client_secret_basic',
					);
				}
			}
		}

		return array(
			'client_id'     => (string) $request->get_param( 'client_id' ),
			'client_secret' => (string) $request->get_param( 'client_secret' ),
			'method'        => '' !== (string) $request->get_param( 'client_secret' ) ? 'client_secret_post' : 'none',
		);
	}

	private static function grant_authorization_code( \WP_REST_Request $request ) {
		$code          = (string) $request->get_param( 'code' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
		$code_verifier = (string) $request->get_param( 'code_verifier' );

		$creds         = self::extract_client_credentials( $request );
		$client_id     = $creds['client_id'];
		$client_secret = $creds['client_secret'];

		self::log( sprintf( 'token/auth_code: client_id=%s auth=%s has_verifier=%s', $client_id, $creds['method'], '' !== $code_verifier ? 'yes' : 'no' ) );

		if ( '' === $code || '' === $client_id ) {
			return self::token_error( 'invalid_request', 'Missing required parameters.' );
		}

		$code_data = Token_Store::get( 'code', $code );
		Token_Store::delete( 'code', $code );

		if ( ! $code_data ) {
			return self::token_error( 'invalid_grant', 'Authorization code expired or invalid.' );
		}

		if ( $code_data['client_id'] !== $client_id ) {
			return self::token_error( 'invalid_grant', 'client_id mismatch.' );
		}

		if ( '' !== $redirect_uri && $code_data['redirect_uri'] !== $redirect_uri ) {
			return self::token_error( 'invalid_grant', 'redirect_uri mismatch.' );
		}

		// Verify client: accept PKCE and/or a client secret.  Require at least one
		// valid proof.  This supports public clients (PKCE only), confidential
		// clients (secret only), and clients that send both.
		$has_challenge = '' !== ( $code_data['code_challenge'] ?? '' );
		$has_secret    = '' !== $client_secret;
		$proof_ok      = false;

		if ( $has_challenge ) {
			$vlen = strlen( $code_verifier );
			if ( $vlen < 43 || $vlen > 128 ) {
				return self::token_error( 'invalid_request', 'code_verifier must be 43–128 characters.' );
			}
			if ( preg_replace( '/[A-Za-z0-9\-._~]/', '', $code_verifier ) !== '' ) {
				return self::token_error( 'invalid_request', 'code_verifier contains invalid characters.' );
			}
			$expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
			if ( ! hash_equals( $code_data['code_challenge'], $expected ) ) {
				return self::token_error( 'invalid_grant', 'PKCE verification failed.' );
			}
			$proof_ok = true;
		}

		if ( $has_secret ) {
			if ( ! Client_Manager::verify_secret( $client_id, $client_secret ) ) {
				return self::token_error( 'invalid_client', 'Invalid client_secret.', 401 );
			}
			$proof_ok = true;
		}

		if ( ! $proof_ok ) {
			return self::token_error( 'invalid_client', 'Client authentication required: provide a code_verifier (PKCE) or client_secret.', 401 );
		}

		return self::issue_tokens( $code_data['user_id'], $client_id );
	}

	private static function grant_refresh_token( \WP_REST_Request $request ) {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		$creds         = self::extract_client_credentials( $request );
		$client_id     = $creds['client_id'];
		$client_secret = $creds['client_secret'];

		if ( '' === $refresh_token || '' === $client_id ) {
			return self::token_error( 'invalid_request', 'Missing required parameters.' );
		}

		if ( '' !== $client_secret && ! Client_Manager::verify_secret( $client_id, $client_secret ) ) {
			return self::token_error( 'invalid_client', 'Invalid client_secret.', 401 );
		}

		$rt_data = Token_Store::get( 'refresh', $refresh_token );

		if ( ! $rt_data ) {
			return self::token_error( 'invalid_grant', 'Refresh token expired or invalid.' );
		}

		if ( $rt_data['client_id'] !== $client_id ) {
			return self::token_error( 'invalid_grant', 'client_id mismatch.' );
		}

		// A confidential client (it proved its client_secret just now: claude.ai
		// does) keeps the SAME refresh token, its 30 days pushed forward on every
		// use. Rotating it broke vibrantcolorink.com: claude.ai sometimes comes
		// back with the token from before its last refresh (a lost response, or
		// another of its workers), minutes later, and a rotated token was dead
		// after REFRESH_GRACE, so the connector was dropped. OAuth 2.1 requires
		// rotation only for public clients; those keep it below.
		if ( '' !== $client_secret && empty( $rt_data['rotated_at'] ) ) {
			$rt_data['expires'] = time() + self::TTL_REFRESH;
			Token_Store::put( 'refresh', $refresh_token, $rt_data );
			return self::issue_tokens( (int) $rt_data['user_id'], $client_id, $refresh_token );
		}

		if ( empty( $rt_data['rotated_at'] ) ) {
			// First use: rotate, but keep the old token alive for a short grace period.
			$rt_data['rotated_at'] = time();
			$rt_data['expires']    = time() + self::REFRESH_GRACE;
			Token_Store::put( 'refresh', $refresh_token, $rt_data );
		} else {
			self::log( sprintf( 'refresh token reused %ds after rotation (within grace) client=%s', time() - (int) $rt_data['rotated_at'], $client_id ) );
		}

		return self::issue_tokens( (int) $rt_data['user_id'], $client_id );
	}

	/**
	 * Write a diagnostic line to the PHP error log (prefixed for easy grep).
	 * Always logs — OAuth events are low-frequency and we are actively debugging.
	 */
	/** True when MCP_ABILITIES_OAUTH_DEBUG is set: per-request tracing on. */
	public static function debugging(): bool {
		return defined( 'MCP_ABILITIES_OAUTH_DEBUG' ) && MCP_ABILITIES_OAUTH_DEBUG;
	}

	/** Per-request detail: only with MCP_ABILITIES_OAUTH_DEBUG (it fires on every MCP call). */
	public static function debug( string $message ): void {
		if ( self::debugging() ) {
			self::log( $message );
		}
	}

	public static function log( string $message ): void {
		error_log( 'AI Nexus OAuth: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * @param string $keep_refresh A refresh token to hand back unchanged (a
	 *                             confidential client's refresh); '' = new one.
	 */
	private static function issue_tokens( int $user_id, string $client_id, string $keep_refresh = '' ): \WP_REST_Response {
		$access_token  = Client_Manager::generate_token( 32 );
		$refresh_token = '' !== $keep_refresh ? $keep_refresh : Client_Manager::generate_token( 32 );

		Token_Store::put( 'token', $access_token,
			array( 'user_id' => $user_id, 'client_id' => $client_id, 'expires' => time() + self::TTL_ACCESS ) );

		self::log( sprintf(
			'issued access token for user_id=%d client=%s token_hash=%s',
			$user_id,
			$client_id,
			substr( hash( 'sha256', $access_token ), 0, 12 )
		) );

		if ( '' === $keep_refresh ) {
			Token_Store::put( 'refresh', $refresh_token,
				array( 'user_id' => $user_id, 'client_id' => $client_id, 'expires' => time() + self::TTL_REFRESH ) );
		}

		Token_Store::cleanup();

		return new \WP_REST_Response( array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => self::TTL_ACCESS,
			'refresh_token' => $refresh_token,
			'scope'         => 'mcp',
		), 200 );
	}

	// ── UserInfo endpoint ──────────────────────────────────────────────────

	public static function require_bearer_auth() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'Bearer token required.', 'mcp-abilities' ), array( 'status' => 401 ) );
	}

	public static function handle_userinfo_request(): \WP_REST_Response {
		$user = wp_get_current_user();
		return new \WP_REST_Response( array(
			'sub'   => (string) $user->ID,
			'name'  => $user->display_name,
			'email' => $user->user_email,
			'login' => $user->user_login,
		), 200 );
	}

	// ── Authorization endpoint (HTML consent page) ─────────────────────────

	private static function render_authorize_page(): void {
		$params = self::parse_authorize_params();
		if ( is_wp_error( $params ) ) {
			self::render_error_page( $params->get_error_message() );
			return;
		}

		if ( ! is_user_logged_in() ) {
			// Redirect back to the exact URL claude.ai sent the user to (which may
			// be /authorize or /oauth/authorize), preserving all query params, so
			// the flow resumes correctly after login.
			$current_url = home_url( add_query_arg( null, null ) );
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		$client = Client_Manager::get( $params['client_id'] );
		$user   = wp_get_current_user();

		self::output_doctype();
		?>
		<div class="vgc-cc-box">
			<div class="vgc-cc-logo"><img src="<?php echo esc_url( MCP_ABILITIES_URL . 'assets/oauth/claude-icon.svg' ); ?>" alt="Claude" width="48" height="48"></div>
			<h1><?php esc_html_e( 'Authorize Access', 'mcp-abilities' ); ?></h1>
			<p><?php printf( /* translators: 1:client 2:site */ esc_html__( '%1$s is requesting access to %2$s.', 'mcp-abilities' ), '<strong>' . esc_html( $client['name'] ) . '</strong>', '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>' ); ?></p>
			<p class="vgc-cc-user"><?php printf( esc_html__( 'Signed in as %s.', 'mcp-abilities' ), '<strong>' . esc_html( $user->display_name . ' (' . $user->user_login . ')' ) . '</strong>' ); ?></p>
			<p class="vgc-cc-scope-intro"><?php esc_html_e( 'This will allow the application to call MCP tools on this WordPress site with your account permissions.', 'mcp-abilities' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_CONSENT . '_' . $params['client_id'] ); ?>
				<input type="hidden" name="vgc_cc_client_id"             value="<?php echo esc_attr( $params['client_id'] ); ?>">
				<input type="hidden" name="vgc_cc_redirect_uri"          value="<?php echo esc_attr( $params['redirect_uri'] ); ?>">
				<input type="hidden" name="vgc_cc_state"                 value="<?php echo esc_attr( $params['state'] ); ?>">
				<input type="hidden" name="vgc_cc_code_challenge"        value="<?php echo esc_attr( $params['code_challenge'] ?? '' ); ?>">
				<input type="hidden" name="vgc_cc_code_challenge_method" value="<?php echo esc_attr( $params['code_challenge_method'] ?? '' ); ?>">
				<div class="vgc-cc-actions">
					<button type="submit" name="vgc_cc_decision" value="approve" class="vgc-cc-btn vgc-cc-btn--primary"><?php esc_html_e( 'Approve', 'mcp-abilities' ); ?></button>
					<button type="submit" name="vgc_cc_decision" value="deny"    class="vgc-cc-btn vgc-cc-btn--secondary"><?php esc_html_e( 'Deny', 'mcp-abilities' ); ?></button>
				</div>
			</form>
		</div>
		<script>
		// Submit once. A second Approve (double click, slow redirect) issues a second
		// code and sends the browser back to claude.ai with an already-used state,
		// which claude.ai reports as a failed connection even though the first worked.
		(function () {
			var form = document.querySelector( 'form' );
			if ( ! form ) { return; }
			form.addEventListener( 'submit', function ( e ) {
				if ( form.getAttribute( 'data-sent' ) ) { e.preventDefault(); return; }
				form.setAttribute( 'data-sent', '1' );
				var b = e.submitter;
				if ( b ) {
					// Carry the decision explicitly: a disabled button's value is not sent.
					var h = document.createElement( 'input' );
					h.type = 'hidden'; h.name = b.name; h.value = b.value;
					form.appendChild( h );
					b.textContent = <?php echo wp_json_encode( __( 'One moment…', 'mcp-abilities' ) ); ?>;
				}
				setTimeout( function () {
					var all = form.querySelectorAll( 'button' );
					for ( var i = 0; i < all.length; i++ ) { all[ i ].disabled = true; }
				}, 0 );
			} );
		})();
		</script>
		</body></html>
		<?php
	}

	private static function handle_consent_post(): void {
		if ( ! is_user_logged_in() ) {
			self::log( 'consent POST: user NOT logged in — redirecting to wp-login (consent cannot complete)' );
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$client_id             = isset( $_POST['vgc_cc_client_id'] )             ? sanitize_text_field( wp_unslash( $_POST['vgc_cc_client_id'] ) )             : '';
		$redirect_uri          = isset( $_POST['vgc_cc_redirect_uri'] )          ? esc_url_raw( wp_unslash( $_POST['vgc_cc_redirect_uri'] ) )                  : '';
		$state                 = isset( $_POST['vgc_cc_state'] )                 ? sanitize_text_field( wp_unslash( $_POST['vgc_cc_state'] ) )                 : '';
		$decision              = isset( $_POST['vgc_cc_decision'] )              ? sanitize_text_field( wp_unslash( $_POST['vgc_cc_decision'] ) )              : '';
		$code_challenge        = isset( $_POST['vgc_cc_code_challenge'] )        ? sanitize_text_field( wp_unslash( $_POST['vgc_cc_code_challenge'] ) )        : '';
		$code_challenge_method = isset( $_POST['vgc_cc_code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_POST['vgc_cc_code_challenge_method'] ) ) : '';

		self::log( sprintf(
			'consent POST: user_id=%d client_id=%s decision=%s nonce_present=%s redirect_uri=%s',
			get_current_user_id(),
			$client_id,
			$decision ?: '(none)',
			isset( $_POST['_wpnonce'] ) ? 'yes' : 'no',
			$redirect_uri
		) );

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_CONSENT . '_' . $client_id ) ) {
			self::log( 'consent POST: NONCE verification FAILED — rendering error page, no code issued' );
			self::render_error_page( __( 'Security check failed.', 'mcp-abilities' ) );
			return;
		}

		// Re-validate redirect_uri against registered URIs to prevent open-redirect.
		if ( ! Client_Manager::is_valid_redirect_uri( $client_id, $redirect_uri ) ) {
			self::log( 'consent POST: redirect_uri NOT registered for client — rendering error page' );
			self::render_error_page( __( 'Invalid redirect_uri.', 'mcp-abilities' ) );
			return;
		}

		// Build the callback URL with PROPER percent-encoding.
		// WordPress's add_query_arg() does NOT URL-encode values, so the iss
		// (which contains "://" and "/") was being sent raw — e.g.
		// iss=https://tools.vgc-ltd.com/wp-json/vgc-cc/v1 — which a strict OAuth
		// client can mis-parse or reject during callback validation (RFC 9207),
		// causing it to abandon the flow before the token exchange. Use
		// http_build_query() which percent-encodes every value correctly.
		//
		// Use wp_redirect() (NOT wp_safe_redirect): the redirect_uri is an external
		// URL already validated against the client's registered redirect_uris;
		// wp_safe_redirect() would fall back to wp-admin for an off-site URL.
		if ( 'deny' === $decision ) {
			wp_redirect( self::build_callback_url( $redirect_uri, array( 'error' => 'access_denied', 'state' => $state, 'iss' => self::as_issuer() ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		$code     = Client_Manager::generate_token( 32 );
		Token_Store::put( 'code', $code, array(
			'user_id'               => get_current_user_id(),
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'expires'               => time() + self::TTL_CODE,
		) );

		$final = self::build_callback_url( $redirect_uri, array(
			'code'  => $code,
			'state' => $state,
			'iss'   => self::as_issuer(), // RFC 9207 — required by OAuth 2.1 / MCP clients.
		) );
		self::log( sprintf( 'consent POST: APPROVED — issued code, redirecting to %s', $final ) );
		wp_redirect( $final ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Append query parameters to a redirect_uri with correct RFC 3986
	 * percent-encoding (unlike WordPress's add_query_arg, which leaves values
	 * raw).  Empty values are omitted.
	 *
	 * @param string               $redirect_uri Base URI (may already contain a query).
	 * @param array<string,string> $params       Parameters to append.
	 * @return string
	 */
	private static function build_callback_url( string $redirect_uri, array $params ): string {
		$params = array_filter( $params, static fn( $v ) => '' !== $v && null !== $v );
		$query  = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$sep    = ( false === strpos( $redirect_uri, '?' ) ) ? '?' : '&';
		return $redirect_uri . $sep . $query;
	}

	private static function parse_authorize_params() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$client_id             = isset( $_GET['client_id'] )             ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) )             : '';
		$redirect_uri          = isset( $_GET['redirect_uri'] )          ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) )                  : '';
		$response_type         = isset( $_GET['response_type'] )         ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) )         : '';
		$state                 = isset( $_GET['state'] )                 ? sanitize_text_field( wp_unslash( $_GET['state'] ) )                 : '';
		$code_challenge        = isset( $_GET['code_challenge'] )        ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) )        : '';
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '';
		// phpcs:enable

		if ( '' === $client_id )                                                      return new \WP_Error( 'missing_client_id',         __( 'Missing client_id.', 'mcp-abilities' ) );
		if ( null === Client_Manager::get( $client_id ) )                       return new \WP_Error( 'invalid_client',            __( 'Unknown client_id.', 'mcp-abilities' ) );
		if ( '' === $redirect_uri || ! Client_Manager::is_valid_redirect_uri( $client_id, $redirect_uri ) ) return new \WP_Error( 'invalid_redirect_uri', __( 'redirect_uri not registered.', 'mcp-abilities' ) );
		if ( 'code' !== $response_type )                                              return new \WP_Error( 'unsupported_response_type', __( 'Only response_type=code supported.', 'mcp-abilities' ) );
		if ( '' !== $code_challenge_method && 'S256' !== $code_challenge_method )     return new \WP_Error( 'invalid_request',           __( 'Only code_challenge_method=S256 supported.', 'mcp-abilities' ) );

		return compact( 'client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method' );
	}

	// ── Token validation (used by Bearer_Authenticator) ────────────────────

	public static function validate_bearer_token(): ?int {
		$token = self::extract_bearer_token();
		if ( null === $token ) {
			// Only log on the MCP endpoint itself — avoids noise from the admin
			// health check (/wp-json/wp/v2/users/me) and other REST traffic.
			$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security
			if ( self::debugging() && defined( 'REST_REQUEST' ) && REST_REQUEST && str_contains( $uri, '/mcp/' ) ) {
				// Dump which auth-related variables are present (NAMES + whether the
				// value looks like a Bearer header — never the secret itself) so we
				// can tell "no header sent at all" (discovery probe) apart from
				// "header present but my extraction missed it" (stripping/renaming).
				$auth_keys = array();
				foreach ( $_SERVER as $k => $v ) {
					if ( is_string( $k ) && ( stripos( $k, 'AUTH' ) !== false ) ) {
						$prefix = is_string( $v ) ? strtoupper( substr( trim( $v ), 0, 7 ) ) : gettype( $v );
						$auth_keys[] = $k . '(' . $prefix . ')';
					}
				}
				$gah = array();
				if ( function_exists( 'getallheaders' ) ) {
					foreach ( (array) getallheaders() as $hk => $hv ) {
						if ( stripos( (string) $hk, 'auth' ) !== false ) {
							$gah[] = $hk . '(' . strtoupper( substr( (string) $hv, 0, 7 ) ) . ')';
						}
					}
				}
				self::log( sprintf(
					'bearer validation: NO token. method=%s route=%s | $_SERVER auth keys=[%s] | getallheaders auth=[%s]',
					$_SERVER['REQUEST_METHOD'] ?? '?',
					$_SERVER['REQUEST_URI'] ?? '?',
					implode( ', ', $auth_keys ) ?: 'none',
					implode( ', ', $gah ) ?: 'none'
				) );
			}
			return null;
		}

		$data = Token_Store::get( 'token', $token );
		if ( ! $data ) {
			self::log( sprintf( 'bearer validation: token unknown or expired (token_hash=%s)', substr( hash( 'sha256', $token ), 0, 12 ) ) );
			return null;
		}

		self::debug( sprintf( 'bearer validation: OK user_id=%d token_hash=%s', (int) $data['user_id'], substr( hash( 'sha256', $token ), 0, 12 ) ) );
		return (int) $data['user_id'];
	}

	/**
	 * Extract the raw Bearer token from the Authorization header.
	 *
	 * Many hosts (notably SiteGround / Apache + PHP-FPM/CGI) strip or relocate
	 * the Authorization header, so we check every place it can realistically
	 * surface, in order of reliability:
	 *
	 *   1. $_SERVER['HTTP_AUTHORIZATION']            — standard
	 *   2. $_SERVER['REDIRECT_HTTP_AUTHORIZATION']   — set by the .htaccess
	 *                                                  RewriteRule E= workaround
	 *   3. apache_request_headers() / getallheaders() — raw header table
	 *
	 * @return string|null
	 */
	public static function extract_bearer_token(): ?string {
		$candidates = array();

		// Scan every $_SERVER key that ends in HTTP_AUTHORIZATION.  Apache adds a
		// REDIRECT_ prefix (sometimes more than once) when the .htaccess
		// E=HTTP_AUTHORIZATION rule fires before WordPress's internal rewrite to
		// index.php, so the value can appear as HTTP_AUTHORIZATION,
		// REDIRECT_HTTP_AUTHORIZATION, REDIRECT_REDIRECT_HTTP_AUTHORIZATION, etc.
		foreach ( $_SERVER as $key => $value ) {
			if ( is_string( $key ) && str_ends_with( $key, 'HTTP_AUTHORIZATION' ) && ! empty( $value ) ) {
				$candidates[] = (string) wp_unslash( $value ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}

		// Fall back to the raw header table (case-insensitive lookup).
		$headers = array();
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
		}
		if ( is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) && '' !== $value ) {
					$candidates[] = (string) $value;
				}
			}
		}

		foreach ( $candidates as $header ) {
			if ( is_string( $header ) && stripos( $header, 'Bearer ' ) === 0 ) {
				$token = trim( substr( $header, 7 ) );
				if ( '' !== $token ) {
					return $token;
				}
			}
		}

		return null;
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private static function token_error( string $error, string $error_description, int $http_code = 400 ): \WP_REST_Response {
		self::log( sprintf( 'token endpoint error: %s — %s (HTTP %d)', $error, $error_description, $http_code ) );
		$response = new \WP_REST_Response( array( 'error' => $error, 'error_description' => $error_description ), $http_code );
		if ( 'invalid_client' === $error ) {
			$response->header( 'WWW-Authenticate', 'Basic realm="OAuth"' );
		}
		return $response;
	}

	private static function output_doctype(): void {
		// We render before WordPress/REST sets any Content-Type (parse_request
		// priority 1), so declare HTML explicitly.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . __( 'Authorize Claude', 'mcp-abilities' ) ); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.vgc-cc-box{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:32px 40px;max-width:480px;width:100%}
.vgc-cc-logo{text-align:center;margin-bottom:20px}
h1{font-size:1.4em;text-align:center;margin-bottom:16px;color:#1d2327}
p{color:#3c434a;margin-bottom:12px;line-height:1.5}
.vgc-cc-user{background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:10px 14px;font-size:.9em}
.vgc-cc-scope-intro{margin-top:16px;font-size:.9em;color:#646970}
.vgc-cc-actions{display:flex;gap:12px;justify-content:center;margin-top:24px}
.vgc-cc-btn{padding:10px 28px;border-radius:4px;border:none;cursor:pointer;font-size:.95em;font-weight:600}
.vgc-cc-btn--primary{background:#2271b1;color:#fff}
.vgc-cc-btn--primary:hover{background:#135e96}
.vgc-cc-btn--secondary{background:#f6f7f7;color:#2c3338;border:1px solid #8c8f94}
.vgc-cc-error{color:#b91c1c;text-align:center;padding:12px;background:#fef2f2;border-radius:4px;margin-top:8px}
</style>
</head>
<body>
		<?php
	}

	private static function render_error_page( string $message ): void {
		self::output_doctype();
		?>
		<div class="vgc-cc-box">
			<div class="vgc-cc-logo"><img src="<?php echo esc_url( MCP_ABILITIES_URL . 'assets/oauth/claude-icon.svg' ); ?>" alt="Claude" width="48" height="48"></div>
			<h1><?php esc_html_e( 'Authorization Error', 'mcp-abilities' ); ?></h1>
			<div class="vgc-cc-error"><?php echo esc_html( $message ); ?></div>
		</div>
		</body></html>
		<?php
	}
}
