<?php
/**
 * Magic link (one-click email) authentication.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request a magic login link by email/username.
 *
 * @return array<string, mixed>|WP_Error
 */
function logliy_magic_link_request( string $login ) {
	if ( ! logliy_get_setting( 'enable_magic_link', true ) ) {
		return new WP_Error( 'logliy_magic_disabled', __( 'Magic link login is disabled.', 'logliy' ), array( 'status' => 403 ) );
	}

	$window  = (int) logliy_get_setting( 'otp_rate_window_minutes', 15 ) * MINUTE_IN_SECONDS;
	$ip_lim  = (int) logliy_get_setting( 'otp_rate_limit_ip', 20 );
	$ip      = logliy_client_ip();

	if ( trim( $login ) === '' ) {
		return new WP_Error( 'logliy_empty_login', logliy_login_identifier_empty_message(), array( 'status' => 400 ) );
	}

	$cd_ip = logliy_email_request_cooldown( 'email_ip_' . $ip );
	if ( is_wp_error( $cd_ip ) ) {
		return $cd_ip;
	}

	$ip_check = logliy_rate_limit_hit( 'email_ip_' . $ip, $ip_lim, $window );
	if ( is_wp_error( $ip_check ) ) {
		return $ip_check;
	}

	$public = array(
		'ok'       => true,
		'message'  => __( 'If this address is eligible for login, a magic login link has been sent.', 'logliy' ),
		'cooldown' => (int) logliy_get_setting( 'email_request_cooldown_seconds', 60 ),
	);

	$user = logliy_resolve_login_user( $login );
	if ( ! $user instanceof WP_User ) {
		return $public;
	}

	if ( ! logliy_email_login_allowed( $user ) ) {
		logliy_security_event( 'email_denied', (int) $user->ID );
		return $public;
	}
	if ( is_wp_error( logliy_email_account_limit( $user ) ) ) {
		return $public;
	}

	$token   = bin2hex( random_bytes( 32 ) );
	$ttl_min = (int) logliy_get_setting( 'magic_link_ttl_minutes', 15 );
	$ttl     = max( 5, $ttl_min ) * MINUTE_IN_SECONDS;

	$hash = hash( 'sha256', $token );
	if ( ! logliy_email_challenge_store( $user, 'magic', $hash, $ttl ) ) {
		return $public;
	}

	$redirect = '';
	if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect = esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	$url = add_query_arg(
		array_filter(
			array(
				'logliy_magic' => $token,
				'redirect_to'  => $redirect !== '' ? $redirect : null,
			)
		),
		wp_login_url()
	);

	$sent = logliy_magic_link_send_email( $user, $url, $ttl_min );
	if ( is_wp_error( $sent ) ) {
		logliy_email_challenge_delete( (int) $user->ID, 'magic', $hash );
		// Same public shape — do not leak that the account exists via mail errors.
		return $public;
	}

	logliy_security_event( 'email_link_sent', (int) $user->ID );
	return $public;
}

/**
 * Email the magic link.
 *
 * @return true|WP_Error
 */
function logliy_magic_link_send_email( WP_User $user, string $url, int $ttl_minutes ) {
	$blog = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	/* translators: %s: site name */
	$subject = sprintf( __( 'Your login link for %s', 'logliy' ), $blog );
	$body    = sprintf(
		/* translators: 1: user display name, 2: site name, 3: magic URL, 4: minutes */
		__( "Hi %1\$s,\n\nUse this link to sign in to %2\$s:\n\n%3\$s\n\nThis link expires in %4\$d minutes and can be used once. If you did not request it, you can ignore this email.\n\n— Logliy", 'logliy' ),
		$user->display_name !== '' ? $user->display_name : $user->user_login,
		$blog,
		$url,
		$ttl_minutes
	);

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	$ok      = wp_mail( $user->user_email, $subject, $body, $headers );
	if ( ! $ok ) {
		return new WP_Error( 'logliy_magic_mail', __( 'Could not send the login email. Please contact the site admin.', 'logliy' ), array( 'status' => 500 ) );
	}
	return true;
}

/**
 * Consume magic link from login page.
 */
add_action( 'login_init', 'logliy_magic_link_consume', 5 );

/**
 * Consume magic link on the front (e.g. home?logliy_magic=).
 */
add_action( 'init', 'logliy_magic_link_consume_front', 20 );
function logliy_magic_link_consume_front(): void {
	if ( is_admin() ) {
		return;
	}
	// Avoid double-handling on wp-login (login_init already runs).
	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( false !== stripos( $script, 'wp-login.php' ) ) {
		return;
	}
	if ( defined( 'LOGLIY_CUSTOM_LOGIN' ) && LOGLIY_CUSTOM_LOGIN ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( empty( $_GET['logliy_magic'] ) ) {
		return;
	}
	logliy_magic_link_consume();
}

/**
 * Verify and login via magic token.
 */
function logliy_magic_link_consume(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( empty( $_REQUEST['logliy_magic'] ) ) {
		return;
	}
	$done = true;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$token = sanitize_text_field( wp_unslash( (string) $_REQUEST['logliy_magic'] ) );
	if ( $token === '' || ! logliy_get_setting( 'enable_magic_link', true ) ) {
		return;
	}

	$user = logliy_magic_link_verify( $token );
	if ( is_wp_error( $user ) ) {
		logliy_fire_login_failed( 'magic-link', $user );
		wp_safe_redirect( add_query_arg( 'logliy_magic_error', '1', wp_login_url() ) );
		exit;
	}

	$remember = logliy_auto_remember();
	$result   = logliy_complete_login( $user, $remember );
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg( 'logliy_magic_error', '1', wp_login_url() ) );
		exit;
	}
	wp_safe_redirect( $result['redirect'] );
	exit;
}

/** Verify current email policy and consume exactly one link before login. */
function logliy_magic_link_verify( string $token ) {
	$data = strlen( $token ) === 64 && ctype_xdigit( $token ) ? logliy_email_challenge_get( 'magic', 0, hash( 'sha256', $token ) ) : null;
	$user = $data ? get_user_by( 'id', (int) $data['user_id'] ) : false;
	if ( ! logliy_get_setting( 'enable_magic_link', true ) || ! $user instanceof WP_User
		|| ! logliy_email_login_allowed( $user ) || ! logliy_email_challenge_consume( $user, $data ) ) {
		return new WP_Error( 'logliy_magic_invalid', __( 'Magic link invalid or expired.', 'logliy' ), array( 'status' => 401 ) );
	}
	return $user;
}
