<?php
/**
 * Login UI on wp-login.php and shared markup/assets.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue login assets.
 */
add_action( 'login_enqueue_scripts', 'logliy_enqueue_login_assets' );
function logliy_enqueue_login_assets(): void {
	logliy_register_login_assets();
	wp_enqueue_style( 'logliy-login' );
	wp_enqueue_script( 'logliy-login' );
	wp_enqueue_script( 'logliy-passkey' );
	logliy_print_login_branding_css();
}

/**
 * Register shared login assets (also used by WooCommerce).
 */
function logliy_register_login_assets(): void {
	static $localized = false;

	wp_register_style(
		'logliy-fonts',
		'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap',
		array(),
		LOGLIY_VERSION
	);
	wp_register_style(
		'logliy-login',
		LOGLIY_PLUGIN_URL . 'assets/css/login.css',
		array( 'logliy-fonts' ),
		LOGLIY_VERSION
	);
	wp_register_script(
		'logliy-passkey',
		LOGLIY_PLUGIN_URL . 'assets/js/passkey.js',
		array(),
		LOGLIY_VERSION,
		true
	);
	wp_register_script(
		'logliy-login',
		LOGLIY_PLUGIN_URL . 'assets/js/login.js',
		array( 'logliy-passkey' ),
		LOGLIY_VERSION,
		true
	);

	if ( $localized ) {
		return;
	}
	$localized = true;

	$redirect = '';
	if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect = esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	$password_ok   = logliy_password_login_globally_allowed() || logliy_password_emergency_allowed();
	$auto_remember = logliy_auto_remember();
	$id_label      = logliy_login_identifier_label();

	wp_localize_script(
		'logliy-login',
		'logliyLogin',
		array(
			'restUrl'           => esc_url_raw( rest_url( 'logliy/v1' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'redirectTo'        => $redirect,
			'enablePasskey'     => (bool) logliy_get_setting( 'enable_passkey', true ),
			'enableEmailOtp'    => (bool) logliy_get_setting( 'enable_email_otp', true ),
			'enableMagicLink'   => (bool) logliy_get_setting( 'enable_magic_link', true ),
			'allowPassword'     => $password_ok,
			'hidePassword'      => (bool) logliy_get_setting( 'hide_wp_login_password', true ) && ! $password_ok,
			'autoRemember'      => $auto_remember,
			'loginIdentifier'   => logliy_login_identifier_mode(),
			'emailCooldown'     => (int) logliy_get_setting( 'email_request_cooldown_seconds', 60 ),
			'turnstileRequired' => logliy_turnstile_required(),
			'i18n'              => array(
				'passkey'          => __( 'Passkey', 'logliy' ),
				'emailCode'        => __( 'Email code', 'logliy' ),
				'magicLink'        => __( 'Magic link', 'logliy' ),
				'password'         => __( 'Password', 'logliy' ),
				'sendCode'         => __( 'Send code', 'logliy' ),
				'sendMagic'        => __( 'Send magic link', 'logliy' ),
				'verifyCode'       => __( 'Sign in', 'logliy' ),
				'usePasskey'       => __( 'Sign in with Passkey', 'logliy' ),
				'loginPlaceholder' => $id_label,
				'codePlaceholder'  => __( '6-digit code', 'logliy' ),
				'remember'         => __( 'Remember me', 'logliy' ),
				'working'          => __( 'Please wait…', 'logliy' ),
				'waitSeconds'      => __( 'Wait %d s', 'logliy' ),
				'passkeyFail'      => __( 'Passkey sign-in failed or was cancelled.', 'logliy' ),
				'passkeyBusy'      => __( 'Passkey is busy — please try again.', 'logliy' ),
				'otpSent'          => __( 'Check your email for a login code.', 'logliy' ),
				'magicSent'        => __( 'Check your email for a magic login link.', 'logliy' ),
				'errorGeneric'     => __( 'Something went wrong. Please try again.', 'logliy' ),
				'captchaRequired'  => __( 'Please complete the CAPTCHA challenge and try again.', 'logliy' ),
			),
		)
	);
}

/**
 * Inline branding CSS for the login page.
 */
function logliy_print_login_branding_css(): void {
	$css   = array();
	$color = (string) logliy_get_setting( 'login_bg_color', '' );
	$img_id = (int) logliy_get_setting( 'login_bg_image_id', 0 );
	$img    = $img_id > 0 ? wp_get_attachment_image_url( $img_id, 'full' ) : '';

	if ( $color !== '' || ( is_string( $img ) && $img !== '' ) ) {
		$parts = array();
		if ( is_string( $img ) && $img !== '' ) {
			$parts[] = 'url(' . esc_url( $img ) . ') center / cover no-repeat';
		}
		if ( $color !== '' ) {
			$parts[] = esc_attr( $color );
		}
		$css[] = 'body.login.logliy-login{background:' . implode( ',', $parts ) . '!important;}';
	}

	if ( logliy_get_setting( 'hide_back_to_blog', false ) ) {
		$css[] = 'body.login.logliy-login #backtoblog{display:none!important;}';
	}
	if ( logliy_get_setting( 'hide_lost_password', false ) ) {
		$css[] = 'body.login.logliy-login #nav{display:none!important;}';
	}

	$custom = trim( (string) logliy_get_setting( 'login_custom_css', '' ) );
	if ( $custom !== '' ) {
		$css[] = $custom;
	}

	if ( $css === array() ) {
		return;
	}
	wp_add_inline_style( 'logliy-login', implode( "\n", $css ) );
}

/**
 * Inject Logliy panel on wp-login.php.
 */
add_action( 'login_form', 'logliy_render_wp_login_panel' );
function logliy_render_wp_login_panel(): void {
	echo logliy_get_login_panel_html( 'wp-login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
}

/**
 * Hide default password fields via body class when passwordless.
 *
 * @param array<int, string> $classes Classes.
 * @return array<int, string>
 */
add_filter( 'login_body_class', 'logliy_login_body_class' );
function logliy_login_body_class( array $classes ): array {
	$password_ok = logliy_password_login_globally_allowed() || logliy_password_emergency_allowed();
	if ( ! $password_ok && logliy_get_setting( 'hide_wp_login_password', true ) ) {
		$classes[] = 'logliy-passwordless';
	}
	$classes[] = 'logliy-login';
	return $classes;
}

/**
 * Shared login panel markup.
 *
 * @param string $context wp-login|woocommerce|checkout|….
 */
function logliy_get_login_panel_html( string $context = 'wp-login' ): string {
	$password_ok   = logliy_password_login_globally_allowed() || logliy_password_emergency_allowed();
	$passkey       = (bool) logliy_get_setting( 'enable_passkey', true );
	$otp           = (bool) logliy_get_setting( 'enable_email_otp', true );
	$magic         = (bool) logliy_get_setting( 'enable_magic_link', true );
	$brand         = logliy_login_brand_name();
	$tagline       = logliy_login_tagline();
	$logo_url      = logliy_login_logo_url();
	$auto_remember = logliy_auto_remember();
	$id_label      = logliy_login_identifier_label();
	$id_label_opt  = logliy_login_identifier_label( true );
	$input_type    = logliy_login_identifier_mode() === 'email' ? 'email' : 'text';
	$autocomplete  = logliy_login_identifier_mode() === 'email' ? 'username email' : 'username';

	$active = $passkey ? 'passkey' : ( $otp ? 'otp' : ( $magic ? 'magic' : 'password' ) );

	ob_start();
	?>
	<div class="logliy-panel" data-logliy-context="<?php echo esc_attr( $context ); ?>" hidden>
		<div class="logliy-brand">
			<?php if ( $logo_url !== '' ) : ?>
				<img class="logliy-brand-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $brand ); ?>" />
			<?php else : ?>
				<span class="logliy-brand-mark" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l7 4v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V7l7-4z"/><path d="M9 12l2 2 4-4"/></svg>
				</span>
			<?php endif; ?>
			<span class="logliy-brand-name"><?php echo esc_html( $brand ); ?></span>
		</div>
		<p class="logliy-lead"><?php echo esc_html( $tagline ); ?></p>

		<div class="logliy-tabs" role="tablist">
			<?php if ( $passkey ) : ?>
				<button type="button" class="logliy-tab<?php echo $active === 'passkey' ? ' is-active' : ''; ?>" role="tab" data-logliy-tab="passkey" aria-selected="<?php echo $active === 'passkey' ? 'true' : 'false'; ?>"><?php echo esc_html__( 'Passkey', 'logliy' ); ?></button>
			<?php endif; ?>
			<?php if ( $otp ) : ?>
				<button type="button" class="logliy-tab<?php echo $active === 'otp' ? ' is-active' : ''; ?>" role="tab" data-logliy-tab="otp" aria-selected="<?php echo $active === 'otp' ? 'true' : 'false'; ?>"><?php echo esc_html__( 'Email code', 'logliy' ); ?></button>
			<?php endif; ?>
			<?php if ( $magic ) : ?>
				<button type="button" class="logliy-tab<?php echo $active === 'magic' ? ' is-active' : ''; ?>" role="tab" data-logliy-tab="magic" aria-selected="<?php echo $active === 'magic' ? 'true' : 'false'; ?>"><?php echo esc_html__( 'Magic link', 'logliy' ); ?></button>
			<?php endif; ?>
			<?php if ( $password_ok ) : ?>
				<button type="button" class="logliy-tab" role="tab" data-logliy-tab="password" aria-selected="false"><?php echo esc_html__( 'Password', 'logliy' ); ?></button>
			<?php endif; ?>
		</div>

		<div class="logliy-msg" data-logliy-msg hidden role="status"></div>

		<?php if ( $passkey ) : ?>
		<div class="logliy-pane<?php echo $active === 'passkey' ? ' is-active' : ''; ?>" data-logliy-pane="passkey">
			<label class="logliy-label" for="logliy-passkey-login"><?php echo esc_html( $id_label_opt ); ?></label>
			<input class="logliy-input" type="<?php echo esc_attr( $input_type ); ?>" id="logliy-passkey-login" name="logliy_passkey_login" autocomplete="<?php echo esc_attr( $autocomplete . ' webauthn' ); ?>" placeholder="<?php echo esc_attr( $id_label ); ?>" />
			<label class="logliy-check"><input type="checkbox" data-logliy-remember <?php checked( $auto_remember ); ?> /> <?php echo esc_html__( 'Remember me', 'logliy' ); ?></label>
			<button type="button" class="logliy-btn logliy-btn-primary" data-logliy-passkey-auth><?php echo esc_html__( 'Sign in with Passkey', 'logliy' ); ?></button>
		</div>
		<?php endif; ?>

		<?php if ( $otp ) : ?>
		<div class="logliy-pane<?php echo $active === 'otp' ? ' is-active' : ''; ?>" data-logliy-pane="otp">
			<label class="logliy-label" for="logliy-otp-login"><?php echo esc_html( $id_label ); ?></label>
			<input class="logliy-input" type="<?php echo esc_attr( $input_type ); ?>" id="logliy-otp-login" name="logliy_otp_login" autocomplete="<?php echo esc_attr( $autocomplete ); ?>" placeholder="<?php echo esc_attr( $id_label ); ?>" />
			<div class="logliy-otp-step" data-logliy-otp-step="request">
				<button type="button" class="logliy-btn logliy-btn-primary" data-logliy-otp-request><?php echo esc_html__( 'Send code', 'logliy' ); ?></button>
			</div>
			<div class="logliy-otp-step" data-logliy-otp-step="verify" hidden>
				<label class="logliy-label" for="logliy-otp-code"><?php echo esc_html__( 'Login code', 'logliy' ); ?></label>
				<input class="logliy-input logliy-input-code" type="text" inputmode="numeric" pattern="[0-9]*" id="logliy-otp-code" autocomplete="one-time-code" />
				<label class="logliy-check"><input type="checkbox" data-logliy-remember <?php checked( $auto_remember ); ?> /> <?php echo esc_html__( 'Remember me', 'logliy' ); ?></label>
				<button type="button" class="logliy-btn logliy-btn-primary" data-logliy-otp-verify><?php echo esc_html__( 'Sign in', 'logliy' ); ?></button>
				<button type="button" class="logliy-btn logliy-btn-link" data-logliy-otp-resend><?php echo esc_html__( 'Resend code', 'logliy' ); ?></button>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( $magic ) : ?>
		<div class="logliy-pane<?php echo $active === 'magic' ? ' is-active' : ''; ?>" data-logliy-pane="magic">
			<label class="logliy-label" for="logliy-magic-login"><?php echo esc_html( $id_label ); ?></label>
			<input class="logliy-input" type="<?php echo esc_attr( $input_type ); ?>" id="logliy-magic-login" name="logliy_magic_login" autocomplete="<?php echo esc_attr( $autocomplete ); ?>" placeholder="<?php echo esc_attr( $id_label ); ?>" />
			<p class="logliy-hint"><?php echo esc_html__( 'We will email you a one-time link to sign in.', 'logliy' ); ?></p>
			<button type="button" class="logliy-btn logliy-btn-primary" data-logliy-magic-request><?php echo esc_html__( 'Send magic link', 'logliy' ); ?></button>
		</div>
		<?php endif; ?>

		<?php if ( $password_ok ) : ?>
		<div class="logliy-pane" data-logliy-pane="password">
			<p class="logliy-hint"><?php echo esc_html__( 'Use the password fields below.', 'logliy' ); ?></p>
		</div>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Hide the language switcher on wp-login.php when enabled.
 *
 * @param bool $show Whether to show the dropdown.
 */
add_filter( 'login_display_language_dropdown', 'logliy_filter_login_language_dropdown' );
function logliy_filter_login_language_dropdown( bool $show ): bool {
	if ( logliy_get_setting( 'hide_language_switcher', false ) ) {
		return false;
	}
	return $show;
}

/**
 * Auto-check WordPress core "Remember Me" + footer branding + magic errors.
 */
add_action( 'login_footer', 'logliy_login_footer_extras' );
function logliy_login_footer_extras(): void {
	if ( logliy_auto_remember() ) {
		echo "<script>(function(){var e=document.getElementById('rememberme');if(e){e.checked=true;}})();</script>\n";
	}

	$footer = trim( (string) logliy_get_setting( 'login_footer_text', '' ) );
	if ( $footer !== '' ) {
		echo '<p class="logliy-login-footer">' . wp_kses_post( $footer ) . "</p>\n";
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $_GET['logliy_magic_error'] ) ) {
		echo '<p class="logliy-login-footer is-error" role="alert">' . esc_html__( 'Magic link invalid or expired. Please request a new one.', 'logliy' ) . "</p>\n";
	}
}

/**
 * Adjust default WP login field label for identifier mode.
 *
 * @param string $translation Translated text.
 * @param string $text        Original text.
 * @param string $domain      Text domain.
 */
add_filter( 'gettext', 'logliy_filter_login_identifier_gettext', 20, 3 );
function logliy_filter_login_identifier_gettext( string $translation, string $text, string $domain ): string {
	if ( $domain !== 'default' ) {
		return $translation;
	}
	if ( ! isset( $GLOBALS['pagenow'] ) || $GLOBALS['pagenow'] !== 'wp-login.php' ) {
		return $translation;
	}
	if ( $text !== 'Username or Email Address' && $text !== 'Username or Email' ) {
		return $translation;
	}
	return logliy_login_identifier_label();
}
