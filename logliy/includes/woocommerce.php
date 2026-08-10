<?php
/**
 * WooCommerce classic + Blocks login bridge.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap only when WooCommerce is active.
 */
add_action( 'plugins_loaded', 'logliy_woocommerce_boot', 20 );
function logliy_woocommerce_boot(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	add_action( 'woocommerce_login_form_start', 'logliy_wc_login_assets', 5 );
	add_action( 'woocommerce_login_form_start', 'logliy_wc_render_panel', 10 );
	add_filter( 'woocommerce_login_redirect', 'logliy_wc_login_redirect', 10, 2 );

	add_action( 'wp_enqueue_scripts', 'logliy_wc_blocks_assets', 20 );
	add_filter( 'render_block_woocommerce/checkout', 'logliy_wc_blocks_prepend_panel', 10, 2 );
	add_filter( 'render_block_woocommerce/customer-account', 'logliy_wc_blocks_prepend_panel', 10, 2 );
}

/**
 * Enqueue assets on WC classic login forms.
 */
function logliy_wc_login_assets(): void {
	if ( is_admin() ) {
		return;
	}
	$on_account  = function_exists( 'is_account_page' ) && is_account_page() && logliy_get_setting( 'wc_enable_myaccount', true );
	$on_checkout = function_exists( 'is_checkout' ) && is_checkout() && logliy_get_setting( 'wc_enable_checkout', true );
	if ( ! $on_account && ! $on_checkout ) {
		return;
	}
	logliy_register_login_assets();
	wp_enqueue_style( 'logliy-login' );
	wp_enqueue_script( 'logliy-login' );
	wp_enqueue_script( 'logliy-passkey' );
}

/**
 * Render Logliy panel above WC classic login form.
 */
function logliy_wc_render_panel(): void {
	$on_account  = function_exists( 'is_account_page' ) && is_account_page() && logliy_get_setting( 'wc_enable_myaccount', true );
	$on_checkout = function_exists( 'is_checkout' ) && is_checkout() && logliy_get_setting( 'wc_enable_checkout', true );
	if ( ! $on_account && ! $on_checkout ) {
		return;
	}
	$context = $on_checkout ? 'checkout' : 'woocommerce';
	echo logliy_get_login_panel_html( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Prefer checkout URL when logging in from checkout.
 *
 * @param string  $redirect Redirect URL.
 * @param WP_User $user     User.
 */
function logliy_wc_login_redirect( string $redirect, $user ): string {
	unset( $user );
	if ( function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'wc_get_checkout_url' ) ) {
		return wc_get_checkout_url();
	}
	return $redirect;
}

/**
 * Enqueue assets for block checkout / account when guest.
 */
function logliy_wc_blocks_assets(): void {
	if ( is_admin() || is_user_logged_in() || ! logliy_get_setting( 'wc_enable_blocks', true ) ) {
		return;
	}
	$need = false;
	if ( function_exists( 'is_checkout' ) && is_checkout() && logliy_get_setting( 'wc_enable_checkout', true ) ) {
		$need = true;
	}
	if ( function_exists( 'is_account_page' ) && is_account_page() && logliy_get_setting( 'wc_enable_myaccount', true ) ) {
		$need = true;
	}
	if ( ! $need ) {
		return;
	}
	logliy_register_login_assets();
	wp_enqueue_style( 'logliy-login' );
	wp_enqueue_script( 'logliy-login' );
	wp_enqueue_script( 'logliy-passkey' );
}

/**
 * Prepend Logliy panel to WooCommerce block markup for guests.
 *
 * @param string               $content Block HTML.
 * @param array<string, mixed> $block   Block.
 */
function logliy_wc_blocks_prepend_panel( string $content, array $block ): string {
	unset( $block );
	if ( is_user_logged_in() || ! logliy_get_setting( 'wc_enable_blocks', true ) ) {
		return $content;
	}
	$context = ( function_exists( 'is_checkout' ) && is_checkout() ) ? 'checkout-blocks' : 'woocommerce-blocks';
	return logliy_get_login_panel_html( $context ) . $content;
}
