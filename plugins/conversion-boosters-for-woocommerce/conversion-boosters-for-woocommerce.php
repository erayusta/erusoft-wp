<?php
/**
 * Plugin Name:       Conversion Boosters for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/conversion-boosters-for-woocommerce/
 * Description:       Free-shipping progress bar with product suggestions that close the gap, cart recommendations, an opt-in welcome-coupon newsletter and a real, server-side expiring cart coupon with a countdown. Every module is off by default.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       conversion-boosters-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CB_VERSION', '1.0.0' );
define( 'ERU_CB_FILE', __FILE__ );
define( 'ERU_CB_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_CB_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_cb_daily', 'eru_cb_cleanup_more', 'eru_cb_purge_more' ) as $eru_cb_hook ) {
				as_unschedule_all_actions( $eru_cb_hook, array(), 'eru-cb' );
			}
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'conversion-boosters-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		foreach ( array( 'settings', 'lock', 'coupons', 'shipping', 'newsletter', 'timed', 'privacy', 'front' ) as $eru_cb_f ) {
			require_once ERU_CB_PATH . 'includes/class-eru-cb-' . $eru_cb_f . '.php';
		}

		Eru_CB_Settings::init();
		Eru_CB_Coupons::init();
		Eru_CB_Shipping::init();
		Eru_CB_Newsletter::init();
		Eru_CB_Timed::init();
		Eru_CB_Privacy::init();
		Eru_CB_Front::init();

		if ( is_admin() ) {
			require_once ERU_CB_PATH . 'includes/class-eru-cb-admin.php';
			Eru_CB_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CB_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-cb' ) ) . '">' . esc_html__( 'Ayarlar', 'conversion-boosters-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	},
	20
);
