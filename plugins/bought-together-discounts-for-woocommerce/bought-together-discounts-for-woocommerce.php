<?php
/**
 * Plugin Name:       Bought Together Discounts for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/bought-together-discounts-for-woocommerce/
 * Description:       Define "bought together" product sets with a set discount. A block on the product page offers the set with an add-all-to-cart button; the discount is applied automatically when the whole set is in the cart.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bought-together-discounts-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_BTD_VERSION', '1.0.0' );
define( 'ERU_BTD_FILE', __FILE__ );
define( 'ERU_BTD_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_BTD_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'bought-together-discounts-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_BTD_PATH . 'includes/class-eru-btd-sets.php';
		require_once ERU_BTD_PATH . 'includes/class-eru-btd-cart.php';
		require_once ERU_BTD_PATH . 'includes/class-eru-btd-render.php';
		Eru_BTD_Cart::init();
		Eru_BTD_Render::init();

		if ( is_admin() ) {
			require_once ERU_BTD_PATH . 'includes/class-eru-btd-admin.php';
			Eru_BTD_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_BTD_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-btd' ) ) . '">' . esc_html__( 'Ayarlar', 'bought-together-discounts-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
