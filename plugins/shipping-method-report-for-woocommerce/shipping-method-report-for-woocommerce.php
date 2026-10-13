<?php
/**
 * Plugin Name:       Shipping Method Report for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/shipping-method-report-for-woocommerce/
 * Description:       Admin report of orders and product quantities per shipping method (and per pickup location) for a date range, with CSV export.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shipping-method-report-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SMR_VERSION', '1.0.0' );
define( 'ERU_SMR_FILE', __FILE__ );
define( 'ERU_SMR_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'shipping-method-report-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_SMR_PATH . 'includes/class-eru-smr-report.php';
		require_once ERU_SMR_PATH . 'includes/class-eru-smr-admin.php';
		Eru_SMR_Admin::init();
	}
);
