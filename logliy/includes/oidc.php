<?php
/**
 * OpenID Connect (authorization code + PKCE) login.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether SSO is fully configured and may appear on the login form.
 */
function logliy_oidc_is_ready(): bool {
	if ( ! logliy_get_setting( 'enable_oidc', false ) ) {
		return false;
	}
	$issuer = (string) logliy_get_setting( 'oidc_issuer', '' );
	$id     = (string) logliy_get_setting( 'oidc_client_id', '' );
	$secret = (string) logliy_get_setting( 'oidc_client_secret', '' );
	return $issuer !== '' && $id !== '' && $secret !== '';
}

/**
 * Redirect URI registered at the identity provider.
 */
function logliy_oidc_redirect_uri(): string {
	return add_query_arg( 'logliy_oidc_cb', '1', wp_login_url() );
}

/**
 * Label for the SSO button.
 */
function logliy_oidc_button_label(): string {
	$label = trim( (string) logliy_get_setting( 'oidc_button_label', '' ) );
	if ( $label === '' ) {
		return __( 'Continue with SSO', 'logliy' );
	}
	return $label;
}

/**
 * Start URL from the login panel (always the WordPress login URL).
 *
 * @param string $context Panel context (wp-login|woocommerce|checkout|…).
 */
function logliy_oidc_start_url( string $context = 'wp-login' ): string {
	$redirect = '';
	if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect = esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
	if ( $redirect === '' && $context === 'checkout' && function_exists( 'wc_get_checkout_url' ) ) {
		$redirect = (string) wc_get_checkout_url();
	}
	if ( $redirect === '' && $context === 'woocommerce' && function_exists( 'wc_get_page_permalink' ) ) {
		$account = wc_get_page_permalink( 'myaccount' );
		$redirect = is_string( $account ) ? $account : '';
	}

	$args = array( 'logliy_oidc' => '1' );
	if ( logliy_auto_remember() ) {
		$args['remember'] = '1';
	}

	$url = $redirect !== '' ? wp_login_url( $redirect ) : wp_login_url();
	return add_query_arg( $args, $url );
}

/**
 * Handle OIDC start + callback on the login screen (incl. custom login slug).
 */
add_action( 'login_init', 'logliy_oidc_login_init', 4 );
function logliy_oidc_login_init(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $_REQUEST['logliy_oidc_cb'] ) ) {
		logliy_oidc_callback();
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $_REQUEST['logliy_oidc'] ) ) {
		logliy_oidc_start();
	}
}

/**
 * Redirect to the identity provider.
 */
