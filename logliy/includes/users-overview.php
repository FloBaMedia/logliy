<?php
/**
 * Standard users list: Passkeys + last login per user.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Count Passkeys per user (batch).
 *
 * @param list<int> $user_ids User IDs.
 * @return array<int, int> user_id => count
 */
function logliy_db_passkey_counts_for_users( array $user_ids ): array {
	$user_ids = array_values( array_filter( array_map( 'absint', $user_ids ) ) );
	if ( $user_ids === array() ) {
		return array();
	}
	global $wpdb;
	$table = logliy_credentials_table();
	$place = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom table; dynamic IN() placeholders for absint user IDs.
	$sql  = $wpdb->prepare(
		"SELECT user_id, COUNT(*) AS cnt FROM {$table} WHERE user_id IN ({$place}) GROUP BY user_id",
		...$user_ids
	);
	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql from $wpdb->prepare() above.
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$out = array_fill_keys( $user_ids, 0 );
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$out[ (int) $row['user_id'] ] = (int) $row['cnt'];
		}
	}
	return $out;
}


/** Add Logliy information to the standard WordPress users list. */
add_filter( 'manage_users_columns', 'logliy_users_columns' );
function logliy_users_columns( array $columns ): array {
	if ( current_user_can( 'manage_options' ) ) {
		$columns['logliy_passkeys'] = __( 'Passkeys', 'logliy' );
		$columns['logliy_last_login'] = __( 'Last login', 'logliy' );
	}
	return $columns;
}

add_filter( 'manage_users_custom_column', 'logliy_users_column_value', 10, 3 );
function logliy_users_column_value( string $output, string $column, int $user_id ): string {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $output;
	}
	if ( $column === 'logliy_passkeys' ) {
		// Batch the visible list rather than querying credentials once per row.
		global $wp_list_table;
		$ids = array( $user_id );
		if ( isset( $wp_list_table->items ) && is_array( $wp_list_table->items ) ) {
			$ids = array_map( static function ( $user ) { return (int) $user->ID; }, $wp_list_table->items );
		}
		static $counts = array();
		if ( ! array_key_exists( $user_id, $counts ) ) {
			$counts += logliy_db_passkey_counts_for_users( $ids );
		}
		return esc_html( (string) ( $counts[ $user_id ] ?? 0 ) );
	}
	if ( $column === 'logliy_last_login' ) {
		$last = (int) get_user_meta( $user_id, 'logliy_last_login', true );
		return $last > 0 ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) ) : esc_html__( 'Unknown', 'logliy' );
	}
	return $output;
}
