<?php
/**
 * Session policy: cookie lifetime, admin idle timeout, logout everywhere.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Track last login timestamp.
 *
 * @param string  $user_login Login.
 * @param WP_User $user       User.
 */
add_action( 'wp_login', 'logliy_track_last_login', 10, 2 );
function logliy_track_last_login( string $user_login, $user ): void {
	unset( $user_login );
	if ( $user instanceof WP_User ) {
		update_user_meta( (int) $user->ID, 'logliy_last_login', time() );
		update_user_meta( (int) $user->ID, 'logliy_last_activity', time() );
	}
}

/**
 * Auth cookie expiration from settings.
 *
 * @param int  $length   Length in seconds.
 * @param int  $user_id  User ID.
 * @param bool $remember Remember me.
 */
add_filter( 'auth_cookie_expiration', 'logliy_filter_auth_cookie_expiration', 20, 3 );
function logliy_filter_auth_cookie_expiration( int $length, int $user_id, bool $remember ): int {
	unset( $user_id );
	if ( $remember ) {
		$days = (int) logliy_get_setting( 'session_remember_days', 14 );
		return max( DAY_IN_SECONDS, $days * DAY_IN_SECONDS );
	}
	$hours = (int) logliy_get_setting( 'session_expire_hours', 48 );
	return max( HOUR_IN_SECONDS, $hours * HOUR_IN_SECONDS );
}

/**
 * Touch last activity for idle timeout (admin).
 */
add_action( 'admin_init', 'logliy_session_touch_activity', 1 );
add_action( 'wp_loaded', 'logliy_session_idle_check', 20 );

