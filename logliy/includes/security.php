<?php
/**
 * Security events and opt-in notifications. No addresses, IPs or secrets in logs.
 *
 * @package Logliy
 */
defined( 'ABSPATH' ) || exit;

/** Fixed event names also define the only text allowed in notifications. */
function logliy_security_event_labels(): array {
	return array(
		'login_success' => __( 'Login succeeded', 'logliy' ),
		'login_failed' => __( 'Login failed', 'logliy' ),
		'email_denied' => __( 'Email login denied by policy', 'logliy' ),
		'email_rate_limited' => __( 'Email request limit reached', 'logliy' ),
		'email_code_sent' => __( 'Login code sent', 'logliy' ),
		'email_link_sent' => __( 'Login link sent', 'logliy' ),
		'sso_denied' => __( 'SSO login denied', 'logliy' ),
		'passkey_added' => __( 'Passkey added', 'logliy' ),
		'passkey_removed' => __( 'Passkey removed', 'logliy' ),
		'sso_linked' => __( 'SSO identity linked', 'logliy' ),
		'sessions_revoked' => __( 'Sessions revoked', 'logliy' ),
		'account_changed' => __( 'Account password or email changed', 'logliy' ),
		'plugin_installed' => __( 'Plugin installed', 'logliy' ),
		'plugin_updated' => __( 'Plugin updated', 'logliy' ),
		'plugin_activated' => __( 'Plugin activated', 'logliy' ),
		'plugin_deactivated' => __( 'Plugin deactivated', 'logliy' ),
		'plugin_deleted' => __( 'Plugin deleted', 'logliy' ),
		'user_created' => __( 'User created', 'logliy' ),
		'user_deleted' => __( 'User deleted', 'logliy' ),
		'user_role_changed' => __( 'User role changed', 'logliy' ),
		'settings_changed' => __( 'Logliy settings changed', 'logliy' ),
		'notification_failed' => __( 'Security notification could not be sent', 'logliy' ),
		'notification_limited' => __( 'Security notification rate limit reached', 'logliy' ),
	);
}

