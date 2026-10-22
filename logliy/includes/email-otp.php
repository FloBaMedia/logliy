<?php
/**
 * Email OTP authentication.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * HMAC hash for a short-lived OTP (cheaper than bcrypt; attempt-capped).
 * Bound to user ID so the same code does not hash identically across accounts.
 */
function logliy_otp_hash( string $code, int $user_id ): string {
	return hash_hmac( 'sha256', $user_id . '|' . $code, wp_salt( 'auth' ) );
}

/**
 * Uniform public OTP request response (no account enumeration).
 *
 * @return array{ok:bool,message:string}
 */
function logliy_otp_public_request_response(): array {
	return array(
		'cooldown' => (int) logliy_get_setting( 'email_request_cooldown_seconds', 60 ),
		'ok'      => true,
		'message' => __( 'If this address is eligible for login, a login code has been sent.', 'logliy' ),
	);
}

/**
 * Resolve WP_User from email or username.
 *
 * @return WP_User|WP_Error
 */
function logliy_otp_resolve_user( string $login ) {
	$login = trim( $login );
	if ( $login === '' ) {
		return new WP_Error( 'logliy_empty_login', logliy_login_identifier_empty_message(), array( 'status' => 400 ) );
	}
	$user = logliy_resolve_login_user( $login );
	if ( ! $user instanceof WP_User ) {
		// Soft miss — same shape as a successful send.
		return new WP_Error( 'logliy_otp_sent', __( 'If this address is eligible for login, a login code has been sent.', 'logliy' ), array( 'status' => 200, 'soft' => true ) );
	}
	return $user;
}

/**
 * Request an OTP email.
 *
 * @return array<string, mixed>|WP_Error
 */
function logliy_otp_request( string $login ) {
	if ( ! logliy_get_setting( 'enable_email_otp', true ) ) {
		return new WP_Error( 'logliy_otp_disabled', __( 'Email code login is disabled.', 'logliy' ), array( 'status' => 403 ) );
	}

	if ( trim( $login ) === '' ) {
		return new WP_Error( 'logliy_empty_login', logliy_login_identifier_empty_message(), array( 'status' => 400 ) );
	}

	$window  = (int) logliy_get_setting( 'otp_rate_window_minutes', 15 ) * MINUTE_IN_SECONDS;
	$ip_lim  = (int) logliy_get_setting( 'otp_rate_limit_ip', 20 );
	$ip      = logliy_client_ip();

	// Shared cooldown for OTP + Magic Link (per IP) — blocks spam clicks.
	$cd_ip = logliy_email_request_cooldown( 'email_ip_' . $ip );
	if ( is_wp_error( $cd_ip ) ) {
		return $cd_ip;
	}

	$ip_check = logliy_rate_limit_hit( 'email_ip_' . $ip, $ip_lim, $window );
	if ( is_wp_error( $ip_check ) ) {
		return $ip_check;
	}

	$user = logliy_otp_resolve_user( $login );
	if ( is_wp_error( $user ) ) {
		$data = $user->get_error_data();
		if ( is_array( $data ) && ! empty( $data['soft'] ) ) {
			return logliy_otp_public_request_response();
		}
		return $user;
	}

	if ( ! logliy_email_login_allowed( $user ) ) {
		logliy_security_event( 'email_denied', (int) $user->ID );
		return logliy_otp_public_request_response();
	}
	if ( is_wp_error( logliy_email_account_limit( $user ) ) ) {
		return logliy_otp_public_request_response();
	}

	$length = (int) logliy_get_setting( 'otp_length', 6 );
	$max    = ( 10 ** $length ) - 1;
	$code   = str_pad( (string) random_int( 0, $max ), $length, '0', STR_PAD_LEFT );
	$ttl    = (int) logliy_get_setting( 'otp_ttl_minutes', 10 ) * MINUTE_IN_SECONDS;

	$hash = logliy_otp_hash( $code, (int) $user->ID );
	if ( ! logliy_email_challenge_store( $user, 'otp', $hash, $ttl ) ) {
		return logliy_otp_public_request_response();
	}

	$sent = logliy_otp_send_email( $user, $code );
	if ( is_wp_error( $sent ) ) {
		logliy_email_challenge_delete( (int) $user->ID, 'otp', $hash );
		return logliy_otp_public_request_response();
	}

	logliy_security_event( 'email_code_sent', (int) $user->ID );
	$cooldown = (int) logliy_get_setting( 'email_request_cooldown_seconds', 60 );
	$resp     = logliy_otp_public_request_response();
	$resp['cooldown'] = $cooldown;
	return $resp;
}

