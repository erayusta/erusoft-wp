<?php
/**
 * Plugin Name:       Exit-Intent Coupons for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/exit-intent-coupons-for-woocommerce/
 * Description:       Shows a coupon pop-up to visitors who are about to leave the page: a fixed coupon code, or a single-use, time-limited coupon generated for each visitor within a daily budget.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       exit-intent-coupons-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_EIC_VERSION', '1.0.0' );
define( 'ERU_EIC_FILE', __FILE__ );
define( 'ERU_EIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_EIC_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'eru_eic_cleanup' );
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'exit-intent-coupons-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_EIC_PATH . 'includes/class-eru-eic-settings.php';
		require_once ERU_EIC_PATH . 'includes/class-eru-eic-coupon.php';
		require_once ERU_EIC_PATH . 'includes/class-eru-eic-front.php';
		Eru_EIC_Coupon::init();
		Eru_EIC_Front::init();

		if ( is_admin() ) {
			require_once ERU_EIC_PATH . 'includes/class-eru-eic-admin.php';
			Eru_EIC_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_EIC_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-eic' ) ) . '">' . esc_html__( 'Ayarlar', 'exit-intent-coupons-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
