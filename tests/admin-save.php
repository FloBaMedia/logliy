<?php
/** Run only on a disposable WordPress CLI installation. All mail is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
require_once WP_PLUGIN_DIR . '/logliy/includes/admin.php';
logliy_register_settings();
add_filter( 'pre_wp_mail', '__return_true' );
$before = get_option( LOGLIY_OPT_SETTINGS );
$actor = get_current_user_id();
$checks = 0;
$assert = static function ( bool $condition, string $name ) use ( &$checks ): void {
	if ( ! $condition ) { throw new RuntimeException( $name ); }
	++$checks;
};
try {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
	wp_set_current_user( $admin->ID );
	update_option( LOGLIY_OPT_SETTINGS, logliy_default_settings(), false );
	logliy_update_settings( array( 'login_brand_name' => 'Keep branding', 'enable_oidc' => true, 'oidc_client_secret' => 'test-only-value', 'email_allowlist_enabled' => true ) );
	$input = array( 'otp_rate_limit_account' => '7', 'login_brand_name' => 'stale value', 'enable_oidc' => false, '_logliy_form' => '1' );
	$result = logliy_save_settings_tab( 'email_otp', $input );
	$assert( ! is_wp_error( $result ) && logliy_get_setting( 'otp_rate_limit_account' ) === 7, 'persists active-tab changes' );
	$assert( logliy_get_setting( 'login_brand_name' ) === 'Keep branding' && logliy_get_setting( 'enable_oidc' ) === true, 'ignores stale hidden fields from other tabs' );
	$assert( logliy_get_setting( 'email_allowlist_enabled' ) === false, 'unchecked active-tab toggle persists false' );
	$assert( ! array_key_exists( 'oidc_client_secret', $result ), 'response excludes secrets' );
	$assert( ! is_wp_error( logliy_save_settings_tab( 'email_otp', $input ) ), 'unchanged settings count as success' );
	$result = logliy_save_settings_tab( 'email_otp', array( 'email_allowlist_enabled' => '1', 'email_allowed_addresses' => '@example.com' ) );
	$assert( ! is_wp_error( $result ) && logliy_get_setting( 'email_allowlist_enabled' ) === true, 'checked active-tab toggle persists true' );
	$snapshot = logliy_get_settings();
	$GLOBALS['wp_settings_errors'] = array();
	$result = logliy_save_settings_tab( 'email_otp', array( 'email_allowed_addresses' => '*@*.com', 'otp_rate_limit_account' => '9' ) );
	$assert( is_wp_error( $result ) && $snapshot === logliy_get_settings(), 'validation failure rejects the entire write' );
	$GLOBALS['wp_settings_errors'] = array();
	$assert( is_wp_error( logliy_save_settings_tab( 'security_log', array() ) ), 'read-only/unknown tab cannot save' );
	$assert( is_wp_error( logliy_save_settings_tab( 'email_otp', array( 'otp_rate_limit_account' => array( 'invalid' ) ) ) ), 'scalar fields reject arrays' );
	$assert( is_wp_error( logliy_save_settings_tab( 'redirects', array( 'redirect_login_roles' => array( 'administrator' => array() ) ) ) ), 'nested malformed values rejected' );
	$result = logliy_save_settings_tab( 'sso', array( 'oidc_client_secret' => '' ) );
	$assert( ! is_wp_error( $result ) && logliy_get_setting( 'oidc_client_secret' ) === 'test-only-value' && ! isset( $result['oidc_client_secret'] ), 'empty SSO secret preserves value without echoing it' );
	// A database/storage filter refuses the write, without suppressing the failure.
	$block_write = static function () { return logliy_get_settings(); };
	add_filter( 'pre_update_option_' . LOGLIY_OPT_SETTINGS, $block_write );
	$result = logliy_save_settings_tab( 'email_otp', array( 'otp_rate_limit_account' => '11' ) );
	remove_filter( 'pre_update_option_' . LOGLIY_OPT_SETTINGS, $block_write );
	$assert( is_wp_error( $result ) && logliy_get_setting( 'otp_rate_limit_account' ) !== 11, 'refused storage is surfaced as an error' );
	wp_set_current_user( 0 );
	$assert( is_wp_error( logliy_save_settings_tab( 'general', array() ) ), 'unauthenticated saves denied' );
	wp_set_current_user( $admin->ID );
	$assert( wp_verify_nonce( wp_create_nonce( 'logliy_group-options' ), 'logliy_group-options' ) !== false, 'uses standard Settings API nonce action' );
	$assert( wp_verify_nonce( 'invalid', 'logliy_group-options' ) === false, 'invalid nonce rejected' );
	if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
	$ajax_die = static function () { return static function () { throw new RuntimeException( 'test-json-exit' ); }; };
	add_filter( 'wp_die_ajax_handler', $ajax_die );
	$invoke = static function ( array $post ): array {
		$_POST = $post;
		$_REQUEST = $post;
		$GLOBALS['wp_settings_errors'] = array();
		ob_start();
		try { logliy_ajax_save_settings(); }
		catch ( RuntimeException $error ) { if ( $error->getMessage() !== 'test-json-exit' ) { throw $error; } }
		return json_decode( ob_get_clean(), true );
	};
	$payload = array( '_wpnonce' => 'bad', 'logliy_active_tab' => 'email_otp', 'logliy_settings' => array( 'otp_rate_limit_account' => '13' ) );
	$snapshot = logliy_get_settings();
	$reply = $invoke( $payload );
	$assert( $reply['success'] === false && logliy_get_settings() === $snapshot, 'AJAX invalid nonce makes no changes' );
	$payload['_wpnonce'] = wp_create_nonce( 'logliy_group-options' );
	$reply = $invoke( $payload );
	$assert( $reply['success'] === true && logliy_get_setting( 'otp_rate_limit_account' ) === 13, 'AJAX valid nonce saves and confirms persistence' );
	$assert( ! str_contains( wp_json_encode( $reply ), 'test-only-value' ), 'AJAX response never exposes stored secret' );
	wp_set_current_user( 0 );
	$snapshot = logliy_get_settings();
	$reply = $invoke( $payload );
	$assert( $reply['success'] === false && logliy_get_settings() === $snapshot, 'AJAX unauthorized actor makes no changes' );
	remove_filter( 'wp_die_ajax_handler', $ajax_die );

} finally {
	$GLOBALS['wp_settings_errors'] = array();
	wp_set_current_user( isset( $admin ) ? $admin->ID : $actor );
	update_option( LOGLIY_OPT_SETTINGS, $before, false );
	wp_set_current_user( $actor );
}
WP_CLI::success( $checks . ' tab-scoped save checks passed. All mail intercepted.' );
