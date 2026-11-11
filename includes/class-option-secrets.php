<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Credentials kept INSIDE an option's value (2.41.0).
 *
 * The options tools protect whole options by name. Many plugins keep their API
 * user, password, token or key as one field of a "<plugin>_settings" array, which
 * a name rule cannot see. Here the value itself is looked at:
 *
 *   - Reading (get_option): every field whose key looks like a credential is
 *     replaced by MASK, at any depth, in arrays, objects and JSON strings.
 *   - Writing (update_option): such a field cannot be changed or removed. Sending
 *     MASK back (what a read showed) keeps the stored value, so an AI can change
 *     the other fields of a settings array without wiping the credential in it.
 *
 * A key counts as a credential when it names a password, secret, token, API /
 * private / access / auth key, licence key or credentials (see KEY_PATTERN). A
 * public key (a reCAPTCHA site key, a publishable key) does not.
 */
final class Option_Secrets {

    public const MASK = '*** hidden by AI Nexus ***';

    /** Field names that hold a credential. */
    private const KEY_PATTERN = '/(^|[_\-\s.])(pass|passwd|password|passphrase|pwd)($|[_\-\s.])|password|passwd|secret|token|api[_\-]?key|apikey|private[_\-]?key|access[_\-]?key|auth[_\-]?key|licen[cs]e|credential|hmac|signing[_\-]?key/i';

    /** How deep a value is looked into. */
    private const MAX_DEPTH = 12;

    public static function is_secret_key( $key ): bool {
        return is_string( $key ) && '' !== $key && 1 === preg_match( self::KEY_PATTERN, $key );
    }

    /**
     * The value with every credential field masked.
     *
     * @param mixed    $value
     * @param string[] $found Paths of the masked fields, appended in place.
     * @return mixed
     */
    public static function mask( $value, array &$found = [], string $path = '', int $depth = 0 ) {
        if ( $depth > self::MAX_DEPTH ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            $decoded = self::json_object( $value );
            if ( null !== $decoded ) {
                $before  = count( $found );
                $masked  = self::mask( $decoded, $found, $path, $depth + 1 );
                return count( $found ) > $before ? (string) wp_json_encode( $masked ) : $value;
            }
            return $value;
        }
        $is_object = is_object( $value );
        if ( ! is_array( $value ) && ! $is_object ) {
            return $value;
        }
        $out = $is_object ? clone $value : $value;
        foreach ( ( $is_object ? get_object_vars( $value ) : $value ) as $key => $item ) {
            $here = '' === $path ? (string) $key : $path . '.' . $key;
            if ( self::is_secret_key( (string) $key ) && self::is_filled( $item ) ) {
                $item    = self::MASK;
                $found[] = $here;
            } else {
                $item = self::mask( $item, $found, $here, $depth + 1 );
            }
            if ( $is_object ) {
                $out->{$key} = $item;
            } else {
                $out[ $key ] = $item;
            }
        }
        return $out;
    }

    /**
     * Check a new value against the stored one: credential fields may not change
     * or disappear; MASK keeps the stored value.
     *
     * @param mixed $new
     * @param mixed $old
     * @return array{value:mixed, error:?string}
     */
    public static function guard_write( $new, $old ): array {
        $error = null;
        $value = self::merge( $new, $old, '', 0, $error );
        return [ 'value' => $value, 'error' => $error ];
    }

