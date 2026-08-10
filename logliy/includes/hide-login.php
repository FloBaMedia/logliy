<?php
/**
 * Custom login URL (disabled when another hide-login plugin is active).
 *
 * Behaviour (WPS Hide Login style):
 * - Only /{slug}/ shows the login form.
 * - /wp-login.php and unauthenticated /wp-admin return 404 (no redirect to the secret URL).
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Known hide-login plugins that conflict with Logliy's custom login URL.
 *
 * @return array<string, string> plugin_file => label
 */
function logliy_hide_login_conflict_plugins(): array {
	return array(
		'wps-hide-login/wps-hide-login.php'                   => 'WPS Hide Login',
		'hide-my-wp/index.php'                                => 'Hide My WP',
		'hide-login-page/hide-login-page.php'                 => 'Hide Login Page',
		'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
		'rename-wp-login/rename-wp-login.php'                 => 'Rename wp-login.php',
		'change-wp-admin-login/change-wp-admin-login.php'     => 'Change wp-admin login',
		'protected-wp-login/protected-wp-login.php'           => 'Protected WP Login',
	);
}

/**
 * Detect an active conflicting hide-login plugin.
 *
 * @return string|null Plugin label or null.
 */
function logliy_conflicting_hide_login_plugin(): ?string {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( logliy_hide_login_conflict_plugins() as $file => $label ) {
		if ( is_plugin_active( $file ) ) {
			return $label;
		}
	}
	if ( defined( 'WPS_HIDE_LOGIN_VERSION' ) || class_exists( '\WPS\WPS_Hide_Login\Plugin', false ) ) {
		return 'WPS Hide Login';
	}
	return null;
}

/**
 * Whether Logliy's custom login URL feature may run.
 */
function logliy_custom_login_url_active(): bool {
	if ( ! logliy_get_setting( 'enable_custom_login_url', false ) ) {
		return false;
	}
	if ( logliy_conflicting_hide_login_plugin() ) {
		return false;
	}
	$slug = (string) logliy_get_setting( 'custom_login_slug', 'cp' );
	return $slug !== '';
}

/**
 * Current custom login slug.
 */
function logliy_custom_login_slug(): string {
	return sanitize_title( (string) logliy_get_setting( 'custom_login_slug', 'cp' ) );
}

/**
 * Public URL of the custom login page.
 */
function logliy_custom_login_url( string $scheme = 'login' ): string {
	$slug = logliy_custom_login_slug();
	return home_url( '/' . $slug . '/', $scheme );
}

/**
 * Request path helper.
 */
function logliy_request_path(): string {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	return untrailingslashit( $path !== '' ? $path : '/' );
}

/**
 * Whether the current request is the custom login slug.
 */
function logliy_is_custom_login_request(): bool {
	if ( defined( 'LOGLIY_CUSTOM_LOGIN' ) && LOGLIY_CUSTOM_LOGIN ) {
		return true;
	}
	if ( (int) get_query_var( 'logliy_login' ) === 1 ) {
		return true;
	}
	$slug = logliy_custom_login_slug();
	$path = logliy_request_path();
	$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	$base = ( $home === '' || $home === '/' ) ? '' : $home;
	$want = untrailingslashit( $base . '/' . $slug );
	return $path === $want || $path === '/' . $slug;
}

/**
 * Whether this is an allowed admin endpoint for guests (ajax/post).
 */
function logliy_is_public_admin_endpoint(): bool {
	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$path   = logliy_request_path();
	if ( false !== stripos( $script, 'admin-ajax.php' ) || false !== stripos( $path, '/admin-ajax.php' ) ) {
		return true;
	}
	if ( false !== stripos( $script, 'admin-post.php' ) || false !== stripos( $path, '/admin-post.php' ) ) {
		return true;
	}
	return false;
}

/**
 * Soft 404 — do not reveal the real login URL.
 *
 * Never loads the theme 404 template during early bootstrap (e.g. init from
 * /wp-admin). Themes like Divi call builder helpers that are not loaded yet
 * and fatally error (et_pb_is_pagebuilder_used).
 */
