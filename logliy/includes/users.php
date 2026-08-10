<?php
/**
 * User profile Passkey management + per-user password allow.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue profile scripts.
 *
 * @param string $hook Hook suffix.
 */
add_action( 'admin_enqueue_scripts', 'logliy_profile_assets' );
function logliy_profile_assets( string $hook ): void {
	if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
		return;
	}
	wp_enqueue_style( 'logliy-admin', LOGLIY_PLUGIN_URL . 'assets/css/admin.css', array(), LOGLIY_VERSION );
	wp_enqueue_script( 'logliy-passkey', LOGLIY_PLUGIN_URL . 'assets/js/passkey.js', array(), LOGLIY_VERSION, true );
	wp_enqueue_script( 'logliy-profile', LOGLIY_PLUGIN_URL . 'assets/js/profile.js', array( 'logliy-passkey' ), LOGLIY_VERSION, true );
	wp_localize_script(
		'logliy-profile',
		'logliyProfile',
		array(
			'restUrl' => esc_url_raw( rest_url( 'logliy/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'register' => __( 'Register Passkey', 'logliy' ),
				'rename'   => __( 'Rename', 'logliy' ),
				'remove'   => __( 'Remove', 'logliy' ),
				'working'  => __( 'Please wait…', 'logliy' ),
				'success'  => __( 'Passkey saved.', 'logliy' ),
				'fail'     => __( 'Passkey registration failed or was cancelled.', 'logliy' ),
				'confirmDelete' => __( 'Remove this Passkey?', 'logliy' ),
				'removed'       => __( 'Passkey removed.', 'logliy' ),
				'renamed'       => __( 'Passkey renamed.', 'logliy' ),
				'empty'         => __( 'No Passkeys registered yet.', 'logliy' ),
			),
		)
	);
}

/**
 * Profile fields for current / edited user.
 *
 * @param WP_User $user User.
 */