function logliy_oidc_start(): void {
	if ( is_user_logged_in() ) {
		$redirect = admin_url();
		if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $requested !== '' ) {
				$redirect = logliy_safe_redirect_url( $requested );
			}
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	if ( ! logliy_oidc_is_ready() ) {
		logliy_oidc_fail( 'config' );
	}

	$token = logliy_turnstile_token_from_request();
	$captcha = logliy_verify_turnstile( $token );
	if ( is_wp_error( $captcha ) ) {
		logliy_oidc_fail( 'captcha' );
	}

	$window = (int) logliy_get_setting( 'otp_rate_window_minutes', 15 ) * MINUTE_IN_SECONDS;
	$ip_lim = (int) logliy_get_setting( 'otp_rate_limit_ip', 20 );
	$hit    = logliy_rate_limit_hit( 'oidc_ip_' . logliy_client_ip(), max( 5, $ip_lim ), $window );
	if ( is_wp_error( $hit ) ) {
		logliy_oidc_fail( 'rate' );
	}

	$discovery = logliy_oidc_discovery();
	if ( is_wp_error( $discovery ) ) {
		logliy_oidc_fail( 'provider' );
	}

	$auth = (string) ( $discovery['authorization_endpoint'] ?? '' );
	if ( ! logliy_oidc_url_allowed( $auth ) ) {
		logliy_oidc_fail( 'provider' );
	}

	$state    = logliy_oidc_b64url_encode( random_bytes( 24 ) );
	$nonce    = logliy_oidc_b64url_encode( random_bytes( 24 ) );
	$verifier = logliy_oidc_b64url_encode( random_bytes( 32 ) );
	$challenge = logliy_oidc_b64url_encode( hash( 'sha256', $verifier, true ) );

	$redirect_to = '';
	if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_to = esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	set_transient(
		logliy_oidc_state_key( $state ),
		array(
			'nonce'        => $nonce,
			'verifier'     => $verifier,
			'remember'     => ! empty( $_REQUEST['remember'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'redirect_to'  => $redirect_to,
			'redirect_uri' => logliy_oidc_redirect_uri(),
			'created'      => time(),
		),
		10 * MINUTE_IN_SECONDS
	);

	$url = add_query_arg(
		array(
			'response_type'         => 'code',
			'client_id'             => (string) logliy_get_setting( 'oidc_client_id', '' ),
			'redirect_uri'          => logliy_oidc_redirect_uri(),
			'scope'                 => 'openid email profile',
			'state'                 => $state,
			'nonce'                 => $nonce,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		),
		$auth
	);

	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External IdP from discovery.
	exit;
}

/**
 * Handle the identity-provider callback.
 */
function logliy_oidc_callback(): void {
	if ( is_user_logged_in() ) {
		wp_safe_redirect( admin_url() );
		exit;
	}

	if ( ! logliy_oidc_is_ready() ) {
		logliy_oidc_fail( 'config' );
	}

	// OAuth error is query-only. Ignore when an authorization code is present
	// (success response). Never use $_REQUEST — WordPress login uses `error`,
	// and cookies can leak into REQUEST on some PHP setups.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$oauth_code  = isset( $_GET['code'] ) ? (string) wp_unslash( $_GET['code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$oauth_error = isset( $_GET['error'] ) ? sanitize_key( (string) wp_unslash( $_GET['error'] ) ) : '';
	if ( $oauth_error !== '' && $oauth_code === '' ) {
		logliy_oidc_fail( 'denied' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$state = isset( $_REQUEST['state'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['state'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$code = isset( $_REQUEST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['code'] ) ) : '';
	if ( $state === '' || $code === '' || strlen( $state ) > 256 || strlen( $code ) > 2048 ) {
		logliy_oidc_fail( 'state' );
	}

	$key  = logliy_oidc_state_key( $state );
	$data = get_transient( $key );
	delete_transient( $key );
	if ( ! is_array( $data ) || empty( $data['verifier'] ) || empty( $data['nonce'] ) ) {
		logliy_oidc_fail( 'state' );
	}

	$tokens = logliy_oidc_exchange_code( $code, (string) $data['verifier'], (string) ( $data['redirect_uri'] ?? logliy_oidc_redirect_uri() ) );
	if ( is_wp_error( $tokens ) ) {
		logliy_oidc_fail( 'token' );
	}

	$id_token = (string) ( $tokens['id_token'] ?? '' );
	$claims   = logliy_oidc_verify_id_token( $id_token, (string) $data['nonce'] );
	if ( is_wp_error( $claims ) ) {
		logliy_oidc_fail( 'claims' );
	}

	$email = logliy_oidc_email_from_claims( $claims );
	if ( $email === '' && ! empty( $tokens['access_token'] ) ) {
		$email = logliy_oidc_email_from_userinfo( (string) $tokens['access_token'], (string) $claims['sub'] );
	}
	if ( $email === '' ) {
		logliy_oidc_fail( 'claims' );
	}
	if ( isset( $claims['email_verified'] ) && $claims['email_verified'] !== true && $claims['email_verified'] !== 'true' && $claims['email_verified'] !== 1 ) {
		logliy_oidc_fail( 'claims' );
	}

	$user = logliy_oidc_resolve_user( $claims, $email );
	if ( is_wp_error( $user ) ) {
		logliy_oidc_fail( 'nouser' );
	}

	if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_REQUEST['redirect_to'] );
	}
	if ( ! empty( $data['redirect_to'] ) ) {
		$_REQUEST['redirect_to'] = (string) $data['redirect_to'];
	}

	$remember = ! empty( $data['remember'] );
	$result   = logliy_complete_login( $user, $remember );
	if ( is_wp_error( $result ) ) {
		logliy_oidc_fail( 'auth' );
	}

	wp_safe_redirect( $result['redirect'] );
	exit;
}

/**
 * Redirect back to login with a generic error code.
 */
function logliy_oidc_fail( string $code ): void {
	wp_safe_redirect( add_query_arg( 'logliy_oidc_error', sanitize_key( $code ), wp_login_url() ) );
	exit;
}

/**
 * Human-readable SSO error for the login footer.
 */
function logliy_oidc_error_message( string $code ): string {
	switch ( $code ) {
		case 'rate':
			return __( 'Too many SSO attempts. Please wait and try again.', 'logliy' );
		case 'captcha':
			return __( 'Please complete the CAPTCHA challenge and try again.', 'logliy' );
		case 'denied':
			return __( 'SSO sign-in was cancelled or denied.', 'logliy' );
		case 'auth':
			return __( 'SSO sign-in could not be completed. If this keeps happening, wait a few minutes in case a security plugin locked the IP.', 'logliy' );
		case 'nouser':
			return __( 'No matching WordPress account was found for this SSO identity.', 'logliy' );
		default:
			return __( 'SSO sign-in failed. Please try again.', 'logliy' );
	}
}

/**
 * Transient key for an OIDC state value.
 */
function logliy_oidc_state_key( string $state ): string {
	return 'logliy_oidc_' . hash( 'sha256', $state );
}

/**
 * Fetch and cache OpenID Provider metadata.
 *
 * @return array<string, mixed>|WP_Error
 */
function logliy_oidc_discovery() {
	$issuer = (string) logliy_get_setting( 'oidc_issuer', '' );
	if ( $issuer === '' || ! logliy_oidc_url_allowed( $issuer ) ) {
		return new WP_Error( 'logliy_oidc_issuer', 'Invalid issuer.' );
	}

	$cache_key = 'logliy_oidc_disc_' . md5( $issuer );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && ! empty( $cached['authorization_endpoint'] ) ) {
		return $cached;
	}

	$url  = untrailingslashit( $issuer ) . '/.well-known/openid-configuration';
	$body = logliy_oidc_http_json( $url, 'GET' );
	if ( is_wp_error( $body ) ) {
		return $body;
	}

	$doc_iss = isset( $body['issuer'] ) ? untrailingslashit( (string) $body['issuer'] ) : '';
	if ( $doc_iss !== untrailingslashit( $issuer ) ) {
		return new WP_Error( 'logliy_oidc_iss_mismatch', 'Issuer mismatch in discovery document.' );
	}
	if ( empty( $body['authorization_endpoint'] ) || empty( $body['token_endpoint'] ) || empty( $body['jwks_uri'] ) ) {
		return new WP_Error( 'logliy_oidc_discovery', 'Incomplete OpenID configuration.' );
	}

	set_transient( $cache_key, $body, HOUR_IN_SECONDS );
	return $body;
}

/**
 * Exchange the authorization code for tokens.
 *
 * @return array<string, mixed>|WP_Error
 */
function logliy_oidc_exchange_code( string $code, string $verifier, string $redirect_uri ) {
	$discovery = logliy_oidc_discovery();
	if ( is_wp_error( $discovery ) ) {
		return $discovery;
	}

	$token_url = (string) ( $discovery['token_endpoint'] ?? '' );
	if ( ! logliy_oidc_url_allowed( $token_url ) ) {
		return new WP_Error( 'logliy_oidc_token_url', 'Invalid token endpoint.' );
	}

	$response = wp_remote_post(
		$token_url,
		array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'      => array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $redirect_uri,
				'client_id'     => (string) logliy_get_setting( 'oidc_client_id', '' ),
				'client_secret' => (string) logliy_get_setting( 'oidc_client_secret', '' ),
				'code_verifier' => $verifier,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code_http = (int) wp_remote_retrieve_response_code( $response );
	$decoded   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( $code_http < 200 || $code_http >= 300 || ! is_array( $decoded ) || empty( $decoded['id_token'] ) ) {
		return new WP_Error( 'logliy_oidc_token', 'Token exchange failed.' );
	}

	return $decoded;
}

/**
 * Verify ID token signature and standard claims.
 *
 * @return array<string, mixed>|WP_Error
 */
function logliy_oidc_verify_id_token( string $jwt, string $nonce ) {
	$parts = explode( '.', $jwt );
	if ( count( $parts ) !== 3 ) {
		return new WP_Error( 'logliy_oidc_jwt', 'Malformed ID token.' );
	}

	$header_json = logliy_oidc_b64url_decode( $parts[0] );
	$payload_json = logliy_oidc_b64url_decode( $parts[1] );
	$sig          = logliy_oidc_b64url_decode( $parts[2] );
	$header       = json_decode( $header_json, true );
	$claims       = json_decode( $payload_json, true );
	if ( ! is_array( $header ) || ! is_array( $claims ) || $sig === '' ) {
		return new WP_Error( 'logliy_oidc_jwt', 'Malformed ID token.' );
	}

	$alg = (string) ( $header['alg'] ?? '' );
	if ( $alg !== 'RS256' ) {
		return new WP_Error( 'logliy_oidc_alg', 'Unsupported ID token algorithm.' );
	}

	$pem = logliy_oidc_signing_pem( $header );
	if ( is_wp_error( $pem ) || $pem === '' ) {
		return is_wp_error( $pem ) ? $pem : new WP_Error( 'logliy_oidc_kid', 'No matching signing key.' );
	}

	$signed = $parts[0] . '.' . $parts[1];
	$ok     = openssl_verify( $signed, $sig, $pem, OPENSSL_ALGO_SHA256 );
	if ( $ok !== 1 ) {
		return new WP_Error( 'logliy_oidc_sig', 'ID token signature is invalid.' );
	}

	$issuer    = untrailingslashit( (string) logliy_get_setting( 'oidc_issuer', '' ) );
	$client_id = (string) logliy_get_setting( 'oidc_client_id', '' );
	$iss       = isset( $claims['iss'] ) ? untrailingslashit( (string) $claims['iss'] ) : '';
	if ( $iss === '' || ! hash_equals( $issuer, $iss ) ) {
		return new WP_Error( 'logliy_oidc_iss', 'ID token issuer mismatch.' );
	}

	$aud = $claims['aud'] ?? '';
	$aud_ok = false;
	if ( is_string( $aud ) ) {
		$aud_ok = hash_equals( $client_id, $aud );
	} elseif ( is_array( $aud ) ) {
		foreach ( $aud as $audience ) {
			if ( is_string( $audience ) && hash_equals( $client_id, $audience ) ) {
				$aud_ok = true;
				break;
			}
		}
		if ( $aud_ok && isset( $claims['azp'] ) && is_string( $claims['azp'] ) && $claims['azp'] !== '' && ! hash_equals( $client_id, $claims['azp'] ) ) {
			$aud_ok = false;
		}
	}
	if ( ! $aud_ok ) {
		return new WP_Error( 'logliy_oidc_aud', 'ID token audience mismatch.' );
	}

	$now = time();
	$skew = 60;
	if ( empty( $claims['exp'] ) || (int) $claims['exp'] + $skew < $now ) {
		return new WP_Error( 'logliy_oidc_exp', 'ID token expired.' );
	}
	if ( isset( $claims['nbf'] ) && (int) $claims['nbf'] - $skew > $now ) {
		return new WP_Error( 'logliy_oidc_nbf', 'ID token is not yet valid.' );
	}
	if ( isset( $claims['iat'] ) && (int) $claims['iat'] - $skew > $now ) {
		return new WP_Error( 'logliy_oidc_iat', 'ID token issued in the future.' );
	}

	$token_nonce = (string) ( $claims['nonce'] ?? '' );
	if ( $token_nonce === '' || ! hash_equals( $nonce, $token_nonce ) ) {
		return new WP_Error( 'logliy_oidc_nonce', 'ID token nonce mismatch.' );
	}

	if ( empty( $claims['sub'] ) || ! is_string( $claims['sub'] ) ) {
		return new WP_Error( 'logliy_oidc_sub', 'ID token is missing subject.' );
	}

	return $claims;
}

/**
 * Resolve the PEM public key for this token header from JWKS.
 *
 * @param array<string, mixed> $header JWT header.
 * @return string|WP_Error
 */
function logliy_oidc_signing_pem( array $header ) {
	$jwks = logliy_oidc_jwks();
	if ( is_wp_error( $jwks ) ) {
		return $jwks;
	}

	$keys = isset( $jwks['keys'] ) && is_array( $jwks['keys'] ) ? $jwks['keys'] : array();
	$kid  = isset( $header['kid'] ) ? (string) $header['kid'] : '';
	$jwk  = null;
	foreach ( $keys as $key ) {
		if ( ! is_array( $key ) ) {
			continue;
		}
		$kty = (string) ( $key['kty'] ?? '' );
		if ( $kty !== 'RSA' ) {
			continue;
		}
		$use = (string) ( $key['use'] ?? '' );
		if ( $use === 'enc' ) {
			continue;
		}
		$key_alg = (string) ( $key['alg'] ?? '' );
		if ( $key_alg !== '' && $key_alg !== 'RS256' ) {
			continue;
		}
		if ( $kid !== '' ) {
			if ( isset( $key['kid'] ) && (string) $key['kid'] === $kid ) {
				$jwk = $key;
				break;
			}
			continue;
		}
		$jwk = $key;
		break;
	}

	if ( ! is_array( $jwk ) ) {
		return new WP_Error( 'logliy_oidc_kid', 'No matching signing key.' );
	}

	if ( ! empty( $jwk['x5c'][0] ) && is_string( $jwk['x5c'][0] ) ) {
		$b64 = preg_replace( '/\s+/', '', $jwk['x5c'][0] );
		return "-----BEGIN CERTIFICATE-----\n" . chunk_split( (string) $b64, 64, "\n" ) . "-----END CERTIFICATE-----\n";
	}

	if ( empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
		return new WP_Error( 'logliy_oidc_jwk', 'Incomplete signing key.' );
	}

	$n = logliy_oidc_b64url_decode( (string) $jwk['n'] );
	$e = logliy_oidc_b64url_decode( (string) $jwk['e'] );
	if ( $n === '' || $e === '' ) {
		return new WP_Error( 'logliy_oidc_jwk', 'Incomplete signing key.' );
	}

	$pem = logliy_oidc_rsa_pem( $n, $e );
	if ( $pem === '' ) {
		return new WP_Error( 'logliy_oidc_jwk', 'Incomplete signing key.' );
	}
	return $pem;
}

/**
 * Fetch and cache JWKS.
 *
 * @return array<string, mixed>|WP_Error
 */
function logliy_oidc_jwks() {
	$discovery = logliy_oidc_discovery();
	if ( is_wp_error( $discovery ) ) {
		return $discovery;
	}

	$jwks_uri = (string) ( $discovery['jwks_uri'] ?? '' );
	if ( ! logliy_oidc_url_allowed( $jwks_uri ) ) {
		return new WP_Error( 'logliy_oidc_jwks_url', 'Invalid JWKS URI.' );
	}

	$cache_key = 'logliy_oidc_jwks_' . md5( $jwks_uri );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && ! empty( $cached['keys'] ) ) {
		return $cached;
	}

	$body = logliy_oidc_http_json( $jwks_uri, 'GET' );
	if ( is_wp_error( $body ) ) {
		return $body;
	}
	if ( empty( $body['keys'] ) || ! is_array( $body['keys'] ) ) {
		return new WP_Error( 'logliy_oidc_jwks', 'JWKS document has no keys.' );
	}

	set_transient( $cache_key, $body, HOUR_IN_SECONDS );
	return $body;
}

/**
 * Email claim from a verified ID token.
 *
 * @param array<string, mixed> $claims Claims.
 */
function logliy_oidc_email_from_claims( array $claims ): string {
	$email = isset( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '';
	return is_email( $email ) ? $email : '';
}

/**
 * Fallback: UserInfo endpoint when the ID token has no email.
 */
function logliy_oidc_email_from_userinfo( string $access_token, string $sub ): string {
	$discovery = logliy_oidc_discovery();
	if ( is_wp_error( $discovery ) ) {
		return '';
	}
	$userinfo = (string) ( $discovery['userinfo_endpoint'] ?? '' );
	if ( $userinfo === '' || ! logliy_oidc_url_allowed( $userinfo ) ) {
		return '';
	}

	$response = wp_remote_get(
		$userinfo,
		array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			),
		)
	);
	if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return '';
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) ) {
		return '';
	}
	if ( empty( $body['sub'] ) || ! hash_equals( $sub, (string) $body['sub'] ) ) {
		return '';
	}
	$email = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
	return is_email( $email ) ? $email : '';
}

