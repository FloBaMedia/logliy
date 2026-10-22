<?php
/** Run on a disposable WordPress site with Logliy active. All mail is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
$mail = array();
add_filter( 'pre_wp_mail', static function ( $result, $args ) use ( &$mail ) { $mail[] = $args; return true; }, 10, 2 );
$previous = get_option( LOGLIY_OPT_SETTINGS );
$checks = 0;
$assert = static function ( bool $ok, string $name ) use ( &$checks ): void {
	if ( ! $ok ) { throw new RuntimeException( $name ); }
	++$checks;
};
$events = array( 'installed', 'updated', 'activated', 'deactivated', 'deleted' );
try {
	foreach ( array( true, false ) as $legacy ) {
		update_option( LOGLIY_OPT_SETTINGS, array( 'notify_plugin_changes' => $legacy ), false );
		foreach ( $events as $event ) {
			$assert( logliy_get_setting( 'notify_plugin_' . $event ) === $legacy, 'legacy preference preserved: ' . $event );
		}
		logliy_settings_ensure_defaults();
		$assert( ! array_key_exists( 'notify_plugin_changes', get_option( LOGLIY_OPT_SETTINGS ) ), 'legacy key removed after persistence' );
	}
	update_option( LOGLIY_OPT_SETTINGS, array( 'notify_plugin_changes' => true, 'notify_plugin_deleted' => false ), false );
	$assert( logliy_get_setting( 'notify_plugin_deleted' ) === false && logliy_get_setting( 'notify_plugin_installed' ) === true, 'explicit per-event selection wins over legacy' );
	$settings = logliy_default_settings();
	$settings['notification_email'] = 'agent@floba-media.de';
	update_option( LOGLIY_OPT_SETTINGS, $settings, false );
	logliy_rate_limit_clear( 'security_mail_agent@floba-media.de' );
	foreach ( $events as $selected ) {
		$patch = array();
		foreach ( $events as $event ) { $patch['notify_plugin_' . $event] = $event === $selected; }
		logliy_update_settings( $patch );
		$mail = array();
		foreach ( $events as $event ) { logliy_security_event( 'plugin_' . $event, 0, 'example/example.php' ); }
		$assert( count( $mail ) === 1 && str_contains( $mail[0]['message'], logliy_security_event_labels()[ 'plugin_' . $selected ] ), 'only selected event notifies: ' . $selected );
	}
	$clean = logliy_sanitize_settings( array( '_logliy_form' => '1', 'notify_plugin_updated' => '1' ) );
	foreach ( $events as $event ) { $assert( $clean['notify_plugin_' . $event] === ( $event === 'updated' ), 'unchecked event clears on save: ' . $event ); }
	$import = logliy_settings_import_payload( array( 'plugin' => 'logliy', 'settings' => array( 'notify_plugin_changes' => true ) ) );
	$assert( $import === true && logliy_get_setting( 'notify_plugin_installed' ) && logliy_get_setting( 'notify_plugin_deleted' ), 'legacy export imports per-event preferences' );
} finally {
	update_option( LOGLIY_OPT_SETTINGS, $previous, false );
}
WP_CLI::success( $checks . ' notification preference checks passed. All mail intercepted.' );
