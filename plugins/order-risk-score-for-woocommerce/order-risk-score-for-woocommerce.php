<?php
/**
 * Plugin Name:       Order Risk Score for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/order-risk-score-for-woocommerce/
 * Description:       Siparişlere ayarlanabilir sinyallerle 0-100 arası risk puanı verir; sipariş listesinde rozet, sipariş detayında kutu gösterir. Otomatik aksiyon almaz.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-risk-score-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_ORS_VERSION', '1.0.0' );
define( 'ERU_ORS_FILE', __FILE__ );
define( 'ERU_ORS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_ORS_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'order-risk-score-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_ORS_PATH . 'includes/class-eru-ors-scorer.php';
		require_once ERU_ORS_PATH . 'includes/class-eru-ors-admin.php';

		Eru_Ors_Admin::init();

		// Siparişi oluşumunda puanla (klasik ödeme, Store API ve kod ile oluşturulan siparişler).
		add_action( 'woocommerce_checkout_order_processed', array( 'Eru_Ors_Scorer', 'on_checkout_processed' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( 'Eru_Ors_Scorer', 'on_order' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( 'Eru_Ors_Scorer', 'on_new_order' ), 1000, 2 );

		add_filter(
			'woocommerce_get_settings_pages',
			function ( $pages ) {
				require_once ERU_ORS_PATH . 'includes/class-eru-ors-settings.php';
				$pages[] = new Eru_Ors_Settings();
				return $pages;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_ORS_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=eru_ors' ) ) . '">' . esc_html__( 'Ayarlar', 'order-risk-score-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
