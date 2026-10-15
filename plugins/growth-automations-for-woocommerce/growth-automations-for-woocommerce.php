<?php
/**
 * Plugin Name:       Growth Automations for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/growth-automations-for-woocommerce/
 * Description:       Bank-transfer payment reminders, consent-based abandoned-cart e-mails with a restore link, win-back e-mails with unique expiring coupons, review requests and variation-aware back-in-stock alerts. Every automation is off by default.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       growth-automations-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_GRA_VERSION', '1.0.0' );
define( 'ERU_GRA_FILE', __FILE__ );
define( 'ERU_GRA_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_GRA_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Activation and bootstrap. Activation creates the tables only; nothing is scheduled until an automation is enabled.
 */
class Eru_GRA_Plugin {

	const FILES = array( 'settings', 'lock', 'db', 'consent', 'coupons', 'mail', 'cart', 'alerts', 'runner', 'privacy' );

	/** Load the classes. */
	public static function load() {
		foreach ( self::FILES as $f ) {
			require_once ERU_GRA_PATH . 'includes/class-eru-gra-' . $f . '.php';
		}
	}

	/** Activation: tables only. */
	public static function activate() {
		self::load();
		Eru_GRA_DB::install();
	}

	/** Deactivation: drop pending jobs. */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_gra_run', 'eru_gra_run_more', 'eru_gra_notify', 'eru_gra_purge_more', 'eru_gra_cleanup_more', 'eru_gra_daily' ) as $hook ) {
				as_unschedule_all_actions( $hook, null, 'eru-gra' );
			}
		}
	}
}

register_activation_hook( __FILE__, array( 'Eru_GRA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Eru_GRA_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'growth-automations-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		Eru_GRA_Plugin::load();
		Eru_GRA_Settings::init();
		Eru_GRA_DB::init();
		Eru_GRA_Consent::init();
		Eru_GRA_Cart::init();
		Eru_GRA_Alerts::init();
		Eru_GRA_Runner::init();
		Eru_GRA_Privacy::init();

		if ( is_admin() ) {
			require_once ERU_GRA_PATH . 'includes/class-eru-gra-admin.php';
			Eru_GRA_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_GRA_PATH . 'includes/class-eru-gra-cli.php';
			WP_CLI::add_command( 'eru-gra', 'Eru_GRA_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_GRA_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-gra' ) ) . '">' . esc_html__( 'Ayarlar', 'growth-automations-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	},
	20
);