/**
 * Match or optionally create a WordPress user.
 *
 * @param array<string, mixed> $claims Verified claims.
 * @return WP_User|WP_Error
 */
function logliy_oidc_resolve_user( array $claims, string $email ) {
	$sub = (string) $claims['sub'];
	$iss = untrailingslashit( (string) $claims['iss'] );

	$linked = get_users(
		array(
			'number'      => 2,
			'count_total' => false,
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Lookup by OIDC subject, limited to 2.
				'relation' => 'AND',
				array(
					'key'   => 'logliy_oidc_sub',
					'value' => $sub,
				),
				array(
					'key'   => 'logliy_oidc_iss',
					'value' => $iss,
				),
			),
		)
	);
	if ( count( $linked ) > 1 ) {
		return new WP_Error( 'logliy_oidc_ambiguous', 'Ambiguous SSO identity.' );
	}
	if ( isset( $linked[0] ) && $linked[0] instanceof WP_User ) {
		return $linked[0];
	}

	$by_email = get_user_by( 'email', $email );
	if ( $by_email instanceof WP_User ) {
		$existing_sub = (string) get_user_meta( $by_email->ID, 'logliy_oidc_sub', true );
		$existing_iss = untrailingslashit( (string) get_user_meta( $by_email->ID, 'logliy_oidc_iss', true ) );
		if ( $existing_sub !== '' && $existing_iss === $iss && ! hash_equals( $existing_sub, $sub ) ) {
			return new WP_Error( 'logliy_oidc_email_taken', 'Email already linked to another SSO identity.' );
		}
		update_user_meta( $by_email->ID, 'logliy_oidc_sub', $sub );
		update_user_meta( $by_email->ID, 'logliy_oidc_iss', $iss );
		return $by_email;
	}

	if ( ! logliy_get_setting( 'oidc_create_users', false ) ) {
		return new WP_Error( 'logliy_oidc_nouser', 'No matching user.' );
	}

	$login = sanitize_user( (string) strstr( $email, '@', true ), true );
	if ( $login === '' ) {
		$login = 'sso';
	}
	$base = $login;
	$i    = 1;
	while ( username_exists( $login ) ) {
		$login = $base . $i;
		++$i;
	}

	$display = '';
	if ( ! empty( $claims['name'] ) && is_string( $claims['name'] ) ) {
		$display = sanitize_text_field( $claims['name'] );
	} elseif ( ! empty( $claims['given_name'] ) && is_string( $claims['given_name'] ) ) {
		$display = sanitize_text_field( $claims['given_name'] );
	}

	$role     = (string) get_option( 'default_role', 'subscriber' );
	$role_obj = get_role( $role );
	if ( ! $role_obj instanceof WP_Role || $role_obj->has_cap( 'manage_options' ) ) {
		$role = 'subscriber';
	}

	$insert = array(
		'user_login'   => $login,
		'user_email'   => $email,
		'user_pass'    => wp_generate_password( 32, true, true ),
		'display_name' => $display !== '' ? $display : $login,
		'role'         => $role,
	);

	/**
	 * Filter data passed to wp_insert_user() for a new SSO account.
	 *
	 * @param array<string, mixed> $insert User insert args.
	 * @param array<string, mixed> $claims Verified ID token claims.
	 */
	$insert = apply_filters( 'logliy_oidc_user_insert', $insert, $claims );

	$user_id = wp_insert_user( $insert );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	update_user_meta( (int) $user_id, 'logliy_oidc_sub', $sub );
	update_user_meta( (int) $user_id, 'logliy_oidc_iss', $iss );

	$user = get_user_by( 'id', (int) $user_id );
	return $user instanceof WP_User ? $user : new WP_Error( 'logliy_oidc_create', 'Could not create user.' );
}

