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
 * Constant-time OTP check. Accepts legacy hashes briefly (bcrypt / unbound HMAC).
 */
function logliy_otp_hash_equals( string $code, string $stored, int $user_id ): bool {
	if ( $stored === '' ) {
		return false;
	}
	// Legacy bcrypt/phpass from earlier releases (OTP TTL is minutes — rare).
	if ( str_starts_with( $stored, '$P$' ) || str_starts_with( $stored, '$2y$' ) || str_starts_with( $stored, '$wp$' ) ) {
		return wp_check_password( $code, $stored );
	}
	if ( hash_equals( $stored, logliy_otp_hash( $code, $user_id ) ) ) {
		return true;
	}
	// Legacy unbound HMAC from 0.0.4 (same TTL window).
	$legacy = hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
	return hash_equals( $stored, $legacy );
}

/**
 * Uniform public OTP request response (no account enumeration).
 *
 * @return array{ok:bool,message:string}
 */
function logliy_otp_public_request_response(): array {
	return array(
		'ok'      => true,
		'message' => __( 'If an account exists, a login code has been sent.', 'logliy' ),
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
		return new WP_Error( 'logliy_otp_sent', __( 'If an account exists, a login code has been sent.', 'logliy' ), array( 'status' => 200, 'soft' => true ) );
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
	$acc_lim = (int) logliy_get_setting( 'otp_rate_limit_account', 5 );
	$ip      = logliy_client_ip();

	// Shared cooldown for OTP + Magic Link (per IP) — blocks spam clicks.
	$cd_ip = logliy_email_request_cooldown( 'email_ip_' . $ip );
	if ( is_wp_error( $cd_ip ) ) {
		return $cd_ip;
	}

	$ip_check = logliy_rate_limit_hit( 'otp_ip_' . $ip, $ip_lim, $window );
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

	$cd_user = logliy_email_request_cooldown( 'email_user_' . $user->ID );
	if ( is_wp_error( $cd_user ) ) {
		// Same shape as unknown account — no enumeration via cooldown.
		return logliy_otp_public_request_response();
	}

	$acc_check = logliy_rate_limit_hit( 'otp_user_' . $user->ID, $acc_lim, $window );
	if ( is_wp_error( $acc_check ) ) {
		return logliy_otp_public_request_response();
	}

	$length = (int) logliy_get_setting( 'otp_length', 6 );
	$max    = ( 10 ** $length ) - 1;
	$code   = str_pad( (string) random_int( 0, $max ), $length, '0', STR_PAD_LEFT );
	$ttl    = (int) logliy_get_setting( 'otp_ttl_minutes', 10 ) * MINUTE_IN_SECONDS;

	$payload = array(
		'user_id' => (int) $user->ID,
		'hash'    => logliy_otp_hash( $code, (int) $user->ID ),
		'tries'   => 0,
		'ip'      => $ip,
	);
	set_transient( 'logliy_otp_' . $user->ID, $payload, $ttl );

	$sent = logliy_otp_send_email( $user, $code );
	if ( is_wp_error( $sent ) ) {
		delete_transient( 'logliy_otp_' . $user->ID );
		return logliy_otp_public_request_response();
	}

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
		return new WP_Error( 'logliy_otp_invalid', __( 'Invalid login code.', 'logliy' ), array( 'status' => 401 ) );
	}

	$payload = get_transient( 'logliy_otp_' . $user->ID );
	if ( ! is_array( $payload ) || empty( $payload['hash'] ) ) {
		logliy_fire_login_failed( $user->user_login, __( 'Login code expired. Please request a new one.', 'logliy' ) );
		return new WP_Error( 'logliy_otp_expired', __( 'Login code expired. Please request a new one.', 'logliy' ), array( 'status' => 401 ) );
	}

	$tries = (int) ( $payload['tries'] ?? 0 );
	if ( $tries >= 5 ) {
		delete_transient( 'logliy_otp_' . $user->ID );
		logliy_fire_login_failed( $user->user_login, __( 'Too many invalid code attempts.', 'logliy' ) );
		return new WP_Error( 'logliy_otp_locked', __( 'Too many invalid code attempts. Please request a new code.', 'logliy' ), array( 'status' => 429 ) );
	}

	if ( ! logliy_otp_hash_equals( $code, (string) $payload['hash'], (int) $user->ID ) ) {
		$payload['tries'] = $tries + 1;
		$ttl              = (int) logliy_get_setting( 'otp_ttl_minutes', 10 ) * MINUTE_IN_SECONDS;
		set_transient( 'logliy_otp_' . $user->ID, $payload, $ttl );
		logliy_fire_login_failed( $user->user_login, __( 'Invalid login code.', 'logliy' ) );
		return new WP_Error( 'logliy_otp_invalid', __( 'Invalid login code.', 'logliy' ), array( 'status' => 401 ) );
	}

	delete_transient( 'logliy_otp_' . $user->ID );

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
