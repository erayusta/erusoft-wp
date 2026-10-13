<?php
/**
 * Plugin Name:       Click & Collect for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/click-collect-for-woocommerce/
 * Description:       Store pickup as a real shipping method: configurable pickup locations, location chosen at checkout and saved on the order, shown in admin, e-mails and My Account. Optional "Ready for pickup" order status.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       click-collect-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CCL_VERSION', '1.0.0' );
define( 'ERU_CCL_FILE', __FILE__ );
define( 'ERU_CCL_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'click-collect-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_CCL_PATH . 'includes/class-eru-ccl-locations.php';
		require_once ERU_CCL_PATH . 'includes/class-eru-ccl-checkout.php';
		require_once ERU_CCL_PATH . 'includes/class-eru-ccl-admin.php';

		Eru_CCL_Checkout::init();
		Eru_CCL_Admin::init();

		add_filter(
			'woocommerce_shipping_methods',
			function ( $methods ) {
				require_once ERU_CCL_PATH . 'includes/class-eru-ccl-shipping-method.php';
				$methods['eru_ccl'] = 'Eru_CCL_Shipping_Method';
				return $methods;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CCL_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-ccl' ) ) . '">' . esc_html__( 'Teslim noktaları', 'click-collect-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
