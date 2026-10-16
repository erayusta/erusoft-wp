<?php
/**
 * Plugin Name:       Marketplace Orders for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/marketplace-orders-for-woocommerce/
 * Description:       Imports Trendyol and Hepsiburada orders into WooCommerce through pluggable adapters without duplicates: an atomic per-package lock and a unique map row guard every import, orders are saved with order notifications muted, marketplace statuses only move forward, and stock is left alone or reduced once per order and restored on cancel. A stock bridge module pushes stock and prices with rules (exclusions, floor and ceiling, multiplier, rounding) in debounced batches. Observe mode logs what would be imported or sent; raw payloads live in their own table with a retention period. Includes a non-destructive, re-runnable migrator from the older Erusoft Trendyol and Hepsiburada plugins, WP-CLI and privacy tools.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       marketplace-orders-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_MPO_VERSION', '1.0.0' );
define( 'ERU_MPO_FILE', __FILE__ );
define( 'ERU_MPO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_MPO_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Loading, activation and deactivation. Activation creates the tables only: no job, no request.
 */
class Eru_MPO_Plugin {

	const FILES = array( 'settings', 'installer', 'dto', 'adapter', 'registry', 'lock', 'logger', 'raw', 'map', 'isolated-save', 'statuses', 'stock', 'builder', 'lifecycle', 'importer', 'migrator', 'cron', 'credentials', 'rules', 'stock-bridge', 'reports', 'privacy', 'jobs' );

	/** Admin screens (loaded in wp-admin, and by tests and WP-CLI through load_admin()). */
	const ADMIN = array( 'class-eru-mpo-admin.php', 'admin/class-eru-mpo-admin-pages.php', 'admin/class-eru-mpo-admin-actions.php', 'admin/class-eru-mpo-orders-list.php', 'admin/class-eru-mpo-metabox.php' );

	/** Marketplace layer: HTTP helper, scheduler and acknowledgement queue, adapters, Product Push bridge. */
	const EXTRA = array( 'http.php', 'sync-scheduler.php', 'adapters/class-eru-mpo-adapter-trendyol.php', 'adapters/class-eru-mpo-adapter-hepsiburada.php', 'ppt-bridge.php' );

	/** Loads the classes (idempotent). */
	public static function load() {
		foreach ( self::FILES as $f ) {
			$file = 'adapter' === $f ? 'interface-eru-mpo-adapter.php' : 'class-eru-mpo-' . $f . '.php';
			require_once ERU_MPO_PATH . 'includes/' . $file;
		}
		foreach ( self::EXTRA as $file ) {
			require_once ERU_MPO_PATH . 'includes/' . $file;
		}
	}

	/** Loads and hooks the admin screens once. Marketplace tabs are added on admin_init in wp-admin. */
	public static function load_admin() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		self::load();
		foreach ( self::ADMIN as $file ) {
			require_once ERU_MPO_PATH . 'includes/' . $file;
		}
		Eru_MPO_Admin::init();
		Eru_MPO_Admin_Actions::init();
		Eru_MPO_Orders_List::init();
		Eru_MPO_Metabox::init();
		if ( is_admin() && ! did_action( 'admin_init' ) ) {
			add_action( 'admin_init', array( 'Eru_MPO_Credentials', 'init' ) );
			add_action( 'admin_init', array( 'Eru_MPO_Admin_Pages', 'init' ) );
		} else {
			Eru_MPO_Credentials::init();
			Eru_MPO_Admin_Pages::init();
		}
	}

	/** Activation: create or migrate the tables; the next request re-arms work that was left (migration). */
	public static function activate() {
		self::load();
		Eru_MPO_Installer::maybe_install();
		update_option( 'eru_mpo_rearm', '1', true );
	}

	/** Deactivation: drop every job of the group; nothing else changes. */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'eru-mpo' );
		}
		delete_option( 'eru_mpo_rearm' );
	}
}

register_activation_hook( __FILE__, array( 'Eru_MPO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Eru_MPO_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Marketplace Orders for WooCommerce, WooCommerce gerektirir.', 'marketplace-orders-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		Eru_MPO_Plugin::load();
		Eru_MPO_Installer::maybe_install();
		Eru_MPO_Statuses::init();
		Eru_MPO_Stock::init();
		Eru_MPO_Importer::init();
		Eru_MPO_Migrator::init();
		Eru_MPO_Cron::init();
		Eru_MPO_Adapter_Trendyol::init();
		Eru_MPO_Adapter_Hepsiburada::init();
		Eru_MPO_Sync::init();
		Eru_MPO_Ack::init();
		Eru_MPO_PPT_Bridge::init();
		Eru_MPO_Stock_Bridge::init();
		Eru_MPO_Reports::init();
		Eru_MPO_Privacy::init();
		Eru_MPO_Jobs::init();

		if ( is_admin() ) {
			Eru_MPO_Plugin::load_admin();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_MPO_PATH . 'includes/class-eru-mpo-cli.php';
			WP_CLI::add_command( 'eru-mpo', 'Eru_MPO_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_MPO_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-mpo' ) ) . '">' . esc_html__( 'Ayarlar', 'marketplace-orders-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	},
	20
);

/**
 * Imports one normalised order through a registered adapter (see Eru_MPO_Importer::import()).
 *
 * @param string            $marketplace Adapter key.
 * @param Eru_MPO_Order_DTO $dto         Order.
 * @return array|WP_Error Import result, or an error when the adapter is unknown.
 */
function eru_mpo_import( $marketplace, $dto ) {
	$adapter = class_exists( 'Eru_MPO_Registry' ) ? Eru_MPO_Registry::get( (string) $marketplace ) : null;
	if ( ! $adapter ) {
		return new WP_Error( 'eru_mpo_unknown_adapter', __( 'Bilinmeyen pazaryeri.', 'marketplace-orders-for-woocommerce' ) );
	}
	return Eru_MPO_Importer::import( $adapter, $dto );
}
