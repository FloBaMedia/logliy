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
	wp_clear_auth_cookie();
	wp_safe_redirect( wp_login_url() );
	exit;
}
