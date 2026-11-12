<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The address a request came from, for every part of AI Nexus that records or
 * counts one (activity log, connection approvals, OAuth rate limits).
 *
 * It is REMOTE_ADDR. X-Forwarded-For is written by the caller, so it says nothing
 * unless the machine that connected to this server is a proxy the site trusts.
 *
 * A site behind a proxy names it with the filter mcp_abilities_trusted_proxies
 * (off by default): addresses or CIDR ranges, IPv4 or IPv6. When REMOTE_ADDR is one
 * of them, X-Forwarded-For is read from the right, since each proxy appends the
 * address it saw, and the first hop that is not a trusted proxy is the visitor.
 * Anything to the left of that hop was sent by the visitor and is ignored.
 */
final class Client_Ip {

    /** The caller's address, or '' when there is no usable one (WP-CLI). */
    public static function get(): string {
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? self::normal( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( '' === $remote ) {
            return '';
        }

        /**
         * Filters which proxies may say who the visitor is.
         *
         * @param string[] $proxies Addresses or CIDR ranges, e.g. [ '10.0.0.5', '173.245.48.0/20' ]. Default none.
         * @param string   $remote  REMOTE_ADDR of this request.
         */
        $trusted = (array) apply_filters( 'mcp_abilities_trusted_proxies', [], $remote );
        if ( ! $trusted || ! self::in_list( $remote, $trusted ) ) {
            return $remote;
        }

        $forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        foreach ( array_reverse( explode( ',', $forwarded ) ) as $hop ) {
            $hop = self::normal( $hop );
            if ( '' === $hop ) {
                break; // Not an address: nothing further left can be believed.
            }
            if ( ! self::in_list( $hop, $trusted ) ) {
                return $hop;
            }
        }
        return $remote;
    }

    /** REMOTE_ADDR in its plain form, or '' (2.42.0). */
    public static function remote(): string {
        return isset( $_SERVER['REMOTE_ADDR'] ) ? self::normal( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    }

    /** Whether the site names this address as a proxy (filter mcp_abilities_trusted_proxies). 2.42.0. */
    public static function is_trusted_proxy( string $ip ): bool {
        $ip = self::normal( $ip );
        if ( '' === $ip ) {
            return false;
        }
        $trusted = (array) apply_filters( 'mcp_abilities_trusted_proxies', [], $ip );
        return $trusted && self::in_list( $ip, $trusted );
    }

    /** A valid address in its plain form, or ''. IPv4 inside IPv6 comes out as IPv4. */
    private static function normal( string $ip ): string {
        $ip = trim( $ip );
        if ( 0 === stripos( $ip, '::ffff:' ) && false !== filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $ip = substr( $ip, 7 );
        }
        return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    /** @param array<int,mixed> $list Addresses or CIDR ranges; anything else is skipped. */
    private static function in_list( string $ip, array $list ): bool {
        $packed = (string) inet_pton( $ip );
        foreach ( $list as $entry ) {
            if ( ! is_string( $entry ) || '' === trim( $entry ) ) {
                continue;
            }
            $parts = explode( '/', trim( $entry ), 2 );
            $net   = '' !== self::normal( $parts[0] ) ? (string) inet_pton( self::normal( $parts[0] ) ) : '';
            if ( '' === $net || strlen( $net ) !== strlen( $packed ) ) {
                continue;
            }
            $max  = strlen( $net ) * 8;
            $bits = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : ( isset( $parts[1] ) ? -1 : $max );
            if ( $bits < 0 || $bits > $max ) {
                continue;
            }
            $whole = intdiv( $bits, 8 );
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
}
