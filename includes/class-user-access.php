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
     * Whether an account holds more than the current user: it can manage_options
     * (whatever its role is called) and the current user cannot.
     */
    public static function outranks( \WP_User $target ): bool {
        return user_can( $target, 'manage_options' ) && ! current_user_can( 'manage_options' );
    }

    /**
     * Whether the current user may do this to this account: a meta capability
     * (edit_user, promote_user, delete_user) with the account's id, so that
     * map_meta_cap filters run: WooCommerce's (a shop manager edits customers
     * only) and multisite's (super admins).
     */
    public static function can_on( string $meta_cap, \WP_User $target ): bool {
        return current_user_can( $meta_cap, $target->ID );
    }

    /**
     * Whether removing this account would leave nobody who can manage the site:
     * it is the last with the administrator role, or the last with manage_options.
     */
    public static function is_last_admin( \WP_User $target ): bool {
        if ( in_array( 'administrator', (array) $target->roles, true )
            && count( get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ] ) ) <= 1 ) {
            return true;
        }
        return user_can( $target, 'manage_options' )
            && ! get_users( [ 'capability' => 'manage_options', 'exclude' => [ $target->ID ], 'fields' => 'ID', 'number' => 1 ] );
    }

    /**
     * Why the current user may not give themselves this role, or null (also when the
     * account is someone else's). The new role must keep edit_users and promote_users:
     * the REST API asks the first, the profile screen the second. A multisite super
     * admin may, as in WordPress.
     */
    public static function own_role_error( \WP_User $target, string $role ): ?string {
        if ( (int) $target->ID !== get_current_user_id() || ( is_multisite() && current_user_can( 'manage_network_users' ) ) ) {
            return null;
        }
        $role_object = get_role( $role );
        if ( $role_object && $role_object->has_cap( 'edit_users' ) && $role_object->has_cap( 'promote_users' ) ) {
            return null;
        }
        return sprintf( 'You cannot give your own account the "%s" role: it would take away your permission to manage users. Ask another administrator.', $role );
    }

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
