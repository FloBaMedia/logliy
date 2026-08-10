<?php
/**
 * Password login policy (default: disabled).
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the emergency wp-config constant allows passwords.
 */
function logliy_password_emergency_allowed(): bool {
	return defined( 'LOGLIY_ALLOW_PASSWORD' ) && LOGLIY_ALLOW_PASSWORD;
}

/**
 * Whether password login is allowed site-wide by settings or emergency constant.
 */
function logliy_password_login_globally_allowed(): bool {
	if ( logliy_password_emergency_allowed() ) {
		return true;
	}
	return (bool) logliy_get_setting( 'allow_password_login', false );
}

/**
 * Per-user meta: allow password login for this user.
 */
function logliy_user_password_allowed( int $user_id ): bool {
	return get_user_meta( $user_id, 'logliy_allow_password', true ) === '1';
}

/**
 * Whether a specific user may use password login.
 *
 * @param WP_User|null $user User or null.
 */
function logliy_password_allowed_for_user( ?WP_User $user ): bool {
	if ( logliy_password_emergency_allowed() ) {
		return true;
	}
	if ( ! $user instanceof WP_User ) {
		return logliy_password_login_globally_allowed();
	}
	if ( logliy_user_password_allowed( (int) $user->ID ) ) {
		return true;
	}
	if ( logliy_password_login_globally_allowed() ) {
		$roles = logliy_get_setting( 'password_allowed_roles', array() );
		if ( ! is_array( $roles ) || $roles === array() ) {
			return true;
		}
		foreach ( (array) $user->roles as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return true;
			}
		}
		return false;
	}
	$roles = logliy_get_setting( 'password_allowed_roles', array() );
	if ( is_array( $roles ) ) {
		foreach ( (array) $user->roles as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Detect non-interactive / API auth contexts that must never be blocked.
 */
function logliy_is_exempt_auth_context(): bool {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		// Application Passwords and API auth use authenticate during REST.
		return true;
	}
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return true;
	}
	return false;
}

/**
 * Block password authentication when policy disallows it.
 *
 * @param WP_User|WP_Error|null $user     User or error.
 * @param string                $username Username.
 * @param string                $password Password.
 * @return WP_User|WP_Error|null
 */
function logliy_filter_authenticate( $user, string $username, string $password ) {
	if ( $password === '' ) {
		return $user;
	}
	if ( logliy_is_exempt_auth_context() ) {
		return $user;
	}

	if ( logliy_is_password_login_request() && $username !== '' ) {
		$mode = logliy_login_identifier_mode();
		if ( $mode === 'email' && ! is_email( $username ) ) {
			return new WP_Error( 'logliy_login_identifier', logliy_login_identifier_empty_message() );
		}
		if ( $mode === 'username' && is_email( $username ) ) {
			return new WP_Error( 'logliy_login_identifier', logliy_login_identifier_empty_message() );
		}
	}

	if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
		// Still block empty success paths; let WP continue for unknown users.
		if ( ! logliy_password_login_globally_allowed() && ! logliy_password_emergency_allowed() ) {
			// Only intervene when a password was supplied on a frontend login surface.
			if ( logliy_is_password_login_request() ) {
				return new WP_Error(
					'logliy_password_disabled',
					__( 'Password login is disabled. Use a Passkey or Email code instead.', 'logliy' )
				);
			}
		}
		return $user;
	}

	if ( logliy_password_allowed_for_user( $user ) ) {
		return $user;
	}

	if ( ! logliy_is_password_login_request() ) {
		return $user;
	}

	return new WP_Error(
		'logliy_password_disabled',
		__( 'Password login is disabled for this account. Use a Passkey or Email code instead.', 'logliy' )
	);
}
add_filter( 'authenticate', 'logliy_filter_authenticate', 30, 3 );

/**
 * Secondary check after user is resolved.
 *
 * @param WP_User|WP_Error $user User.
 * @return WP_User|WP_Error
 */
function logliy_filter_wp_authenticate_user( $user ) {
	if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
		return $user;
	}
	if ( logliy_is_exempt_auth_context() ) {
		return $user;
	}
	if ( ! logliy_is_password_login_request() ) {
		return $user;
	}
	if ( logliy_password_allowed_for_user( $user ) ) {
		return $user;
	}
	return new WP_Error(
		'logliy_password_disabled',
		__( 'Password login is disabled for this account. Use a Passkey or Email code instead.', 'logliy' )
	);
}
add_filter( 'wp_authenticate_user', 'logliy_filter_wp_authenticate_user', 30 );

/**
 * Heuristic: classic password form POST (wp-login / WC login).
 */
function logliy_is_password_login_request(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( empty( $_POST['pwd'] ) && empty( $_POST['password'] ) && empty( $_POST['logliy_password'] ) ) {
		return false;
	}
	// Application password creation screens etc. still send pwd — exempt REST already handled.
	return true;
}