/**
 * GET JSON helper.
 *
 * @return array<string, mixed>|WP_Error
 */
function logliy_oidc_http_json( string $url, string $method = 'GET' ) {
	unset( $method );
	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array( 'Accept' => 'application/json' ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'logliy_oidc_http', 'Provider request failed.' );
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'logliy_oidc_json', 'Provider returned invalid JSON.' );
	}
	return $body;
}

/**
 * Allow only https IdP URLs (http on localhost for development).
 */
function logliy_oidc_url_allowed( string $url ): bool {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return false;
	}
	$scheme = strtolower( (string) $parts['scheme'] );
	$host   = strtolower( (string) $parts['host'] );
	if ( $scheme === 'https' ) {
		return true;
	}
	return $scheme === 'http' && ( $host === 'localhost' || $host === '127.0.0.1' );
}

/**
 * Base64url decode.
 */
function logliy_oidc_b64url_decode( string $data ): string {
	$remainder = strlen( $data ) % 4;
	if ( $remainder > 0 ) {
		$data .= str_repeat( '=', 4 - $remainder );
	}
	$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
	return is_string( $decoded ) ? $decoded : '';
}

/**
 * Base64url encode (no padding).
 */
function logliy_oidc_b64url_encode( string $data ): string {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * DER length prefix.
 */
function logliy_oidc_der_length( int $len ): string {
	if ( $len < 0x80 ) {
		return chr( $len );
	}
	$bytes = ltrim( pack( 'N', $len ), "\x00" );
	return chr( 0x80 | strlen( $bytes ) ) . $bytes;
}

/**
 * DER unsigned integer.
 */
function logliy_oidc_der_uint( string $bytes ): string {
	$bytes = ltrim( $bytes, "\x00" );
	if ( $bytes === '' ) {
		$bytes = "\x00";
	}
	if ( ( ord( $bytes[0] ) & 0x80 ) !== 0 ) {
		$bytes = "\x00" . $bytes;
	}
	return "\x02" . logliy_oidc_der_length( strlen( $bytes ) ) . $bytes;
}

/**
 * Build an RSA SubjectPublicKeyInfo PEM from modulus/exponent.
 */
function logliy_oidc_rsa_pem( string $n, string $e ): string {
	$seq = logliy_oidc_der_uint( $n ) . logliy_oidc_der_uint( $e );
	$rsa = "\x30" . logliy_oidc_der_length( strlen( $seq ) ) . $seq;
	$alg = hex2bin( '300d06092a864886f70d0101010500' );
	if ( ! is_string( $alg ) ) {
		return '';
	}
	$bit    = "\x00" . $rsa;
	$bitstr = "\x03" . logliy_oidc_der_length( strlen( $bit ) ) . $bit;
	$spki   = $alg . $bitstr;
	$der    = "\x30" . logliy_oidc_der_length( strlen( $spki ) ) . $spki;
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}
