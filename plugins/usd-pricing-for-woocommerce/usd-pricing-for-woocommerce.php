<?php
/**
 * Plugin Name:       USD Pricing for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/usd-pricing-for-woocommerce/
 * Description:       Price products in US dollars and charge in the store currency: the price is calculated from a manual exchange rate or the daily TCMB rate, with an optional markup.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       usd-pricing-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_USD_VERSION', '1.0.0' );
define( 'ERU_USD_FILE', __FILE__ );
define( 'ERU_USD_PATH', plugin_dir_path( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'eru_usd_resync' );
	wp_clear_scheduled_hook( 'eru_usd_refresh_rate' );
} );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'usd-pricing-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_USD_PATH . 'includes/class-eru-usd-rate.php';
		require_once ERU_USD_PATH . 'includes/class-eru-usd-pricing.php';
		Eru_USD_Pricing::init();

		if ( is_admin() ) {
			require_once ERU_USD_PATH . 'includes/class-eru-usd-admin.php';
			Eru_USD_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_USD_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-usd' ) ) . '">' . esc_html__( 'Ayarlar', 'usd-pricing-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
