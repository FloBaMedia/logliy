<?php
/**
 * IP / account rate limiting.
 *
 * One atomic INSERT … ON DUPLICATE KEY UPDATE against logliy_rl.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check and increment a rate-limit bucket.
 *
 * @param string $bucket Unique bucket key (already sanitized).
 * @param int    $limit  Max hits in window.
 * @param int    $window Window in seconds.
 * @return true|WP_Error True if allowed.
 */
function logliy_rate_limit_hit( string $bucket, int $limit, int $window ) {
	$ttl     = max( $window, 1 );
	$limited = new WP_Error(
		'logliy_rate_limited',
		__( 'Too many attempts. Please wait and try again.', 'logliy' ),
		array( 'status' => 429 )
	);

	global $wpdb;
	$table = logliy_rate_limit_table();
	$hash  = md5( $bucket );
	$now   = time();
	$exp   = $now + $ttl;

	/*
	 * Atomic upsert against {$wpdb->prefix}logliy_rl (fixed plugin table).
	 * phpcs:disable kept open until all queries finish — an early phpcs:enable
	 * inside a branch is treated as unconditional by Plugin Check.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$ok = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (bucket_hash, cnt, expires) VALUES (%s, LAST_INSERT_ID(1), %d)
			ON DUPLICATE KEY UPDATE
				cnt = LAST_INSERT_ID(IF(expires <= %d, 1, LEAST(cnt + 1, 4294967295))),
				expires = IF(expires <= %d, %d, expires)",
			$hash,
			$exp,
			$now,
			$now,
			$exp
		)
	);

	$count = 0;
	if ( false !== $ok ) {
		$count = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

		if ( 1 === wp_rand( 1, 200 ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE expires <= %d",
					$now
				)
			);
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

	// Table missing / DB error — fail closed for auth endpoints.
	if ( false === $ok || $count < 1 || $count > $limit ) {
		return $limited;
	}

	return true;
}

/**
 * Clear a rate-limit bucket.
 */
function logliy_rate_limit_clear( string $bucket ): void {
	$key = 'logliy_rl_' . md5( $bucket );
	delete_transient( $key );
	if ( wp_using_ext_object_cache() ) {
		wp_cache_delete( $key, 'logliy_rl' );
	}
	global $wpdb;
	$table = logliy_rate_limit_table();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table via $wpdb->delete().
	$wpdb->delete( $table, array( 'bucket_hash' => md5( $bucket ) ), array( '%s' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

/**
 * Per-request cooldown (minimum seconds between email login requests).
 *
 * Shared across Email OTP and Magic Link for the same bucket.
 *
 * @param string $bucket Unique bucket (e.g. email_ip_1.2.3.4 or email_user_12).
 * @return true|WP_Error
 */
function logliy_email_request_cooldown( string $bucket ) {
	$seconds = (int) logliy_get_setting( 'email_request_cooldown_seconds', 60 );
	if ( $seconds <= 0 ) {
		return true;
	}

	$hit = logliy_rate_limit_hit( 'cooldown_' . $bucket, 1, $seconds );
	if ( is_wp_error( $hit ) ) {
		return new WP_Error( 'logliy_cooldown', __( 'Please wait before requesting another login email.', 'logliy' ), array( 'status' => 429, 'retry_after' => $seconds ) );
	}
	return true;
}

/** Shared account and site email budget for both email login methods. */
function logliy_email_account_limit( WP_User $user ) {
	$window = (int) logliy_get_setting( 'otp_rate_window_minutes', 15 ) * MINUTE_IN_SECONDS;
	$cooldown = logliy_email_request_cooldown( 'email_user_' . $user->ID );
	if ( is_wp_error( $cooldown ) ) {
		return $cooldown;
	}
	$account = logliy_rate_limit_hit( 'email_user_' . $user->ID, (int) logliy_get_setting( 'otp_rate_limit_account', 5 ), $window );
	if ( is_wp_error( $account ) ) {
		logliy_security_event( 'email_rate_limited', (int) $user->ID );
		return $account;
	}
	$site = logliy_rate_limit_hit( 'email_site', (int) logliy_get_setting( 'email_rate_limit_site', 100 ), $window );
	if ( is_wp_error( $site ) ) {
		logliy_security_event( 'email_rate_limited' );
		return $site;
	}
	return true;
}