/** Persist a bounded event, then send configured notifications independently. */
function logliy_security_event( string $event, int $user_id = 0, string $resource = '' ): void {
	if ( ! isset( logliy_security_event_labels()[ $event ] ) ) {
		return;
	}
	// Only plugin events may have a resource; never accept free-form log context.
	$resource = str_starts_with( $event, 'plugin_' ) ? substr( sanitize_text_field( $resource ), 0, 191 ) : '';
	if ( logliy_get_setting( 'security_log_enabled', true ) ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only plugin audit table, deliberately uncached.
		$ok = $wpdb->insert( $wpdb->prefix . 'logliy_security_events', array(
			'event' => $event, 'actor_id' => get_current_user_id(), 'user_id' => max( 0, $user_id ),
			'resource' => $resource, 'created_at' => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%d', '%d', '%s', '%s' ) );
		if ( false === $ok ) {
			set_transient( 'logliy_security_log_failed', 1, DAY_IN_SECONDS );
		} else {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $wpdb->prefix . 'logliy_security_events', (int) $wpdb->insert_id - 10000 ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	logliy_security_notify( $event, $user_id, $resource );
}

/** Send no login-attempt emails: hostile traffic must not create mail floods. */
function logliy_security_notify( string $event, int $user_id, string $resource ): void {
	$account = in_array( $event, array( 'passkey_added', 'passkey_removed', 'sso_linked', 'sessions_revoked', 'account_changed' ), true );
	if ( $account ) {
		$key = 'notify_account_security';
	} elseif ( str_starts_with( $event, 'plugin_' ) ) {
		$key = 'notify_' . $event;
	} elseif ( str_starts_with( $event, 'user_' ) ) {
		$key = 'notify_user_changes';
	} elseif ( $event === 'settings_changed' ) {
		$key = 'notify_settings_changes';
	} else {
		return;
	}
	if ( ! logliy_get_setting( $key, false ) ) {
		return;
	}
	$recipient = (string) logliy_get_setting( 'notification_email', '' );
	$recipient = $recipient !== '' ? $recipient : (string) get_option( 'admin_email' );
	$recipients = is_email( $recipient ) ? array( $recipient ) : array();
	if ( $account ) {
		$user = get_user_by( 'id', $user_id );
		if ( $user instanceof WP_User && is_email( $user->user_email ) ) {
			$recipients[] = $user->user_email;
		}
	}
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$label = logliy_security_event_labels()[ $event ];
	/* translators: %s: website name. */
	$subject = sprintf( __( '[Logliy] Security event on %s', 'logliy' ), $site );
	$subject = str_replace( array( "\r", "\n" ), '', $subject );
	/* translators: 1: event, 2: site URL, 3: UTC timestamp, 4: account ID, 5: plugin file. */
	$body = sprintf( __( "Event: %1\$s\nSite: %2\$s\nTime (UTC): %3\$s\nAccount ID: %4\$d\nPlugin: %5\$s\n\nIf you did not expect this change, contact your site administrator.", 'logliy' ), $label, home_url( '/' ), gmdate( 'Y-m-d H:i:s' ), $user_id, $resource );
	foreach ( array_unique( $recipients ) as $to ) {
		$hit = logliy_rate_limit_hit( 'security_mail_' . strtolower( $to ), 20, HOUR_IN_SECONDS );
		if ( is_wp_error( $hit ) ) {
			set_transient( 'logliy_security_mail_failed', 'limited', DAY_IN_SECONDS );
			logliy_security_event( 'notification_limited', $user_id );
			continue;
		}
		if ( ! wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) {
			set_transient( 'logliy_security_mail_failed', 'failed', DAY_IN_SECONDS );
			logliy_security_event( 'notification_failed', $user_id );
		}
	}
}

add_action( 'wp_login', 'logliy_security_login', 20, 2 );
function logliy_security_login( string $login, WP_User $user ): void {
	logliy_security_event( 'login_success', (int) $user->ID );
}
add_action( 'wp_login_failed', 'logliy_security_login_failed' );
function logliy_security_login_failed(): void {
	logliy_security_event( 'login_failed' );
}
add_action( 'user_register', 'logliy_security_user_created' );
function logliy_security_user_created( int $user_id ): void {
	logliy_security_event( 'user_created', $user_id );
}
add_action( 'deleted_user', 'logliy_security_user_deleted' );
function logliy_security_user_deleted( int $user_id ): void {
	logliy_security_event( 'user_deleted', $user_id );
}
add_action( 'set_user_role', 'logliy_security_role_changed', 10, 3 );
function logliy_security_role_changed( int $user_id, string $role, array $old_roles ): void {
	if ( $old_roles && $old_roles !== array( $role ) ) {
		logliy_security_event( 'user_role_changed', $user_id );
	}
}
add_action( 'add_user_role', 'logliy_security_role_added', 10, 2 );
add_action( 'remove_user_role', 'logliy_security_role_added', 10, 2 );
function logliy_security_role_added( int $user_id, string $role ): void {
	logliy_security_event( 'user_role_changed', $user_id );
}
add_action( 'profile_update', 'logliy_security_account_changed', 10, 2 );
function logliy_security_account_changed( int $user_id, WP_User $old ): void {
	$user = get_user_by( 'id', $user_id );
	if ( $user instanceof WP_User && ( $user->user_email !== $old->user_email || $user->user_pass !== $old->user_pass ) ) {
		logliy_security_event( 'account_changed', $user_id );
	}
}
add_action( 'after_password_reset', 'logliy_security_password_reset' );
function logliy_security_password_reset( WP_User $user ): void {
	logliy_security_event( 'account_changed', (int) $user->ID );
}
add_action( 'updated_option', 'logliy_security_settings_changed', 10, 3 );
function logliy_security_settings_changed( string $option, $old, $new ): void {
	if ( $option === LOGLIY_OPT_SETTINGS && $old !== $new ) {
		logliy_security_event( 'settings_changed' );
	}
}

add_action( 'activated_plugin', 'logliy_security_plugin_activated' );
function logliy_security_plugin_activated( string $plugin ): void {
	logliy_security_event( 'plugin_activated', 0, $plugin );
}
add_action( 'deactivated_plugin', 'logliy_security_plugin_deactivated' );
function logliy_security_plugin_deactivated( string $plugin ): void {
	logliy_security_event( 'plugin_deactivated', 0, $plugin );
}
add_action( 'deleted_plugin', 'logliy_security_plugin_deleted', 10, 2 );
function logliy_security_plugin_deleted( string $plugin, bool $deleted ): void {
	if ( $deleted ) {
		logliy_security_event( 'plugin_deleted', 0, $plugin );
	}
}

/** Per-package success hook also handles successful items in mixed bulk updates. */
add_filter( 'upgrader_post_install', 'logliy_security_plugin_installed', 20, 3 );
function logliy_security_plugin_installed( $response, array $extra, array $result ) {
	if ( is_wp_error( $response ) || ! $response || ( $extra['type'] ?? '' ) !== 'plugin' ) {
		return $response;
	}
	$action = $extra['action'] ?? '';
	if ( $action === 'install' || $action === 'update' ) {
		$resource = isset( $extra['plugin'] ) ? plugin_basename( $extra['plugin'] ) : sanitize_file_name( $result['destination_name'] ?? '' );
		logliy_security_event( $action === 'install' ? 'plugin_installed' : 'plugin_updated', 0, $resource );
	}
	return $response;
}

add_action( 'init', 'logliy_security_schedule_cleanup' );
function logliy_security_schedule_cleanup(): void {
	if ( ! wp_next_scheduled( 'logliy_security_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'logliy_security_cleanup' );
	}
}
add_action( 'logliy_security_cleanup', 'logliy_security_cleanup' );
function logliy_security_cleanup(): void {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) logliy_get_setting( 'security_log_retention_days', 30 ) * DAY_IN_SECONDS );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded plugin state cleanup.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $wpdb->prefix . 'logliy_security_events', $cutoff ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires <= %d', $wpdb->prefix . 'logliy_email_challenges', time() ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires <= %d', logliy_rate_limit_table(), time() ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
