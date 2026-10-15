<?php
/**
 * Plugin Name:       Dopigo Integration for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/dopigo-integration-for-woocommerce/
 * Description:       Sync stock and prices with Dopigo in the direction you choose, push orders with VAT lines, sync order statuses both ways, import products on a time budget, and audit SKU matching. Queued through Action Scheduler with a dashboard and a filterable log.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dopigo-integration-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_DPG_VERSION', '1.0.0' );
define( 'ERU_DPG_FILE', __FILE__ );
define( 'ERU_DPG_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_DPG_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Dopigo Integration for WooCommerce, WooCommerce gerektirir.', 'dopigo-integration-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		foreach ( array( 'settings', 'logger', 'lock', 'api', 'products', 'importer', 'orders', 'status', 'cron', 'tools' ) as $eru_dpg_file ) {
			require_once ERU_DPG_PATH . 'includes/class-eru-dpg-' . $eru_dpg_file . '.php';
		}

		Eru_DPG_Settings::init();
		Eru_DPG_Logger::maybe_install();
		Eru_DPG_Cron::init();
		Eru_DPG_Products::instance()->init();
		Eru_DPG_Orders::init();
		Eru_DPG_Status::init();

		if ( is_admin() ) {
			require_once ERU_DPG_PATH . 'includes/class-eru-dpg-admin.php';
			require_once ERU_DPG_PATH . 'includes/class-eru-dpg-metabox.php';
			Eru_DPG_Admin::init();
			Eru_DPG_Metabox::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_DPG_PATH . 'includes/class-eru-dpg-cli.php';
			WP_CLI::add_command( 'eru-dpg', 'Eru_DPG_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_DPG_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-dpg' ) ) . '">' . esc_html__( 'Ayarlar', 'dopigo-integration-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_dpg_pull', 'eru_dpg_import', 'eru_dpg_daily', 'eru_dpg_tool' ) as $eru_dpg_hook ) {
				as_unschedule_all_actions( $eru_dpg_hook, null, 'eru-dpg' );
			}
		}
	}
);
