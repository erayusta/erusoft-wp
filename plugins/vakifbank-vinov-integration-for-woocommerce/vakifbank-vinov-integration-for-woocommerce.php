<?php
/**
 * Plugin Name:       VakıfBank Vinov Integration for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/vakifbank-vinov-integration-for-woocommerce/
 * Description:       VakıfBank Vinov payment method for the classic checkout: the customer's identity number and SMS code are verified by the bank on the server, the debtor check uses the server-computed cart total, an installment commission is added as a visible fee line so the instruction amount equals the order total, and an ambiguous create is looked up before any retry. A scheduled status sync marks approved instructions paid and cancelled ones cancelled; the order screen queries and cancels instructions; read-only instruction, campaign and movement views. The identity number is never stored; orders keep a mask.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vakifbank-vinov-integration-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_VNV_VERSION', '1.0.0' );
define( 'ERU_VNV_FILE', __FILE__ );
define( 'ERU_VNV_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_VNV_URL', plugin_dir_url( __FILE__ ) );

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
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'VakıfBank Vinov Integration for WooCommerce, WooCommerce gerektirir.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		foreach ( array( 'settings', 'identity', 'logger', 'lock', 'rate-limit', 'api-client', 'installments', 'session', 'otp-controller', 'payment', 'gateway', 'blocks', 'instructions', 'cron', 'sync', 'privacy', 'admin', 'order-box' ) as $eru_vnv_file ) {
			require_once ERU_VNV_PATH . 'includes/class-eru-vnv-' . $eru_vnv_file . '.php';
		}

		Eru_VNV_Settings::init();
		Eru_VNV_Installments::init();
		Eru_VNV_OTP_Controller::init();
		Eru_VNV_Gateway::init();
		Eru_VNV_Blocks::init();
		Eru_VNV_Instructions::init();
		Eru_VNV_Cron::init();
		Eru_VNV_Sync::init();
		Eru_VNV_Privacy::init();

		if ( is_admin() ) {
			Eru_VNV_Admin::init();
			Eru_VNV_Order_Box::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_VNV_PATH . 'includes/class-eru-vnv-cli.php';
			WP_CLI::add_command( 'eru-vnv', 'Eru_VNV_CLI' );
		}

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'Eru_VNV_Gateway';
				return $gateways;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_VNV_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=eru_vnv' ) ) . '">' . esc_html__( 'Ayarlar', 'vakifbank-vinov-integration-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);

register_activation_hook(
	__FILE__,
	function () {
		// The classes are not loaded during activation: the next request re-arms what is needed.
		update_option( 'eru_vnv_rearm', '1', true );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'Eru_VNV_Cron' ) ) {
			Eru_VNV_Cron::deactivate();
		} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_vnv_sync', 'eru_vnv_daily', 'eru_vnv_conn_test', 'eru_vnv_bank_cancel' ) as $eru_vnv_hook ) {
				as_unschedule_all_actions( $eru_vnv_hook, null, 'eru-vnv' );
			}
		}
		delete_option( 'eru_vnv_rearm' );
	}
);
