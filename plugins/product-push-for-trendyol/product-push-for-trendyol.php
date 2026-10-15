<?php
/**
 * Plugin Name:       Product Push for Trendyol
 * Plugin URI:        https://wordpress.erusoft.com/product-push-for-trendyol/
 * Description:       Push simple and variable WooCommerce products to Trendyol over the Product V2 API: per-product Trendyol fields with a category attribute form, Ajax category search and a category map, validation before sending, bulk push, approval-aware update routing, batch result polling and WP-CLI commands.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-push-for-trendyol
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PPT_VERSION', '1.0.0' );
define( 'ERU_PPT_FILE', __FILE__ );
define( 'ERU_PPT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_PPT_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Product Push for Trendyol, WooCommerce gerektirir.', 'product-push-for-trendyol' ) . '</p></div>'
			);
			return;
		}

		foreach ( array( 'settings', 'lock', 'log', 'client', 'categories', 'builder', 'pusher', 'poller', 'cron', 'bulk' ) as $eru_ppt_file ) {
			require_once ERU_PPT_PATH . 'includes/class-eru-ppt-' . $eru_ppt_file . '.php';
		}

		Eru_PPT_Settings::init();
		Eru_PPT_Cron::init();
		Eru_PPT_Pusher::init();
		Eru_PPT_Categories::init();

		if ( is_admin() ) {
			require_once ERU_PPT_PATH . 'includes/class-eru-ppt-admin.php';
			require_once ERU_PPT_PATH . 'includes/class-eru-ppt-metabox.php';
			Eru_PPT_Admin::init();
			Eru_PPT_Metabox::init();
			Eru_PPT_Bulk::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_PPT_PATH . 'includes/class-eru-ppt-cli.php';
			WP_CLI::add_command( 'eru-ppt', 'Eru_PPT_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_PPT_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-ppt' ) ) . '">' . esc_html__( 'Ayarlar', 'product-push-for-trendyol' ) . '</a>' );
				return $links;
			}
		);
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_ppt_poll', 'eru_ppt_push', 'eru_ppt_refresh' ) as $eru_ppt_hook ) {
				as_unschedule_all_actions( $eru_ppt_hook, null, 'eru-ppt' );
			}
		}
	}
);
