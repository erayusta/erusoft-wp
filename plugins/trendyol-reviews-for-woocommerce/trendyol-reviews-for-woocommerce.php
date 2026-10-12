<?php
/**
 * Plugin Name:       Trendyol Reviews for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/trendyol-reviews-for-woocommerce/
 * Description:       Kendi Trendyol mağazanızın ürün yorumlarını WooCommerce ürün yorumu olarak aktarır: ürün listesi Trendyol Satıcı API ile (toplu), ürün linkiyle (tekil) veya CSV ile; barkod/SKU ile eşleştirir, isimleri maskeler.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trendyol-reviews-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_TYR_VERSION', '1.0.0' );
define( 'ERU_TYR_FILE', __FILE__ );
define( 'ERU_TYR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_TYR_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'eru_tyr_daily' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'eru-tyr' );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Trendyol Reviews for WooCommerce, WooCommerce gerektirir.', 'trendyol-reviews-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_TYR_PATH . 'includes/class-eru-tyr-settings.php';
		require_once ERU_TYR_PATH . 'includes/class-eru-tyr-logger.php';
		require_once ERU_TYR_PATH . 'includes/class-eru-tyr-client.php';
		require_once ERU_TYR_PATH . 'includes/class-eru-tyr-matcher.php';
		require_once ERU_TYR_PATH . 'includes/class-eru-tyr-importer.php';
		require_once ERU_TYR_PATH . 'includes/class-eru-tyr-store-import.php';
		require_once ERU_TYR_PATH . 'includes/class-eru-tyr-url-import.php';
		require_once ERU_TYR_PATH . 'includes/class-eru-tyr-frontend.php';

		Eru_TYR_Store_Import::init();
		Eru_TYR_Frontend::init();

		if ( is_admin() ) {
			require_once ERU_TYR_PATH . 'includes/class-eru-tyr-admin.php';
			Eru_TYR_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_TYR_PATH . 'includes/class-eru-tyr-cli.php';
			WP_CLI::add_command( 'trendyol-reviews', 'Eru_TYR_CLI' );
		}
	}
);
