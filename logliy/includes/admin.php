<?php
/**
 * Admin settings UI (Settings → Logliy).
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register settings page.
 */
add_action( 'admin_menu', 'logliy_admin_menu' );
function logliy_admin_menu(): void {
	add_options_page(
		__( 'Logliy', 'logliy' ),
		__( 'Logliy', 'logliy' ),
		'manage_options',
		LOGLIY_ADMIN_PAGE,
		'logliy_settings_page'
	);
}

/**
 * Register setting.
 */
add_action( 'admin_init', 'logliy_register_settings' );
function logliy_register_settings(): void {
	register_setting(
		'logliy_group',
		LOGLIY_OPT_SETTINGS,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'logliy_sanitize_settings',
			'default'           => logliy_default_settings(),
		)
	);
}

/**
 * Keep the active settings tab after options.php redirects.
 *
 * @param string $location Redirect URL.
 * @return string
 */
add_filter( 'wp_redirect', 'logliy_settings_keep_tab_redirect' );
function logliy_settings_keep_tab_redirect( string $location ): string {
	if ( ! is_admin() ) {
		return $location;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only reading our own hidden field after options.php nonce already passed.
	if ( empty( $_POST['logliy_active_tab'] ) || empty( $_POST['option_page'] ) || (string) $_POST['option_page'] !== 'logliy_group' ) {
		return $location;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$tab = sanitize_key( (string) wp_unslash( $_POST['logliy_active_tab'] ) );
	$allowed = array( 'general', 'redirects', 'passkeys', 'email_otp', 'woocommerce', 'users', 'advanced' );
	if ( ! in_array( $tab, $allowed, true ) ) {
		return $location;
	}
	return add_query_arg( 'tab', $tab, $location );
}

/**
 * Primary save button markup (top + bottom of settings form).
 *
 * @param string $extra_class Optional extra CSS class(es).
 */
function logliy_render_save_button( string $extra_class = '' ): void {
	$class = trim( 'button button-primary lg-save-btn ' . $extra_class );
	printf(
		'<p class="lg-save-row"><button type="submit" class="%1$s">%2$s</button></p>',
		esc_attr( $class ),
		esc_html__( 'Save settings', 'logliy' )
	);
}

/**
 * Import / Export card (own form — must not nest inside the settings form).
 */
function logliy_render_import_export_card(): void {
	?>
	<div class="lg-card">
		<h2><?php echo esc_html__( 'Import / Export', 'logliy' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Copy Logliy settings to another WordPress site. Passkeys and user data are not included. Logo / background images and Relying Party ID are reset (site-specific).', 'logliy' ); ?></p>
		<p>
			<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=logliy_export_settings' ), 'logliy_export_settings' ) ); ?>">
				<?php echo esc_html__( 'Export settings (JSON)', 'logliy' ); ?>
			</a>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="lg-import-form">
			<?php wp_nonce_field( 'logliy_import_settings' ); ?>
			<input type="hidden" name="action" value="logliy_import_settings" />
			<p>
				<label for="logliy_import_file" class="screen-reader-text"><?php echo esc_html__( 'Settings JSON file', 'logliy' ); ?></label>
				<input type="file" name="logliy_import_file" id="logliy_import_file" accept="application/json,.json" required />
			</p>
			<p>
				<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Replace all Logliy settings on this site with the import file? This overwrites branding, redirects, and security options. Only import files you trust.', 'logliy' ) ); ?>');">
					<?php echo esc_html__( 'Import settings', 'logliy' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Admin styles / scripts.
 *
 * @param string $hook Hook.
 */
add_action( 'admin_enqueue_scripts', 'logliy_admin_assets' );
function logliy_admin_assets( string $hook ): void {
	if ( $hook !== 'settings_page_' . LOGLIY_ADMIN_PAGE ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'logliy-admin', LOGLIY_PLUGIN_URL . 'assets/css/admin.css', array(), LOGLIY_VERSION );
	wp_enqueue_script( 'logliy-admin', LOGLIY_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), LOGLIY_VERSION, true );
}

/**
 * Redirect to settings after activation.
 */
add_action( 'admin_init', 'logliy_activation_redirect' );
function logliy_activation_redirect(): void {
	if ( ! get_transient( 'logliy_activation_redirect' ) ) {
		return;
	}
	delete_transient( 'logliy_activation_redirect' );
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=' . LOGLIY_ADMIN_PAGE ) );
	exit;
}

/**
 * Whether environment + admin Passkey setup is complete.
 */
function logliy_onboarding_is_complete(): bool {
	foreach ( logliy_environment_checks() as $check ) {
		if ( empty( $check['ok'] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Environment checks (incl. current admin Passkey when Passkeys are enabled).
 *
 * @return list<array{ok:bool,label:string}>
 */
function logliy_environment_checks(): array {
	$https_ok = logliy_is_https() || logliy_rp_id() === 'localhost';
	$ext_ok   = extension_loaded( 'openssl' ) && ( extension_loaded( 'gmp' ) || extension_loaded( 'bcmath' ) || extension_loaded( 'sodium' ) );
	$lib_ok   = logliy_passkey_available();
	$mail_ok  = function_exists( 'wp_mail' );

	$checks = array(
		array(
			'ok'    => $https_ok,
			'label' => $https_ok ? __( 'HTTPS is available (required for Passkeys).', 'logliy' ) : __( 'HTTPS is missing — Passkeys will not work in production.', 'logliy' ),
		),
		array(
			'ok'    => $lib_ok,
			'label' => $lib_ok ? __( 'WebAuthn library loaded.', 'logliy' ) : __( 'WebAuthn vendor library missing — run Composer install.', 'logliy' ),
		),
		array(
			'ok'    => $ext_ok,
			'label' => $ext_ok ? __( 'PHP crypto extensions look OK.', 'logliy' ) : __( 'Install openssl and gmp/bcmath/sodium for WebAuthn.', 'logliy' ),
		),
		array(
			'ok'    => $mail_ok,
			'label' => __( 'wp_mail is available for Email OTP (verify delivery with a test).', 'logliy' ),
		),
	);

	if ( logliy_get_setting( 'enable_passkey', true ) && is_user_logged_in() ) {
		$has_pk = logliy_user_has_passkey( get_current_user_id() );
		$checks[] = array(
			'ok'    => $has_pk,
			'label' => $has_pk
				? __( 'You have at least one Passkey registered.', 'logliy' )
				: __( 'Register a Passkey on your profile so you can sign in without a password.', 'logliy' ),
		);
	}

	return $checks;
}

/**
 * Dismiss onboarding banner (query arg or auto-complete).
 */
add_action( 'admin_init', 'logliy_handle_dismiss_onboarding' );
function logliy_handle_dismiss_onboarding(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Manual dismiss.
	if ( isset( $_GET['logliy_dismiss_onboarding'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'logliy_dismiss_onboarding' );
		logliy_update_settings( array( 'onboarding_dismissed' => true ) );
		delete_transient( 'logliy_show_onboarding' );
		$redirect = remove_query_arg( array( 'logliy_dismiss_onboarding', '_wpnonce' ) );
		wp_safe_redirect( $redirect !== '' ? $redirect : admin_url() );
		exit;
	}

	// Auto-dismiss once setup is complete (HTTPS, library, Passkey, …).
	if ( ! (bool) logliy_get_setting( 'onboarding_dismissed', false ) && logliy_onboarding_is_complete() ) {
		logliy_update_settings( array( 'onboarding_dismissed' => true ) );
		delete_transient( 'logliy_show_onboarding' );
	}
}

/**
 * Onboarding / environment checks notice.
 */
add_action( 'admin_notices', 'logliy_onboarding_notice' );
function logliy_onboarding_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Already done / dismissed → never show.
	if ( (bool) logliy_get_setting( 'onboarding_dismissed', false ) ) {
		return;
	}
	if ( logliy_onboarding_is_complete() ) {
		return;
	}

	$on_settings = isset( $_GET['page'] ) && $_GET['page'] === LOGLIY_ADMIN_PAGE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$show_flag   = (bool) get_transient( 'logliy_show_onboarding' );
	if ( ! $on_settings && ! $show_flag ) {
		return;
	}

	$checks  = logliy_environment_checks();
	$dismiss = wp_nonce_url(
		add_query_arg( 'logliy_dismiss_onboarding', '1' ),
		'logliy_dismiss_onboarding'
	);

	$all_ok = true;
	foreach ( $checks as $check ) {
		if ( empty( $check['ok'] ) ) {
			$all_ok = false;
			break;
		}
	}

	$class = $all_ok ? 'notice notice-success' : 'notice notice-warning';
	echo '<div class="' . esc_attr( $class ) . ' logliy-onboarding is-dismissible"><p><strong>' . esc_html__( 'Logliy setup', 'logliy' ) . '</strong></p><ul style="list-style:disc;margin-left:1.2em">';
	foreach ( $checks as $check ) {
		$icon = ! empty( $check['ok'] ) ? '✓' : '✗';
		echo '<li>' . esc_html( $icon . ' ' . $check['label'] ) . '</li>';
	}
	echo '</ul><p>';
	echo esc_html__( 'Password login is off by default. Register a Passkey on your profile or confirm Email OTP works before locking yourself out.', 'logliy' );
	echo ' <a href="' . esc_url( admin_url( 'profile.php#logliy-passkeys' ) ) . '">' . esc_html__( 'Open your profile', 'logliy' ) . '</a>';
	echo ' · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss this notice', 'logliy' ) . '</a>';
	echo '</p></div>';
}

/**
 * Settings page markup.
 */
function logliy_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s     = logliy_get_settings();
	$tab   = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tabs  = array(
		'general'     => __( 'General', 'logliy' ),
		'redirects'   => __( 'Redirects', 'logliy' ),
		'passkeys'    => __( 'Passkeys', 'logliy' ),
		'email_otp'   => __( 'Email OTP', 'logliy' ),
		'woocommerce' => __( 'WooCommerce', 'logliy' ),
		'users'       => __( 'Users', 'logliy' ),
		'advanced'    => __( 'Advanced', 'logliy' ),
	);
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'general';
	}

	$roles = wp_roles()->roles;
	$login_roles_map  = is_array( $s['redirect_login_roles'] ?? null ) ? $s['redirect_login_roles'] : array();
	$logout_roles_map = is_array( $s['redirect_logout_roles'] ?? null ) ? $s['redirect_logout_roles'] : array();
	$conflict_plugin  = logliy_conflicting_hide_login_plugin();
	?>
	<div class="wrap lg-wrap">
		<div class="lg-header">
			<span class="lg-header-logo" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l7 4v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V7l7-4z"/><path d="M9 12l2 2 4-4"/></svg>
			</span>
			<div>
				<h1>
					<?php echo esc_html__( 'Logliy – LoginProtect', 'logliy' ); ?>
					<span class="lg-version"><?php echo esc_html( 'v' . LOGLIY_VERSION ); ?></span>
				</h1>
				<p><?php echo esc_html__( 'Passwordless login with Passkeys and Email codes.', 'logliy' ); ?></p>
			</div>
		</div>

		<nav class="lg-tabs" aria-label="<?php echo esc_attr__( 'Settings tabs', 'logliy' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="lg-tab<?php echo $tab === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . LOGLIY_ADMIN_PAGE . '&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $tab === 'users' ) : ?>
			<?php logliy_render_users_overview(); ?>
			<p class="lg-footer"><?php echo esc_html__( 'Logliy by FloBa Media.', 'logliy' ); ?></p>
		</div>
			<?php
			return;
		endif;
		?>

		<form method="post" action="options.php" class="lg-form">
			<?php settings_fields( 'logliy_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[_logliy_form]" value="1" />
			<input type="hidden" name="logliy_active_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<?php logliy_render_save_button( 'lg-save-btn--top' ); ?>

			<?php if ( $tab === 'general' ) : ?>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Authentication methods', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><?php echo esc_html__( 'Passkeys', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[enable_passkey]" value="1" <?php checked( ! empty( $s['enable_passkey'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Email OTP', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[enable_email_otp]" value="1" <?php checked( ! empty( $s['enable_email_otp'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Magic link', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[enable_magic_link]" value="1" <?php checked( ! empty( $s['enable_magic_link'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'One-click sign-in link sent by email.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Allow password login', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[allow_password_login]" value="1" <?php checked( ! empty( $s['allow_password_login'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'Off by default. Emergency override: define( \'LOGLIY_ALLOW_PASSWORD\', true ); in wp-config.php.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Allow XML-RPC passwords', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[allow_xmlrpc_password]" value="1" <?php checked( ! empty( $s['allow_xmlrpc_password'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'Off by default. When password login is off, XML-RPC account passwords are blocked (WordPress app, Jetpack, some backups). Enable only if a tool still needs the account password over XML-RPC. Prefer Application Passwords where possible.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Password roles', 'logliy' ); ?></th>
						<td>
							<?php
							$allowed_roles = is_array( $s['password_allowed_roles'] ) ? $s['password_allowed_roles'] : array();
							foreach ( $roles as $role_key => $role_obj ) :
								?>
								<label style="display:inline-block;margin:0 12px 8px 0">
									<input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[password_allowed_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $allowed_roles, true ) ); ?> />
									<?php echo esc_html( translate_user_role( $role_obj['name'] ) ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php echo esc_html__( 'If password login is enabled and no roles are checked, all roles may use passwords. If password login is off, checked roles are still allowed.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Hide WP password fields', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[hide_wp_login_password]" value="1" <?php checked( ! empty( $s['hide_wp_login_password'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Dismiss onboarding', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[onboarding_dismissed]" value="1" <?php checked( ! empty( $s['onboarding_dismissed'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'Hides the setup banner. It also disappears automatically once HTTPS, libraries and your Passkey are ready.', 'logliy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Login page', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><?php echo esc_html__( 'Auto Remember Me', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[auto_remember]" value="1" <?php checked( ! empty( $s['auto_remember'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'Keep remember me option always checked on login page.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Login Order', 'logliy' ); ?></th>
						<td>
							<?php $id_mode = isset( $s['login_identifier'] ) ? (string) $s['login_identifier'] : 'both'; ?>
							<label style="display:block;margin:0 0 8px">
								<input type="radio" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_identifier]" value="both" <?php checked( $id_mode, 'both' ); ?> />
								<?php echo esc_html__( 'Both Username Or Email Address', 'logliy' ); ?>
							</label>
							<label style="display:block;margin:0 0 8px">
								<input type="radio" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_identifier]" value="username" <?php checked( $id_mode, 'username' ); ?> />
								<?php echo esc_html__( 'Only Username', 'logliy' ); ?>
							</label>
							<label style="display:block;margin:0 0 8px">
								<input type="radio" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_identifier]" value="email" <?php checked( $id_mode, 'email' ); ?> />
								<?php echo esc_html__( 'Only Email Address', 'logliy' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'Enable users to login using their username or email address.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Language Switcher', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[hide_language_switcher]" value="1" <?php checked( ! empty( $s['hide_language_switcher'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'Remove Language Switcher Dropdown On Login Page.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Hide “Back to site”', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[hide_back_to_blog]" value="1" <?php checked( ! empty( $s['hide_back_to_blog'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Hide lost password link', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[hide_lost_password]" value="1" <?php checked( ! empty( $s['hide_lost_password'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Delete All Settings', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[delete_settings_on_uninstall]" value="1" <?php checked( ! empty( $s['delete_settings_on_uninstall'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'Enable this option to delete every settings of this plugin on uninstall.', 'logliy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Login branding', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><label for="login_brand_name"><?php echo esc_html__( 'Brand name', 'logliy' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_brand_name]" id="login_brand_name" value="<?php echo esc_attr( (string) $s['login_brand_name'] ); ?>" placeholder="<?php echo esc_attr__( 'Logliy', 'logliy' ); ?>" />
							<p class="description"><?php echo esc_html__( 'Shown on the login panel. Leave empty for the default Logliy name.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="login_tagline"><?php echo esc_html__( 'Tagline', 'logliy' ); ?></label></th>
						<td>
							<input type="text" class="large-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_tagline]" id="login_tagline" value="<?php echo esc_attr( (string) $s['login_tagline'] ); ?>" placeholder="<?php echo esc_attr__( 'Sign in with a Passkey or Email code.', 'logliy' ); ?>" />
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Logo', 'logliy' ); ?></th>
						<td>
							<?php
							$logo_id  = (int) $s['login_logo_id'];
							$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
							?>
							<input type="hidden" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_logo_id]" id="login_logo_id" value="<?php echo esc_attr( (string) $logo_id ); ?>" />
							<div class="lg-logo-preview" data-logliy-logo-preview <?php echo $logo_url ? '' : 'hidden'; ?>>
								<?php if ( $logo_url ) : ?>
									<img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:64px;max-width:180px;display:block;margin-bottom:8px" />
								<?php endif; ?>
							</div>
							<button type="button" class="button" data-logliy-logo-upload><?php echo esc_html__( 'Select logo', 'logliy' ); ?></button>
							<button type="button" class="button-link-delete" data-logliy-logo-clear <?php echo $logo_id ? '' : 'hidden'; ?>><?php echo esc_html__( 'Remove', 'logliy' ); ?></button>
							<p class="description"><?php echo esc_html__( 'Optional. Replaces the default shield icon on the login panel.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="login_bg_color"><?php echo esc_html__( 'Background color', 'logliy' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_bg_color]" id="login_bg_color" value="<?php echo esc_attr( (string) ( $s['login_bg_color'] ?? '' ) ); ?>" placeholder="#eef3fa" />
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Background image', 'logliy' ); ?></th>
						<td>
							<?php
							$bg_id  = (int) ( $s['login_bg_image_id'] ?? 0 );
							$bg_url = $bg_id ? wp_get_attachment_image_url( $bg_id, 'medium' ) : '';
							?>
							<input type="hidden" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_bg_image_id]" id="login_bg_image_id" value="<?php echo esc_attr( (string) $bg_id ); ?>" />
							<div class="lg-logo-preview" data-logliy-bg-preview <?php echo $bg_url ? '' : 'hidden'; ?>>
								<?php if ( $bg_url ) : ?>
									<img src="<?php echo esc_url( $bg_url ); ?>" alt="" style="max-height:80px;max-width:200px;display:block;margin-bottom:8px" />
								<?php endif; ?>
							</div>
							<button type="button" class="button" data-logliy-bg-upload><?php echo esc_html__( 'Select background', 'logliy' ); ?></button>
							<button type="button" class="button-link-delete" data-logliy-bg-clear <?php echo $bg_id ? '' : 'hidden'; ?>><?php echo esc_html__( 'Remove', 'logliy' ); ?></button>
						</td>
					</tr>
					<tr>
						<th><label for="login_footer_text"><?php echo esc_html__( 'Login footer', 'logliy' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="2" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[login_footer_text]" id="login_footer_text" placeholder="<?php echo esc_attr__( 'Imprint · Privacy', 'logliy' ); ?>"><?php echo esc_textarea( (string) ( $s['login_footer_text'] ?? '' ) ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Optional text or HTML shown below the login form (links allowed). For extra styling use Appearance → Customize → Additional CSS.', 'logliy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<?php elseif ( $tab === 'redirects' ) : ?>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'After login', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><label for="redirect_login_default"><?php echo esc_html__( 'Default redirect', 'logliy' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[redirect_login_default]" id="redirect_login_default" value="<?php echo esc_attr( (string) ( $s['redirect_login_default'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( admin_url() ); ?>" />
							<p class="description"><?php echo esc_html__( 'Used when no role-specific URL is set. Leave empty for WordPress default.', 'logliy' ); ?></p>
						</td>
					</tr>
					<?php foreach ( $roles as $role_key => $role_obj ) : ?>
					<tr>
						<th><?php echo esc_html( translate_user_role( $role_obj['name'] ) ); ?></th>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[redirect_login_roles][<?php echo esc_attr( $role_key ); ?>]" value="<?php echo esc_attr( (string) ( $login_roles_map[ $role_key ] ?? '' ) ); ?>" />
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
			</div>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'After logout', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><label for="redirect_logout_default"><?php echo esc_html__( 'Default redirect', 'logliy' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[redirect_logout_default]" id="redirect_logout_default" value="<?php echo esc_attr( (string) ( $s['redirect_logout_default'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
						</td>
					</tr>
					<?php foreach ( $roles as $role_key => $role_obj ) : ?>
					<tr>
						<th><?php echo esc_html( translate_user_role( $role_obj['name'] ) ); ?></th>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[redirect_logout_roles][<?php echo esc_attr( $role_key ); ?>]" value="<?php echo esc_attr( (string) ( $logout_roles_map[ $role_key ] ?? '' ) ); ?>" />
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
			</div>
			<?php elseif ( $tab === 'passkeys' ) : ?>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Passkey options', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><label for="passkey_uv"><?php echo esc_html__( 'User verification', 'logliy' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[passkey_uv]" id="passkey_uv">
								<option value="required" <?php selected( $s['passkey_uv'], 'required' ); ?>><?php echo esc_html__( 'Required', 'logliy' ); ?></option>
								<option value="preferred" <?php selected( $s['passkey_uv'], 'preferred' ); ?>><?php echo esc_html__( 'Preferred', 'logliy' ); ?></option>
								<option value="discouraged" <?php selected( $s['passkey_uv'], 'discouraged' ); ?>><?php echo esc_html__( 'Discouraged', 'logliy' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="passkey_resident_key"><?php echo esc_html__( 'Sign-in without username', 'logliy' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[passkey_resident_key]" id="passkey_resident_key">
								<option value="required" <?php selected( $s['passkey_resident_key'], 'required' ); ?>><?php echo esc_html__( 'Yes — save Passkey on the device', 'logliy' ); ?></option>
								<option value="preferred" <?php selected( $s['passkey_resident_key'], 'preferred' ); ?>><?php echo esc_html__( 'Preferred (if the device supports it)', 'logliy' ); ?></option>
								<option value="discouraged" <?php selected( $s['passkey_resident_key'], 'discouraged' ); ?>><?php echo esc_html__( 'No — require username first', 'logliy' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html__( 'When enabled, browsers can offer the Passkey automatically (no email/username needed). Recommended for most sites.', 'logliy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<?php elseif ( $tab === 'email_otp' ) : ?>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Email code settings', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><label for="otp_ttl_minutes"><?php echo esc_html__( 'Code lifetime (minutes)', 'logliy' ); ?></label></th>
						<td><input type="number" min="1" max="60" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[otp_ttl_minutes]" id="otp_ttl_minutes" value="<?php echo esc_attr( (string) $s['otp_ttl_minutes'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="otp_length"><?php echo esc_html__( 'Code length', 'logliy' ); ?></label></th>
						<td><input type="number" min="4" max="8" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[otp_length]" id="otp_length" value="<?php echo esc_attr( (string) $s['otp_length'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="otp_rate_limit_account"><?php echo esc_html__( 'Max requests / account', 'logliy' ); ?></label></th>
						<td><input type="number" min="1" max="50" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[otp_rate_limit_account]" id="otp_rate_limit_account" value="<?php echo esc_attr( (string) $s['otp_rate_limit_account'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="otp_rate_limit_ip"><?php echo esc_html__( 'Max requests / IP', 'logliy' ); ?></label></th>
						<td><input type="number" min="1" max="200" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[otp_rate_limit_ip]" id="otp_rate_limit_ip" value="<?php echo esc_attr( (string) $s['otp_rate_limit_ip'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="otp_rate_window_minutes"><?php echo esc_html__( 'Rate-limit window (minutes)', 'logliy' ); ?></label></th>
						<td><input type="number" min="1" max="120" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[otp_rate_window_minutes]" id="otp_rate_window_minutes" value="<?php echo esc_attr( (string) $s['otp_rate_window_minutes'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="email_request_cooldown_seconds"><?php echo esc_html__( 'Email request cooldown (seconds)', 'logliy' ); ?></label></th>
						<td>
							<input type="number" min="0" max="600" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[email_request_cooldown_seconds]" id="email_request_cooldown_seconds" value="<?php echo esc_attr( (string) ( $s['email_request_cooldown_seconds'] ?? 60 ) ); ?>" class="small-text" />
							<p class="description"><?php echo esc_html__( 'Minimum wait between Email code / Magic link requests (shared). 0 = off. Default 60.', 'logliy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Magic link', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><label for="magic_link_ttl_minutes"><?php echo esc_html__( 'Link lifetime (minutes)', 'logliy' ); ?></label></th>
						<td><input type="number" min="5" max="60" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[magic_link_ttl_minutes]" id="magic_link_ttl_minutes" value="<?php echo esc_attr( (string) ( $s['magic_link_ttl_minutes'] ?? 15 ) ); ?>" class="small-text" /></td>
					</tr>
				</table>
			</div>
			<?php elseif ( $tab === 'woocommerce' ) : ?>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'WooCommerce', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><?php echo esc_html__( 'My Account login', 'logliy' ); ?></th>
						<td><label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[wc_enable_myaccount]" value="1" <?php checked( ! empty( $s['wc_enable_myaccount'] ) ); ?> /><span class="lg-toggle-slider"></span></label></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Checkout login', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[wc_enable_checkout]" value="1" <?php checked( ! empty( $s['wc_enable_checkout'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'Classic checkout login form. Guest checkout is unchanged.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Block checkout / account', 'logliy' ); ?></th>
						<td>
							<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[wc_enable_blocks]" value="1" <?php checked( ! empty( $s['wc_enable_blocks'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
							<p class="description"><?php echo esc_html__( 'Show Logliy above WooCommerce Checkout and Customer Account blocks for guests.', 'logliy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<?php else : ?>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Custom login URL', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><?php echo esc_html__( 'Enable custom login URL', 'logliy' ); ?></th>
						<td>
							<?php if ( $conflict_plugin ) : ?>
								<input type="hidden" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[enable_custom_login_url]" value="<?php echo ! empty( $s['enable_custom_login_url'] ) ? '1' : '0'; ?>" />
								<label class="lg-toggle"><input type="checkbox" value="1" <?php checked( ! empty( $s['enable_custom_login_url'] ) ); ?> disabled /><span class="lg-toggle-slider"></span></label>
								<p class="description" style="color:#b32d2e">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: plugin name */
											__( 'Disabled while %s is active — that plugin already hides wp-login.php.', 'logliy' ),
											$conflict_plugin
										)
									);
									?>
								</p>
							<?php else : ?>
								<label class="lg-toggle"><input type="checkbox" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[enable_custom_login_url]" value="1" <?php checked( ! empty( $s['enable_custom_login_url'] ) ); ?> /><span class="lg-toggle-slider"></span></label>
								<p class="description"><?php echo esc_html__( 'Hides /wp-login.php and /wp-admin for guests with a 404 (does not redirect to the secret URL). Login only works at your slug. Auto-disabled if WPS Hide Login (or similar) is active.', 'logliy' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="custom_login_slug"><?php echo esc_html__( 'Login slug', 'logliy' ); ?></label></th>
						<td>
							<code><?php echo esc_html( trailingslashit( home_url( '/' ) ) ); ?></code>
							<?php if ( $conflict_plugin ) : ?>
								<input type="hidden" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[custom_login_slug]" value="<?php echo esc_attr( (string) ( $s['custom_login_slug'] ?? 'cp' ) ); ?>" />
								<input type="text" class="regular-text" id="custom_login_slug" value="<?php echo esc_attr( (string) ( $s['custom_login_slug'] ?? 'cp' ) ); ?>" style="max-width:12em" disabled />
							<?php else : ?>
								<input type="text" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[custom_login_slug]" id="custom_login_slug" value="<?php echo esc_attr( (string) ( $s['custom_login_slug'] ?? 'cp' ) ); ?>" style="max-width:12em" />
							<?php endif; ?>
							<?php if ( logliy_custom_login_url_active() ) : ?>
								<p class="description"><?php echo esc_html__( 'Current login URL:', 'logliy' ); ?> <a href="<?php echo esc_url( logliy_custom_login_url() ); ?>"><?php echo esc_html( logliy_custom_login_url() ); ?></a></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Sessions', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><label for="session_expire_hours"><?php echo esc_html__( 'Session length (hours)', 'logliy' ); ?></label></th>
						<td>
							<input type="number" min="1" max="336" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[session_expire_hours]" id="session_expire_hours" value="<?php echo esc_attr( (string) ( $s['session_expire_hours'] ?? 48 ) ); ?>" class="small-text" />
							<p class="description"><?php echo esc_html__( 'Without “Remember me”. WordPress default is 48 hours.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="session_remember_days"><?php echo esc_html__( 'Remember me (days)', 'logliy' ); ?></label></th>
						<td>
							<input type="number" min="1" max="90" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[session_remember_days]" id="session_remember_days" value="<?php echo esc_attr( (string) ( $s['session_remember_days'] ?? 14 ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th><label for="admin_idle_timeout_minutes"><?php echo esc_html__( 'Admin idle timeout (minutes)', 'logliy' ); ?></label></th>
						<td>
							<input type="number" min="0" max="480" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[admin_idle_timeout_minutes]" id="admin_idle_timeout_minutes" value="<?php echo esc_attr( (string) ( $s['admin_idle_timeout_minutes'] ?? 0 ) ); ?>" class="small-text" />
							<p class="description"><?php echo esc_html__( '0 = off. Logs out users with manage_options after idle time.', 'logliy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Advanced', 'logliy' ); ?></h2>
				<table class="form-table lg-form-table" role="presentation">
					<tr>
						<th><label for="rp_id"><?php echo esc_html__( 'Relying Party ID', 'logliy' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[rp_id]" id="rp_id" value="<?php echo esc_attr( (string) $s['rp_id'] ); ?>" placeholder="<?php echo esc_attr( logliy_rp_id() ); ?>" />
							<p class="description"><?php echo esc_html__( 'Usually your site hostname. Leave empty to use the site host automatically.', 'logliy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rp_name"><?php echo esc_html__( 'Relying Party name', 'logliy' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[rp_name]" id="rp_name" value="<?php echo esc_attr( (string) $s['rp_name'] ); ?>" placeholder="<?php echo esc_attr( logliy_rp_name() ); ?>" />
						</td>
					</tr>
					<tr>
						<th><label for="related_origins"><?php echo esc_html__( 'Related origins (stub)', 'logliy' ); ?></label></th>
						<td>
							<?php
							$origins = is_array( $s['related_origins'] ) ? implode( "\n", $s['related_origins'] ) : '';
							?>
							<textarea class="large-text code" rows="4" name="<?php echo esc_attr( LOGLIY_OPT_SETTINGS ); ?>[related_origins]" id="related_origins" placeholder="https://shop.example.com"><?php echo esc_textarea( $origins ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Optional extra allowed origins (one per line). Full Related Origins / multi-domain support comes later.', 'logliy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<div class="lg-card">
				<h2><?php echo esc_html__( 'Environment', 'logliy' ); ?></h2>
				<ul class="lg-checks">
					<?php foreach ( logliy_environment_checks() as $check ) : ?>
						<li class="<?php echo $check['ok'] ? 'is-ok' : 'is-bad'; ?>"><?php echo esc_html( $check['label'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<?php
			// Preserve values from other tabs so unchecked tabs don't wipe settings.
			$preserve = $s;
			$tab_keys = array(
				'general'     => array( 'enable_passkey', 'enable_email_otp', 'enable_magic_link', 'allow_password_login', 'allow_xmlrpc_password', 'password_allowed_roles', 'hide_wp_login_password', 'onboarding_dismissed', 'login_brand_name', 'login_tagline', 'login_logo_id', 'auto_remember', 'login_identifier', 'hide_language_switcher', 'delete_settings_on_uninstall', 'hide_back_to_blog', 'hide_lost_password', 'login_bg_color', 'login_bg_image_id', 'login_footer_text' ),
				'redirects'   => array( 'redirect_login_default', 'redirect_logout_default', 'redirect_login_roles', 'redirect_logout_roles' ),
				'passkeys'    => array( 'passkey_uv', 'passkey_resident_key' ),
				'email_otp'   => array( 'otp_ttl_minutes', 'otp_length', 'otp_rate_limit_account', 'otp_rate_limit_ip', 'otp_rate_window_minutes', 'email_request_cooldown_seconds', 'magic_link_ttl_minutes' ),
				'woocommerce' => array( 'wc_enable_myaccount', 'wc_enable_checkout', 'wc_enable_blocks' ),
				'advanced'    => array( 'rp_id', 'rp_name', 'related_origins', 'enable_custom_login_url', 'custom_login_slug', 'session_expire_hours', 'session_remember_days', 'admin_idle_timeout_minutes' ),
			);
			$active_keys = $tab_keys[ $tab ] ?? array();
			foreach ( $preserve as $pkey => $pval ) {
				if ( $pkey === 'login_custom_css' ) {
					continue;
				}
				if ( in_array( $pkey, $active_keys, true ) ) {
					continue;
				}
				if ( is_array( $pval ) ) {
					foreach ( $pval as $i => $item ) {
						printf(
							'<input type="hidden" name="%s[%s][%s]" value="%s" />' . "\n",
							esc_attr( LOGLIY_OPT_SETTINGS ),
							esc_attr( $pkey ),
							esc_attr( (string) $i ),
							esc_attr( is_scalar( $item ) ? (string) $item : '' )
						);
					}
				} elseif ( is_bool( $pval ) ) {
					printf(
						'<input type="hidden" name="%s[%s]" value="%s" />' . "\n",
						esc_attr( LOGLIY_OPT_SETTINGS ),
						esc_attr( $pkey ),
						$pval ? '1' : '0'
					);
				} else {
					printf(
						'<input type="hidden" name="%s[%s]" value="%s" />' . "\n",
						esc_attr( LOGLIY_OPT_SETTINGS ),
						esc_attr( $pkey ),
						esc_attr( (string) $pval )
					);
				}
			}
			?>

			<?php logliy_render_save_button( 'lg-save-btn--bottom' ); ?>
		</form>

		<?php if ( $tab === 'advanced' ) : ?>
			<?php logliy_render_import_export_card(); ?>
		<?php endif; ?>

		<p class="lg-footer"><?php echo esc_html__( 'Logliy by FloBa Media.', 'logliy' ); ?></p>
	</div>
	<?php
}

/**
 * Settings link on plugins list.
 *
 * @param array<string,string> $links Links.
 * @return array<string,string>
 */
add_filter( 'plugin_action_links_' . plugin_basename( LOGLIY_PLUGIN_FILE ), 'logliy_plugin_action_links' );
function logliy_plugin_action_links( array $links ): array {
	$url = admin_url( 'options-general.php?page=' . LOGLIY_ADMIN_PAGE );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'logliy' ) . '</a>' );
	return $links;
}
