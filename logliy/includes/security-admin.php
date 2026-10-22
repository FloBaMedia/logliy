<?php
/**
 * Security settings and audit viewer in the existing Logliy admin layout.
 *
 * @package Logliy
 */
defined( 'ABSPATH' ) || exit;

/** Render security fields using the existing settings form (no nested forms). */
function logliy_security_fields( array $settings, array $fields ): void {
	?><table class="form-table lg-form-table" role="presentation"><?php
	foreach ( $fields as $key => $field ) {
		[ $label, $type, $description ] = $field;
		$id = 'logliy_' . $key;
		$name = LOGLIY_OPT_SETTINGS . '[' . $key . ']';
		?><tr><th><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th><td><?php
		if ( $type === 'checkbox' ) {
			?><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> /><?php
		} elseif ( $type === 'textarea' ) {
			?><textarea class="large-text code" rows="5" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" aria-describedby="<?php echo esc_attr( $id . '_help' ); ?>"><?php echo esc_textarea( $settings[ $key ] ); ?></textarea><?php
		} else {
			?><input type="<?php echo esc_attr( $type ); ?>" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" aria-describedby="<?php echo esc_attr( $id . '_help' ); ?>" <?php if ( $type === 'number' ) { echo 'min="1"'; } ?> /><?php
		}
		?><p class="description" id="<?php echo esc_attr( $id . '_help' ); ?>"><?php echo esc_html( $description ); ?></p></td></tr><?php
	}
	?></table><?php
}

function logliy_email_policy_fields( array $settings ): void {
	logliy_security_fields( $settings, array(
		'email_allowlist_enabled' => array( __( 'Restrict email login', 'logliy' ), 'checkbox', __( 'Apply the list below to Email Code and Magic Link, including links and codes already sent. An enabled empty list blocks all email login.', 'logliy' ) ),
		'email_allowed_addresses' => array( __( 'Allowed email addresses and domains', 'logliy' ), 'textarea', __( 'One rule per line: @example.com, company-*@example.com or employee1@example.com. Domains are exact; wildcards are allowed only before @. Passkey and SSO are separate.', 'logliy' ) ),
		'email_rate_limit_site' => array( __( 'Max email requests / site', 'logliy' ), 'number', __( 'Shared across Email Code and Magic Link within the rate-limit window (1–10000). Account and IP limits are also shared across both methods.', 'logliy' ) ),
	) );
}
function logliy_sso_policy_fields( array $settings ): void {
	logliy_security_fields( $settings, array(
		'oidc_require_verified_email' => array( __( 'Require verified SSO email', 'logliy' ), 'checkbox', __( 'Recommended. The provider must supply email_verified: true. Providers without this claim are denied while this is enabled.', 'logliy' ) ),
		'oidc_allowlist_enabled' => array( __( 'Restrict SSO email addresses', 'logliy' ), 'checkbox', __( 'Check the provider email before linking or creating an account and on every SSO login. An enabled empty list denies all SSO login.', 'logliy' ) ),
		'oidc_allowed_addresses' => array( __( 'Allowed SSO addresses and domains', 'logliy' ), 'textarea', __( 'One address, @domain or local-part wildcard per line. This is separate from the Email Code / Magic Link list.', 'logliy' ) ),
		'oidc_groups_enabled' => array( __( 'Restrict SSO groups or roles', 'logliy' ), 'checkbox', __( 'Require at least one listed value in the signed ID token. An empty list or missing claim denies access. This does not grant WordPress roles.', 'logliy' ) ),
		'oidc_groups_claim' => array( __( 'Group / role claim', 'logliy' ), 'text', __( 'For example groups, roles or realm_access.roles. Matching is case-sensitive. Configure your provider to include this claim in the ID token.', 'logliy' ) ),
		'oidc_allowed_groups' => array( __( 'Allowed groups or roles', 'logliy' ), 'textarea', __( 'One exact group or role value per line. When email and group restrictions are enabled, both must pass.', 'logliy' ) ),
	) );
}
function logliy_security_settings_fields( array $settings ): void {
	logliy_security_fields( $settings, array(
		'security_log_enabled' => array( __( 'Enable security log', 'logliy' ), 'checkbox', __( 'Records event types, UTC time, actor/account IDs and plugin file names. No raw IP addresses, email addresses, codes, links or tokens. Stores at most 10000 events.', 'logliy' ) ),
		'security_log_retention_days' => array( __( 'Log retention (days)', 'logliy' ), 'number', __( '1–365 days. Expired entries are hidden immediately and deleted by hourly WordPress cron. Disabling logging does not erase existing entries.', 'logliy' ) ),
		'notification_email' => array( __( 'Administration notification email', 'logliy' ), 'email', __( 'Leave empty to use the WordPress administration email. Account security notifications also go to the affected account.', 'logliy' ) ),
		'notify_account_security' => array( __( 'Account security notifications', 'logliy' ), 'checkbox', __( 'Passkey added/removed, SSO identity linked, sessions revoked, account email/password changed. WordPress may send its own notices too.', 'logliy' ) ),
		'notify_plugin_installed' => array( __( 'Plugin installations', 'logliy' ), 'checkbox', __( 'Notify when a plugin is successfully installed.', 'logliy' ) ),
		'notify_plugin_updated' => array( __( 'Plugin updates', 'logliy' ), 'checkbox', __( 'Notify when a plugin is successfully updated, including automatic updates.', 'logliy' ) ),
		'notify_plugin_activated' => array( __( 'Plugin activations', 'logliy' ), 'checkbox', __( 'Notify when a plugin is activated.', 'logliy' ) ),
		'notify_plugin_deactivated' => array( __( 'Plugin deactivations', 'logliy' ), 'checkbox', __( 'Notify when a plugin is deactivated.', 'logliy' ) ),
		'notify_plugin_deleted' => array( __( 'Plugin deletions', 'logliy' ), 'checkbox', __( 'Notify when a plugin is successfully deleted.', 'logliy' ) ),
		'notify_user_changes' => array( __( 'User notifications', 'logliy' ), 'checkbox', __( 'New users, deleted users and role changes. Sends to the administration recipient.', 'logliy' ) ),
		'notify_settings_changes' => array( __( 'Settings notifications', 'logliy' ), 'checkbox', __( 'Changes to Logliy settings. Notifications use the configuration after the change.', 'logliy' ) ),
	) );
	?><p class="description"><?php echo esc_html__( 'Notifications are off by default and limited to 20 messages per recipient per hour. Login failures do not send email. Delivery failures and suppressed messages appear in the security log. Logliy must be active to observe events.', 'logliy' ); ?></p><?php
}

