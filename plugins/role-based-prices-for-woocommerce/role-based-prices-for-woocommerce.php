<?php
/**
 * Plugin Name:       Role-Based Prices for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/role-based-prices-for-woocommerce/
 * Description:       Set a different price per user role on products and variations. Roles that get a custom price are chosen in the settings; nothing changes until you pick roles and fill prices.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       role-based-prices-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_RBP_VERSION', '1.0.0' );
define( 'ERU_RBP_FILE', __FILE__ );
define( 'ERU_RBP_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'role-based-prices-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_RBP_PATH . 'includes/class-eru-rbp-prices.php';
		Eru_RBP_Prices::init();

		if ( is_admin() ) {
			require_once ERU_RBP_PATH . 'includes/class-eru-rbp-admin.php';
			Eru_RBP_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_RBP_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-rbp' ) ) . '">' . esc_html__( 'Ayarlar', 'role-based-prices-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
