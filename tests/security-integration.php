<?php
/** Run with: wp eval-file /path/to/tests/security-integration.php on a disposable site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
global $wpdb, $checks;
$checks = 0;
function logliy_test( $condition, string $name ): void {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $name ); }
	++$checks;
}
function logliy_test_reset_limits(): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', logliy_rate_limit_table() ) );
}
// All mail is intercepted. No account, security or plugin email leaves the test site.
$mail = array(); $fail_mail = false;
add_filter( 'pre_wp_mail', static function ( $result, $args ) use ( &$mail, &$fail_mail ) { $mail[] = $args; return ! $fail_mail; }, 10, 2 );
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$admin_id = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
wp_set_current_user( (int) $admin_id );
update_option( LOGLIY_OPT_SETTINGS, logliy_default_settings(), false );
logliy_update_settings( array( 'notify_account_security' => false, 'notify_plugin_changes' => false, 'notify_user_changes' => false, 'notify_settings_changes' => false, 'email_request_cooldown_seconds' => 0, 'otp_rate_limit_account' => 50, 'otp_rate_limit_ip' => 200, 'email_rate_limit_site' => 100 ) );
$id = wp_insert_user( array( 'user_login' => 'logliy-security-test', 'user_email' => 'allowed@floba-media.de', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
logliy_test( ! is_wp_error( $id ), 'create test user' );
$user = get_user_by( 'id', $id );
foreach ( array(
	array( 'a@floba-media.de', '@floba-media.de', true ),
	array( 'A@FLOBA-MEDIA.DE', ' @floba-media.de ', true ),
	array( 'a@sub.floba-media.de', '@floba-media.de', false ),
	array( 'a@floba-media.de.evil.org', '@floba-media.de', false ),
	array( 'firma-berlin@abc.de', 'firma-*@abc.de', true ),
	array( 'firma-@abc.de', 'firma-*@abc.de', true ),
	array( 'firma-berlin@evil.de', 'firma-*@abc.de', false ),
	array( 'firma-berlin@abc.de.evil.de', 'firma-*@abc.de', false ),
	array( 'mitarbeiter1@floba-media.de', 'mitarbeiter1@floba-media.de', true ),
	array( 'mitarbeiter2@floba-media.de', 'mitarbeiter1@floba-media.de', false ),
	array( 'a@floba-media.de', '', false ),
	array( 'a@floba-media.de', '*@*.de', false ),
	array( 'a@floba-media.de', "bad-rule\n@floba-media.de", true ),
) as [ $email, $rule, $expected ] ) {
	logliy_test( logliy_email_matches_rules( $email, $rule ) === $expected, 'allowlist boundary: ' . $email . '/' . $rule );
}
$clean = logliy_sanitize_settings( array( 'email_allowlist_enabled' => '1', 'email_allowed_addresses' => " @FLOBA-MEDIA.DE \r\nfirma-*@abc.de" ) );
logliy_test( $clean['email_allowed_addresses'] === "@floba-media.de\nfirma-*@abc.de", 'normalizes complete list' );
$bad = logliy_sanitize_settings( array( 'email_allowed_addresses' => "@floba-media.de\n*@*.de" ), $clean );
logliy_test( $bad['email_allowed_addresses'] === $clean['email_allowed_addresses'], 'invalid rule preserves entire policy' );
logliy_update_settings( array( 'email_allowlist_enabled' => true, 'email_allowed_addresses' => '' ) );
$mail = array();
$denied = logliy_otp_request( $user->user_login );
$unknown = logliy_otp_request( 'unknown@example.invalid' );
logliy_test( $denied === $unknown && count( $mail ) === 0, 'blocked/unknown OTP uniform and no mail' );
$denied = logliy_magic_link_request( $user->user_login );
$unknown = logliy_magic_link_request( 'unknown@example.invalid' );
logliy_test( $denied === $unknown && count( $mail ) === 0, 'blocked/unknown link uniform and no mail' );
logliy_update_settings( array( 'email_allowed_addresses' => '@floba-media.de' ) );
logliy_test_reset_limits();
logliy_otp_request( $user->user_login );
logliy_test( count( $mail ) === 1, 'allowed username sends to stored email' );
preg_match( '/\n\n(\d{6})\n\n/', $mail[0]['message'], $match );
$code = $match[1] ?? '';
logliy_test( strlen( $code ) === 6, 'captures OTP from intercepted mail' );
logliy_update_settings( array( 'email_allowed_addresses' => '@other.de' ) );
logliy_test( is_wp_error( logliy_otp_verify( $user->user_login, $code ) ), 'changed allowlist denies old OTP' );
logliy_update_settings( array( 'email_allowed_addresses' => '@floba-media.de' ) );
// Do not issue auth cookies to the CLI: stop at the standard security filter.
$block_auth = static function () { return new WP_Error( 'test_auth_stop', 'Stop before cookies.' ); };
add_filter( 'authenticate', $block_auth, 999 );
$result = logliy_otp_verify( $user->user_login, $code );
logliy_test( is_wp_error( $result ) && $result->get_error_code() === 'test_auth_stop', 'valid OTP reaches normal security filters' );
logliy_test( logliy_email_challenge_get( 'otp', $id ) === null, 'OTP consumed before issuing session' );
logliy_test( is_wp_error( logliy_otp_verify( $user->user_login, $code ) ), 'OTP replay rejected' );
remove_filter( 'authenticate', $block_auth, 999 );

$mail = array(); logliy_test_reset_limits();
logliy_magic_link_request( $user->user_login );
preg_match( '/logliy_magic=([a-f0-9]{64})/', $mail[0]['message'] ?? '', $match );
$magic = $match[1] ?? '';
logliy_test( strlen( $magic ) === 64, 'link issued for allowed account' );
logliy_update_settings( array( 'email_allowed_addresses' => '@other.de' ) );
logliy_test( is_wp_error( logliy_magic_link_verify( $magic ) ), 'changed list denies previously issued link' );
logliy_update_settings( array( 'email_allowed_addresses' => '@floba-media.de' ) );
logliy_test( logliy_magic_link_verify( $magic ) instanceof WP_User && is_wp_error( logliy_magic_link_verify( $magic ) ), 'full link verification single-use' );
$mail = array();
logliy_magic_link_request( $user->user_login );
preg_match( '/logliy_magic=([a-f0-9]{64})/', $mail[0]['message'] ?? '', $first );
logliy_magic_link_request( $user->user_login );
preg_match( '/logliy_magic=([a-f0-9]{64})/', $mail[1]['message'] ?? '', $second );
logliy_test( is_wp_error( logliy_magic_link_verify( $first[1] ?? '' ) ) && logliy_magic_link_verify( $second[1] ?? '' ) instanceof WP_User, 'newly sent link invalidates old one' );
$hash = logliy_otp_hash( '123456', $id );
logliy_email_challenge_store( $user, 'otp', $hash, 600 );
$row = logliy_email_challenge_get( 'otp', $id );
for ( $i = 0; $i < 5; ++$i ) { logliy_test( logliy_email_challenge_attempt( $user, $row ), 'first five atomic attempts allowed' ); }
logliy_test( ! logliy_email_challenge_attempt( $user, $row ), 'sixth atomic attempt rejected' );
logliy_test( logliy_email_challenge_get( 'otp', $id )['expires'] === $row['expires'], 'attempts do not extend expiry' );
logliy_email_challenge_store( $user, 'otp', str_repeat( 'b', 64 ), 600 );
logliy_test( ! logliy_email_challenge_consume( $user, $row ), 'stale verifier cannot consume replacement' );
logliy_email_challenge_store( $user, 'magic', str_repeat( 'c', 64 ), 600 );
$row = logliy_email_challenge_get( 'magic', $id );
logliy_test( logliy_email_challenge_consume( $user, $row ) && ! logliy_email_challenge_consume( $user, $row ), 'link consumed exactly once' );
logliy_email_challenge_store( $user, 'magic', str_repeat( 'c', 64 ), -1 );
logliy_test( logliy_email_challenge_get( 'magic', $id ) === null, 'expired link rejected' );
logliy_email_challenge_store( $user, 'magic', str_repeat( 'c', 64 ), 600 );
$row = logliy_email_challenge_get( 'magic', $id );
$changed_user = clone $user; $changed_user->user_email = 'changed@floba-media.de';
logliy_test( ! logliy_email_challenge_consume( $changed_user, $row ), 'email change invalidates issued challenge' );
$mail = array(); logliy_test_reset_limits();
logliy_update_settings( array( 'otp_rate_limit_account' => 1 ) );
logliy_otp_request( $user->user_login );
$_SERVER['REMOTE_ADDR'] = '192.0.2.2';
logliy_magic_link_request( $user->user_login );
logliy_test( count( $mail ) === 1, 'account budget shared across methods and IPs' );
$mail = array(); logliy_test_reset_limits();
logliy_update_settings( array( 'otp_rate_limit_account' => 50, 'otp_rate_limit_ip' => 1 ) );
logliy_otp_request( $user->user_login );
logliy_test( is_wp_error( logliy_magic_link_request( $user->user_login ) ) && count( $mail ) === 1, 'IP budget shared across methods' );
logliy_test_reset_limits();
logliy_update_settings( array( 'otp_rate_limit_ip' => 200, 'email_rate_limit_site' => 1 ) );
logliy_test( logliy_email_account_limit( $user ) === true && is_wp_error( logliy_email_account_limit( $user ) ), 'site email cap enforced' );
logliy_test_reset_limits();
logliy_test( logliy_rate_limit_hit( 'boundary', 1, 60 ) === true && is_wp_error( logliy_rate_limit_hit( 'boundary', 1, 60 ) ), 'durable rate-limit boundary' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires = %d WHERE bucket_hash = %s', logliy_rate_limit_table(), time() - 1, md5( 'boundary' ) ) );
logliy_test( logliy_rate_limit_hit( 'boundary', 1, 60 ) === true, 'expired bucket resets' );
logliy_update_settings( array( 'email_request_cooldown_seconds' => 1 ) );
logliy_test( logliy_email_request_cooldown( 'cooldown-test' ) === true && is_wp_error( logliy_email_request_cooldown( 'cooldown-test' ) ), 'atomic cooldown enforced' );
$claims = array( 'sub' => 'test-sub', 'iss' => 'https://idp.example.invalid', 'email_verified' => true, 'groups' => array( 'staff' ) );
logliy_test( logliy_oidc_access_allowed( $claims, $user->user_email ), 'verified SSO allowed with restrictions off' );
logliy_test( ! logliy_oidc_access_allowed( array(), $user->user_email ), 'missing verified email denied by default' );
logliy_test( ! logliy_oidc_access_allowed( array( 'email_verified' => 'true' ), $user->user_email ), 'malformed verified claim denied' );
logliy_update_settings( array( 'oidc_allowlist_enabled' => true, 'oidc_allowed_addresses' => '@floba-media.de', 'oidc_groups_enabled' => true, 'oidc_allowed_groups' => 'staff' ) );
logliy_test( logliy_oidc_access_allowed( $claims, $user->user_email ), 'SSO requires allowed address and group' );
logliy_test( ! logliy_oidc_access_allowed( $claims, 'someone@other.de' ), 'SSO email restriction enforced' );
$no_group = $claims; unset( $no_group['groups'] );
logliy_test( ! logliy_oidc_access_allowed( $no_group, $user->user_email ), 'missing group denied' );
logliy_test( is_wp_error( logliy_oidc_resolve_user( $no_group, $user->user_email ) ) && get_user_meta( $id, 'logliy_oidc_sub', true ) === '', 'denied SSO cannot link account' );
logliy_update_settings( array( 'oidc_groups_claim' => 'realm_access.roles' ) );
$nested = $claims; $nested['realm_access'] = array( 'roles' => array( 'staff' ) );
logliy_test( logliy_oidc_access_allowed( $nested, $user->user_email ), 'nested signed role claim supported' );
logliy_update_settings( array( 'oidc_allowed_groups' => '' ) );
logliy_test( ! logliy_oidc_access_allowed( $nested, $user->user_email ), 'enabled empty group list denies all' );
$manager = WP_Session_Tokens::get_instance( $id );
$one = $manager->create( time() + 600 ); $two = $manager->create( time() + 600 );
logliy_test( logliy_session_revoke( $id, hash( 'sha256', $one ) ) === true && ! $manager->verify( $one ) && $manager->verify( $two ), 'targeted session revoke preserves other session' );
wp_set_current_user( $id );
logliy_test( is_wp_error( logliy_session_revoke( (int) $admin_id, 'all' ) ), 'subscriber cannot revoke another account' );
logliy_test( logliy_session_revoke( $id, 'all' ) === true && ! $manager->verify( $two ), 'self can revoke all sessions' );
wp_set_current_user( (int) $admin_id );
$mail = array(); logliy_test_reset_limits();
logliy_update_settings( array( 'notify_account_security' => true, 'notify_plugin_changes' => true, 'notify_user_changes' => true, 'notification_email' => 'agent@floba-media.de' ) );
do_action( 'activated_plugin', 'example/example.php' );
logliy_test( count( $mail ) === 1 && $mail[0]['to'] === 'agent@floba-media.de', 'plugin action notifies configured admin' );
$count = count( $mail );
apply_filters( 'upgrader_post_install', true, array( 'type' => 'plugin', 'action' => 'install' ), array( 'destination_name' => 'example' ) );
logliy_test( count( $mail ) === $count + 1, 'successful installation sends notification' );
$count = count( $mail );
apply_filters( 'upgrader_post_install', new WP_Error( 'failed' ), array( 'type' => 'plugin', 'action' => 'install' ), array() );
logliy_test( count( $mail ) === $count, 'failed installation does not claim success' );
logliy_security_event( 'passkey_added', $id );
logliy_test( count( $mail ) === $count + 2, 'account security notifies owner and admin' );
$count = count( $mail );
do_action( 'wp_login_failed', 'private-address@example.invalid' );
logliy_test( count( $mail ) === $count, 'failed login does not flood email' );
for ( $i = 0; $i < 25; ++$i ) { do_action( 'activated_plugin', 'example/example.php' ); }
logliy_test( count( array_filter( $mail, static fn( $m ) => $m['to'] === 'agent@floba-media.de' ) ) === 20, 'notifications bounded per recipient' );

logliy_test_reset_limits();
$count = count( $mail );
logliy_update_settings( array( 'security_log_enabled' => false ) );
do_action( 'activated_plugin', 'example/example.php' );
logliy_test( count( $mail ) === $count + 1, 'notifications work independently of log switch' );
logliy_update_settings( array( 'security_log_enabled' => true ) );
$fail_mail = true;
do_action( 'activated_plugin', 'example/example.php' );
$fail_mail = false;
logliy_test( get_transient( 'logliy_security_mail_failed' ) === 'failed', 'mail failure visible to administrators' );
logliy_test( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', $wpdb->prefix . 'logliy_security_events', 'notification_failed' ) ) > 0, 'mail failure recorded without secret context' );
logliy_test( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', $wpdb->prefix . 'logliy_security_events', 'plugin_installed' ) ) > 0, 'installation success persisted' );
$events = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $wpdb->prefix . 'logliy_security_events' ), ARRAY_A );
$encoded = wp_json_encode( $events );
logliy_test( ! str_contains( $encoded, '@' ) && ! str_contains( $encoded, '192.0.2.' ) && ! str_contains( $encoded, $one ), 'audit contains no raw email, IP or token' );
$wpdb->insert( $wpdb->prefix . 'logliy_security_events', array( 'event' => 'login_failed', 'created_at' => '2000-01-01 00:00:00' ) );
logliy_security_cleanup();
logliy_test( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at < %s', $wpdb->prefix . 'logliy_security_events', '2001-01-01' ) ) === 0, 'retention removes old events' );
logliy_test( wp_next_scheduled( 'logliy_security_cleanup' ) !== false, 'cleanup scheduled' );
$wpdb->insert( $wpdb->prefix . 'logliy_security_events', array( 'id' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM %i', $wpdb->prefix . 'logliy_security_events' ) ) + 20000, 'event' => 'login_failed', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
logliy_security_event( 'login_failed' );
logliy_test( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'logliy_security_events' ) ) <= 10000, 'audit storage capped' );

// Restore notification defaults before deleting only our disposable test account.
logliy_update_settings( array( 'notify_account_security' => false, 'notify_plugin_changes' => false, 'notify_user_changes' => false, 'notify_settings_changes' => false ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $id );
WP_CLI::success( $checks . ' security integration checks passed. All mail intercepted.' );