/** Read-only, capability-protected and paginated audit log. */
function logliy_render_security_log(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
	$page = isset( $_GET['log_page'] ) ? max( 1, min( 200, absint( $_GET['log_page'] ) ) ) : 1;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) logliy_get_setting( 'security_log_retention_days', 30 ) * DAY_IN_SECONDS );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live audit data is intentionally uncached.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE created_at >= %s ORDER BY id DESC LIMIT 51 OFFSET %d', $wpdb->prefix . 'logliy_security_events', $cutoff, ( $page - 1 ) * 50 ), ARRAY_A );
	$labels = logliy_security_event_labels();
	?><div class="lg-card"><h2><?php echo esc_html__( 'Security log', 'logliy' ); ?></h2>
	<?php if ( ! is_array( $rows ) ) : ?>
		<p role="alert"><?php echo esc_html__( 'The security log could not be read. Check the database connection and plugin tables.', 'logliy' ); ?></p>
	<?php else : ?>
	<div class="lg-security-table"><table class="widefat striped">
	<thead><tr><th><?php echo esc_html__( 'Time (UTC)', 'logliy' ); ?></th><th><?php echo esc_html__( 'Event', 'logliy' ); ?></th><th><?php echo esc_html__( 'Actor ID', 'logliy' ); ?></th><th><?php echo esc_html__( 'Account ID', 'logliy' ); ?></th><th><?php echo esc_html__( 'Plugin', 'logliy' ); ?></th></tr></thead><tbody>
	<?php foreach ( array_slice( $rows, 0, 50 ) as $row ) : ?>
		<tr><td><?php echo esc_html( $row['created_at'] ); ?></td><td><?php echo esc_html( $labels[ $row['event'] ] ?? $row['event'] ); ?></td><td><?php echo esc_html( $row['actor_id'] ); ?></td><td><?php echo esc_html( $row['user_id'] ); ?></td><td><?php echo esc_html( $row['resource'] ); ?></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $rows ) : ?><tr><td colspan="5"><?php echo esc_html__( 'No security events in this retention period.', 'logliy' ); ?></td></tr><?php endif; ?>
	</tbody></table></div>
	<p>
	<?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'log_page', $page - 1 ) ); ?>"><?php echo esc_html__( 'Newer events', 'logliy' ); ?></a><?php endif; ?>
	<?php if ( count( $rows ) > 50 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'log_page', $page + 1 ) ); ?>"><?php echo esc_html__( 'Older events', 'logliy' ); ?></a><?php endif; ?>
	</p>
	<?php endif; ?></div><?php
}

add_action( 'admin_notices', 'logliy_security_admin_notices' );
function logliy_security_admin_notices(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only controls notice visibility.
	if ( ! current_user_can( 'manage_options' ) || ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) !== LOGLIY_ADMIN_PAGE ) {
		return;
	}
	if ( get_transient( 'logliy_security_log_failed' ) || get_transient( 'logliy_security_mail_failed' ) ) {
		?><div class="notice notice-warning"><p><?php echo esc_html__( 'A security log write or notification failed, or a notification reached the hourly limit within the last 24 hours. Check the database and email delivery, and review the security log.', 'logliy' ); ?></p></div><?php
	}
}
