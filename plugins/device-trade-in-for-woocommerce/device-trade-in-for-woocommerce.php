<?php
/**
 * Plugin Name:       Device Trade-In for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/device-trade-in-for-woocommerce/
 * Description:       Let customers trade in a device when they buy: pick brand/model/capacity and condition from an admin-managed value table, see a live estimate, and get the value deducted in the cart (capped at the product price), with an approval flow, optional order hold, overdue reminders and an unlisted-device request form.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       device-trade-in-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_DTI_VERSION', '1.0.0' );
define( 'ERU_DTI_FILE', __FILE__ );
define( 'ERU_DTI_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_DTI_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			require_once ERU_DTI_PATH . 'includes/class-eru-dti-settings.php';
			require_once ERU_DTI_PATH . 'includes/class-eru-dti-db.php';
			Eru_DTI_DB::maybe_upgrade();
			add_option( 'eru_dti_settings', array(), '', false );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'eru_dti_daily', array(), 'eru-dti' );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'device-trade-in-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		foreach ( array( 'settings', 'db', 'pricing', 'csv', 'cart', 'front', 'lead', 'hold', 'overdue', 'privacy' ) as $eru_dti_f ) {
			require_once ERU_DTI_PATH . 'includes/class-eru-dti-' . $eru_dti_f . '.php';
		}

		Eru_DTI_DB::maybe_upgrade();
		Eru_DTI_Settings::init();
		Eru_DTI_Cart::init();
		Eru_DTI_Front::init();
		Eru_DTI_Lead::init();
		Eru_DTI_Hold::init();
		Eru_DTI_Overdue::init();
		Eru_DTI_Privacy::init();

		if ( is_admin() ) {
			require_once ERU_DTI_PATH . 'includes/class-eru-dti-admin.php';
			Eru_DTI_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_DTI_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-dti' ) ) . '">' . esc_html__( 'Ayarlar', 'device-trade-in-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	},
	20
);
