<?php
/**
 * Plugin Name:       Supplier XML Importer for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/supplier-xml-importer-for-woocommerce/
 * Description:       Import and update WooCommerce products from supplier XML feeds (URL or uploaded file) with a field-mapping UI, price rules, dry-run preview, batched processing and scheduled runs through Action Scheduler.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       supplier-xml-importer-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SXI_VERSION', '1.0.0' );
define( 'ERU_SXI_FILE', __FILE__ );
define( 'ERU_SXI_URL', plugin_dir_url( __FILE__ ) );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'supplier-xml-importer-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-sxi-feeds.php';
		require_once __DIR__ . '/includes/class-eru-sxi-reader.php';
		require_once __DIR__ . '/includes/class-eru-sxi-importer.php';
		require_once __DIR__ . '/includes/class-eru-sxi-runner.php';
		Eru_SXI_Runner::init();
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-sxi-admin.php';
			Eru_SXI_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_SXI_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=product&page=eru-sxi' ) ) . '">' . esc_html__( 'Besleme ayarları', 'supplier-xml-importer-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'eru_sxi_scheduled', null, 'eru-sxi' );
			as_unschedule_all_actions( 'eru_sxi_batch', null, 'eru-sxi' );
		}
	}
);
