<?php
/**
 * REST API: namespace logliy/v1.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register routes.
 */
add_action( 'rest_api_init', 'logliy_register_rest_routes' );
function logliy_register_rest_routes(): void {
	$ns = 'logliy/v1';

	register_rest_route(
		$ns,
		'/otp/request',
		array(
			'methods'             => 'POST',
			'callback'            => 'logliy_rest_otp_request',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$ns,
		'/otp/verify',
		array(
			'methods'             => 'POST',
			'callback'            => 'logliy_rest_otp_verify',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$ns,
		'/magic/request',
		array(
			'methods'             => 'POST',
			'callback'            => 'logliy_rest_magic_request',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$ns,
		'/passkey/auth/options',
		array(
			'methods'             => 'POST',
			'callback'            => 'logliy_rest_passkey_auth_options',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$ns,
		'/passkey/auth/verify',
		array(
			'methods'             => 'POST',
			'callback'            => 'logliy_rest_passkey_auth_verify',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$ns,
		'/passkey/register/options',
		array(
			'methods'             => 'POST',
			'callback'            => 'logliy_rest_passkey_register_options',
			'permission_callback' => 'is_user_logged_in',
		)
	);

	register_rest_route(
		$ns,
		'/passkey/register/verify',
		array(
			'methods'             => 'POST',
			'callback'            => 'logliy_rest_passkey_register_verify',
			'permission_callback' => 'is_user_logged_in',
		)
	);

	register_rest_route(
		$ns,
		'/passkey/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'PATCH',
				'callback'            => 'logliy_rest_passkey_rename',
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => 'logliy_rest_passkey_delete',
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);

	register_rest_route(
		$ns,
		'/config',
		array(
			'methods'             => 'GET',
			'callback'            => 'logliy_rest_config',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Convert WP_Error to REST response.
 *
 * @param mixed $result Result or WP_Error.
 * @return WP_REST_Response|WP_Error
 */
function logliy_rest_result( $result ) {
	if ( is_wp_error( $result ) ) {
		$data   = $result->get_error_data();
		$status = 400;
		$extra  = array();
		if ( is_array( $data ) ) {
			if ( isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}
			if ( isset( $data['retry_after'] ) ) {
				$extra['retry_after'] = (int) $data['retry_after'];
			}
		}
		return new WP_Error(
			$result->get_error_code(),
			$result->get_error_message(),
			array_merge( array( 'status' => $status ), $extra )
		);
	}
	return rest_ensure_response( $result );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_config( WP_REST_Request $request ) {
	unset( $request );
	$password_ok = logliy_password_login_globally_allowed() || logliy_password_emergency_allowed();
	return rest_ensure_response(
		array(
			'enable_passkey'       => (bool) logliy_get_setting( 'enable_passkey', true ),
			'enable_email_otp'     => (bool) logliy_get_setting( 'enable_email_otp', true ),
			'enable_magic_link'    => (bool) logliy_get_setting( 'enable_magic_link', true ),
			'allow_password_login' => $password_ok,
			'rp_id'                => logliy_rp_id(),
			'https'                => logliy_is_https() || logliy_rp_id() === 'localhost',
			'turnstile_required'   => logliy_turnstile_required(),
		)
	);
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_otp_request( WP_REST_Request $request ) {
	$captcha = logliy_verify_turnstile( logliy_turnstile_token_from_request( $request ) );
	if ( is_wp_error( $captcha ) ) {
		return logliy_rest_result( $captcha );
	}
	$login = sanitize_text_field( (string) $request->get_param( 'login' ) );
	return logliy_rest_result( logliy_otp_request( $login ) );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_otp_verify( WP_REST_Request $request ) {
	$captcha = logliy_verify_turnstile( logliy_turnstile_token_from_request( $request ) );
	if ( is_wp_error( $captcha ) ) {
		return logliy_rest_result( $captcha );
	}
	$login       = sanitize_text_field( (string) $request->get_param( 'login' ) );
	$code        = sanitize_text_field( (string) $request->get_param( 'code' ) );
	$remember    = (bool) $request->get_param( 'remember' );
	$redirect_to = esc_url_raw( (string) $request->get_param( 'redirect_to' ) );
	return logliy_rest_result( logliy_otp_verify( $login, $code, $remember, $redirect_to ) );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_magic_request( WP_REST_Request $request ) {
	$captcha = logliy_verify_turnstile( logliy_turnstile_token_from_request( $request ) );
	if ( is_wp_error( $captcha ) ) {
		return logliy_rest_result( $captcha );
	}
	$login = sanitize_text_field( (string) $request->get_param( 'login' ) );
	if ( $request->get_param( 'redirect_to' ) ) {
		$_REQUEST['redirect_to'] = esc_url_raw( (string) $request->get_param( 'redirect_to' ) );
	}
	return logliy_rest_result( logliy_magic_link_request( $login ) );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_passkey_auth_options( WP_REST_Request $request ) {
	$login = sanitize_text_field( (string) $request->get_param( 'login' ) );
	$user  = null;
	if ( $login !== '' ) {
		$user = logliy_resolve_login_user( $login );
	}
	return logliy_rest_result( logliy_passkey_auth_options( $user instanceof WP_User ? $user : null ) );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_passkey_auth_verify( WP_REST_Request $request ) {
	$captcha = logliy_verify_turnstile( logliy_turnstile_token_from_request( $request ) );
	if ( is_wp_error( $captcha ) ) {
		return logliy_rest_result( $captcha );
	}
	$challenge_id = sanitize_text_field( (string) $request->get_param( 'challenge_id' ) );
	$credential   = $request->get_param( 'credential' );
	$remember     = (bool) $request->get_param( 'remember' );
	$redirect_to  = esc_url_raw( (string) $request->get_param( 'redirect_to' ) );
	if ( ! is_array( $credential ) ) {
		return new WP_Error( 'logliy_bad_credential', __( 'Invalid Passkey response.', 'logliy' ), array( 'status' => 400 ) );
	}
	return logliy_rest_result( logliy_passkey_auth_verify( $challenge_id, $credential, $remember, $redirect_to ) );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_passkey_register_options( WP_REST_Request $request ) {
	unset( $request );
	$user = wp_get_current_user();
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'logliy_auth', __( 'You must be logged in.', 'logliy' ), array( 'status' => 401 ) );
	}
	return logliy_rest_result( logliy_passkey_register_options( $user ) );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_passkey_register_verify( WP_REST_Request $request ) {
	$user = wp_get_current_user();
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'logliy_auth', __( 'You must be logged in.', 'logliy' ), array( 'status' => 401 ) );
	}
	$challenge_id = sanitize_text_field( (string) $request->get_param( 'challenge_id' ) );
	$credential   = $request->get_param( 'credential' );
	$name         = sanitize_text_field( (string) $request->get_param( 'name' ) );
	if ( ! is_array( $credential ) ) {
		return new WP_Error( 'logliy_bad_credential', __( 'Invalid Passkey response.', 'logliy' ), array( 'status' => 400 ) );
	}
	$result = logliy_passkey_register_verify( $user, $challenge_id, $credential, $name );
	if ( ! is_wp_error( $result ) ) {
		delete_user_meta( (int) $user->ID, 'logliy_dismiss_passkey_nag' );
	}
	return logliy_rest_result( $result );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_passkey_rename( WP_REST_Request $request ) {
	$user = wp_get_current_user();
	$id   = (int) $request->get_param( 'id' );
	$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
	$name = mb_substr( $name, 0, 191 );
	if ( $name === '' ) {
		return new WP_Error( 'logliy_name_required', __( 'Name is required.', 'logliy' ), array( 'status' => 400 ) );
	}
	$ok = logliy_db_rename_credential( $id, (int) $user->ID, $name );
	if ( ! $ok ) {
		return new WP_Error( 'logliy_not_found', __( 'Passkey not found.', 'logliy' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'ok' => true, 'name' => $name ) );
}

/**
 * @param WP_REST_Request $request Request.
 */
function logliy_rest_passkey_delete( WP_REST_Request $request ) {
	$user = wp_get_current_user();
	$id   = (int) $request->get_param( 'id' );
	$ok   = logliy_db_delete_credential( $id, (int) $user->ID );
	if ( ! $ok ) {
		return new WP_Error( 'logliy_not_found', __( 'Passkey not found.', 'logliy' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'ok' => true ) );
}
