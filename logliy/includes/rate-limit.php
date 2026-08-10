<?php
/**
 * IP / account rate limiting.
 *
 * Object cache: atomic wp_cache_incr.
 * Otherwise: one atomic INSERT … ON DUPLICATE KEY UPDATE against logliy_rl.
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
	$key     = 'logliy_rl_' . md5( $bucket );
	$ttl     = max( $window, 60 );
	$limited = new WP_Error(
		'logliy_rate_limited',
		__( 'Too many attempts. Please wait and try again.', 'logliy' ),
		array( 'status' => 429 )
	);

	if ( wp_using_ext_object_cache() ) {
		$group = 'logliy_rl';
		wp_cache_add( $key, 0, $group, $ttl );
		$count = wp_cache_incr( $key, 1, $group );
		if ( false === $count ) {
			wp_cache_set( $key, 1, $group, $ttl );
			$count = 1;
		}
		if ( $count > $limit ) {
			return $limited;
		}
		return true;
	}

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
			"INSERT INTO {$table} (bucket_hash, cnt, expires) VALUES (%s, 1, %d)
			ON DUPLICATE KEY UPDATE
				cnt = IF(expires < %d, 1, cnt + 1),
				expires = IF(expires < %d, %d, expires)",
			$hash,
			$exp,
			$now,
			$now,
			$exp
		)
	);

	$count = 0;
	if ( false !== $ok ) {
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cnt FROM {$table} WHERE bucket_hash = %s",
				$hash
			)
		);

		if ( 1 === wp_rand( 1, 200 ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE expires < %d",
					$now
				)
			);
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

	// Table missing / DB error — fail closed for auth endpoints.
	if ( false === $ok || $count > $limit ) {
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

	$key   = 'logliy_cd_' . md5( $bucket );
	$until = get_transient( $key );
	$now   = time();

	if ( is_numeric( $until ) && (int) $until > $now ) {
		$wait = (int) $until - $now;
		return new WP_Error(
			'logliy_cooldown',
			sprintf(
				/* translators: %d: seconds remaining */
				__( 'Please wait %d seconds before requesting another login email.', 'logliy' ),
				max( 1, $wait )
			),
			array(
				'status'      => 429,
				'retry_after' => max( 1, $wait ),
			)
		);
	}

	set_transient( $key, $now + $seconds, $seconds );
	return true;
}

/**
 * Mark cooldown after a successful email send (OTP / magic).
 * Prefer calling this only after a real send so failed attempts don't lock users out unnecessarily.
 * For spam protection on IP, call logliy_email_request_cooldown() before work instead.
 *
 * @param string $bucket Bucket key.
 */
function logliy_email_request_cooldown_touch( string $bucket ): void {
	$seconds = (int) logliy_get_setting( 'email_request_cooldown_seconds', 60 );
	if ( $seconds <= 0 ) {
		return;
	}
	set_transient( 'logliy_cd_' . md5( $bucket ), time() + $seconds, $seconds );
}
