<?php
/**
 * Database helpers for passkey credentials.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Credentials table name.
 */
function logliy_credentials_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'logliy_credentials';
}

/**
 * Create / upgrade credentials table.
 */
function logliy_db_install(): void {
	global $wpdb;

	$table           = logliy_credentials_table();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		credential_id varchar(255) NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		public_key longblob NOT NULL,
		sign_count bigint(20) unsigned NOT NULL DEFAULT 0,
		name varchar(191) NOT NULL DEFAULT '',
		transports text NULL,
		aaguid varchar(36) NOT NULL DEFAULT '',
		attestation_type varchar(64) NOT NULL DEFAULT 'none',
		trust_path longtext NULL,
		backup_eligible tinyint(1) NULL,
		backup_status tinyint(1) NULL,
		uv_initialized tinyint(1) NULL,
		created_at datetime NOT NULL,
		last_used_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY credential_id (credential_id),
		KEY user_id (user_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'logliy_db_version', LOGLIY_DB_VERSION, false );
}

/**
 * Base64url encode binary.
 */
function logliy_b64u_encode( string $bin ): string {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

/**
 * Base64url decode.
 */
function logliy_b64u_decode( string $b64 ): string {
	$pad = 4 - ( strlen( $b64 ) % 4 );
	if ( $pad < 4 ) {
		$b64 .= str_repeat( '=', $pad );
	}
	$raw = base64_decode( strtr( $b64, '-_', '+/' ), true );
	return $raw === false ? '' : $raw;
}

/**
 * Insert a credential row.
 *
 * @param array<string, mixed> $data Row data.
 * @return int|false Insert ID or false.
 */
function logliy_db_insert_credential( array $data ) {
	global $wpdb;

	$transports = isset( $data['transports'] ) && is_array( $data['transports'] )
		? wp_json_encode( array_values( $data['transports'] ) )
		: '[]';

	$now = current_time( 'mysql', true );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom credentials table; no WP API equivalent.
	$result = $wpdb->insert(
		logliy_credentials_table(),
		array(
			'credential_id'    => (string) $data['credential_id'],
			'user_id'          => (int) $data['user_id'],
			'public_key'       => (string) $data['public_key'],
			'sign_count'       => (int) ( $data['sign_count'] ?? 0 ),
			'name'             => (string) ( $data['name'] ?? __( 'Passkey', 'logliy' ) ),
			'transports'       => $transports,
			'aaguid'           => (string) ( $data['aaguid'] ?? '' ),
			'attestation_type' => (string) ( $data['attestation_type'] ?? 'none' ),
			'trust_path'       => isset( $data['trust_path'] ) ? (string) $data['trust_path'] : null,
			'backup_eligible'  => isset( $data['backup_eligible'] ) ? (int) (bool) $data['backup_eligible'] : null,
			'backup_status'    => isset( $data['backup_status'] ) ? (int) (bool) $data['backup_status'] : null,
			'uv_initialized'   => isset( $data['uv_initialized'] ) ? (int) (bool) $data['uv_initialized'] : null,
			'created_at'       => $now,
			'last_used_at'     => null,
		),
		array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
	);

	if ( $result ) {
		$insert_id = (int) $wpdb->insert_id;
		logliy_db_flush_user_credential_cache( (int) $data['user_id'] );
		return $insert_id;
	}

	return false;
}

/**
 * Find credential by base64url credential id.
 *
 * @return array<string, mixed>|null
 */
function logliy_db_get_credential_by_id( string $credential_id_b64u ): ?array {
	global $wpdb;
	$table     = logliy_credentials_table();
	$cache_key = 'cred_' . md5( $credential_id_b64u );
	$cached    = wp_cache_get( $cache_key, 'logliy_credentials' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; cached above.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE credential_id = %s LIMIT 1", $credential_id_b64u ), ARRAY_A );
	if ( is_array( $row ) ) {
		wp_cache_set( $cache_key, $row, 'logliy_credentials', 5 * MINUTE_IN_SECONDS );
		return $row;
	}
	return null;
}

/**
 * Credentials for a user.
 *
 * @return list<array<string, mixed>>
 */
function logliy_db_get_credentials_for_user( int $user_id ): array {
	global $wpdb;
	$cache_key = 'user_' . $user_id;
	$cached    = wp_cache_get( $cache_key, 'logliy_credentials' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$table = logliy_credentials_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; cached above.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id ), ARRAY_A );
	$rows = is_array( $rows ) ? $rows : array();
	wp_cache_set( $cache_key, $rows, 'logliy_credentials', 5 * MINUTE_IN_SECONDS );
	return $rows;
}

/**
 * Whether a user has at least one Passkey.
 */
function logliy_user_has_passkey( int $user_id ): bool {
	return logliy_db_get_credentials_for_user( $user_id ) !== array();
}

/**
 * Flush credential caches for a user.
 */
function logliy_db_flush_user_credential_cache( int $user_id ): void {
	wp_cache_delete( 'user_' . $user_id, 'logliy_credentials' );
}

/**
 * Update sign count and last used.
 */
function logliy_db_touch_credential( int $id, int $sign_count, ?bool $backup_eligible = null, ?bool $backup_status = null ): void {
	global $wpdb;
	$data = array(
		'sign_count'   => $sign_count,
		'last_used_at' => current_time( 'mysql', true ),
	);
	$format = array( '%d', '%s' );
	if ( $backup_eligible !== null ) {
		$data['backup_eligible'] = (int) $backup_eligible;
		$format[]                = '%d';
	}
	if ( $backup_status !== null ) {
		$data['backup_status'] = (int) $backup_status;
		$format[]              = '%d';
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom credentials table write.
	$wpdb->update( logliy_credentials_table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
}

/**
 * Rename credential (must belong to user).
 */
function logliy_db_rename_credential( int $id, int $user_id, string $name ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom credentials table write.
	$result = $wpdb->update(
		logliy_credentials_table(),
		array( 'name' => $name ),
		array(
			'id'      => $id,
			'user_id' => $user_id,
		),
		array( '%s' ),
		array( '%d', '%d' )
	);
	if ( $result !== false ) {
		logliy_db_flush_user_credential_cache( $user_id );
		return true;
	}
	return false;
}

/**
 * Delete credential (must belong to user unless admin override).
 */
function logliy_db_delete_credential( int $id, int $user_id ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom credentials table write.
	$result = $wpdb->delete(
		logliy_credentials_table(),
		array(
			'id'      => $id,
			'user_id' => $user_id,
		),
		array( '%d', '%d' )
	);
	if ( (int) $result > 0 ) {
		logliy_db_flush_user_credential_cache( $user_id );
		return true;
	}
	return false;
}

/**
 * Drop table (uninstall).
 */
function logliy_db_drop(): void {
	global $wpdb;
	$table = logliy_credentials_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall/drop of plugin table.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
