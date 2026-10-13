<?php
/**
 * Plugin Name:       Abandoned Checkout Call List for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/abandoned-checkout-call-list-for-woocommerce/
 * Description:       With consent, records checkout e-mail/phone and cart when a visitor leaves checkout, and gives call-centre staff a follow-up list with statuses and notes. Marks recovered orders and purges old rows.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       abandoned-checkout-call-list-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_ACCL_VERSION', '1.0.0' );
define( 'ERU_ACCL_FILE', __FILE__ );
define( 'ERU_ACCL_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_ACCL_URL', plugin_dir_url( __FILE__ ) );

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
		require_once ERU_ACCL_PATH . 'includes/class-eru-accl-db.php';
		Eru_ACCL_DB::install();
		if ( ! wp_next_scheduled( 'eru_accl_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'eru_accl_purge' );
		}
	}
);
register_deactivation_hook( __FILE__, fn() => wp_clear_scheduled_hook( 'eru_accl_purge' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'abandoned-checkout-call-list-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_ACCL_PATH . 'includes/class-eru-accl-db.php';
		require_once ERU_ACCL_PATH . 'includes/class-eru-accl-capture.php';
		require_once ERU_ACCL_PATH . 'includes/class-eru-accl-admin.php';

		Eru_ACCL_DB::maybe_upgrade();
		Eru_ACCL_Capture::init();
		Eru_ACCL_Admin::init();

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_ACCL_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-accl' ) ) . '">' . esc_html__( 'Liste', 'abandoned-checkout-call-list-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
