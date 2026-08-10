<?php
/**
 * Role-based login / logout redirects.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pick redirect URL for a user from role map + default.
 *
 * @param WP_User              $user    User.
 * @param array<string,string> $role_map Role => URL.
 * @param string               $default Default URL (may be empty).
 */
function logliy_pick_role_redirect( WP_User $user, array $role_map, string $default ): string {
	foreach ( (array) $user->roles as $role ) {
		if ( isset( $role_map[ $role ] ) && $role_map[ $role ] !== '' ) {
			return (string) $role_map[ $role ];
		}
	}
	return $default;
}

/**
 * After login redirect (password + Logliy paths via login_redirect).
 *
 * @param string           $redirect_to           Requested redirect.
 * @param string           $requested_redirect_to Original requested.
 * @param WP_User|WP_Error $user                  User or error.
 * @return string
 */
add_filter( 'login_redirect', 'logliy_filter_login_redirect', 20, 3 );
function logliy_filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! $user instanceof WP_User ) {
		return $redirect_to;
	}

	// Honor an explicit non-empty redirect_to from the form/request when it is not just the admin default.
	$requested = is_string( $requested_redirect_to ) ? $requested_redirect_to : '';
	if ( $requested !== '' && wp_validate_redirect( $requested, false ) ) {
		$admin = untrailingslashit( admin_url() );
		$req   = untrailingslashit( $requested );
		if ( $req !== $admin && strpos( $req, $admin . '/' ) !== 0 ) {
			return $redirect_to;
		}
	}

	$map     = logliy_get_setting( 'redirect_login_roles', array() );
	$map     = is_array( $map ) ? $map : array();
	$default = (string) logliy_get_setting( 'redirect_login_default', '' );
	$picked  = logliy_pick_role_redirect( $user, $map, $default );
	if ( $picked === '' ) {
		return $redirect_to;
	}
	return logliy_safe_redirect_url( $picked );
}

/**
 * After logout redirect.
 *
 * @param string           $redirect_to           Redirect.
 * @param string           $requested_redirect_to Requested.
 * @param WP_User|WP_Error $user                  User.
 * @return string
 */
add_filter( 'logout_redirect', 'logliy_filter_logout_redirect', 20, 3 );
function logliy_filter_logout_redirect( $redirect_to, $requested_redirect_to, $user ) {
	unset( $requested_redirect_to );
	if ( ! $user instanceof WP_User ) {
		$default = (string) logliy_get_setting( 'redirect_logout_default', '' );
		return $default !== '' ? logliy_safe_redirect_url( $default ) : $redirect_to;
	}
	$map     = logliy_get_setting( 'redirect_logout_roles', array() );
	$map     = is_array( $map ) ? $map : array();
	$default = (string) logliy_get_setting( 'redirect_logout_default', '' );
	$picked  = logliy_pick_role_redirect( $user, $map, $default );
	if ( $picked === '' ) {
		return $redirect_to;
	}
	return logliy_safe_redirect_url( $picked );
}
