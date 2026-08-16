<?php
/**
 * Settings defaults and accessors.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default settings (password login off by default).
 *
 * @return array<string, mixed>
 */
function logliy_default_settings(): array {
	return array(
		'enable_passkey'               => true,
		'enable_email_otp'             => true,
		'enable_magic_link'            => true,
		'allow_password_login'         => false,
		'allow_xmlrpc_password'        => false,
		'password_allowed_roles'       => array(),
		'otp_ttl_minutes'              => 10,
		'otp_length'                   => 6,
		'otp_rate_limit_account'       => 5,
		'otp_rate_limit_ip'            => 20,
		'otp_rate_window_minutes'      => 15,
		'email_request_cooldown_seconds' => 60,
		'magic_link_ttl_minutes'       => 15,
		'passkey_uv'                   => 'required',
		'passkey_resident_key'         => 'required',
		'rp_id'                        => '',
		'rp_name'                      => '',
		'related_origins'              => array(),
		'wc_enable_myaccount'          => true,
		'wc_enable_checkout'           => true,
		'wc_enable_blocks'             => true,
		'hide_wp_login_password'       => true,
		'onboarding_dismissed'         => false,
		'login_brand_name'             => '',
		'login_logo_id'                => 0,
		'login_tagline'                => '',
		'auto_remember'                => true,
		'login_identifier'             => 'both',
		'hide_language_switcher'       => false,
		'delete_settings_on_uninstall' => false,
		// Redirects.
		'redirect_login_default'       => '',
		'redirect_logout_default'      => '',
		'redirect_login_roles'         => array(),
		'redirect_logout_roles'        => array(),
		// Custom login URL.
		'enable_custom_login_url'      => false,
		'custom_login_slug'            => 'cp',
		// Session.
		'session_expire_hours'         => 48,
		'session_remember_days'        => 14,
		'admin_idle_timeout_minutes'   => 0,
		// Branding extras.
		'login_bg_color'               => '',
		'login_bg_image_id'            => 0,
		'hide_back_to_blog'            => false,
		'hide_lost_password'           => false,
		'login_custom_css'             => '',
		'login_footer_text'            => '',
	);
}

/**
 * Ensure options exist (activation / upgrade).
 */
function logliy_settings_ensure_defaults(): void {
	$existing = get_option( LOGLIY_OPT_SETTINGS, null );
	if ( ! is_array( $existing ) ) {
		add_option( LOGLIY_OPT_SETTINGS, logliy_default_settings(), '', false );
		return;
	}
	$merged = array_merge( logliy_default_settings(), $existing );
	update_option( LOGLIY_OPT_SETTINGS, $merged, false );
}

/**
 * Get all settings.
 *
 * @return array<string, mixed>
 */
