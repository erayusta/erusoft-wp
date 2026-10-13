<?php
/**
 * Plugin Name:       Automatic Discounts for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/automatic-discounts-for-woocommerce/
 * Description:       Coupon-free automatic discounts: percentage or fixed amount off the cart when the subtotal reaches a threshold, or when enough items of a category are in the cart. Date ranges, priorities and "stop further rules".
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       automatic-discounts-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_AD_VERSION', '1.0.0' );
define( 'ERU_AD_FILE', __FILE__ );
define( 'ERU_AD_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_AD_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'automatic-discounts-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_AD_PATH . 'includes/class-eru-ad-rules.php';
		require_once ERU_AD_PATH . 'includes/class-eru-ad-cart.php';
		Eru_AD_Cart::init();

		if ( is_admin() ) {
			require_once ERU_AD_PATH . 'includes/class-eru-ad-admin.php';
			Eru_AD_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_AD_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-ad' ) ) . '">' . esc_html__( 'Ayarlar', 'automatic-discounts-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
