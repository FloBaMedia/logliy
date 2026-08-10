<?php
/**
 * Simple IP / account rate limiting via transients.
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
	$key   = 'logliy_rl_' . md5( $bucket );
	$data  = get_transient( $key );
	$count = 0;
	if ( is_array( $data ) && isset( $data['count'] ) ) {
		$count = (int) $data['count'];
	}

	if ( $count >= $limit ) {
		return new WP_Error(
			'logliy_rate_limited',
			__( 'Too many attempts. Please wait and try again.', 'logliy' ),
			array( 'status' => 429 )
		);
	}

	set_transient(
		$key,
		array(
			'count' => $count + 1,
			'start' => time(),
		),
		max( $window, 60 )
	);

	return true;
}

/**
 * Clear a rate-limit bucket.
 */
function logliy_rate_limit_clear( string $bucket ): void {
	delete_transient( 'logliy_rl_' . md5( $bucket ) );
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
