<?php
/**
 * Durable, single-use email challenges. Reads and conditional writes bypass caches.
 *
 * @package Logliy
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic authentication state has no cached WordPress equivalent.

/** Bind challenges to the current address without storing the address. */
function logliy_email_address_hash( WP_User $user ): string {
	return hash_hmac( 'sha256', strtolower( $user->user_email ), wp_salt( 'auth' ) );
}

/** Replace the previous challenge of the same method for this account. */
function logliy_email_challenge_store( WP_User $user, string $method, string $hash, int $ttl ): bool {
	global $wpdb;
	return false !== $wpdb->replace(
		$wpdb->prefix . 'logliy_email_challenges',
		array( 'user_id' => $user->ID, 'method' => $method, 'token_hash' => $hash, 'email_hash' => logliy_email_address_hash( $user ), 'expires' => time() + $ttl, 'attempts' => 0 ),
		array( '%d', '%s', '%s', '%s', '%d', '%d' )
	);
}

/** Delete only the exact challenge, preserving concurrent replacements. */
function logliy_email_challenge_delete( int $user_id, string $method, string $hash ): bool {
	global $wpdb;
	return 1 === $wpdb->delete( $wpdb->prefix . 'logliy_email_challenges', array( 'user_id' => $user_id, 'method' => $method, 'token_hash' => $hash ), array( '%d', '%s', '%s' ) );
}

/** Read a challenge by account or by high-entropy token hash. */
function logliy_email_challenge_get( string $method, int $user_id = 0, string $hash = '' ): ?array {
	global $wpdb;
	if ( $user_id > 0 ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE method = %s AND user_id = %d AND expires > %d", $wpdb->prefix . 'logliy_email_challenges', $method, $user_id, time() ), ARRAY_A );
	} else {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE method = %s AND token_hash = %s AND expires > %d", $wpdb->prefix . 'logliy_email_challenges', $method, $hash, time() ), ARRAY_A );
	}
	return is_array( $row ) ? $row : null;
}

/** Count an attempt atomically, without extending the original deadline. */
function logliy_email_challenge_attempt( WP_User $user, array $row ): bool {
	global $wpdb;
	return 1 === $wpdb->query( $wpdb->prepare(
		"UPDATE %i SET attempts = attempts + 1 WHERE user_id = %d AND method = %s AND token_hash = %s AND email_hash = %s AND expires > %d AND attempts < 5",
		$wpdb->prefix . 'logliy_email_challenges', $user->ID, $row['method'], $row['token_hash'], logliy_email_address_hash( $user ), time()
	) );
}

/** Only one concurrent request may consume this challenge. */
function logliy_email_challenge_consume( WP_User $user, array $row ): bool {
	global $wpdb;
	return 1 === $wpdb->query( $wpdb->prepare(
		"DELETE FROM %i WHERE user_id = %d AND method = %s AND token_hash = %s AND email_hash = %s AND expires > %d AND attempts <= 5",
		$wpdb->prefix . 'logliy_email_challenges', $user->ID, $row['method'], $row['token_hash'], logliy_email_address_hash( $user ), time()
	) );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