    /**
     * @param mixed $new
     * @param mixed $old
     * @return mixed
     */
    private static function merge( $new, $old, string $path, int $depth, ?string &$error ) {
        if ( null !== $error || $depth > self::MAX_DEPTH ) {
            return $new;
        }
        // A JSON string stored by the plugin: look inside it the same way.
        if ( is_string( $old ) && null !== self::json_object( $old ) ) {
            $old_data = self::json_object( $old );
            $new_data = is_string( $new ) ? self::json_object( $new ) : ( is_array( $new ) ? $new : null );
            if ( null !== $new_data ) {
                $merged = self::merge( $new_data, $old_data, $path, $depth + 1, $error );
                return null === $error ? (string) wp_json_encode( $merged ) : $new;
            }
            if ( self::has_secret( $old_data ) ) {
                $error = sprintf( 'This option holds credentials%s; an AI may change its other fields, but not replace it as a whole with something else.', '' !== $path ? ' (at ' . $path . ')' : '' );
            }
            return $new;
        }
        $old_is = is_array( $old ) || is_object( $old );
        if ( ! $old_is ) {
            return $new;
        }
        $old_vars = is_object( $old ) ? get_object_vars( $old ) : $old;
        if ( ! is_array( $new ) && ! is_object( $new ) ) {
            if ( self::has_secret( $old_vars ) ) {
                $error = sprintf( 'This option holds credentials%s; an AI may change its other fields, but not replace it as a whole with something else.', '' !== $path ? ' (at ' . $path . ')' : '' );
            }
            return $new;
        }
        $is_object = is_object( $new );
        $new_vars  = $is_object ? get_object_vars( $new ) : $new;
        foreach ( $old_vars as $key => $old_item ) {
            $here = '' === $path ? (string) $key : $path . '.' . $key;
            if ( self::is_secret_key( (string) $key ) && self::is_filled( $old_item ) ) {
                if ( ! array_key_exists( $key, $new_vars ) || self::MASK === $new_vars[ $key ] ) {
                    $new_vars[ $key ] = $old_item; // left out or masked: the stored credential stays
                } elseif ( $new_vars[ $key ] !== $old_item ) {
                    $error = sprintf( 'The field "%s" holds a credential: an AI cannot change it. Change it in the plugin\'s own settings screen. Send the other fields only, or send it back as "%s".', $here, self::MASK );
                    return $new;
                }
                continue;
            }
            if ( array_key_exists( $key, $new_vars ) ) {
                $new_vars[ $key ] = self::merge( $new_vars[ $key ], $old_item, $here, $depth + 1, $error );
            } elseif ( self::has_secret( $old_item ) ) {
                $new_vars[ $key ] = $old_item; // a branch holding credentials is not dropped either
            }
        }
        // MASK sent for a credential field that is not stored: not a value to save.
        foreach ( $new_vars as $key => $item ) {
            if ( self::MASK === $item && ! array_key_exists( $key, $old_vars ) ) {
                unset( $new_vars[ $key ] );
            }
        }
        if ( $is_object ) {
            $obj = new \stdClass();
            foreach ( $new_vars as $k => $v ) {
                $obj->{$k} = $v;
            }
            return $obj;
        }
        return $new_vars;
    }

    /** Whether a value holds a filled credential field anywhere. */
    public static function has_secret( $value, int $depth = 0 ): bool {
        if ( $depth > self::MAX_DEPTH ) {
            return false;
        }
        if ( is_string( $value ) ) {
            $decoded = self::json_object( $value );
            return null !== $decoded && self::has_secret( $decoded, $depth + 1 );
        }
        if ( ! is_array( $value ) && ! is_object( $value ) ) {
            return false;
        }
        foreach ( ( is_object( $value ) ? get_object_vars( $value ) : $value ) as $key => $item ) {
            if ( ( self::is_secret_key( (string) $key ) && self::is_filled( $item ) ) || self::has_secret( $item, $depth + 1 ) ) {
                return true;
            }
        }
        return false;
    }

    /** A credential worth hiding: a non-empty scalar (an empty field or a flag is not one). */
    private static function is_filled( $item ): bool {
        if ( is_bool( $item ) || null === $item ) {
            return false;
        }
        if ( is_array( $item ) || is_object( $item ) ) {
            return ! empty( (array) $item );
        }
        return '' !== trim( (string) $item );
    }

    /**
     * A string holding a JSON object or array, decoded; null otherwise.
     *
     * @return array|null
     */
    private static function json_object( string $value ): ?array {
        $trim = ltrim( $value );
        if ( '' === $trim || ( '{' !== $trim[0] && '[' !== $trim[0] ) || strlen( $value ) > 2000000 ) {
            return null;
        }
        $data = json_decode( $value, true );
        return is_array( $data ) ? $data : null;
    }
}
