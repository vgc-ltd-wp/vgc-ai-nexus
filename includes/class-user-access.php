<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Which roles the current user may give to a user (create_user, update_user).
 *
 * - The role must be one WordPress lets this user assign: get_editable_roles()
 *   (filterable with `editable_roles`, as on the Users screens).
 * - Administrators (manage_options) may assign any of those.
 * - With promote_users but without manage_options: no role that carries a
 *   capability the caller lacks, so a custom role with manage_options (or any
 *   other power the caller does not have) cannot be handed out.
 * - Without promote_users: only roles with nothing beyond `read`
 *   (subscriber, WooCommerce customer).
 */
final class User_Access {

    /**
     * Why the current user may not assign this role, or null when they may.
     */
    public static function role_error( string $role ): ?string {
        if ( ! function_exists( 'get_editable_roles' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $roles = get_editable_roles();
        if ( '' === $role || ! isset( $roles[ $role ] ) ) {
            return sprintf( 'Unknown role "%s". Roles you can choose from: %s.', $role, implode( ', ', array_keys( $roles ) ) );
        }
        if ( current_user_can( 'manage_options' ) ) {
            return null;
        }

        // Legacy level_N capabilities say nothing about what a role may do.
        $caps = array_filter(
            array_keys( array_filter( (array) ( $roles[ $role ]['capabilities'] ?? [] ) ) ),
            static fn( $cap ) => ! preg_match( '/^level_\d+$/', (string) $cap )
        );

        if ( ! current_user_can( 'promote_users' ) ) {
            return array_diff( $caps, [ 'read' ] )
                ? sprintf( 'Without the promote_users permission you can only give subscriber-level roles; "%s" has more permissions.', $role )
                : null;
        }
        foreach ( $caps as $cap ) {
            if ( ! current_user_can( $cap ) ) {
                return sprintf( 'You cannot give the "%s" role: it has permissions your own account does not have (e.g. %s).', $role, $cap );
            }
        }
        return null;
    }
}
