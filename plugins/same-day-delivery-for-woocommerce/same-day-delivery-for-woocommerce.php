<?php
/**
 * Plugin Name:       Same-Day Delivery for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/same-day-delivery-for-woocommerce/
 * Description:       Cut-off time, working days and holidays: shows "ships today / tomorrow" on product and cart pages and adds an optional same-day shipping method for eligible cities/districts.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       same-day-delivery-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SDD_VERSION', '1.0.0' );
define( 'ERU_SDD_FILE', __FILE__ );
define( 'ERU_SDD_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_SDD_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'same-day-delivery-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_SDD_PATH . 'includes/class-eru-sdd-schedule.php';
		require_once ERU_SDD_PATH . 'includes/class-eru-sdd-frontend.php';
		require_once ERU_SDD_PATH . 'includes/class-eru-sdd-admin.php';

		Eru_SDD_Frontend::init();
		Eru_SDD_Admin::init();

		add_filter(
			'woocommerce_shipping_methods',
			function ( $methods ) {
				require_once ERU_SDD_PATH . 'includes/class-eru-sdd-shipping-method.php';
				$methods['eru_sdd'] = 'Eru_SDD_Shipping_Method';
				return $methods;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_SDD_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-sdd' ) ) . '">' . esc_html__( 'Ayarlar', 'same-day-delivery-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
