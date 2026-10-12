<?php
/**
 * Plugin Name: HTF — PixelYourSite Fragment Fix
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Replaces an undefined pys_generate_token() call in PixelYourSite's add-to-cart fragment with a server-generated token.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: PixelYourSite Pro's AJAX events fragment (#pys_ajax_events) prints
 *
 *     var pys_action_event_id = '<shared event id>' || pys_generate_token();
 *
 * pys_generate_token() is a global function in the plugin's unminified public.js, but the
 * minified file that sites actually load wraps everything in an IIFE and the minifier
 * mangles the name, so window.pys_generate_token is undefined in the browser. When the
 * PHP-side shared event id is empty, '' || pys_generate_token() throws a ReferenceError
 * and the rest of that inline script never runs: the unique event id sync and the pixel
 * fireEvent loop are skipped.
 *
 * FIX: the fragment HTML is intercepted before it is sent and the call is replaced with a
 * PHP-generated token literal (PixelYourSite's own generator when available, otherwise
 * wp_generate_uuid4()). Vendor files are not touched, so plugin updates do not undo it. If
 * the vendor fixes the bug the call pattern is gone and the fragment is left alone.
 *
 * Does nothing without WooCommerce or PixelYourSite (the fragment key will not exist).
 *
 * Disable: add_filter( 'eru_htf_pixelyoursite_fragment_fix_enabled', '__return_false' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'woocommerce_add_to_cart_fragments',
	static function ( $fragments ) {
		if ( ! is_array( $fragments ) || empty( $fragments['#pys_ajax_events'] ) ) {
			return $fragments;
		}

		if ( ! apply_filters( 'eru_htf_pixelyoursite_fragment_fix_enabled', true ) ) {
			return $fragments;
		}

		$html = $fragments['#pys_ajax_events'];
		if ( ! is_string( $html ) || false === strpos( $html, 'pys_generate_token(' ) ) {
			return $fragments;
		}

		if ( function_exists( 'PixelYourSite\\pys_generate_token' ) ) {
			$token = \PixelYourSite\pys_generate_token();
		} else {
			$token = wp_generate_uuid4();
		}

		// Matches pys_generate_token() as well as pys_generate_token(36) and similar forms.
		$fixed = preg_replace(
			'/pys_generate_token\s*\([^)]*\)/',
			"'" . esc_js( (string) $token ) . "'",
			$html
		);

		if ( null !== $fixed ) {
			$fragments['#pys_ajax_events'] = $fixed;
		}

		return $fragments;
	},
	999
);
