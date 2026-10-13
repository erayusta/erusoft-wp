<?php
/**
 * Plugin Name:       Order SMS & Delivery Codes for İleti Merkezi
 * Plugin URI:        https://wordpress.erusoft.com/order-sms-delivery-codes-for-ileti-merkezi/
 * Description:       Sends SMS through İleti Merkezi when WooCommerce order statuses change (rules with templates such as {siparis_no}, {ad}, {kargo_takip}). Optional delivery verification code: the customer receives a one-time code by SMS and staff can only mark the order delivered after entering it. Test mode logs instead of sending.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-sms-delivery-codes-for-ileti-merkezi
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_OSMS_VERSION', '1.0.0' );
define( 'ERU_OSMS_FILE', __FILE__ );
define( 'ERU_OSMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_OSMS_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_OSMS_PATH . 'includes/class-eru-osms-installer.php';
register_activation_hook( __FILE__, array( 'Eru_OSMS_Installer', 'install' ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</p></div>'
			);
			return;
		}

		foreach ( array( 'settings', 'text', 'decision', 'client', 'log', 'order', 'delivery', 'rules', 'lock', 'metabox', 'admin' ) as $part ) {
			require_once ERU_OSMS_PATH . 'includes/class-eru-osms-' . $part . '.php';
		}

		Eru_OSMS_Installer::maybe_update();

		// Rule engine: status change → log + queue; sending happens in the Action Scheduler worker.
		add_action( 'woocommerce_order_status_changed', array( 'Eru_OSMS_Rules', 'status_changed' ), 30, 4 );
		add_action( Eru_OSMS_Rules::AS_HOOK, array( 'Eru_OSMS_Rules', 'worker' ), 10, 1 );
		// Delivery code: record transitions that skipped the code; block staff entry points.
		add_action( 'woocommerce_order_status_changed', array( 'Eru_OSMS_Delivery', 'codeless_check' ), 20, 4 );
		Eru_OSMS_Lock::init();

		if ( is_admin() ) {
			Eru_OSMS_Metabox::init();
			Eru_OSMS_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_OSMS_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-osms' ) ) . '">' . esc_html__( 'Ayarlar', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</a>' );
				return $links;
			}
		);
	}
);
