<?php
/**
 * Plugin Name:       Hide Categories for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/hide-categories-for-woocommerce/
 * Description:       Hides selected product categories (and their sub-categories) from shop loops, widgets, category lists and search, with an optional 404 for direct access.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hide-categories-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_HC_VERSION', '1.0.0' );
define( 'ERU_HC_FILE', __FILE__ );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'hide-categories-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-hc.php';
		Eru_HC::init();
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-hc-admin.php';
			Eru_HC_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_HC_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-hc' ) ) . '">' . esc_html__( 'Ayarlar', 'hide-categories-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