function logliy_get_settings(): array {
	$stored = get_option( LOGLIY_OPT_SETTINGS, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return array_merge( logliy_default_settings(), $stored );
}

/**
 * Get a single setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function logliy_get_setting( string $key, $default = null ) {
	$settings = logliy_get_settings();
	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
}

/**
 * Update settings (partial merge).
 *
 * @param array<string, mixed> $patch Partial settings.
 * @return array<string, mixed>
 */
function logliy_update_settings( array $patch ): array {
	$settings = array_merge( logliy_get_settings(), $patch );
	update_option( LOGLIY_OPT_SETTINGS, $settings, false );
	return $settings;
}

/**
 * Sanitize settings from admin form or import.
 *
 * @param array<string, mixed>      $input Raw input.
 * @param array<string, mixed>|null $base  Optional baseline (defaults to current settings).
 *                                         Pass logliy_default_settings() for a full replace import.
 * @return array<string, mixed>
 */
function logliy_sanitize_settings( array $input, ?array $base = null ): array {
	$defaults = logliy_default_settings();
	if ( is_array( $base ) ) {
		$out = array_merge( $defaults, $base );
	} else {
		$out = logliy_get_settings();
	}

	$bool_keys = array(
		'enable_passkey',
		'enable_email_otp',
		'enable_magic_link',
		'allow_password_login',
		'allow_xmlrpc_password',
		'wc_enable_myaccount',
		'wc_enable_checkout',
		'wc_enable_blocks',
		'hide_wp_login_password',
		'onboarding_dismissed',
		'auto_remember',
		'hide_language_switcher',
		'delete_settings_on_uninstall',
		'enable_custom_login_url',
		'hide_back_to_blog',
		'hide_lost_password',
	);
	foreach ( $bool_keys as $key ) {
		if ( array_key_exists( $key, $input ) ) {
			$val          = $input[ $key ];
			$out[ $key ] = ( $val === '1' || $val === 1 || $val === true );
		}
	}

	// Unchecked checkboxes from full form posts.
	if ( isset( $input['_logliy_form'] ) && $input['_logliy_form'] === '1' ) {
		foreach ( $bool_keys as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$out[ $key ] = false;
			}
		}
	}

	if ( isset( $input['otp_ttl_minutes'] ) ) {
		$out['otp_ttl_minutes'] = max( 1, min( 60, (int) $input['otp_ttl_minutes'] ) );
	}
	if ( isset( $input['otp_length'] ) ) {
		$out['otp_length'] = max( 4, min( 8, (int) $input['otp_length'] ) );
	}
	if ( isset( $input['otp_rate_limit_account'] ) ) {
		$out['otp_rate_limit_account'] = max( 1, min( 50, (int) $input['otp_rate_limit_account'] ) );
	}
	if ( isset( $input['otp_rate_limit_ip'] ) ) {
		$out['otp_rate_limit_ip'] = max( 1, min( 200, (int) $input['otp_rate_limit_ip'] ) );
	}
	if ( isset( $input['otp_rate_window_minutes'] ) ) {
		$out['otp_rate_window_minutes'] = max( 1, min( 120, (int) $input['otp_rate_window_minutes'] ) );
	}
	if ( isset( $input['email_request_cooldown_seconds'] ) ) {
		$out['email_request_cooldown_seconds'] = max( 0, min( 600, (int) $input['email_request_cooldown_seconds'] ) );
	}
	if ( isset( $input['magic_link_ttl_minutes'] ) ) {
		$out['magic_link_ttl_minutes'] = max( 5, min( 60, (int) $input['magic_link_ttl_minutes'] ) );
	}
	if ( isset( $input['session_expire_hours'] ) ) {
		$out['session_expire_hours'] = max( 1, min( 336, (int) $input['session_expire_hours'] ) );
	}
	if ( isset( $input['session_remember_days'] ) ) {
		$out['session_remember_days'] = max( 1, min( 90, (int) $input['session_remember_days'] ) );
	}
	if ( isset( $input['admin_idle_timeout_minutes'] ) ) {
		$out['admin_idle_timeout_minutes'] = max( 0, min( 480, (int) $input['admin_idle_timeout_minutes'] ) );
	}

	if ( isset( $input['passkey_uv'] ) && in_array( $input['passkey_uv'], array( 'required', 'preferred', 'discouraged' ), true ) ) {
		$out['passkey_uv'] = $input['passkey_uv'];
	}
	if ( isset( $input['passkey_resident_key'] ) && in_array( $input['passkey_resident_key'], array( 'required', 'preferred', 'discouraged' ), true ) ) {
		$out['passkey_resident_key'] = $input['passkey_resident_key'];
	}

	if ( isset( $input['rp_id'] ) ) {
		$out['rp_id'] = sanitize_text_field( (string) $input['rp_id'] );
	}
	if ( isset( $input['rp_name'] ) ) {
		$out['rp_name'] = sanitize_text_field( (string) $input['rp_name'] );
	}

	if ( isset( $input['login_brand_name'] ) ) {
		$out['login_brand_name'] = sanitize_text_field( (string) $input['login_brand_name'] );
	}
	if ( isset( $input['login_tagline'] ) ) {
		$out['login_tagline'] = sanitize_text_field( (string) $input['login_tagline'] );
	}
	if ( isset( $input['login_logo_id'] ) ) {
		$out['login_logo_id'] = absint( $input['login_logo_id'] );
	}
	if ( isset( $input['login_bg_image_id'] ) ) {
		$out['login_bg_image_id'] = absint( $input['login_bg_image_id'] );
	}
	if ( isset( $input['login_bg_color'] ) ) {
		$color = sanitize_hex_color( (string) $input['login_bg_color'] );
		$out['login_bg_color'] = is_string( $color ) ? $color : '';
	}
	// Arbitrary CSS is not accepted (WordPress.org guideline).
	$out['login_custom_css'] = '';
	if ( isset( $input['login_footer_text'] ) ) {
		$out['login_footer_text'] = wp_kses_post( (string) $input['login_footer_text'] );
	}

	if ( isset( $input['custom_login_slug'] ) ) {
		$slug = sanitize_title( (string) $input['custom_login_slug'] );
		$reserved = array( 'wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', 'dashboard', 'feed', 'wp-json' );
		if ( $slug === '' || in_array( $slug, $reserved, true ) ) {
			$slug = 'cp';
		}
		$out['custom_login_slug'] = $slug;
	}

	if ( isset( $input['redirect_login_default'] ) ) {
		$out['redirect_login_default'] = esc_url_raw( trim( (string) $input['redirect_login_default'] ) );
	}
	if ( isset( $input['redirect_logout_default'] ) ) {
		$out['redirect_logout_default'] = esc_url_raw( trim( (string) $input['redirect_logout_default'] ) );
	}

	if ( isset( $input['redirect_login_roles'] ) && is_array( $input['redirect_login_roles'] ) ) {
		$out['redirect_login_roles'] = logliy_sanitize_role_url_map( $input['redirect_login_roles'] );
	}
	if ( isset( $input['redirect_logout_roles'] ) && is_array( $input['redirect_logout_roles'] ) ) {
		$out['redirect_logout_roles'] = logliy_sanitize_role_url_map( $input['redirect_logout_roles'] );
	}

	if ( isset( $input['login_identifier'] ) && in_array( (string) $input['login_identifier'], array( 'both', 'username', 'email' ), true ) ) {
		$out['login_identifier'] = (string) $input['login_identifier'];
	}

	if ( isset( $input['password_allowed_roles'] ) && is_array( $input['password_allowed_roles'] ) ) {
		$roles = array();
		foreach ( $input['password_allowed_roles'] as $role ) {
			$role = sanitize_key( (string) $role );
			if ( $role !== '' ) {
				$roles[] = $role;
			}
		}
		$out['password_allowed_roles'] = array_values( array_unique( $roles ) );
	} elseif ( isset( $input['_logliy_form'] ) ) {
		$out['password_allowed_roles'] = array();
	}

	if ( isset( $input['related_origins'] ) ) {
		$raw = is_array( $input['related_origins'] ) ? $input['related_origins'] : preg_split( '/[\r\n,]+/', (string) $input['related_origins'] );
		$origins = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $origin ) {
				$origin = esc_url_raw( trim( (string) $origin ) );
				if ( $origin !== '' ) {
					$origins[] = untrailingslashit( $origin );
				}
			}
		}
		$out['related_origins'] = array_values( array_unique( $origins ) );
	}

	unset( $defaults );

	unset( $out['_logliy_form'] );

	// Flush rewrite rules when custom login URL settings change.
	$prev_enable = (bool) logliy_get_setting( 'enable_custom_login_url', false );
	$prev_slug   = (string) logliy_get_setting( 'custom_login_slug', 'cp' );
	$new_enable  = ! empty( $out['enable_custom_login_url'] );
	$new_slug    = (string) ( $out['custom_login_slug'] ?? 'cp' );
	if ( $prev_enable !== $new_enable || $prev_slug !== $new_slug ) {
		update_option( 'logliy_flush_rewrite', 1, false );
	}

	return $out;
}

