<?php
/**
 * Plugin Name:       Price Lists for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/price-lists-for-woocommerce/
 * Description:       Named price lists with fixed prices and percentage rules, assigned to user roles or individual users. Nothing changes until you create a list.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       price-lists-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PLST_VERSION', '1.0.0' );
define( 'ERU_PLST_FILE', __FILE__ );
define( 'ERU_PLST_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'price-lists-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_PLST_PATH . 'includes/class-eru-plst-lists.php';
		require_once ERU_PLST_PATH . 'includes/class-eru-plst-pricing.php';
		Eru_PLST_Pricing::init();

		Eru_PLST_Lists::init();

		if ( is_admin() ) {
			require_once ERU_PLST_PATH . 'includes/class-eru-plst-admin.php';
			Eru_PLST_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_PLST_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=eru_plst_list' ) ) . '">' . esc_html__( 'Ayarlar', 'price-lists-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
