<?php
/**
 * Plugin Name:       Order JSON Export for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/order-json-export-for-woocommerce/
 * Description:       Exports WooCommerce orders as JSON with a documented schema: bulk action, date-range screen and an authenticated REST endpoint.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-json-export-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_OJE_VERSION', '1.0.0' );
define( 'ERU_OJE_FILE', __FILE__ );
define( 'ERU_OJE_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'order-json-export-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_OJE_PATH . 'includes/class-eru-oje-exporter.php';
		require_once ERU_OJE_PATH . 'includes/class-eru-oje-admin.php';
		require_once ERU_OJE_PATH . 'includes/class-eru-oje-rest.php';

		Eru_OJE_Admin::init();
		Eru_OJE_Rest::init();

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_OJE_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-oje' ) ) . '">' . esc_html__( 'Dışa aktar', 'order-json-export-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
