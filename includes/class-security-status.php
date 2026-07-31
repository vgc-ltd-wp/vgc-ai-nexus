<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Detects security layers that block the WordPress REST API — and with it the
 * MCP endpoint — before this plugin ever runs.
 *
 * Field reality: WP Cerber's Hardening module (and similar features in other
 * security plugins) can 403 every /wp-json/* request with an HTML block page.
 * From the outside that is indistinguishable from "AI Nexus is broken", and it
 * produced exactly that support report. The plugin itself is the best place to
 * notice the situation and say precisely which knob to turn.
 *
 * Two signals, reported separately because they mean different things:
 *   - a known REST-blocking security plugin is ACTIVE (static fact), and
 *   - a loopback request to our own MCP endpoint is REJECTED (probe).
 *
 * Caveat encoded in all wording: a passing loopback does NOT prove external
 * clients can connect — IP-allowlist setups (this exact customer's choice)
 * pass from the server's own address while still blocking the world.
 */
class Security_Status {

    private const PROBE_TRANSIENT = 'mcp_abilities_security_probe';
    private const PROBE_TTL       = 6 * HOUR_IN_SECONDS;

    /**
     * Known security plugins with REST-blocking features, with the exact fix.
     *
     * @return array<string,array{name:string,fix:string}>
     */
    private static function known_blockers(): array {
        return [
            'cerber' => [
                'name'   => 'WP Cerber Security',
                'active' => defined( 'CERBER_VER' ) || class_exists( 'WP_Cerber' ),
                'fix'    => __( 'WP Cerber → Hardening → "Block access to the WordPress REST API except…": add the "mcp" namespace, or add every connecting IP to the White IP Access List (Cerber\'s block page shows a ✋ icon and an RID code).', 'mcp-abilities' ),
            ],
            'solid'  => [
                'name'   => 'Solid Security (iThemes)',
                'active' => class_exists( 'ITSEC_Core' ),
                'fix'    => __( 'Solid Security → Advanced → System Tweaks / REST API: set REST API access so authenticated (Application Password) requests are allowed.', 'mcp-abilities' ),
            ],
            'wordfence' => [
                'name'   => 'Wordfence',
                'active' => defined( 'WORDFENCE_VERSION' ),
                'fix'    => __( 'Wordfence → Firewall: check whether a rule or rate limit is blocking /wp-json/mcp/* and allowlist it if so.', 'mcp-abilities' ),
            ],
            'aiowps' => [
                'name'   => 'All-In-One Security',
                'active' => defined( 'AIO_WP_SECURITY_VERSION' ),
                'fix'    => __( 'All-In-One Security → Firewall / REST API security: disable REST blocking or exempt authenticated requests.', 'mcp-abilities' ),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function get( bool $with_probe = true ): array {
        $detected = [];
        foreach ( self::known_blockers() as $key => $b ) {
            if ( $b['active'] ) {
                $detected[] = [ 'key' => $key, 'name' => $b['name'], 'fix' => $b['fix'] ];
            }
        }

        $status = [
            'security_plugins' => $detected,
            'probe'            => $with_probe ? self::probe() : null,
        ];

        // Blocked = the loopback got a non-MCP response. Unverifiable when the
        // probe could not run (e.g. loopback requests disabled on the host).
        $probe            = $status['probe'];
        $status['blocked'] = is_array( $probe ) && ( $probe['blocked'] ?? false );

        return $status;
    }

    /**
     * Loopback POST to our own MCP endpoint. Cached — this runs on admin
     * screens, not on every request. An unauthenticated initialize is enough:
     * a working stack answers with JSON (result or JSON-RPC/REST error);
     * a security layer answers with an HTML block page or a plain 403.
     *
     * @return array{ran:bool,blocked:bool,http_code:int|null,detail:string}
     */
    public static function probe( bool $force = false ): array {
        if ( ! $force ) {
            $cached = get_transient( self::PROBE_TRANSIENT );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $url = rest_url( 'mcp/mcp-adapter-default-server' );
        $res = wp_remote_post( $url, [
            'timeout'   => 8,
            'sslverify' => false, // loopback to self; staging certs vary
            'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
            'body'      => wp_json_encode( [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [ 'protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass(), 'clientInfo' => [ 'name' => 'ai-nexus-selftest', 'version' => '1.0' ] ],
            ] ),
        ] );

        if ( is_wp_error( $res ) ) {
            $out = [ 'ran' => false, 'blocked' => false, 'http_code' => null, 'detail' => 'Loopback request failed: ' . $res->get_error_message() ];
        } else {
            $code    = (int) wp_remote_retrieve_response_code( $res );
            $body    = (string) wp_remote_retrieve_body( $res );
            $is_json = null !== json_decode( $body, true );
            $is_html = ! $is_json && false !== stripos( ltrim( $body ), '<' );
            $cerber  = $is_html && ( false !== strpos( $body, 'RID' ) || false !== stripos( $body, 'not allowed to proceed' ) );

            if ( $is_json ) {
                // Any JSON — success or a JSON-RPC/REST auth error — means the
                // request REACHED the WordPress REST stack. Not blocked.
                $out = [ 'ran' => true, 'blocked' => false, 'http_code' => $code, 'detail' => 'MCP endpoint reachable from the server itself (HTTP ' . $code . ').' ];
            } else {
                $out = [
                    'ran'       => true,
                    'blocked'   => true,
                    'http_code' => $code,
                    'detail'    => sprintf(
                        'The MCP endpoint returned a non-JSON %s (HTTP %d) — a security layer is intercepting REST requests before WordPress runs.',
                        $cerber ? 'WP Cerber block page' : 'response',
                        $code
                    ),
                ];
            }
        }

        set_transient( self::PROBE_TRANSIENT, $out, self::PROBE_TTL );
        return $out;
    }

    /** One-line human summary, used in the guide's site facts. */
    public static function summary(): string {
        $s       = self::get();
        $plugins = wp_list_pluck( $s['security_plugins'], 'name' );

        if ( $s['blocked'] ) {
            return sprintf(
                'REST API BLOCKED by a security layer%s — external MCP connections will fail until the "mcp" namespace or the connecting IPs are allowed. %s',
                $plugins ? ' (' . implode( ', ', $plugins ) . ' active)' : '',
                $s['security_plugins'][0]['fix'] ?? ''
            );
        }
        if ( $plugins ) {
            return sprintf(
                '%s active. The MCP endpoint answers from the server itself, but if this site restricts REST by IP allowlist, external connections still fail unless every connecting IP is listed. If a connection stops working, check the security plugin\'s REST/IP settings before suspecting AI Nexus.',
                implode( ', ', $plugins )
            );
        }
        return 'No known REST-blocking security plugin detected.';
    }

    /**
     * Admin notice: hard error when the loopback proves the endpoint is
     * blocked; otherwise stay quiet (a merely-present security plugin is
     * reported in the guide, not nagged about).
     */
    public static function maybe_admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = self::get();
        if ( ! $s['blocked'] ) {
            return;
        }
        $fixes = wp_list_pluck( $s['security_plugins'], 'fix' );
        echo '<div class="notice notice-error"><p><strong>VGC AI Nexus:</strong> '
            . esc_html__( 'A security layer is blocking the WordPress REST API, so AI assistants cannot connect to this site — no AI Nexus setting can fix this.', 'mcp-abilities' )
            . ' ' . esc_html( $s['probe']['detail'] ?? '' );
        if ( $fixes ) {
            echo '</p><p>' . esc_html__( 'Fix:', 'mcp-abilities' ) . ' ' . esc_html( implode( ' ', $fixes ) );
        }
        echo '</p></div>';
    }
}
