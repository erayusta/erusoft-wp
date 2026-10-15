<?php
/**
 * Plugin Name:       Turkish Shipping Carriers for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/turkish-shipping-carriers-for-woocommerce/
 * Description:       Turkish cargo carriers (Yurtiçi, Aras, MNG, PTT, Sürat, HepsiJET, Trendyol Express, Sendeo…) as a zone-compatible shipping method with per-carrier flat, per-desi and free-over-amount rates, plus a tracking number on the order with a tracking link in customer e-mails and My Account.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       turkish-shipping-carriers-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_TSC_VERSION', '1.0.1' );
define( 'ERU_TSC_FILE', __FILE__ );
define( 'ERU_TSC_PATH', plugin_dir_path( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'turkish-shipping-carriers-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_TSC_PATH . 'includes/class-eru-tsc-carriers.php';
		require_once ERU_TSC_PATH . 'includes/class-eru-tsc-tracking.php';
		require_once ERU_TSC_PATH . 'includes/class-eru-tsc-admin.php';

		Eru_TSC_Tracking::init();
		Eru_TSC_Admin::init();

		add_filter(
			'woocommerce_shipping_methods',
			function ( $methods ) {
				require_once ERU_TSC_PATH . 'includes/class-eru-tsc-shipping-method.php';
				$methods['eru_tsc'] = 'Eru_TSC_Shipping_Method';
				return $methods;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_TSC_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-tsc' ) ) . '">' . esc_html__( 'Kargo firmaları', 'turkish-shipping-carriers-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);

/**
 * Store a tracking number from code (saves the order; a new number sends the optional customer note and fires
 * `eru_tsc_tracking_saved`).
 *
 * @since 1.0.1
 * @param WC_Order    $order   Order.
 * @param string      $carrier Carrier id.
 * @param string|null $number  Tracking number; null keeps the stored one.
 * @return bool True when the number changed to a new non-empty value (false when the plugin is not loaded).
 */
function eru_tsc_set_tracking( $order, string $carrier, ?string $number = null ): bool {
	if ( ! class_exists( 'Eru_TSC_Tracking' ) || ! $order instanceof WC_Order ) {
		return false;
	}
	return Eru_TSC_Tracking::save_tracking( $order, $carrier, $number );
}

/**
 * Tracking data of an order: carrier id, carrier name, tracking number and tracking URL ('' when unknown).
 *
 * @param WC_Order|int $order Order or order ID.
 * @return array{carrier:string,carrier_name:string,number:string,url:string}
 */
function eru_tsc_get_tracking( $order ): array {
	if ( ! class_exists( 'Eru_TSC_Tracking' ) ) {
		return array( 'carrier' => '', 'carrier_name' => '', 'number' => '', 'url' => '' );
	}
	return Eru_TSC_Tracking::get( $order );
}
