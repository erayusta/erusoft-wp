<?php
/**
 * Plugin Name:       Gift Product Rules for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/gift-product-rules-for-woocommerce/
 * Description:       Automatically adds a free gift product to the cart when a rule matches (cart total, a product or a category in the cart) and removes it when the rule stops matching.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gift-product-rules-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_GPR_VERSION', '1.0.0' );
define( 'ERU_GPR_FILE', __FILE__ );
define( 'ERU_GPR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_GPR_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'gift-product-rules-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_GPR_PATH . 'includes/class-eru-gpr-rules.php';
		require_once ERU_GPR_PATH . 'includes/class-eru-gpr-cart.php';
		Eru_GPR_Cart::init();

		if ( is_admin() ) {
			require_once ERU_GPR_PATH . 'includes/class-eru-gpr-admin.php';
			Eru_GPR_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_GPR_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-gpr' ) ) . '">' . esc_html__( 'Ayarlar', 'gift-product-rules-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
