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
	$acc_lim = (int) logliy_get_setting( 'otp_rate_limit_account', 5 );
	$ip_lim  = (int) logliy_get_setting( 'otp_rate_limit_ip', 20 );
	$ip      = logliy_client_ip();

	if ( trim( $login ) === '' ) {
		return new WP_Error( 'logliy_empty_login', logliy_login_identifier_empty_message(), array( 'status' => 400 ) );
	}

	$cd_ip = logliy_email_request_cooldown( 'email_ip_' . $ip );
	if ( is_wp_error( $cd_ip ) ) {
		return $cd_ip;
	}

	$ip_check = logliy_rate_limit_hit( 'magic_ip_' . $ip, $ip_lim, $window );
	if ( is_wp_error( $ip_check ) ) {
		return $ip_check;
	}

	$public = array(
		'ok'       => true,
		'message'  => __( 'If an account exists, a magic login link has been sent.', 'logliy' ),
		'cooldown' => (int) logliy_get_setting( 'email_request_cooldown_seconds', 60 ),
	);

	$user = logliy_resolve_login_user( $login );
	if ( ! $user instanceof WP_User ) {
		return $public;
	}

	$cd_user = logliy_email_request_cooldown( 'email_user_' . $user->ID );
	if ( is_wp_error( $cd_user ) ) {
		return $cd_user;
	}

	$acc_check = logliy_rate_limit_hit( 'magic_user_' . $user->ID, $acc_lim, $window );
	if ( is_wp_error( $acc_check ) ) {
		return $public;
	}

	$token   = bin2hex( random_bytes( 32 ) );
	$ttl_min = (int) logliy_get_setting( 'magic_link_ttl_minutes', 15 );
	$ttl     = max( 5, $ttl_min ) * MINUTE_IN_SECONDS;

	set_transient(
		'logliy_magic_' . hash( 'sha256', $token ),
		array(
			'user_id' => (int) $user->ID,
			'created' => time(),
		),
		$ttl
	);

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
		return $sent;
	}

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

	$key  = 'logliy_magic_' . hash( 'sha256', $token );
	$data = get_transient( $key );
	delete_transient( $key );

	if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
		logliy_fire_login_failed( 'magic-link', __( 'Magic link invalid or expired.', 'logliy' ) );
		wp_safe_redirect( add_query_arg( 'logliy_magic_error', '1', wp_login_url() ) );
		exit;
	}

	$user = get_user_by( 'id', (int) $data['user_id'] );
	if ( ! $user instanceof WP_User ) {
		wp_safe_redirect( add_query_arg( 'logliy_magic_error', '1', wp_login_url() ) );
		exit;
	}

	$remember = logliy_auto_remember();
	$result   = logliy_complete_login( $user, $remember );
	wp_safe_redirect( $result['redirect'] );
	exit;
}