function logliy_hide_login_deny(): void {
	status_header( 404 );
	nocache_headers();

	/*
	 * Plain 404 response — safe at any load stage (admin init, login_init, etc.).
	 * Avoid get_header()/theme templates here.
	 */
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	$title = esc_html__( 'Page not found.', 'logliy' );
	$body  = esc_html__( 'Page not found.', 'logliy' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- title/body escaped above.
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>'
		. $title
		. '</title></head><body><h1>'
		. $body
		. '</h1></body></html>';
	exit;
}

/**
 * Register rewrite + query var.
 */
add_action( 'init', 'logliy_hide_login_init', 5 );
function logliy_hide_login_init(): void {
	add_rewrite_tag( '%logliy_login%', '([0-9]+)' );
	if ( ! logliy_custom_login_url_active() ) {
		return;
	}
	$slug = logliy_custom_login_slug();
	add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?logliy_login=1', 'top' );

	if ( get_option( 'logliy_flush_rewrite' ) ) {
		flush_rewrite_rules( false );
		delete_option( 'logliy_flush_rewrite' );
	}
}

/**
 * @param array<string, mixed> $vars Vars.
 * @return array<string, mixed>
 */
add_filter( 'query_vars', 'logliy_hide_login_query_vars' );
function logliy_hide_login_query_vars( array $vars ): array {
	$vars[] = 'logliy_login';
	return $vars;
}

/**
 * Early: hide wp-admin for guests (404, no redirect to /cp).
 */
add_action( 'init', 'logliy_hide_login_protect_admin', 0 );
function logliy_hide_login_protect_admin(): void {
	if ( ! logliy_custom_login_url_active() || is_user_logged_in() ) {
		return;
	}
	if ( logliy_is_custom_login_request() ) {
		return;
	}
	if ( logliy_is_public_admin_endpoint() ) {
		return;
	}

	$path = logliy_request_path();
	if ( preg_match( '#/wp-admin(?:/|$)#', $path ) || ( is_admin() && ! wp_doing_ajax() ) ) {
		logliy_hide_login_deny();
	}
}

/**
 * Serve wp-login.php on the custom slug.
 */
add_action( 'template_redirect', 'logliy_hide_login_template_redirect', 1 );
function logliy_hide_login_template_redirect(): void {
	if ( ! logliy_custom_login_url_active() ) {
		return;
	}
	if ( (int) get_query_var( 'logliy_login' ) === 1 || logliy_is_custom_login_request() ) {
		logliy_load_wp_login();
	}
}

/**
 * Intercept requests that hit wp-login.php directly → 404 (hide).
 */
add_action( 'login_init', 'logliy_hide_login_block_wp_login', 1 );
function logliy_hide_login_block_wp_login(): void {
	if ( ! logliy_custom_login_url_active() ) {
		return;
	}
	if ( defined( 'LOGLIY_CUSTOM_LOGIN' ) && LOGLIY_CUSTOM_LOGIN ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action  = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : 'login';
	$allowed = array( 'logout', 'postpass', 'confirmaction' );
	if ( in_array( $action, $allowed, true ) ) {
		return;
	}

	$path = logliy_request_path();
	if ( false !== stripos( $path, 'wp-login.php' ) ) {
		logliy_hide_login_deny();
	}
}

/**
 * If core tries to redirect guests from wp-admin → login URL, show 404 instead.
 *
 * @param string $location Redirect URL.
 * @param int    $status   HTTP status.
 * @return string
 */
add_filter( 'wp_redirect', 'logliy_hide_login_block_admin_redirect', 10, 2 );
function logliy_hide_login_block_admin_redirect( string $location, int $status ) {
	unset( $status );
	if ( ! logliy_custom_login_url_active() || is_user_logged_in() ) {
		return $location;
	}
	if ( logliy_is_public_admin_endpoint() || logliy_is_custom_login_request() ) {
		return $location;
	}

	$path = logliy_request_path();
	if ( ! preg_match( '#/wp-admin(?:/|$)#', $path ) && ! is_admin() ) {
		return $location;
	}

	$slug = logliy_custom_login_slug();
	if ( false !== stripos( $location, 'wp-login.php' ) || false !== stripos( $location, '/' . $slug ) ) {
		logliy_hide_login_deny();
	}

	return $location;
}

/**
 * Load core wp-login.php under the custom URL.
 */
function logliy_load_wp_login(): void {
	if ( ! defined( 'LOGLIY_CUSTOM_LOGIN' ) ) {
		define( 'LOGLIY_CUSTOM_LOGIN', true );
	}

	/*
	 * wp-login.php expects these when rendered as a standalone script.
	 * Including it from template_redirect leaves them undefined → PHP warnings.
	 */
	global $pagenow, $user_login, $error, $errors, $interim_login, $action, $redirect_to;
	$pagenow       = 'wp-login.php';
	$user_login    = '';
	$error         = '';
	$errors        = new WP_Error();
	$interim_login = false;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- mirroring core login bootstrap.
	if ( ! isset( $_REQUEST['action'] ) || $_REQUEST['action'] === '' ) {
		$_REQUEST['action'] = 'login';
		$_GET['action']     = 'login';
	}
	$action = sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( empty( $redirect_to ) && isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_to = esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
	if ( empty( $redirect_to ) ) {
		$redirect_to = admin_url();
	}

	// Help core treat this like a login request for relative assets / checks.
	$_SERVER['PHP_SELF'] = '/wp-login.php';

	require ABSPATH . 'wp-login.php';
	exit;
}

/**
 * Rewrite wp-login.php URLs to the custom slug (emails, logout links, etc.).
 *
 * @param string      $url    URL.
 * @param string      $path   Path.
 * @param string|null $scheme Scheme.
 * @return string
 */
add_filter( 'site_url', 'logliy_hide_login_site_url', 10, 3 );
add_filter( 'network_site_url', 'logliy_hide_login_site_url', 10, 3 );
function logliy_hide_login_site_url( string $url, string $path, $scheme ) {
	if ( ! logliy_custom_login_url_active() ) {
		return $url;
	}
	if ( strpos( $path, 'wp-login.php' ) === false && strpos( $url, 'wp-login.php' ) === false ) {
		return $url;
	}
	$slug  = logliy_custom_login_slug();
	$query = '';
	$parts = wp_parse_url( $url );
	if ( ! empty( $parts['query'] ) ) {
		$query = '?' . $parts['query'];
	}
	return home_url( '/' . $slug . '/' . $query, is_string( $scheme ) ? $scheme : null );
}

/**
 * Admin notice when custom URL is on but a conflict disables it.
 */
add_action( 'admin_notices', 'logliy_hide_login_conflict_notice' );
function logliy_hide_login_conflict_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! logliy_get_setting( 'enable_custom_login_url', false ) ) {
		return;
	}
	$conflict = logliy_conflicting_hide_login_plugin();
	if ( ! $conflict ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	echo esc_html(
		sprintf(
			/* translators: %s: conflicting plugin name */
			__( 'Logliy custom login URL is disabled because %s is active. Use that plugin’s login URL instead.', 'logliy' ),
			$conflict
		)
	);
	echo '</p></div>';
}
