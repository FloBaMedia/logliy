<?php
/**
 * Uninstall cleanup for Logliy.
 *
 * @package Logliy
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$logliy_settings = get_option( 'logliy_settings', array() );
$logliy_wipe     = is_array( $logliy_settings ) && ! empty( $logliy_settings['delete_settings_on_uninstall'] );

// Keep all data unless the admin opted in to wipe on uninstall.
if ( ! $logliy_wipe ) {
	return;
}

global $wpdb;

$logliy_options = array(
	'logliy_settings',
	'logliy_plugin_version',
	'logliy_db_version',
	'logliy_wf_notice_dismissed',
);

foreach ( $logliy_options as $logliy_option ) {
	delete_option( $logliy_option );
}

$logliy_table = $wpdb->prefix . 'logliy_credentials';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall drops the plugin table; name is prefix + fixed slug.
$wpdb->query( "DROP TABLE IF EXISTS {$logliy_table}" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('logliy_allow_password','logliy_dismiss_passkey_nag')" );

// Best-effort transient cleanup.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_logliy_%' OR option_name LIKE '_transient_timeout_logliy_%'" );
