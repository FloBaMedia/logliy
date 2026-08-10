<?php
/**
 * Settings import / export for cloning config between sites.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keys that are site-specific and should not transfer as-is.
 *
 * @return list<string>
 */
function logliy_settings_export_site_keys(): array {
	return array(
		'rp_id',            // Hostname differs per site.
		'login_logo_id',    // Attachment IDs are local.
		'login_bg_image_id',
	);
}

/**
 * Build export payload (JSON-serializable).
 *
 * @return array{plugin:string,version:string,exported_at:string,settings:array<string,mixed>}
 */
function logliy_settings_export_payload(): array {
	$settings = logliy_get_settings();
	$defaults = logliy_default_settings();
	foreach ( logliy_settings_export_site_keys() as $key ) {
		if ( array_key_exists( $key, $defaults ) ) {
			$settings[ $key ] = $defaults[ $key ];
		}
	}

	return array(
		'plugin'      => 'logliy',
		'version'     => LOGLIY_VERSION,
		'exported_at' => gmdate( 'c' ),
		'settings'    => $settings,
	);
}

/**
 * Import settings from a decoded export payload.
 *
 * @param array<string, mixed> $payload Decoded JSON.
 * @return true|WP_Error
 */
function logliy_settings_import_payload( array $payload ) {
	if ( ( $payload['plugin'] ?? '' ) !== 'logliy' ) {
		return new WP_Error(
			'logliy_import_plugin',
			__( 'This file is not a Logliy settings export.', 'logliy' )
		);
	}

	$raw = $payload['settings'] ?? null;
	if ( ! is_array( $raw ) ) {
		return new WP_Error(
			'logliy_import_settings',
			__( 'The export file has no settings object.', 'logliy' )
		);
	}

	$defaults = logliy_default_settings();
	$input    = array_intersect_key( $raw, $defaults );

	foreach ( logliy_settings_export_site_keys() as $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			continue;
		}
		if ( $key === 'login_logo_id' || $key === 'login_bg_image_id' ) {
			$id = absint( $input[ $key ] );
			$input[ $key ] = ( $id > 0 && wp_attachment_is_image( $id ) ) ? $id : 0;
		}
		if ( $key === 'rp_id' ) {
			// Empty → auto host; keep only if explicitly set and non-empty.
			$input[ $key ] = sanitize_text_field( (string) $input[ $key ] );
		}
	}

	// Sanitize against defaults (not the previous site config) without writing live mid-import.
	$clean = logliy_sanitize_settings( $input, $defaults );
	update_option( LOGLIY_OPT_SETTINGS, $clean, false );
	return true;
}

/**
 * Admin-post: download settings as JSON.
 */
add_action( 'admin_post_logliy_export_settings', 'logliy_handle_export_settings' );
function logliy_handle_export_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export settings.', 'logliy' ), 403 );
	}
	check_admin_referer( 'logliy_export_settings' );

	$payload = logliy_settings_export_payload();
	$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) ) {
		wp_die( esc_html__( 'Could not encode settings.', 'logliy' ), 500 );
	}

	$filename = 'logliy-settings-' . gmdate( 'Y-m-d' ) . '.json';
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . (string) strlen( $json ) );
	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw JSON download.
	exit;
}

/**
 * Admin-post: import settings from uploaded JSON.
 */
add_action( 'admin_post_logliy_import_settings', 'logliy_handle_import_settings' );
function logliy_handle_import_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to import settings.', 'logliy' ), 403 );
	}
	check_admin_referer( 'logliy_import_settings' );

	$redirect = admin_url( 'options-general.php?page=' . LOGLIY_ADMIN_PAGE . '&tab=advanced' );

	if ( empty( $_FILES['logliy_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		wp_safe_redirect( add_query_arg( 'logliy_import', 'missing', $redirect ) );
		exit;
	}

	$file = $_FILES['logliy_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( ! empty( $file['error'] ) ) {
		wp_safe_redirect( add_query_arg( 'logliy_import', 'upload', $redirect ) );
		exit;
	}

	$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
	if ( $size <= 0 || $size > 512 * 1024 ) {
		wp_safe_redirect( add_query_arg( 'logliy_import', 'size', $redirect ) );
		exit;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading uploaded temp file.
	$raw = file_get_contents( (string) $file['tmp_name'] );
	if ( ! is_string( $raw ) || $raw === '' ) {
		wp_safe_redirect( add_query_arg( 'logliy_import', 'empty', $redirect ) );
		exit;
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		wp_safe_redirect( add_query_arg( 'logliy_import', 'json', $redirect ) );
		exit;
	}

	$result = logliy_settings_import_payload( $data );
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg( 'logliy_import', 'invalid', $redirect ) );
		exit;
	}

	wp_safe_redirect( add_query_arg( 'logliy_import', 'ok', $redirect ) );
	exit;
}

/**
 * Flash notices after import redirect.
 */
add_action( 'admin_notices', 'logliy_import_admin_notices' );
function logliy_import_admin_notices(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash from our redirect.
	if ( ! isset( $_GET['page'] ) || (string) $_GET['page'] !== LOGLIY_ADMIN_PAGE ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$code = isset( $_GET['logliy_import'] ) ? sanitize_key( (string) wp_unslash( $_GET['logliy_import'] ) ) : '';
	if ( $code === '' ) {
		return;
	}

	$messages = array(
		'ok'      => array( 'success', __( 'Settings imported successfully. Review Relying Party ID and branding images on this site.', 'logliy' ) ),
		'missing' => array( 'error', __( 'Please choose a Logliy settings JSON file to import.', 'logliy' ) ),
		'upload'  => array( 'error', __( 'The upload failed. Please try again.', 'logliy' ) ),
		'size'    => array( 'error', __( 'The import file is empty or too large (max 512 KB).', 'logliy' ) ),
		'empty'   => array( 'error', __( 'The import file could not be read.', 'logliy' ) ),
		'json'    => array( 'error', __( 'The import file is not valid JSON.', 'logliy' ) ),
		'invalid' => array( 'error', __( 'The import file is not a valid Logliy settings export.', 'logliy' ) ),
	);

	if ( ! isset( $messages[ $code ] ) ) {
		return;
	}

	[ $class, $text ] = $messages[ $code ];
	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $class ),
		esc_html( $text )
	);
}
