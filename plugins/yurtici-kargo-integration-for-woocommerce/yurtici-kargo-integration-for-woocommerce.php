<?php
/**
 * Plugin Name:       Yurtiçi Kargo Integration for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/yurtici-kargo-integration-for-woocommerce/
 * Description:       Create Yurtiçi Kargo shipments from WooCommerce orders over the legacy XML (SOAP) service, with several sender accounts and routing rules, printable barcode labels, scheduled delivery tracking with back-off, cancel and re-dispatch, an order timeline and a request log.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yurtici-kargo-integration-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_YK_VERSION', '1.0.0' );
define( 'ERU_YK_FILE', __FILE__ );
define( 'ERU_YK_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_YK_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Yurtiçi Kargo Integration for WooCommerce, WooCommerce gerektirir.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		foreach ( array( 'settings', 'accounts', 'lock', 'logger', 'xml', 'client', 'dispatcher', 'tracker', 'cron', 'privacy' ) as $eru_yk_file ) {
			require_once ERU_YK_PATH . 'includes/class-eru-yk-' . $eru_yk_file . '.php';
		}

		Eru_YK_Settings::init();
		Eru_YK_Logger::maybe_install();
		Eru_YK_Cron::init();
		Eru_YK_Dispatcher::init();
		Eru_YK_Privacy::init();

		if ( is_admin() ) {
			require_once ERU_YK_PATH . 'includes/class-eru-yk-admin.php';
			require_once ERU_YK_PATH . 'includes/class-eru-yk-label.php';
			Eru_YK_Admin::init();
			Eru_YK_Label::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_YK_PATH . 'includes/class-eru-yk-cli.php';
			WP_CLI::add_command( 'eru-yk', 'Eru_YK_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_YK_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=eru_yk' ) ) . '">' . esc_html__( 'Ayarlar', 'yurtici-kargo-integration-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_yk_track', 'eru_yk_daily', 'eru_yk_dispatch' ) as $eru_yk_hook ) {
				as_unschedule_all_actions( $eru_yk_hook, null, 'eru-yk' );
			}
		}
	}
);
