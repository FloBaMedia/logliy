<?php
/**
 * Cloudflare Turnstile / CAPTCHA compatibility for passwordless REST login.
 *
 * Simple Cloudflare Turnstile skips REST_REQUEST on the authenticate filter,
 * so Passkey/OTP must verify the token explicitly.
 *
 * Captcha is only enforced when Turnstile is enabled for WP login *and*
 * actually configured (keys / plugin helpers available). Sites without
 * Captcha are never blocked.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether Turnstile keys / helpers are available for verification.
 *
 * Orphaned Simple Cloudflare Turnstile options left in the DB after uninstall
 * must NOT count as configured — only an active plugin helper or explicit
 * wp-config constants qualify.
 */
function logliy_turnstile_is_configured(): bool {
	// Plugin active: options or constants are fine.
	if ( function_exists( 'cfturnstile_check' ) ) {
		return logliy_turnstile_secret() !== '' || logliy_turnstile_site_key() !== '';
	}

	// Explicit wp-config only — leftover get_option() values are ignored.
	$has_secret = defined( 'CF_TURNSTILE_SECRET_KEY' ) && (string) CF_TURNSTILE_SECRET_KEY !== '';
	$has_site   = defined( 'CF_TURNSTILE_SITE_KEY' ) && (string) CF_TURNSTILE_SITE_KEY !== '';

	return $has_secret && $has_site;
}

/**
 * Whether a Cloudflare Turnstile login challenge is expected.
 *
 * Requires the Simple Cloudflare Turnstile "WP Login" option *and* a
 * configured Turnstile setup. A leftover option after uninstalling the
 * Captcha plugin must not block Logliy logins.
 */
function logliy_turnstile_required(): bool {
	$required = (bool) get_option( 'cfturnstile_login' ) && logliy_turnstile_is_configured();

	/**
	 * Filter whether Logliy must verify a Turnstile token on passwordless login.
	 *
	 * @param bool $required Default: Turnstile WP Login option + configured keys.
	 */
	return (bool) apply_filters( 'logliy_turnstile_required', $required );
}

/**
 * Turnstile site key (best-effort from common sources).
 */
function logliy_turnstile_site_key(): string {
	if ( defined( 'CF_TURNSTILE_SITE_KEY' ) && CF_TURNSTILE_SITE_KEY ) {
		return (string) CF_TURNSTILE_SITE_KEY;
	}
	return (string) get_option( 'cfturnstile_key', '' );
}

/**
 * Turnstile secret key (best-effort).
 */
function logliy_turnstile_secret(): string {
	if ( defined( 'CF_TURNSTILE_SECRET_KEY' ) && CF_TURNSTILE_SECRET_KEY ) {
		return (string) CF_TURNSTILE_SECRET_KEY;
	}
	return (string) get_option( 'cfturnstile_secret', '' );
}

/**
 * Extract Turnstile token from a REST request or POST.
 *
 * @param WP_REST_Request|null $request Request.
 */
function logliy_turnstile_token_from_request( $request = null ): string {
	$token = '';
	if ( $request instanceof WP_REST_Request ) {
		$token = (string) $request->get_param( 'cf_turnstile_response' );
		if ( $token === '' ) {
			$token = (string) $request->get_param( 'cf-turnstile-response' );
		}
	}
	if ( $token === '' && isset( $_POST['cf-turnstile-response'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token = sanitize_text_field( wp_unslash( (string) $_POST['cf-turnstile-response'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
	return sanitize_text_field( $token );
}

/**
 * Low-level siteverify against Cloudflare.
 *
 * @return true|WP_Error
 */
function logliy_turnstile_siteverify( string $token ) {
	if ( function_exists( 'cfturnstile_check' ) ) {
		$check = cfturnstile_check( $token );
		if ( is_array( $check ) && ! empty( $check['success'] ) ) {
			return true;
		}
		$message = function_exists( 'cfturnstile_failed_message' )
			? (string) cfturnstile_failed_message()
			: __( 'CAPTCHA verification failed. Please try again.', 'logliy' );
		return new WP_Error( 'logliy_captcha_failed', wp_strip_all_tags( $message ), array( 'status' => 403 ) );
	}

	$secret = logliy_turnstile_secret();
	if ( $secret === '' ) {
		return new WP_Error(
			'logliy_captcha_unconfigured',
			__( 'CAPTCHA is required but could not be verified. Please contact the site admin.', 'logliy' ),
			array( 'status' => 403 )
		);
	}

	$response = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => logliy_client_ip(),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'logliy_captcha_unreachable',
			__( 'CAPTCHA verification failed. Please try again.', 'logliy' ),
			array( 'status' => 403 )
		);
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( is_array( $body ) && ! empty( $body['success'] ) ) {
		return true;
	}

	return new WP_Error(
		'logliy_captcha_failed',
		__( 'CAPTCHA verification failed. Please try again.', 'logliy' ),
		array( 'status' => 403 )
	);
}

/**
 * Verify Turnstile token. Returns true|WP_Error.
 *
 * - No Captcha configured → always allow.
 * - Token present + verifiable → always verify (reject forged tokens).
 * - Captcha required and no token → fail.
 *
 * @param string $token Token from the browser widget.
 * @return true|WP_Error
 */
function logliy_verify_turnstile( string $token ) {
	$required   = logliy_turnstile_required();
	$can_verify = logliy_turnstile_secret() !== '' || function_exists( 'cfturnstile_check' );

	// Token submitted: always verify when possible (do not accept junk tokens).
	if ( $token !== '' && $can_verify ) {
		return logliy_turnstile_siteverify( $token );
	}

	if ( ! $required ) {
		return true;
	}

	if ( $token === '' ) {
		return new WP_Error(
			'logliy_captcha_required',
			__( 'Please complete the CAPTCHA challenge and try again.', 'logliy' ),
			array( 'status' => 403 )
		);
	}

	// Required but somehow not verifiable — do not lock users out.
	return true;
}
