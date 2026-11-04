<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches a URL a caller supplied, without letting the caller reach the
 * server's own network (SSRF). Every server-side download of a caller's URL
 * goes through here, so the rules are the same everywhere.
 *
 * The AI is untrusted even with administrator credentials, and a file the site
 * downloads ends up as a public attachment: an internal text response would be
 * published. So, for the URL and for every redirect hop:
 *
 *  - http and https only, no credentials in the URL, a normal host name or a
 *    normal IP address (no 2130706433, 0x7f.0.0.1, 127.1: PHP, cURL and the
 *    system resolver do not read those alike).
 *  - The host's A and AAAA records are all read; one address that is not public
 *    refuses the URL, and so does a host that resolves to nothing.
 *  - The connection is pinned to a checked address (CURLOPT_RESOLVE), so a
 *    second DNS answer cannot move it, and the address cURL really connected to
 *    is compared with it afterwards.
 *  - Redirects are followed here, one at a time, never by the HTTP client.
 *  - The size limit stops the transfer. Requests' limit_response_size alone would
 *    keep reading and hand over a truncated file as if it were whole; an
 *    over-limit file is refused, never stored cut short.
 *
 * Why cURL only: WordPress's other transport (fsockopen) has no resolve option,
 * so it could not be pinned. Without cURL the download is refused rather than
 * made unpinned. The same goes for an outgoing proxy, which resolves the host
 * itself (filter mcp_abilities_safe_fetch_through_proxy, for a site whose proxy
 * does its own checking).
 *
 * Filters:
 *  - mcp_abilities_safe_fetch_allow_host (bool, $host, $url): true lets one host
 *    through the address and port checks, for an administrator who wants an
 *    internal host on purpose. Off by default. Redirects are checked hop by hop,
 *    so an allowed host cannot redirect to one that is not.
 *  - mcp_abilities_safe_fetch_resolve (null|string[], $host): replaces the DNS
 *    lookup (tests, and sites with their own resolver).
 *  - http_allowed_safe_ports: WordPress's own list (80, 443, 8080), which
 *    download_url() applied before this class.
 */
final class Safe_Fetch {

    /** Redirect hops followed for one download. */
    private const MAX_REDIRECTS = 3;

    /**
     * IPv4 ranges that are not the public internet. Written out because
     * FILTER_VALIDATE_IP's flags miss some of them (100.64.0.0/10, for one).
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',         // "This" network, 0.0.0.0 included.
        '10.0.0.0/8',        // Private.
        '100.64.0.0/10',     // Carrier-grade NAT; cloud metadata 100.100.100.200.
        '127.0.0.0/8',       // Loopback.
        '169.254.0.0/16',    // Link-local; cloud metadata 169.254.169.254.
        '172.16.0.0/12',     // Private.
        '192.0.0.0/24',      // IETF protocol assignments; Oracle metadata 192.0.0.192.
        '192.0.2.0/24',      // Documentation.
        '192.31.196.0/24',   // AS112.
        '192.52.193.0/24',   // AMT.
        '192.88.99.0/24',    // 6to4 relay.
        '192.168.0.0/16',    // Private.
        '192.175.48.0/24',   // AS112.
        '198.18.0.0/15',     // Benchmarking.
        '198.51.100.0/24',   // Documentation.
        '203.0.113.0/24',    // Documentation.
        '224.0.0.0/4',       // Multicast.
        '240.0.0.0/4',       // Reserved, 255.255.255.255 included.
        '168.63.129.16/32',  // Azure platform services, reachable from every VM.
    ];

    /** IPv6 ranges inside global unicast (2000::/3) that are not the public internet. */
    private const BLOCKED_V6 = [
        '2001::/23',   // IETF special purpose: Teredo, benchmarking, ORCHID.
        '2001:db8::/32', // Documentation.
        '2002::/16',   // 6to4: an IPv4 address in disguise.
        '3fff::/20',   // Documentation.
    ];

    /** For tests: behave as on a server without the cURL extension. */
    public static bool $pretend_no_curl = false;

