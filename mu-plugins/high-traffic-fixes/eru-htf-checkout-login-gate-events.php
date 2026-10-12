<?php
/**
 * Plugin Name: HTF — Checkout Login Gate Events
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Stops GTM4WP begin_checkout and PixelYourSite InitiateCheckout events while a guest only sees the checkout login/registration wall.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: with these WooCommerce settings
 *
 *   woocommerce_enable_guest_checkout                  = no  -> is_registration_required() is true
 *   woocommerce_enable_signup_and_login_from_checkout  = no  -> is_registration_enabled() is false
 *
 * WooCommerce core (templates/checkout/form-checkout.php) exits early for guests and never
 * prints the checkout form; the theme's checkout/form-login.php login/registration wall is
 * shown instead. The page is still the checkout page, so is_checkout() returns true, and
 * both tracking plugins only look at that:
 *
 *   - GTM4WP queues a begin_checkout datalayer push when is_checkout()
 *   - PixelYourSite fires InitiateCheckout when is_checkout() && !is_wc_endpoint_url()
 *
 * Result: guests who never reached the payment step are counted as having begun checkout,
 * inflating the checkout step of the GA4 / Google Ads / Meta funnels.
 *
 * FIX: a detector mirrors WooCommerce core's early-exit condition ("login gate" state).
 * In that state the begin_checkout push is removed from GTM4WP's inline script right
 * before footer scripts are printed, and PixelYourSite's event is rejected through its own
 * 'pys_validate_pixel_event' filter (so the server-side Facebook CAPI copy is not sent
 * either). Logged-in users are not affected. If the store settings change and the wall
 * disappears, the guard idles by itself. Vendor files are never modified.
 *
 * Does nothing without WooCommerce. Without GTM4WP / PixelYourSite the callbacks find
 * nothing to remove.
 *
 * Disable: add_filter( 'eru_htf_checkout_login_gate_events_enabled', '__return_false' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the visitor looking at the checkout login/registration wall instead of the form?
 *
 * Mirrors the early-exit condition in WooCommerce's templates/checkout/form-checkout.php.
 * If that condition changes upstream, update this too.
 *
 * @return bool
 */
function eru_htf_clg_is_login_gate() {
	if ( ! apply_filters( 'eru_htf_checkout_login_gate_events_enabled', true ) ) {
		return false;
	}

	if ( is_admin() || wp_doing_cron() ) {
		return false;
	}

	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'is_checkout' ) || ! function_exists( 'WC' ) ) {
		return false;
	}

	// is_checkout() is not reliable before the main query is set up.
	if ( ! did_action( 'wp' ) ) {
		return false;
	}

	if ( ! is_checkout() ) {
		return false;
	}

	// Thank-you / order-pay pages count as checkout pages but are not the login wall.
	if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
		return false;
	}

	if ( is_user_logged_in() ) {
		return false;
	}

	$checkout = WC()->checkout();
	if ( ! $checkout ) {
		return false;
	}

	return ! $checkout->is_registration_enabled() && $checkout->is_registration_required();
}

/**
 * GTM4WP: remove the begin_checkout push before the inline script is printed.
 *
 * GTM4WP does not write the event directly: it queues it in
 * $GLOBALS['gtm4wp_additional_datalayer_pushes'], and gtm4wp_fire_additional_datalayer_pushes()
 * turns the queue into wp_add_inline_script( 'gtm4wp-additional-datalayer-pushes', ..., 'after' )
 * and resets it.
 *
 * That reset happens at the end of the plugin's own 'gtm4wp_compile_datalayer' callback,
 * so hooking the same filter later cannot clear the queue: it is already empty. The one
 * safe point is after the 'after' inline chunks exist but before they are printed, i.e.
 * right before footer scripts are printed.
 *
 * Only the begin_checkout entry is removed; everything else GTM4WP outputs stays.
 */
add_action( 'wp_print_footer_scripts', 'eru_htf_clg_strip_gtm4wp_begin_checkout', 1 );
function eru_htf_clg_strip_gtm4wp_begin_checkout() {
	if ( ! eru_htf_clg_is_login_gate() ) {
		return;
	}

	$handle  = 'gtm4wp-additional-datalayer-pushes';
	$scripts = wp_scripts();

	if ( ! isset( $scripts->registered[ $handle ] ) ) {
		return;
	}

	$registered = $scripts->registered[ $handle ];
	if ( empty( $registered->extra['after'] ) || ! is_array( $registered->extra['after'] ) ) {
		return;
	}

	$changed = false;
	foreach ( $registered->extra['after'] as $index => $code ) {
		if ( is_string( $code ) && preg_match( '/"event"\s*:\s*"begin_checkout"/', $code ) ) {
			unset( $registered->extra['after'][ $index ] );
			$changed = true;
		}
	}

	if ( $changed ) {
		$registered->extra['after'] = array_values( $registered->extra['after'] );
	}
}

/**
 * Safety net: if GTM4WP ever stops emptying its queue inside its own filter callback
 * (i.e. a push is still queued after the filter chain), the event is dropped here.
 * With current versions the queue is already empty and this callback does nothing.
 */
add_filter( 'gtm4wp_compile_datalayer', 'eru_htf_clg_clear_gtm4wp_queue', PHP_INT_MAX );
function eru_htf_clg_clear_gtm4wp_queue( $data_layer ) {
	if ( empty( $GLOBALS['gtm4wp_additional_datalayer_pushes'] ) || ! is_array( $GLOBALS['gtm4wp_additional_datalayer_pushes'] ) ) {
		return $data_layer;
	}

	if ( ! eru_htf_clg_is_login_gate() ) {
		return $data_layer;
	}

	foreach ( $GLOBALS['gtm4wp_additional_datalayer_pushes'] as $index => $push ) {
		if ( isset( $push['datalayer_object']['event'] ) && 'begin_checkout' === $push['datalayer_object']['event'] ) {
			unset( $GLOBALS['gtm4wp_additional_datalayer_pushes'][ $index ] );
		}
	}

	$GLOBALS['gtm4wp_additional_datalayer_pushes'] = array_values( $GLOBALS['gtm4wp_additional_datalayer_pushes'] );

	return $data_layer;
}

/**
 * PixelYourSite: reject the InitiateCheckout event when it is produced.
 *
 * 'pys_validate_pixel_event' runs for every pixel right before an event is added to the
 * list. Returning false stops both the browser-side pixel call and the Facebook
 * Conversions API copy fed from the same flow.
 *
 * @param bool   $valid Whether the event is valid so far.
 * @param object $event PixelYourSite SingleEvent.
 * @return bool
 */
add_filter( 'pys_validate_pixel_event', 'eru_htf_clg_block_pys_initiate_checkout', PHP_INT_MAX, 2 );
function eru_htf_clg_block_pys_initiate_checkout( $valid, $event = null ) {
	if ( ! $valid || ! is_object( $event ) ) {
		return $valid;
	}

	$id = method_exists( $event, 'getId' ) ? $event->getId() : '';

	$name = '';
	if ( method_exists( $event, 'getPayloadValue' ) ) {
		$name = (string) $event->getPayloadValue( 'name' );
	}

	$is_target = ( 'woo_initiate_checkout' === $id )
		|| in_array( $name, array( 'InitiateCheckout', 'begin_checkout' ), true );

	if ( ! $is_target ) {
		return $valid;
	}

	if ( ! eru_htf_clg_is_login_gate() ) {
		return $valid;
	}

	return false;
}
