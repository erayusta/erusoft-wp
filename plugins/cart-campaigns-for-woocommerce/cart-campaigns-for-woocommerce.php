<?php
/**
 * Plugin Name:       Cart Campaigns for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/cart-campaigns-for-woocommerce/
 * Description:       Cart-threshold campaigns: tiered percentage or fixed discounts when the cart reaches an amount, with a progress message in the cart and mini-cart ("add X more and get Y off").
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cart-campaigns-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CC_VERSION', '1.0.0' );
define( 'ERU_CC_FILE', __FILE__ );
define( 'ERU_CC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_CC_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'cart-campaigns-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_CC_PATH . 'includes/class-eru-cc-campaigns.php';
		Eru_CC_Campaigns::init();

		if ( is_admin() ) {
			require_once ERU_CC_PATH . 'includes/class-eru-cc-admin.php';
			Eru_CC_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CC_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-cc' ) ) . '">' . esc_html__( 'Ayarlar', 'cart-campaigns-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
