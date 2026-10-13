<?php
/**
 * Plugin Name:       Payment Method Discounts for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/payment-method-discounts-for-woocommerce/
 * Description:       Applies a percentage or fixed discount (or surcharge) depending on the payment method chosen at checkout, with optional minimum cart total and category exclusions.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       payment-method-discounts-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PMD_VERSION', '1.0.0' );
define( 'ERU_PMD_FILE', __FILE__ );
define( 'ERU_PMD_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_PMD_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'payment-method-discounts-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_PMD_PATH . 'includes/class-eru-pmd-rules.php';
		require_once ERU_PMD_PATH . 'includes/class-eru-pmd-cart.php';
		Eru_PMD_Cart::init();

		if ( is_admin() ) {
			require_once ERU_PMD_PATH . 'includes/class-eru-pmd-admin.php';
			Eru_PMD_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_PMD_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-pmd' ) ) . '">' . esc_html__( 'Ayarlar', 'payment-method-discounts-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