    /**
     * Download a URL to a temporary file. The caller owns the file.
     *
     * @param string $url       The caller's URL.
     * @param int    $max_bytes Largest file accepted.
     * @param int    $timeout   Seconds for each hop.
     * @return string|\WP_Error Path of the temporary file. On an error nothing is left behind.
     */
    public static function download( string $url, int $max_bytes, int $timeout = 30 ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
            $target = self::check_url( $url );
            if ( is_wp_error( $target ) ) {
                return 0 === $hop ? $target : new \WP_Error(
                    $target->get_error_code(),
                    sprintf( 'Refused: the URL redirects to "%s". %s', self::host_for_message( $url ), $target->get_error_message() )
                );
            }

            $blocked = self::transport_problem( $target['url'], $target['scheme'] );
            if ( null !== $blocked ) {
                return $blocked;
            }

            $tmp = wp_tempnam( basename( (string) wp_parse_url( $target['url'], PHP_URL_PATH ) ) );
            if ( ! $tmp ) {
                return new \WP_Error( 'safe_fetch_no_temp', 'Could not create a temporary file on the server.' );
            }

            $response = self::request( $target, $tmp, $max_bytes, $timeout );
            if ( is_wp_error( $response ) ) {
                self::remove( $tmp );
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) ) {
                self::remove( $tmp );
                $location = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
                if ( '' === $location ) {
                    return new \WP_Error( 'safe_fetch_status', sprintf( 'The server answered HTTP %d without saying where to go, so nothing was stored.', $code ) );
                }
                $url = \WP_Http::make_absolute_url( str_replace( ' ', '%20', $location ), $target['url'] );
                continue;
            }
            if ( 200 !== $code ) {
                self::remove( $tmp );
                return new \WP_Error( 'safe_fetch_status', sprintf( 'The server answered HTTP %d, so nothing was stored.', $code ) );
            }

