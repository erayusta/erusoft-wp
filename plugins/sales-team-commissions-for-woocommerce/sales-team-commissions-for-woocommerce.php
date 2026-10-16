<?php
/**
 * Plugin Name:       Sales Team Commissions for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/sales-team-commissions-for-woocommerce/
 * Description:       Commissions for an in-house sales team: agents with a capped multi-level hierarchy, customers assigned by referral link, round-robin, confirmed invitation or by hand, one rule system (product, category, brand, role, agent, global; per level, with priority and date range), refund and cancellation adjustments, a hold period, a My Account agent panel with payout requests, encrypted payout details, CSV reports, read-only REST and WP-CLI.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sales-team-commissions-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_STC_VERSION', '1.0.0' );
define( 'ERU_STC_FILE', __FILE__ );
define( 'ERU_STC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_STC_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Loading, activation and deactivation. Activation creates the tables only: no job, no cookie, no request.
 */
class Eru_STC_Plugin {

	const FILES = array( 'settings', 'money', 'log', 'installer', 'agents', 'links', 'rules', 'commissions', 'payouts', 'calculator', 'refunds', 'eligibility', 'referral', 'crypto', 'payout-details', 'labels', 'csv', 'reports', 'invitations', 'account', 'rest', 'privacy', 'admin', 'admin-pages' );

	/** Loads the classes (idempotent). */
	public static function load() {
		foreach ( self::FILES as $f ) {
			require_once ERU_STC_PATH . 'includes/class-eru-stc-' . $f . '.php';
		}
	}

	/** Activation: create or migrate the tables; re-activation with the module on rebuilds the endpoints. */
	public static function activate() {
		self::load();
		Eru_STC_Installer::maybe_install();
		if ( Eru_STC_Settings::enabled() ) {
			Eru_STC_Settings::update( array( 'rewrite_pending' => 1 ) );
		}
	}

	/** Deactivation: drop the scheduled job; the watchdog re-arms it after re-activation while the module is on. */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'eru_stc_eligibility', array(), 'eru-stc' );
			as_unschedule_all_actions( 'eru_stc_send_invite', array(), 'eru-stc' );
		}
		delete_option( 'eru_stc_watchdog_at' );
	}

	/** Wires the hooks. Every handler checks the master switch itself, so nothing happens while it is off. */
	public static function boot() {
		self::load();
		Eru_STC_Installer::maybe_install();
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_cap' ), 10, 1 );
		Eru_STC_Calculator::init();
		Eru_STC_Refunds::init();
		Eru_STC_Eligibility::init();
		Eru_STC_Referral::init();
		Eru_STC_Invitations::init();
		Eru_STC_Account::init();
		Eru_STC_Rest::init();
		Eru_STC_Privacy::init();
		add_action( 'init', array( 'Eru_STC_Account', 'maybe_flush' ), 99 );
		if ( is_admin() ) {
			Eru_STC_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_STC_PATH . 'includes/class-eru-stc-cli.php';
			WP_CLI::add_command( 'eru-stc', 'Eru_STC_CLI' );
		}
	}

	/**
	 * Meta-capability: manage_eru_stc follows manage_woocommerce (no role is written, so a role reset
	 * cannot lose it).
	 *
	 * @param array $allcaps Capabilities of the user.
	 * @return array
	 */
	public static function grant_cap( $allcaps ) {
		if ( ! empty( $allcaps['manage_woocommerce'] ) ) {
			$allcaps['manage_eru_stc'] = true;
		}
		return $allcaps;
	}
}

register_activation_hook( __FILE__, array( 'Eru_STC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Eru_STC_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Sales Team Commissions for WooCommerce için WooCommerce etkin olmalıdır.', 'sales-team-commissions-for-woocommerce' ) . '</p></div>';
					}
				}
			);
			return;
		}
		Eru_STC_Plugin::boot();

		/**
		 * Fires once the domain layer is loaded and WooCommerce is active; admin screens and the
		 * My Account panel hook in here.
		 */
		do_action( 'eru_stc_loaded' );
	},
	20
);
