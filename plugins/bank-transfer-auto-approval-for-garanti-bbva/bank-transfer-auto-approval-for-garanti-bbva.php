<?php
/**
 * Plugin Name:       Bank Transfer Auto-Approval for Garanti BBVA
 * Plugin URI:        https://wordpress.erusoft.com/bank-transfer-auto-approval-for-garanti-bbva/
 * Description:       Poll Garanti BBVA account transactions read-only with your consent, match incoming transfers to bank transfer orders by order number and amount (split payments optional), approve the match and e-mail the shop manager. A used-transaction ledger stops one transfer from approving two orders; ambiguous matches go to review. Dry-run mode by default.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bank-transfer-auto-approval-for-garanti-bbva
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_GHA_VERSION', '1.0.0' );
define( 'ERU_GHA_FILE', __FILE__ );
define( 'ERU_GHA_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_GHA_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bank Transfer Auto-Approval for Garanti BBVA, WooCommerce gerektirir.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</p></div>'
			);
			return;
		}

		foreach ( array( 'settings', 'lock', 'log', 'ledger', 'paginator', 'client', 'matcher', 'approver', 'cron', 'privacy' ) as $eru_gha_file ) {
			require_once ERU_GHA_PATH . 'includes/class-eru-gha-' . $eru_gha_file . '.php';
		}

		Eru_GHA_Settings::init();
		Eru_GHA_Log::maybe_install();
		Eru_GHA_Cron::init();
		Eru_GHA_Privacy::init();

		if ( is_admin() ) {
			require_once ERU_GHA_PATH . 'includes/class-eru-gha-admin.php';
			Eru_GHA_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_GHA_PATH . 'includes/class-eru-gha-cli.php';
			WP_CLI::add_command( 'eru-gha', 'Eru_GHA_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_GHA_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-gha&tab=settings' ) ) . '">' . esc_html__( 'Ayarlar', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</a>' );
				return $links;
			}
		);
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_gha_check', 'eru_gha_run_now', 'eru_gha_fetch', 'eru_gha_daily' ) as $eru_gha_hook ) {
				as_unschedule_all_actions( $eru_gha_hook, null, 'eru-gha' );
			}
		}
	}
);
