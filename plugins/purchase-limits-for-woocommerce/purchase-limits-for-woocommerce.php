<?php
/**
 * Plugin Name:       Purchase Limits for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/purchase-limits-for-woocommerce/
 * Description:       Minimum and maximum quantities per product, per category and per order, plus a per-customer limit within a rolling period. Messages are configurable.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       purchase-limits-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PL_VERSION', '1.0.0' );
define( 'ERU_PL_FILE', __FILE__ );
define( 'ERU_PL_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_PL_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'purchase-limits-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_PL_PATH . 'includes/class-eru-pl-settings.php';
		require_once ERU_PL_PATH . 'includes/class-eru-pl-limits.php';
		Eru_PL_Limits::init();

		if ( is_admin() ) {
			require_once ERU_PL_PATH . 'includes/class-eru-pl-admin.php';
			Eru_PL_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_PL_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-pl' ) ) . '">' . esc_html__( 'Ayarlar', 'purchase-limits-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
