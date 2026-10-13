<?php
/**
 * Plugin Name:       High-Value Cart Offer for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/high-value-cart-offer-for-woocommerce/
 * Description:       When the cart reaches a configurable amount, shows an offer box with a button that opens a WhatsApp chat pre-filled with the cart summary. Number, threshold and texts come from settings; nothing is shown until a number is set.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       high-value-cart-offer-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_HVCO_VERSION', '1.0.0' );
define( 'ERU_HVCO_FILE', __FILE__ );
define( 'ERU_HVCO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_HVCO_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'high-value-cart-offer-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_HVCO_PATH . 'includes/class-eru-hvco-offer.php';
		Eru_HVCO_Offer::init();

		if ( is_admin() ) {
			require_once ERU_HVCO_PATH . 'includes/class-eru-hvco-admin.php';
			Eru_HVCO_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_HVCO_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-hvco' ) ) . '">' . esc_html__( 'Ayarlar', 'high-value-cart-offer-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
