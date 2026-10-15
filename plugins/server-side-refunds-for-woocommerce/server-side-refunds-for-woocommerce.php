<?php
/**
 * Plugin Name:       Server-Side Refunds for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/server-side-refunds-for-woocommerce/
 * Description:       Report WooCommerce refunds and paid-order cancellations to Google Analytics 4 (Measurement Protocol) and/or Matomo from the server, through a queue with exponential backoff, de-duplication, a queue screen and a test button.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       server-side-refunds-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SSR_VERSION', '1.0.0' );
define( 'ERU_SSR_FILE', __FILE__ );
define( 'ERU_SSR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_SSR_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Deactivation drops every pending job; the queue rows stay and are scheduled again on the next request after re-activation.
register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'eru_ssr_process', 'eru_ssr_sweep', 'eru_ssr_purge' ) as $eru_ssr_hook ) {
				as_unschedule_all_actions( $eru_ssr_hook, array(), 'eru-ssr' );
			}
		}
		update_option( 'eru_ssr_resume', 1, true );
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'server-side-refunds-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_SSR_PATH . 'includes/class-eru-ssr-settings.php';
		require_once ERU_SSR_PATH . 'includes/class-eru-ssr-lock.php';
		require_once ERU_SSR_PATH . 'includes/class-eru-ssr-queue.php';
		require_once ERU_SSR_PATH . 'includes/class-eru-ssr-ga4.php';
		require_once ERU_SSR_PATH . 'includes/class-eru-ssr-matomo.php';
		require_once ERU_SSR_PATH . 'includes/class-eru-ssr-events.php';

		Eru_SSR_Settings::register();
		Eru_SSR_Queue::init();
		Eru_SSR_Events::init();

		if ( is_admin() ) {
			require_once ERU_SSR_PATH . 'includes/class-eru-ssr-admin.php';
			Eru_SSR_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_SSR_PATH . 'includes/class-eru-ssr-cli.php';
			WP_CLI::add_command( 'eru-ssr queue', 'Eru_SSR_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_SSR_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=integration&section=eru_ssr' ) ) . '">' . esc_html__( 'Ayarlar', 'server-side-refunds-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