/**
 * Sanitize role => URL map from admin form.
 *
 * @param array<string, mixed> $raw Raw map.
 * @return array<string, string>
 */
function logliy_sanitize_role_url_map( array $raw ): array {
	$out = array();
	foreach ( $raw as $role => $url ) {
		$role = sanitize_key( (string) $role );
		$url  = esc_url_raw( trim( (string) $url ) );
		if ( $role !== '' && $url !== '' ) {
			$out[ $role ] = $url;
		}
	}
	return $out;
}

/**
 * Relying Party ID (hostname).
 */
function logliy_rp_id(): string {
	$custom = (string) logliy_get_setting( 'rp_id', '' );
	if ( $custom !== '' ) {
		return $custom;
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return is_string( $host ) ? $host : 'localhost';
}

/**
 * Relying Party display name.
 */
function logliy_rp_name(): string {
	$custom = (string) logliy_get_setting( 'rp_name', '' );
	if ( $custom !== '' ) {
		return $custom;
	}
	return (string) get_bloginfo( 'name' );
}

/**
 * Display name on the login panel (custom or default Logliy).
 */
function logliy_login_brand_name(): string {
	$custom = trim( (string) logliy_get_setting( 'login_brand_name', '' ) );
	if ( $custom !== '' ) {
		return $custom;
	}
	return __( 'Logliy', 'logliy' );
}

/**
 * Optional login tagline.
 */
function logliy_login_tagline(): string {
	$custom = trim( (string) logliy_get_setting( 'login_tagline', '' ) );
	if ( $custom !== '' ) {
		return $custom;
	}
	return __( 'Sign in with a Passkey or Email code.', 'logliy' );
}

/**
 * Optional custom logo URL for the login panel.
 */
function logliy_login_logo_url(): string {
	$id = (int) logliy_get_setting( 'login_logo_id', 0 );
	if ( $id <= 0 ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $id, 'medium' );
	return is_string( $url ) ? $url : '';
}

/**
 * Site origin (scheme://host[:port]).
 */
function logliy_origin(): string {
	$parts  = wp_parse_url( home_url() );
	$scheme = $parts['scheme'] ?? 'https';
	$host   = $parts['host'] ?? 'localhost';
	$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
	return $scheme . '://' . $host . $port;
}

/**
 * Login identifier mode: both|username|email.
 */
function logliy_login_identifier_mode(): string {
	$mode = (string) logliy_get_setting( 'login_identifier', 'both' );
	return in_array( $mode, array( 'both', 'username', 'email' ), true ) ? $mode : 'both';
}

/**
 * Human-readable login field label for the active identifier mode.
 */
function logliy_login_identifier_label( bool $optional = false ): string {
	switch ( logliy_login_identifier_mode() ) {
		case 'email':
			$label = __( 'Email address', 'logliy' );
			break;
		case 'username':
			$label = __( 'Username', 'logliy' );
			break;
		default:
			$label = __( 'Email or username', 'logliy' );
			break;
	}
	if ( $optional ) {
		/* translators: %s: field label (email/username). */
		return sprintf( __( '%s (optional)', 'logliy' ), $label );
	}
	return $label;
}

/**
 * Empty-login validation message for the active identifier mode.
 */
function logliy_login_identifier_empty_message(): string {
	switch ( logliy_login_identifier_mode() ) {
		case 'email':
			return __( 'Please enter your email address.', 'logliy' );
		case 'username':
			return __( 'Please enter your username.', 'logliy' );
		default:
			return __( 'Please enter your email or username.', 'logliy' );
	}
}

/**
 * Resolve a WP_User from a login string according to identifier settings.
 *
 * @return WP_User|null
 */
function logliy_resolve_login_user( string $login ): ?WP_User {
	$login = trim( $login );
	if ( $login === '' ) {
		return null;
	}

	$mode = logliy_login_identifier_mode();

	if ( $mode === 'email' ) {
		if ( ! is_email( $login ) ) {
			return null;
		}
		$user = get_user_by( 'email', $login );
		return $user instanceof WP_User ? $user : null;
	}

	if ( $mode === 'username' ) {
		$user = get_user_by( 'login', $login );
		return $user instanceof WP_User ? $user : null;
	}

	if ( is_email( $login ) ) {
		$user = get_user_by( 'email', $login );
		if ( $user instanceof WP_User ) {
			return $user;
		}
	}

	$user = get_user_by( 'login', $login );
	return $user instanceof WP_User ? $user : null;
}

/**
 * Whether "Remember me" should start checked.
 */
function logliy_auto_remember(): bool {
	return (bool) logliy_get_setting( 'auto_remember', true );
}
