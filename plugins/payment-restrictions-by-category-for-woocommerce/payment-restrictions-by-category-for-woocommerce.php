<?php
/**
 * Plugin Name:       Payment Restrictions by Category for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/payment-restrictions-by-category-for-woocommerce/
 * Description:       Hides selected payment gateways when the cart contains a product from chosen categories (or other product taxonomies) and shows an optional explanatory notice.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       payment-restrictions-by-category-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PRC_VERSION', '1.0.0' );
define( 'ERU_PRC_FILE', __FILE__ );
define( 'ERU_PRC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_PRC_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'payment-restrictions-by-category-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_PRC_PATH . 'includes/class-eru-prc-rules.php';
		require_once ERU_PRC_PATH . 'includes/class-eru-prc-frontend.php';
		Eru_PRC_Frontend::init();

		if ( is_admin() ) {
			require_once ERU_PRC_PATH . 'includes/class-eru-prc-admin.php';
			Eru_PRC_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_PRC_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-prc' ) ) . '">' . esc_html__( 'Ayarlar', 'payment-restrictions-by-category-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
