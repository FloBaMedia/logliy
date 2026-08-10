<?php
/**
 * Wordfence compatibility helpers / documentation hooks.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logliy never removes Wordfence actions.
 * Failed passwordless attempts call logliy_fire_login_failed() → wp_login_failed.
 * Successful logins call wp_login + wp_set_auth_cookie so Wordfence sees normal sessions.
 *
 * Wordfence TOTP 2FA continues to apply on the classic password path.
 * Passkey / Email OTP are treated as primary (password-replacement) factors in v1;
 * they establish a session directly after successful verification.
 */

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
	echo esc_html__( 'Logliy works alongside Wordfence: keep Wordfence for WAF, brute-force lockouts, CAPTCHA, and TOTP. Logliy handles Passkey / Email OTP login methods. Failed Logliy attempts still fire wp_login_failed for Wordfence lockouts.', 'logliy' );
	echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'logliy' ) . '</a>';
	echo '</p></div>';
}
