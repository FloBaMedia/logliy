<?php
/** Tab-scoped AJAX settings save, using the existing validation rules. @package Logliy */
defined( 'ABSPATH' ) || exit;

/** Fields owned by each settings tab; shared with the form renderer. */
function logliy_settings_tab_keys(): array {
	return array(
		'general'     => array( 'enable_passkey', 'enable_email_otp', 'enable_magic_link', 'allow_password_login', 'allow_xmlrpc_password', 'password_allowed_roles', 'hide_wp_login_password', 'onboarding_dismissed', 'login_brand_name', 'login_tagline', 'login_logo_id', 'auto_remember', 'login_identifier', 'hide_language_switcher', 'delete_settings_on_uninstall', 'hide_back_to_blog', 'hide_lost_password', 'login_bg_color', 'login_bg_image_id', 'login_footer_text' ),
		'redirects'   => array( 'redirect_login_default', 'redirect_logout_default', 'redirect_login_roles', 'redirect_logout_roles' ),
		'passkeys'    => array( 'passkey_uv', 'passkey_resident_key' ),
		'email_otp'   => array( 'email_allowlist_enabled', 'email_allowed_addresses', 'email_rate_limit_site', 'otp_ttl_minutes', 'otp_length', 'otp_rate_limit_account', 'otp_rate_limit_ip', 'otp_rate_window_minutes', 'email_request_cooldown_seconds', 'magic_link_ttl_minutes' ),
		'sso'         => array( 'oidc_require_verified_email', 'oidc_allowlist_enabled', 'oidc_allowed_addresses', 'oidc_groups_enabled', 'oidc_groups_claim', 'oidc_allowed_groups', 'enable_oidc', 'oidc_issuer', 'oidc_client_id', 'oidc_client_secret', 'oidc_button_label', 'oidc_create_users' ),
		'woocommerce' => array( 'wc_enable_myaccount', 'wc_enable_checkout', 'wc_enable_blocks' ),
		'security'    => array( 'security_log_enabled', 'security_log_retention_days', 'notification_email', 'notify_account_security', 'notify_plugin_installed', 'notify_plugin_updated', 'notify_plugin_activated', 'notify_plugin_deactivated', 'notify_plugin_deleted', 'notify_user_changes', 'notify_settings_changes' ),
		'advanced'    => array( 'rp_id', 'rp_name', 'related_origins', 'enable_custom_login_url', 'custom_login_slug', 'session_expire_hours', 'session_remember_days', 'admin_idle_timeout_minutes' ),
	);
}

/** Validate and save one tab without trusting hidden values from other tabs. */
function logliy_save_settings_tab( string $tab, array $input ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'logliy_save_forbidden', __( 'You cannot change these settings.', 'logliy' ), array( 'status' => 403 ) );
	}
	$tabs = logliy_settings_tab_keys();
	if ( ! isset( $tabs[ $tab ] ) ) {
		return new WP_Error( 'logliy_save_tab', __( 'Invalid settings tab.', 'logliy' ), array( 'status' => 400 ) );
	}
	$defaults = logliy_default_settings();
	$patch = array_intersect_key( $input, array_flip( $tabs[ $tab ] ) );
	foreach ( $tabs[ $tab ] as $key ) {
		if ( ! array_key_exists( $key, $patch ) ) {
			if ( is_bool( $defaults[ $key ] ) ) {
				$patch[ $key ] = false;
			} elseif ( is_array( $defaults[ $key ] ) ) {
				$patch[ $key ] = array();
			}
			continue;
		}
		$value = $patch[ $key ];
		if ( is_array( $defaults[ $key ] ) ? ! is_array( $value ) : ! is_scalar( $value ) ) {
			return new WP_Error( 'logliy_save_value', __( 'Invalid settings value.', 'logliy' ), array( 'status' => 400 ) );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! is_scalar( $item ) ) {
					return new WP_Error( 'logliy_save_value', __( 'Invalid settings value.', 'logliy' ), array( 'status' => 400 ) );
				}
			}
		}
	}
	$clean = logliy_sanitize_settings( $patch );
	foreach ( get_settings_errors( LOGLIY_OPT_SETTINGS ) as $error ) {
		if ( $error['type'] === 'error' ) {
			return new WP_Error( 'logliy_save_invalid', wp_strip_all_tags( $error['message'] ), array( 'status' => 400 ) );
		}
	}
	$updated = update_option( LOGLIY_OPT_SETTINGS, $clean, false );
	if ( ! $updated && logliy_get_settings() !== $clean ) {
		return new WP_Error( 'logliy_save_failed', __( 'The settings could not be stored. Please try again.', 'logliy' ), array( 'status' => 500 ) );
	}
	$result = array_intersect_key( $clean, array_flip( $tabs[ $tab ] ) );
	unset( $result['oidc_client_secret'] );
	return $result;
}

add_action( 'wp_ajax_logliy_save_settings', 'logliy_ajax_save_settings' );
function logliy_ajax_save_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You cannot change these settings.', 'logliy' ) ), 403 );
	}
	if ( ! check_ajax_referer( 'logliy_group-options', '_wpnonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Your session expired. Reload this page before saving again.', 'logliy' ) ), 403 );
	}
	if ( ! isset( $_POST['logliy_settings'] ) || ! is_array( $_POST['logliy_settings'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing settings data.', 'logliy' ) ), 400 );
	}
	$tab = isset( $_POST['logliy_active_tab'] ) && is_string( $_POST['logliy_active_tab'] ) ? sanitize_key( wp_unslash( $_POST['logliy_active_tab'] ) ) : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each allowlisted field is type-checked and passed through the settings sanitizer.
	$result = logliy_save_settings_tab( $tab, wp_unslash( $_POST['logliy_settings'] ) );
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		wp_send_json_error( array( 'message' => $result->get_error_message() ), (int) ( $data['status'] ?? 400 ) );
	}
	wp_send_json_success( array( 'settings' => $result ) );
}
