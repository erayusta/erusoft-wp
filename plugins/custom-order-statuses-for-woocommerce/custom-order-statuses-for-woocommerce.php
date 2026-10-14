<?php
/**
 * Plugin Name:       Custom Order Statuses for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/custom-order-statuses-for-woocommerce/
 * Description:       Create custom order statuses from the admin: colour, order, groups, paid/stock/editable flags, WooCommerce e-mails, bulk actions, safe delete with order migration, optional webhook topics and WP-CLI.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       custom-order-statuses-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_COS_VERSION', '1.0.0' );
define( 'ERU_COS_FILE', __FILE__ );
define( 'ERU_COS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_COS_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'custom-order-statuses-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_COS_PATH . 'includes/class-eru-cos-statuses.php';
		require_once ERU_COS_PATH . 'includes/class-eru-cos-emails.php';
		require_once ERU_COS_PATH . 'includes/class-eru-cos-orders.php';
		require_once ERU_COS_PATH . 'includes/class-eru-cos-webhooks.php';

		Eru_COS_Statuses::init();
		Eru_COS_Emails::init();
		Eru_COS_Orders::init();
		Eru_COS_Webhooks::init();

		if ( is_admin() ) {
			require_once ERU_COS_PATH . 'includes/class-eru-cos-admin.php';
			Eru_COS_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_COS_PATH . 'includes/class-eru-cos-cli.php';
			WP_CLI::add_command( 'eru-cos', 'Eru_COS_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_COS_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-cos' ) ) . '">' . esc_html__( 'Ayarlar', 'custom-order-statuses-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
