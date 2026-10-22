<?php
/** Run only on a disposable site: deliberately tests opt-in uninstall cleanup. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
global $wpdb;
add_filter( 'pre_wp_mail', '__return_true' );
$settings = logliy_default_settings();
$settings['email_allowlist_enabled'] = true;
$settings['email_allowed_addresses'] = '@example.org';
update_option( LOGLIY_OPT_SETTINGS, $settings, false );
$tables = array( 'logliy_credentials', 'logliy_rl', 'logliy_email_challenges', 'logliy_security_events' );
$table_exists = static function ( string $suffix ) use ( $wpdb ): bool {
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $suffix ) ) ) === $wpdb->prefix . $suffix;
};
$assert = static function ( bool $ok, string $name ): void { if ( ! $ok ) { throw new RuntimeException( $name ); } };
// Simulate upgrade from schema 2, keeping the existing access policy.
foreach ( array( 'logliy_email_challenges', 'logliy_security_events' ) as $table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $wpdb->prefix . $table ) );
}
update_option( LOGLIY_OPT_VERSION, '0.1.1', false );
logliy_maybe_upgrade();
foreach ( $tables as $table ) { $assert( $table_exists( $table ), 'upgrade creates ' . $table ); }
$assert( logliy_get_setting( 'email_allowed_addresses' ) === '@example.org', 'upgrade preserves policy' );
$assert( get_option( 'logliy_db_version' ) === '3', 'upgrade schema version' );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'logliy/logliy.php' ); }
require WP_PLUGIN_DIR . '/logliy/uninstall.php';
foreach ( $tables as $table ) { $assert( $table_exists( $table ), 'default uninstall preserves ' . $table ); }
$id = wp_insert_user( array( 'user_login' => 'logliy-lifecycle-test', 'user_email' => 'lifecycle@example.invalid', 'user_pass' => wp_generate_password() ) );
$assert( ! is_wp_error( $id ), 'create owned disposable account' );
update_user_meta( $id, 'logliy_oidc_sub', 'disposable-subject' );
logliy_update_settings( array( 'delete_settings_on_uninstall' => true ) );
require WP_PLUGIN_DIR . '/logliy/uninstall.php';
foreach ( $tables as $table ) { $assert( ! $table_exists( $table ), 'opt-in cleanup drops ' . $table ); }
$assert( get_option( LOGLIY_OPT_SETTINGS, null ) === null, 'settings removed' );
$assert( get_user_meta( $id, 'logliy_oidc_sub', true ) === '', 'plugin user metadata removed with valid SQL' );
$assert( wp_next_scheduled( 'logliy_security_cleanup' ) === false, 'scheduled task removed' );
logliy_activate();
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $id );
WP_CLI::success( 'Upgrade preservation, default keep-data uninstall, opt-in table/meta cleanup and reactivation passed. All mail intercepted.' );
