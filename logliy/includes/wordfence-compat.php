<?php
/**
 * Wordfence compatibility helpers.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logliy never removes Wordfence actions globally.
 * Failed passwordless attempts call logliy_fire_login_failed() → wp_login_failed.
 * Successful logins call wp_login + wp_set_auth_cookie so Wordfence sees normal sessions.
 *
 * Wordfence TOTP 2FA continues to apply on the classic password path.
 * Passkey / Email OTP / Magic Link are primary (password-replacement) factors —
 * Wordfence Login Security 2FA is suspended only for those complete_login calls
 * (IP lockouts via wordfence::authenticateFilter remain active).
 */

/**
 * Whether Wordfence Login Security 2FA controller is available.
 */
function logliy_wordfence_ls_available(): bool {
	return class_exists( '\WordfenceLS\Controller_WordfenceLS', false );
}

/**
 * Suspend Wordfence LS authenticate (2FA + LS CAPTCHA) for one passwordless login.
 *
 * Lockouts still run via wordfence::authenticateFilter at priority 99.
 */
function logliy_wordfence_ls_suspend_auth(): void {
	if ( ! logliy_wordfence_ls_available() ) {
		return;
	}
	$controller = \WordfenceLS\Controller_WordfenceLS::shared();
	remove_filter( 'authenticate', array( $controller, '_authenticate' ), 25 );
	// Belt-and-suspenders if another code path re-adds the check mid-request.
	add_filter( 'wordfence_ls_require_captcha', '__return_false', 999 );
}

/**
 * Restore Wordfence LS authenticate after passwordless login attempt.
 */
function logliy_wordfence_ls_resume_auth(): void {
	if ( ! logliy_wordfence_ls_available() ) {
		return;
	}
	remove_filter( 'wordfence_ls_require_captcha', '__return_false', 999 );
	$controller = \WordfenceLS\Controller_WordfenceLS::shared();
	if ( false === has_filter( 'authenticate', array( $controller, '_authenticate' ) ) ) {
		add_filter( 'authenticate', array( $controller, '_authenticate' ), 25, 3 );
	}
}

/**
 * Admin notice when Wordfence is active (informational once).
 */
add_action( 'admin_notices', 'logliy_wordfence_compat_notice' );
function logliy_wordfence_compat_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! class_exists( 'wordfence', false ) && ! defined( 'WORDFENCE_VERSION' ) ) {
		return;
	}
	if ( get_option( 'logliy_wf_notice_dismissed' ) === '1' ) {
		return;
	}
	if ( isset( $_GET['logliy_dismiss_wf'] ) && check_admin_referer( 'logliy_dismiss_wf' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		update_option( 'logliy_wf_notice_dismissed', '1', false );
		return;
	}
	$url = wp_nonce_url( add_query_arg( 'logliy_dismiss_wf', '1' ), 'logliy_dismiss_wf' );
	echo '<div class="notice notice-info is-dismissible"><p>';
	echo esc_html__( 'Logliy works alongside Wordfence: keep Wordfence for WAF and lockouts. Passkey / Email OTP / Magic Link skip Wordfence 2FA (they already are a strong factor). Wordfence 2FA still applies on the classic password path.', 'logliy' );
	echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'logliy' ) . '</a>';
	echo '</p></div>';
}