function logliy_session_touch_activity(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$idle = (int) logliy_get_setting( 'admin_idle_timeout_minutes', 0 );
	if ( $idle <= 0 ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	update_user_meta( get_current_user_id(), 'logliy_last_activity', time() );
}

/**
 * Log out admins after idle timeout.
 */
function logliy_session_idle_check(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$idle = (int) logliy_get_setting( 'admin_idle_timeout_minutes', 0 );
	if ( $idle <= 0 ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Don't idle-kick during login/AJAX heartbeat noise on login screen.
	if ( function_exists( 'login_header' ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}

	$user_id  = get_current_user_id();
	$last     = (int) get_user_meta( $user_id, 'logliy_last_activity', true );
	$limit    = $idle * MINUTE_IN_SECONDS;
	$now      = time();

	if ( $last <= 0 ) {
		update_user_meta( $user_id, 'logliy_last_activity', $now );
		return;
	}

	if ( ( $now - $last ) > $limit ) {
		wp_destroy_current_session();
		wp_clear_auth_cookie();
		wp_safe_redirect( wp_login_url() );
		exit;
	}

	// Refresh on front-end requests too for admins browsing the site.
	if ( ! is_admin() ) {
		update_user_meta( $user_id, 'logliy_last_activity', $now );
	}
}

/**
 * Profile: destroy all other sessions (logout everywhere).
 *
 * @param WP_User $user User.
 */
add_action( 'show_user_profile', 'logliy_session_profile_section', 30 );
add_action( 'edit_user_profile', 'logliy_session_profile_section', 30 );
function logliy_session_profile_section( WP_User $user ): void {
	$self = get_current_user_id() === (int) $user->ID;
	if ( ! $self && ! current_user_can( 'edit_users' ) ) {
		return;
	}
	$last = (int) get_user_meta( (int) $user->ID, 'logliy_last_login', true );
	?>
	<h2><?php echo esc_html__( 'Logliy sessions', 'logliy' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><?php echo esc_html__( 'Last login', 'logliy' ); ?></th>
			<td>
				<?php
				echo $last > 0
					? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) )
					: esc_html__( 'Unknown', 'logliy' );
				?>
			</td>
		</tr>
		<?php if ( $self ) : ?>
		<tr>
			<th><?php echo esc_html__( 'Logout everywhere', 'logliy' ); ?></th>
			<td>
				<?php
				$url = wp_nonce_url(
					admin_url( 'profile.php?logliy_logout_all=1' ),
					'logliy_logout_all'
				);
				?>
				<a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html__( 'Sign out of all sessions', 'logliy' ); ?></a>
				<p class="description"><?php echo esc_html__( 'Ends every session for your account on all devices (including this one).', 'logliy' ); ?></p>
			</td>
		</tr>
		<?php endif; ?>
	</table>
	<?php
}

/**
 * Handle logout-everywhere action.
 */
add_action( 'admin_init', 'logliy_handle_logout_all' );
function logliy_handle_logout_all(): void {
	if ( empty( $_GET['logliy_logout_all'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}
	check_admin_referer( 'logliy_logout_all' );
	$user_id = get_current_user_id();
	$manager = WP_Session_Tokens::get_instance( $user_id );
	$manager->destroy_all();
	logliy_security_event( 'sessions_revoked', $user_id );
	wp_clear_auth_cookie();
	wp_safe_redirect( wp_login_url() );
	exit;
}

/** Revoke a stored verifier with compare-and-swap; never expose bearer tokens. */
function logliy_session_revoke( int $user_id, string $verifier ) {
	if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_user', $user_id ) ) {
		return new WP_Error( 'logliy_session_forbidden', __( 'You cannot manage these sessions.', 'logliy' ) );
	}
	$manager = WP_Session_Tokens::get_instance( $user_id );
	if ( $verifier === 'all' ) {
		$manager->destroy_all();
	} else {
		if ( ! $manager instanceof WP_User_Meta_Session_Tokens || ! preg_match( '/\A[a-f0-9]{64}\z/', $verifier ) ) {
			return new WP_Error( 'logliy_session_unsupported', __( 'This session cannot be revoked here. Use sign out of all sessions.', 'logliy' ) );
		}
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			wp_cache_delete( $user_id, 'user_meta' );
			$old = get_user_meta( $user_id, 'session_tokens', true );
			if ( ! is_array( $old ) || ! isset( $old[ $verifier ] ) ) {
				return new WP_Error( 'logliy_session_missing', __( 'Session not found. Refresh the page.', 'logliy' ) );
			}
			$new = $old;
			unset( $new[ $verifier ] );
			if ( update_user_meta( $user_id, 'session_tokens', $new, $old ) ) {
				break;
			}
		}
		if ( $attempt === 3 ) {
			return new WP_Error( 'logliy_session_conflict', __( 'Sessions changed. Refresh the page and try again.', 'logliy' ) );
		}
	}
	logliy_security_event( 'sessions_revoked', $user_id );
	return true;
}

/** Profile buttons submit a separate footer form, outside WordPress's form. */
add_action( 'show_user_profile', 'logliy_session_list', 31 );
add_action( 'edit_user_profile', 'logliy_session_list', 31 );
function logliy_session_list( WP_User $user ): void {
	if ( get_current_user_id() !== (int) $user->ID && ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}
	$GLOBALS['logliy_session_profile_user'] = (int) $user->ID;
	$manager = WP_Session_Tokens::get_instance( (int) $user->ID );
	$sessions = $manager instanceof WP_User_Meta_Session_Tokens ? get_user_meta( $user->ID, 'session_tokens', true ) : array();
	$sessions = is_array( $sessions ) ? $sessions : array();
	?>
	<h3><?php echo esc_html__( 'Active sessions', 'logliy' ); ?></h3>
	<p class="description"><?php echo esc_html__( 'Revoking the current session signs you out. Custom session storage supports revoking all sessions only.', 'logliy' ); ?></p>
	<table class="widefat striped">
		<thead><tr><th><?php echo esc_html__( 'Login (UTC)', 'logliy' ); ?></th><th><?php echo esc_html__( 'Expires (UTC)', 'logliy' ); ?></th><th><?php echo esc_html__( 'Action', 'logliy' ); ?></th></tr></thead>
		<tbody>
		<?php $count = 0; foreach ( $sessions as $verifier => $session ) : ?>
			<?php if ( ! is_array( $session ) || (int) ( $session['expiration'] ?? 0 ) <= time() ) { continue; } ++$count; ?>
			<tr>
				<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $session['login'] ?? 0 ) ) ); ?><?php if ( hash_equals( hash( 'sha256', wp_get_session_token() ), (string) $verifier ) ) { echo ' ' . esc_html__( '(current)', 'logliy' ); } ?></td>
				<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $session['expiration'] ) ); ?></td>
				<td><button type="submit" class="button" form="logliy-session-actions" name="session" value="<?php echo esc_attr( (string) $verifier ); ?>"><?php echo esc_html__( 'Revoke session', 'logliy' ); ?></button></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( $count === 0 ) : ?><tr><td colspan="3"><?php echo esc_html__( 'No sessions available to display.', 'logliy' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>
	<p><button type="submit" class="button" form="logliy-session-actions" name="session" value="all"><?php echo esc_html__( 'Revoke all sessions', 'logliy' ); ?></button></p>
	<?php
}
add_action( 'admin_footer', 'logliy_session_action_form' );
function logliy_session_action_form(): void {
	$user_id = (int) ( $GLOBALS['logliy_session_profile_user'] ?? 0 );
	if ( $user_id <= 0 ) {
		return;
	}
	?>
	<form id="logliy-session-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="logliy_revoke_sessions" />
		<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
		<?php wp_nonce_field( 'logliy_revoke_sessions_' . $user_id ); ?>
	</form>
	<?php
}
add_action( 'admin_post_logliy_revoke_sessions', 'logliy_handle_revoke_sessions' );
function logliy_handle_revoke_sessions(): void {
	if ( ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) !== 'post' ) {
		wp_die( esc_html__( 'Use the session management form.', 'logliy' ), 405 );
	}
	$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	check_admin_referer( 'logliy_revoke_sessions_' . $user_id );
	$session = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
	$result = logliy_session_revoke( $user_id, $session );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), 403 );
	}
	if ( get_current_user_id() === $user_id && ( $session === 'all' || hash_equals( hash( 'sha256', wp_get_session_token() ), $session ) ) ) {
		wp_clear_auth_cookie();
		wp_safe_redirect( wp_login_url() );
	} else {
		wp_safe_redirect( get_current_user_id() === $user_id ? admin_url( 'profile.php' ) : add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) ) );
	}
	exit;
}