add_action( 'show_user_profile', 'logliy_user_profile_section' );
add_action( 'edit_user_profile', 'logliy_user_profile_section' );
function logliy_user_profile_section( WP_User $user ): void {
	$can_edit_pass = current_user_can( 'edit_users' ) || get_current_user_id() === (int) $user->ID;
	$creds         = logliy_db_get_credentials_for_user( (int) $user->ID );
	$allow_pw      = logliy_user_password_allowed( (int) $user->ID );
	$self          = get_current_user_id() === (int) $user->ID;
	?>
	<h2><?php echo esc_html__( 'Logliy – LoginProtect', 'logliy' ); ?></h2>
	<table class="form-table logliy-profile-table" role="presentation">
		<tr>
			<th><label for="logliy_allow_password"><?php echo esc_html__( 'Password login', 'logliy' ); ?></label></th>
			<td>
				<?php if ( current_user_can( 'edit_users' ) ) : ?>
					<label>
						<input type="checkbox" name="logliy_allow_password" id="logliy_allow_password" value="1" <?php checked( $allow_pw ); ?> />
						<?php echo esc_html__( 'Allow password login for this user', 'logliy' ); ?>
					</label>
				<?php else : ?>
					<p class="description"><?php echo $allow_pw || logliy_password_allowed_for_user( $user ) ? esc_html__( 'Password login is allowed for your account.', 'logliy' ) : esc_html__( 'Password login is disabled for your account.', 'logliy' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php if ( $self && logliy_get_setting( 'enable_passkey', true ) ) : ?>
		<tr id="logliy-passkeys">
			<th><?php echo esc_html__( 'Passkeys', 'logliy' ); ?></th>
			<td>
				<div class="logliy-passkey-list" data-logliy-passkey-list>
					<?php if ( $creds === array() ) : ?>
						<p class="description"><?php echo esc_html__( 'No Passkeys registered yet.', 'logliy' ); ?></p>
					<?php else : ?>
						<ul class="logliy-cred-list">
							<?php foreach ( $creds as $row ) : ?>
								<li data-id="<?php echo esc_attr( (string) $row['id'] ); ?>">
									<span class="logliy-cred-name"><?php echo esc_html( (string) $row['name'] ); ?></span>
									<span class="logliy-cred-meta"><?php echo esc_html( sprintf( /* translators: %s: datetime */ __( 'Added %s', 'logliy' ), $row['created_at'] ) ); ?></span>
									<button type="button" class="button-link" data-logliy-rename><?php echo esc_html__( 'Rename', 'logliy' ); ?></button>
									<button type="button" class="button-link-delete" data-logliy-delete><?php echo esc_html__( 'Remove', 'logliy' ); ?></button>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<p>
					<button type="button" class="button button-secondary" data-logliy-register-passkey><?php echo esc_html__( 'Register Passkey', 'logliy' ); ?></button>
				</p>
				<p class="description"><?php echo esc_html__( 'You can register multiple Passkeys (phone, laptop, security key) and remove any of them anytime. Passkeys require HTTPS and a compatible browser/device.', 'logliy' ); ?></p>
				<div class="logliy-profile-msg" data-logliy-profile-msg hidden></div>
			</td>
		</tr>
		<?php endif; ?>
	</table>
	<?php
	unset( $can_edit_pass );
}

/**
 * Save per-user password allow flag.
 *
 * @param int $user_id User ID.
 */
add_action( 'personal_options_update', 'logliy_save_user_profile' );
add_action( 'edit_user_profile_update', 'logliy_save_user_profile' );
function logliy_save_user_profile( int $user_id ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core profile nonce already checked.
	$allow = isset( $_POST['logliy_allow_password'] ) && $_POST['logliy_allow_password'] === '1' ? '1' : '0';
	update_user_meta( $user_id, 'logliy_allow_password', $allow );
}

/**
 * Whether the current user should see the "add a Passkey" admin nag.
 */
function logliy_should_show_passkey_nag(): bool {
	if ( ! is_admin() || ! is_user_logged_in() ) {
		return false;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( ! logliy_get_setting( 'enable_passkey', true ) ) {
		return false;
	}
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return false;
	}
	if ( get_user_meta( $user_id, 'logliy_dismiss_passkey_nag', true ) === '1' ) {
		return false;
	}
	return ! logliy_user_has_passkey( $user_id );
}

/**
 * Handle dismiss of the Passkey nag.
 */
add_action( 'admin_init', 'logliy_handle_dismiss_passkey_nag' );
function logliy_handle_dismiss_passkey_nag(): void {
	if ( ! isset( $_GET['logliy_dismiss_passkey_nag'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'logliy_dismiss_passkey_nag' );
	update_user_meta( get_current_user_id(), 'logliy_dismiss_passkey_nag', '1' );
	$redirect = remove_query_arg( array( 'logliy_dismiss_passkey_nag', '_wpnonce' ) );
	wp_safe_redirect( $redirect !== '' ? $redirect : admin_url() );
	exit;
}

/**
 * Admin banner: encourage administrators to register a Passkey.
 */
add_action( 'admin_notices', 'logliy_passkey_admin_nag' );
function logliy_passkey_admin_nag(): void {
	if ( ! logliy_should_show_passkey_nag() ) {
		return;
	}

	$profile = admin_url( 'profile.php#logliy-passkeys' );
	$dismiss = wp_nonce_url(
		add_query_arg( 'logliy_dismiss_passkey_nag', '1' ),
		'logliy_dismiss_passkey_nag'
	);

	echo '<div class="notice notice-warning logliy-passkey-nag"><p>';
	echo '<strong>' . esc_html__( 'Logliy', 'logliy' ) . ':</strong> ';
	echo esc_html__( 'You do not have a Passkey yet. Administrators should register one so they can still sign in when password login is disabled.', 'logliy' );
	echo ' <a href="' . esc_url( $profile ) . '"><strong>' . esc_html__( 'Add a Passkey', 'logliy' ) . '</strong></a>';
	echo ' · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'logliy' ) . '</a>';
	echo '</p></div>';
}
