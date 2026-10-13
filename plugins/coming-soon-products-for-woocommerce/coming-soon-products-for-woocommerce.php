<?php
/**
 * Plugin Name:       Coming Soon Products for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/coming-soon-products-for-woocommerce/
 * Description:       Mark individual products as coming soon: purchase is blocked, add-to-cart is hidden and a message or badge (with an optional release date) is shown instead of the price.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coming-soon-products-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CSP_VERSION', '1.0.0' );
define( 'ERU_CSP_FILE', __FILE__ );
define( 'ERU_CSP_URL', plugin_dir_url( __FILE__ ) );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'coming-soon-products-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-csp.php';
		Eru_CSP::init();
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-csp-admin.php';
			Eru_CSP_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CSP_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-csp' ) ) . '">' . esc_html__( 'Ayarlar', 'coming-soon-products-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
