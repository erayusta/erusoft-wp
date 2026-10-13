<?php
/**
 * Plugin Name:       Order Blacklist for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/order-blacklist-for-woocommerce/
 * Description:       Blocks checkout for e-mail, phone, IP or address-fragment blocklists. Lists are empty by default; a short log of blocked attempts is kept.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-blacklist-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_OB_VERSION', '1.0.0' );
define( 'ERU_OB_FILE', __FILE__ );
define( 'ERU_OB_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'order-blacklist-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_OB_PATH . 'includes/class-eru-ob-guard.php';
		require_once ERU_OB_PATH . 'includes/class-eru-ob-admin.php';

		Eru_OB_Guard::init();
		Eru_OB_Admin::init();

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_OB_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-ob' ) ) . '">' . esc_html__( 'Ayarlar', 'order-blacklist-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
