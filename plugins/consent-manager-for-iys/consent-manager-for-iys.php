<?php
/**
 * Plugin Name:       Consent Manager for İYS
 * Plugin URI:        https://wordpress.erusoft.com/consent-manager-for-iys/
 * Description:       A consent store with an audit trail for Turkey's İYS (İleti Yönetim Sistemi): e-mail, SMS and call permissions captured at checkout, registration and My Account, İYS business rules (H175, H467), a batched, rate-limited sync queue with retries, İYS pull and nightly reconcile, CSV and signed external import. Everything stays local until the İYS connection is configured and switched on.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       consent-manager-for-iys
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CMI_VERSION', '1.0.0' );
define( 'ERU_CMI_FILE', __FILE__ );
define( 'ERU_CMI_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_CMI_URL', plugin_dir_url( __FILE__ ) );

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
class Eru_CMI_Plugin {

	const FILES = array( 'settings', 'recipient', 'installer', 'lock', 'logger', 'history', 'rules', 'client', 'queue', 'repository', 'pull', 'reconcile', 'retention', 'import', 'scheduler', 'tokens', 'rate-limit', 'links', 'capture-checkout', 'capture-register', 'account', 'rest', 'external', 'privacy', 'gra-bridge' );

	/** Loads the classes (idempotent). */
	public static function load() {
		foreach ( self::FILES as $f ) {
			require_once ERU_CMI_PATH . 'includes/class-eru-cmi-' . $f . '.php';
		}
	}

	/** Activation: create or migrate the tables. */
	public static function activate() {
		self::load();
		Eru_CMI_Installer::maybe_install();
	}

	/** Deactivation: drop every scheduled job; the watchdog re-arms after re-activation if work remains. */
	public static function deactivate() {
		if ( class_exists( 'Eru_CMI_Scheduler' ) ) {
			Eru_CMI_Scheduler::unschedule_all();
		} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_cmi_flush', 'eru_cmi_tick', 'eru_cmi_log_purge', 'eru_cmi_test_connection', 'eru_cmi_pull', 'eru_cmi_reconcile', 'eru_cmi_table_import', 'eru_cmi_gra_backfill' ) as $hook ) {
				as_unschedule_all_actions( $hook, null, 'eru-cmi' );
			}
			as_unschedule_all_actions( 'eru_cmi_capture_order' );
		}
		delete_option( 'eru_cmi_watchdog_at' );
		if ( 'done' !== get_option( 'eru_cmi_gra_backfill' ) ) {
			delete_option( 'eru_cmi_gra_backfill' ); // A copy to Growth Automations in progress restarts after re-activation.
		}
		$front = get_option( 'eru_cmi_front' );
		if ( is_array( $front ) && ! empty( $front['account'] ) ) {
			delete_option( 'rewrite_rules' ); // Drop the Hesabım endpoint; rules are rebuilt on the next request.
		}
	}
}

register_activation_hook( __FILE__, array( 'Eru_CMI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Eru_CMI_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'consent-manager-for-iys' ) . '</p></div>'
			);
			return;
		}

		Eru_CMI_Plugin::load();
		Eru_CMI_Installer::maybe_install();
		Eru_CMI_Settings::init();
		Eru_CMI_Scheduler::init();
		// Capture surfaces read only the autoloaded switches; all are off until enabled under İYS İzinleri → Toplama.
		Eru_CMI_Links::init();
		Eru_CMI_Capture_Checkout::init();
		Eru_CMI_Capture_Register::init();
		Eru_CMI_Account::init();
		Eru_CMI_Rest::init();
		Eru_CMI_External::init();
		Eru_CMI_Privacy::init();
		Eru_CMI_Gra_Bridge::init();

		if ( is_admin() ) {
			require_once ERU_CMI_PATH . 'includes/class-eru-cmi-admin.php';
			Eru_CMI_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_CMI_PATH . 'includes/class-eru-cmi-cli.php';
			WP_CLI::add_command( 'eru-cmi', 'Eru_CMI_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CMI_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-cmi' ) ) . '">' . esc_html__( 'Ayarlar', 'consent-manager-for-iys' ) . '</a>' );
				return $links;
			}
		);
	},
	20
);

/**
 * Consent state of a recipient for one channel.
 *
 * @param string $recipient E-mail or phone (any common format; normalised).
 * @param string $type      EPOSTA | MESAJ | ARAMA.
 * @return bool|null True for ONAY, false for RET, null when unknown (no record or invalid input).
 */
function eru_cmi_is_consented( $recipient, $type ) {
	if ( ! class_exists( 'Eru_CMI_Repository' ) ) {
		return null;
	}
	$row = Eru_CMI_Repository::get( $recipient, $type );
	if ( ! $row ) {
		return null;
	}
	return 'ONAY' === $row->status;
}
