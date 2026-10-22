<?php
/** Concurrent workers for a disposable WP installation; see tests/README.md. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
global $wpdb;
$mode = $args[0] ?? '';
$user = get_user_by( 'login', 'logliy-concurrency-test' );
if ( $mode === 'setup' ) {
	if ( $user ) { throw new RuntimeException( 'Use a clean disposable test account.' ); }
	add_filter( 'pre_wp_mail', '__return_true' );
	logliy_update_settings( array( 'notify_account_security' => false, 'notify_user_changes' => false, 'notify_settings_changes' => false ) );
	$id = wp_insert_user( array( 'user_login' => 'logliy-concurrency-test', 'user_email' => 'concurrency@example.invalid', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$user = get_user_by( 'id', $id );
	logliy_email_challenge_store( $user, 'magic', str_repeat( 'a', 64 ), 600 );
	logliy_email_challenge_store( $user, 'otp', str_repeat( 'b', 64 ), 600 );
	logliy_rate_limit_clear( 'concurrency-test' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE bucket_hash LIKE %s', logliy_rate_limit_table(), 'test-result-%' ) );
	return;
}
if ( ! $user ) { throw new RuntimeException( 'Run setup first.' ); }
if ( in_array( $mode, array( 'consume', 'attempt', 'rate' ), true ) ) {
	if ( $mode === 'consume' ) {
		// All workers deliberately carry the same previously read challenge.
		$row = array( 'method' => 'magic', 'token_hash' => str_repeat( 'a', 64 ) );
		$ok = logliy_email_challenge_consume( $user, $row );
	} elseif ( $mode === 'attempt' ) {
		$row = array( 'method' => 'otp', 'token_hash' => str_repeat( 'b', 64 ) );
		$ok = logliy_email_challenge_attempt( $user, $row );
	} else {
		$ok = ! is_wp_error( logliy_rate_limit_hit( 'concurrency-test', 5, 600 ) );
	}
	if ( $ok ) {
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (bucket_hash, cnt, expires) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE cnt=cnt+1', logliy_rate_limit_table(), 'test-result-' . $mode, time() + 600 ) );
	}
	return;
}
if ( $mode === 'verify' ) {
	foreach ( array( 'consume' => 1, 'attempt' => 5, 'rate' => 5 ) as $kind => $expected ) {
		$actual = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT cnt FROM %i WHERE bucket_hash = %s', logliy_rate_limit_table(), 'test-result-' . $kind ) );
		if ( $actual !== $expected ) { throw new RuntimeException( "$kind: expected $expected, got $actual" ); }
	}
	WP_CLI::success( '20 concurrent workers per operation: one consume, five attempts, five allowed rate hits.' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user->ID );
}
