<?php
/**
 * Plugin Name:       REST Response Cache for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/rest-response-cache-for-woocommerce/
 * Description:       İzin listesindeki herkese açık REST GET uçlarının yanıtlarını önbellekler. Giriş yapmış, kimlik bilgili veya oturumlu istekleri asla önbelleğe almaz; ürün ve terim değişince otomatik temizlenir. Varsayılan olarak liste boştur, yani hiçbir şey önbelleğe alınmaz.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rest-response-cache-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_RRC_VERSION', '1.0.0' );
define( 'ERU_RRC_FILE', __FILE__ );
define( 'ERU_RRC_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'rest-response-cache-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_RRC_PATH . 'includes/class-eru-rrc.php';
		Eru_Rrc::init();

		add_filter(
			'woocommerce_get_settings_pages',
			function ( $pages ) {
				require_once ERU_RRC_PATH . 'includes/class-eru-rrc-settings.php';
				$pages[] = new Eru_Rrc_Settings();
				return $pages;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_RRC_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=eru_rrc' ) ) . '">' . esc_html__( 'Ayarlar', 'rest-response-cache-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