/**
 * Send branded OTP email.
 *
 * @return true|WP_Error
 */
function logliy_otp_send_email( WP_User $user, string $code ) {
	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$subject = sprintf(
		/* translators: %s: site name */
		__( 'Your login code for %s', 'logliy' ),
		$site
	);

	$ttl = (int) logliy_get_setting( 'otp_ttl_minutes', 10 );
	$body = sprintf(
		/* translators: 1: user display name, 2: site name, 3: OTP code, 4: TTL minutes */
		__( "Hi %1\$s,\n\nYour login code for %2\$s is:\n\n%3\$s\n\nThis code expires in %4\$d minutes. If you did not request it, you can ignore this email.\n\n— Logliy", 'logliy' ),
		$user->display_name !== '' ? $user->display_name : $user->user_login,
		$site,
		$code,
		$ttl
	);

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	$ok      = wp_mail( $user->user_email, $subject, $body, $headers );
	if ( ! $ok ) {
		return new WP_Error( 'logliy_mail_failed', __( 'Could not send the login email. Please contact the site admin.', 'logliy' ), array( 'status' => 500 ) );
	}
	return true;
}

/**
 * Verify OTP and log the user in.
 *
 * @return array<string, mixed>|WP_Error
 */
function logliy_otp_verify( string $login, string $code, bool $remember = false, string $redirect_to = '' ) {
	if ( ! logliy_get_setting( 'enable_email_otp', true ) ) {
		return new WP_Error( 'logliy_otp_disabled', __( 'Email code login is disabled.', 'logliy' ), array( 'status' => 403 ) );
	}

	$window = (int) logliy_get_setting( 'otp_rate_window_minutes', 15 ) * MINUTE_IN_SECONDS;
	$ip_lim = max( (int) logliy_get_setting( 'otp_rate_limit_ip', 20 ) * 2, 40 );
	$ip     = logliy_client_ip();

	$ip_check = logliy_rate_limit_hit( 'otp_verify_ip_' . $ip, $ip_lim, $window );
	if ( is_wp_error( $ip_check ) ) {
		return $ip_check;
	}

	$code = preg_replace( '/\s+/', '', $code ) ?? '';
	if ( $code === '' ) {
		return new WP_Error( 'logliy_otp_empty', __( 'Please enter the code from your email.', 'logliy' ), array( 'status' => 400 ) );
	}

	$user = logliy_resolve_login_user( $login );

	if ( ! $user instanceof WP_User ) {
		logliy_fire_login_failed( $login, __( 'Invalid login code.', 'logliy' ) );
		return new WP_Error( 'logliy_otp_invalid', __( 'Login code invalid or expired. Please request a new one.', 'logliy' ), array( 'status' => 401 ) );
	}

	$payload = logliy_email_challenge_get( 'otp', (int) $user->ID );
	if ( ! logliy_email_login_allowed( $user ) || ! $payload || ! logliy_email_challenge_attempt( $user, $payload ) ) {
		logliy_fire_login_failed( $user->user_login );
		return new WP_Error( 'logliy_otp_invalid', __( 'Login code invalid or expired. Please request a new one.', 'logliy' ), array( 'status' => 401 ) );
	}
	if ( ! hash_equals( (string) $payload['token_hash'], logliy_otp_hash( $code, (int) $user->ID ) )
		|| ! logliy_email_challenge_consume( $user, $payload ) ) {
		logliy_fire_login_failed( $user->user_login );
		return new WP_Error( 'logliy_otp_invalid', __( 'Login code invalid or expired. Please request a new one.', 'logliy' ), array( 'status' => 401 ) );
	}

	if ( $redirect_to !== '' ) {
		$_REQUEST['redirect_to'] = $redirect_to;
	}

	$result = logliy_complete_login( $user, $remember );
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( empty( $data['status'] ) ) {
			$data['status'] = 403;
		}
		$result->add_data( $data );
		return $result;
	}
	if ( has_filter( 'woocommerce_login_redirect' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core redirect filter.
		$result['redirect'] = (string) apply_filters( 'woocommerce_login_redirect', $result['redirect'], $user );
		$result['redirect'] = logliy_safe_redirect_url( $result['redirect'] );
	}

	return array(
		'ok'       => true,
		'redirect' => $result['redirect'],
	);
}
