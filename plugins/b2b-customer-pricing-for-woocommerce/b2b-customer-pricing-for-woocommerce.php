<?php
/**
 * Plugin Name:       B2B Customer Pricing for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/b2b-customer-pricing-for-woocommerce/
 * Description:       Customer-specific and customer-group prices or percentage discounts per product, category or the whole catalogue. Nothing changes until you create a rule.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       b2b-customer-pricing-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_B2B_VERSION', '1.0.0' );
define( 'ERU_B2B_FILE', __FILE__ );
define( 'ERU_B2B_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'b2b-customer-pricing-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_B2B_PATH . 'includes/class-eru-b2b-store.php';
		require_once ERU_B2B_PATH . 'includes/class-eru-b2b-pricing.php';
		Eru_B2B_Pricing::init();

		if ( is_admin() ) {
			require_once ERU_B2B_PATH . 'includes/class-eru-b2b-admin.php';
			Eru_B2B_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_B2B_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-b2b' ) ) . '">' . esc_html__( 'Ayarlar', 'b2b-customer-pricing-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