            clearstatcache( true, $tmp );
            if ( (int) filesize( $tmp ) > $max_bytes ) {
                self::remove( $tmp );
                return self::too_large( $max_bytes );
            }
            return $tmp;
        }

        return new \WP_Error( 'safe_fetch_redirects', sprintf( 'The URL redirected more than %d times, so it was not followed further. Give the final address.', self::MAX_REDIRECTS ) );
    }

    /**
     * Whether a URL may be fetched, and where to.
     *
     * @return array{url:string,scheme:string,host:string,port:int,addresses:string[],pin:string}|\WP_Error
     *         The URL rebuilt from its checked parts, and the addresses it resolved to.
     */
    public static function check_url( string $url ) {
        $url = trim( $url );
        // A backslash or a control character is where URL parsers start to disagree.
        if ( '' === $url || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
            return new \WP_Error( 'safe_fetch_bad_url', 'That is not a valid URL.' );
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || '' === (string) ( $parts['host'] ?? '' ) ) {
            return new \WP_Error( 'safe_fetch_bad_url', 'That is not a valid URL.' );
        }

        $scheme = strtolower( (string) $parts['scheme'] );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return new \WP_Error( 'safe_fetch_scheme', 'Only http and https URLs can be downloaded.' );
        }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return new \WP_Error( 'safe_fetch_credentials', 'A URL with a user name or password is not accepted.' );
        }

        $host    = strtolower( (string) $parts['host'] );
        $port    = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
        $literal = self::literal_address( $host );
        if ( null === $literal ) {
            if ( self::is_numeric_host( $host ) ) {
                return new \WP_Error( 'safe_fetch_numeric_host', 'That host is written as a number. Use a host name or an ordinary IP address.' );
            }
            if ( ! preg_match( '/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/', $host ) ) {
                return new \WP_Error( 'safe_fetch_bad_url', 'That host name is not valid. Write it without a trailing dot, and an international name in its xn-- form.' );
            }
        }

        $rebuilt = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . $port : '' )
            . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

        $allowed = (bool) apply_filters( 'mcp_abilities_safe_fetch_allow_host', false, $host, $rebuilt );

        $addresses = null !== $literal ? [ $literal ] : self::resolve( $host );
        if ( ! $addresses ) {
            return new \WP_Error( 'safe_fetch_no_dns', sprintf( 'Could not resolve the host "%s". Check the URL.', $host ) );
        }

        if ( ! $allowed ) {
            foreach ( $addresses as $address ) {
                if ( ! self::is_public_ip( $address ) ) {
                    // Which address stays unsaid: the caller must not learn the internal network from this.
                    return new \WP_Error( 'safe_fetch_internal', sprintf( 'Refused: "%s" points to a private, local or reserved address, and this site downloads only from public addresses. Give a public URL.', $host ) );
                }
            }
            $ports = array_map( 'intval', (array) apply_filters( 'http_allowed_safe_ports', [ 80, 443, 8080 ], $host, $rebuilt ) );
            if ( ! in_array( $port, $ports, true ) ) {
                return new \WP_Error( 'safe_fetch_port', sprintf( 'Port %d is not allowed. This site downloads only from ports %s.', $port, implode( ', ', $ports ) ) );
            }
        }

        return [
            'url'       => $rebuilt,
            'scheme'    => $scheme,
            'host'      => $host,
            'port'      => $port,
            'addresses' => $addresses,
            'pin'       => self::choose_pin( $addresses ),
        ];
    }

    /** Whether an IP address, IPv4 or IPv6, is on the public internet. */
    public static function is_public_ip( string $ip ): bool {
        $packed = @inet_pton( trim( $ip, '[] ' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false is the answer for a non-address.
        if ( false === $packed ) {
            return false;
        }

        if ( 4 === strlen( $packed ) ) {
            return ! self::in_ranges( $packed, self::BLOCKED_V4 );
        }

        // An IPv4 address inside IPv6 is judged as that IPv4 address:
        // mapped (::ffff:0:0/96) and NAT64 (64:ff9b::/96).
        $head = substr( $packed, 0, 12 );
        if ( str_repeat( "\0", 10 ) . "\xff\xff" === $head || hex2bin( '0064ff9b0000000000000000' ) === $head ) {
            return ! self::in_ranges( substr( $packed, 12 ), self::BLOCKED_V4 );
        }

        // Only global unicast is public. That leaves out ::, ::1, ::/96 (IPv4
        // compatible), 100::/64, unique local fc00::/7, link-local fe80::/10,
        // site-local fec0::/10 and multicast ff00::/8.
        if ( 0x20 !== ( ord( $packed[0] ) & 0xe0 ) ) {
            return false;
        }
        return ! self::in_ranges( $packed, self::BLOCKED_V6 );
    }

    // ── The request ──────────────────────────────────────────────────────────

    /**
     * One hop, no redirects, written to $tmp, pinned to the checked address.
     *
     * @param array<string,mixed> $target From check_url().
     * @return array|\WP_Error The wp_remote_get() response.
     */
    private static function request( array $target, string $tmp, int $max_bytes, int $timeout ) {
        $url     = (string) $target['url'];
        $pin     = (string) $target['pin'];
        $is_ip   = null !== self::literal_address( (string) $target['host'] );
        $oversize = false;
        $reached  = false;
        $landed   = '';

        // Nothing another plugin set on the request may turn the fetch into something else.
        $settle = static function ( &$req_url, &$headers, &$data, &$type, &$options ) use ( $url ) {
            if ( $req_url === $url && is_array( $options ) ) {
                $options['transport']        = class_exists( '\WpOrg\Requests\Transport\Curl' ) ? '\WpOrg\Requests\Transport\Curl' : 'Requests_Transport_cURL';
                $options['follow_redirects'] = false;
            }
        };

        $on_curl = static function ( $handle, $args, $req_url ) use ( $url, $pin, $target, $is_ip, $max_bytes, &$oversize, &$reached ) {
            if ( $req_url !== $url ) {
                return;
            }
            $reached = true;
            if ( ! $is_ip ) {
                $address = false !== strpos( $pin, ':' ) ? '[' . $pin . ']' : $pin;
                curl_setopt( $handle, CURLOPT_RESOLVE, [ $target['host'] . ':' . $target['port'] . ':' . $address ] );
            }
            curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
            // Requests would keep reading after limit_response_size and drop the rest.
            curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
            curl_setopt(
                $handle,
                CURLOPT_PROGRESSFUNCTION,
                static function ( $ch, $total, $now ) use ( $max_bytes, &$oversize ) {
                    if ( $total > $max_bytes || $now > $max_bytes ) {
                        $oversize = true;
                        return 1;
                    }
                    return 0;
                }
            );
        };

        $after = static function ( &$headers, &$info = null ) use ( &$landed ) {
            if ( is_array( $info ) && ! empty( $info['primary_ip'] ) ) {
                $landed = (string) $info['primary_ip'];
            }
        };

        add_action( 'requests-requests.before_request', $settle, 10, 5 );
        add_action( 'http_api_curl', $on_curl, 999, 3 );
        add_action( 'requests-curl.after_request', $after, 10, 2 );
        try {
            $response = wp_remote_get( $url, [
                'timeout'             => $timeout,
                'redirection'         => 0,
                'stream'              => true,
                'filename'            => $tmp,
                'limit_response_size' => $max_bytes + 1,
                // Our checks replace it: it would refuse an allowed internal host.
                'reject_unsafe_urls'  => false,
            ] );
        } finally {
            remove_action( 'requests-requests.before_request', $settle, 10 );
            remove_action( 'http_api_curl', $on_curl, 999 );
            remove_action( 'requests-curl.after_request', $after, 10 );
        }

        if ( $oversize ) {
            return self::too_large( $max_bytes );
        }
        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'safe_fetch_failed', 'The download failed: ' . $response->get_error_message() );
        }
        // Fail closed: answered by something that was not this pinned connection
        // (a plugin's pre_http_request, or a transport that ignored the settings).
        // Through a proxy the only address cURL sees is the proxy's.
        if ( ! $reached || ( '' !== $landed && ! self::same_ip( $landed, $pin ) && ! self::through_proxy( $url ) ) ) {
            return new \WP_Error( 'safe_fetch_unpinned', 'The connection did not go to the address that was checked, so the download was dropped.' );
        }
        return $response;
    }

    private static function through_proxy( string $url ): bool {
        $proxy = new \WP_HTTP_Proxy();
        return $proxy->is_enabled() && $proxy->send_through_proxy( $url );
    }

    /** Why this server cannot make a pinned request for a URL, or null when it can. */
    private static function transport_problem( string $url, string $scheme ): ?\WP_Error {
        $ok = ! self::$pretend_no_curl
            && function_exists( 'curl_init' ) && function_exists( 'curl_exec' ) && function_exists( 'curl_version' )
            && defined( 'CURLOPT_RESOLVE' );
        if ( $ok && 'https' === $scheme ) {
            $curl = curl_version();
            $ok   = is_array( $curl ) && ( (int) ( $curl['features'] ?? 0 ) & CURL_VERSION_SSL );
        }
        if ( ! $ok ) {
            return new \WP_Error( 'safe_fetch_no_curl', 'This server cannot download from a URL safely: PHP\'s cURL extension is missing here, or cannot do https. Nothing was fetched.' );
        }

        if ( self::through_proxy( $url ) && ! apply_filters( 'mcp_abilities_safe_fetch_through_proxy', false, $url ) ) {
            return new \WP_Error( 'safe_fetch_proxy', 'This site sends its outgoing requests through a proxy, which looks up addresses itself, so the site cannot make sure the download stays on public addresses. Nothing was fetched. An administrator can allow this with the mcp_abilities_safe_fetch_through_proxy filter.' );
        }
        return null;
    }

    // ── Hosts and addresses ──────────────────────────────────────────────────

    /** The address a host name means when it is an ordinary IP address; null otherwise. */
    private static function literal_address( string $host ): ?string {
        if ( '' !== $host && '[' === $host[0] ) {
            $inner = trim( $host, '[]' );
            return filter_var( $inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? (string) inet_ntop( (string) inet_pton( $inner ) ) : null;
        }
        // FILTER_VALIDATE_IP takes no leading zeros, so 127.000.000.001 is not an IP here.
        return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $host : null;
    }

    /**
     * A host that a resolver may read as a number: all digits and dots, or a last
     * label in hex or digits (WHATWG's "ends in a number"). 2130706433, 127.1,
     * 0x7f.0.0.1, 0177.0.0.1 and 0x7f000001 are all 127.0.0.1 to someone.
     */
    private static function is_numeric_host( string $host ): bool {
        if ( '' !== $host && '[' === $host[0] ) {
            return true; // Brackets holding something that is not an IPv6 address.
        }
        $labels = explode( '.', rtrim( $host, '.' ) );
        $last   = (string) end( $labels );
        return (bool) preg_match( '/^(\d+|0x[0-9a-f]*)$/', $last );
    }

    /**
     * Every address of a host: A and AAAA from DNS, plus what the system resolver
     * says (the hosts file; the only source when dns_get_record is switched off).
     *
     * @return string[]
     */
    private static function resolve( string $host ): array {
        $seam = apply_filters( 'mcp_abilities_safe_fetch_resolve', null, $host );
        if ( is_array( $seam ) ) {
            return array_values( array_unique( array_filter( $seam, 'is_string' ) ) );
        }

        $found = [];
        if ( function_exists( 'dns_get_record' ) ) {
            foreach ( [ [ DNS_A, 'ip' ], [ DNS_AAAA, 'ipv6' ] ] as [ $type, $field ] ) {
                $records = @dns_get_record( $host, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failed lookup warns; it counts as no records.
                foreach ( (array) $records as $record ) {
                    if ( ! empty( $record[ $field ] ) ) {
                        $found[] = (string) $record[ $field ];
                    }
                }
            }
        }
        foreach ( (array) gethostbynamel( $host ) as $ip ) {
            if ( is_string( $ip ) ) {
                $found[] = $ip;
            }
        }
        return array_values( array_unique( $found ) );
    }

    /**
     * The one address to connect to: the first IPv4 if there is one. One address,
     * not a list: cURL before 7.59 cannot read a list and would then ask DNS.
     */
    private static function choose_pin( array $addresses ): string {
        foreach ( $addresses as $address ) {
            if ( false === strpos( $address, ':' ) ) {
                return $address;
            }
        }
        return (string) reset( $addresses );
    }

    private static function same_ip( string $a, string $b ): bool {
        $a = @inet_pton( trim( $a, '[] ' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $b = @inet_pton( trim( $b, '[] ' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        return false !== $a && $a === $b;
    }

    /**
     * Whether a packed address falls in one of the CIDR ranges of its family.
     *
     * @param string[] $ranges
     */
    private static function in_ranges( string $packed, array $ranges ): bool {
        foreach ( $ranges as $range ) {
            [ $base, $bits ] = explode( '/', $range );
            $net             = (string) inet_pton( $base );
            $bits            = (int) $bits;
            $whole           = (int) floor( $bits / 8 );
            if ( 0 !== strncmp( $packed, $net, $whole ) ) {
                continue;
            }
            if ( 0 === $bits % 8 ) {
                return true;
            }
            $mask = ( 0xff << ( 8 - $bits % 8 ) ) & 0xff;
            if ( ( ord( $packed[ $whole ] ) & $mask ) === ( ord( $net[ $whole ] ) & $mask ) ) {
                return true;
            }
        }
        return false;
    }

    // ── Small helpers ────────────────────────────────────────────────────────

    private static function too_large( int $max_bytes ): \WP_Error {
        return new \WP_Error( 'safe_fetch_too_large', sprintf( 'The file at that URL is larger than the %s MB limit, so nothing was stored.', round( $max_bytes / 1048576, 1 ) ) );
    }

    private static function host_for_message( string $url ): string {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        return is_string( $host ) && '' !== $host ? sanitize_text_field( $host ) : 'an unusable address';
    }

    private static function remove( string $file ): void {
        if ( is_file( $file ) ) {
            @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }
    }
}
