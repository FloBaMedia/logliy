<?php
/**
 * Plugin Name:       Logliy - Login Protect (Passkey, Email Code)
 * Plugin URI:        https://github.com/FloBaMedia/logliy
 * Description:       Passwordless WordPress login with Passkeys and Email OTP. Optional password fallback.
 * Version:           0.0.5
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            FloBa Media
 * Author URI:        https://www.floba-media.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       logliy
 * Domain Path:       /languages
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

define( 'LOGLIY_VERSION', '0.0.5' );
define( 'LOGLIY_PLUGIN_FILE', __FILE__ );
define( 'LOGLIY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOGLIY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LOGLIY_ADMIN_PAGE', 'logliy' );
define( 'LOGLIY_OPT_SETTINGS', 'logliy_settings' );
define( 'LOGLIY_OPT_VERSION', 'logliy_plugin_version' );
define( 'LOGLIY_DB_VERSION', '2' );

$logliy_prefixed = LOGLIY_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
$logliy_autoload = LOGLIY_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $logliy_prefixed ) ) {
	require_once $logliy_prefixed;
} elseif ( is_readable( $logliy_autoload ) ) {
	// Fallback for local composer install before Strauss has run.
	require_once $logliy_autoload;
}

require_once LOGLIY_PLUGIN_DIR . 'includes/settings.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/settings-transfer.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/db.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/rate-limit.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/password-policy.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/captcha.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/email-otp.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/magic-link.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/passkey.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/rest.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/login-ui.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/redirects.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/hide-login.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/session.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/users.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/users-overview.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/wordfence-compat.php';
require_once LOGLIY_PLUGIN_DIR . 'includes/woocommerce.php';

if ( is_admin() ) {
	require_once LOGLIY_PLUGIN_DIR . 'includes/admin.php';
}

/**
 * Load bundled translations (EN source, DE bundled).
 */
add_action( 'init', 'logliy_load_textdomain' );
function logliy_load_textdomain(): void {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain(
		'logliy',
		false,
		dirname( plugin_basename( LOGLIY_PLUGIN_FILE ) ) . '/languages'
	);
}

/**
 * Activation: defaults, DB table, capability checks notice flag.
 */
register_activation_hook( __FILE__, 'logliy_activate' );
function logliy_activate(): void {
	logliy_settings_ensure_defaults();
	logliy_db_install();
	update_option( LOGLIY_OPT_VERSION, LOGLIY_VERSION, false );
	update_option( 'logliy_flush_rewrite', 1, false );
	set_transient( 'logliy_activation_redirect', 1, 60 );
	set_transient( 'logliy_show_onboarding', 1, WEEK_IN_SECONDS );
}

/**
 * Deactivation hook (keep data; uninstall.php cleans up).
 */
register_deactivation_hook( __FILE__, 'logliy_deactivate' );
function logliy_deactivate(): void {
	// No destructive cleanup on deactivate.
}

/**
 * Upgrade path after plugin update.
 */
add_action( 'plugins_loaded', 'logliy_maybe_upgrade', 5 );
function logliy_maybe_upgrade(): void {
	$stored = (string) get_option( LOGLIY_OPT_VERSION, '' );
	if ( $stored === LOGLIY_VERSION ) {
		return;
	}
	logliy_db_install();
	logliy_settings_ensure_defaults();
	update_option( LOGLIY_OPT_VERSION, LOGLIY_VERSION, false );
}

/**
 * Client IP helper (REMOTE_ADDR by default).
 *
 * Filter `logliy_client_ip` to honour a trusted proxy (e.g. Cloudflare CF-Connecting-IP)
 * when the host terminates TLS in front of PHP.
 */
function logliy_client_ip(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via filter_var below.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	$ip = sanitize_text_field( $ip );
	$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';

	/**
	 * Filter the client IP used for rate limits / cooldowns.
	 *
	 * @param string $ip Detected IP (REMOTE_ADDR).
	 */
	$filtered = apply_filters( 'logliy_client_ip', $ip );
	if ( is_string( $filtered ) && filter_var( $filtered, FILTER_VALIDATE_IP ) ) {
		return $filtered;
	}
	return $ip;
}

/**
 * Whether the request is over HTTPS (required for Passkeys).
 */
function logliy_is_https(): bool {
	return is_ssl() || ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN );
}

/**
 * Complete a successful Logliy login the same way wp_signon would.
 *
 * Runs authenticate / wp_authenticate_user so Wordfence lockouts, Multisite
 * flags and other plugins can still reject the session. Wordfence Login
 * Security 2FA is suspended here — Passkey/OTP/Magic Link already replace
 * the password factor.
 *
 * @param WP_User $user     Authenticated user.
 * @param bool    $remember Remember me.
 * @return array{redirect:string}|WP_Error
 */
function logliy_complete_login( WP_User $user, bool $remember = false ) {
	/*
	 * Let security plugins (Wordfence IP lockouts, country blocks, etc.) and
	 * Multisite spam/deleted checks run before issuing a cookie.
	 * Suspend Wordfence LS 2FA only for this passwordless completion.
	 */
	logliy_wordfence_ls_suspend_auth();

	try {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core auth filters for compatibility.
		$auth = apply_filters( 'authenticate', $user, $user->user_login, '' );
		if ( is_wp_error( $auth ) ) {
			logliy_fire_login_failed( $user->user_login, $auth );
			return $auth;
		}
		if ( $auth instanceof WP_User ) {
			$user = $auth;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core auth filter for compatibility.
		$checked = apply_filters( 'wp_authenticate_user', $user, '' );
		if ( is_wp_error( $checked ) ) {
			logliy_fire_login_failed( $user->user_login, $checked );
			return $checked;
		}
		if ( $checked instanceof WP_User ) {
			$user = $checked;
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );

		/** Fires after a user logs in (Wordfence and others listen here). */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core login hook for compatibility.
		do_action( 'wp_login', $user->user_login, $user );

		$redirect = admin_url();

		$requested = '';
		if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		if ( $requested !== '' ) {
			$redirect = $requested;
		}

		/**
		 * Filter the redirect URL after a successful Logliy login.
		 *
		 * @param string  $redirect Redirect URL.
		 * @param WP_User $user     User object.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core login_redirect for WP/theme compatibility.
		$redirect = (string) apply_filters( 'login_redirect', $redirect, $requested, $user );
		$redirect = logliy_safe_redirect_url( $redirect );

		return array( 'redirect' => $redirect );
	} finally {
		logliy_wordfence_ls_resume_auth();
	}
}

/**
 * Validate a post-login redirect URL (same-site only).
 */
function logliy_safe_redirect_url( string $url ): string {
	$fallback = admin_url();
	$validated = wp_validate_redirect( $url, $fallback );
	return is_string( $validated ) && $validated !== '' ? $validated : $fallback;
}

/**
 * Fire wp_login_failed for Wordfence / lockout compatibility.
 *
 * @param string               $username Attempted username or email.
 * @param WP_Error|null|string $error    Optional error.
 */
function logliy_fire_login_failed( string $username, $error = null ): void {
	if ( ! ( $error instanceof WP_Error ) ) {
		$error = new WP_Error( 'logliy_auth_failed', is_string( $error ) && $error !== '' ? $error : __( 'Authentication failed.', 'logliy' ) );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook so Wordfence lockouts still apply.
	do_action( 'wp_login_failed', $username, $error );
}
