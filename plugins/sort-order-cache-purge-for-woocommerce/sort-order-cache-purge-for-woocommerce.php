<?php
/**
 * Plugin Name:       Sort Order Cache Purge for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/sort-order-cache-purge-for-woocommerce/
 * Description:       Ürün sıralaması kaydedildiğinde sayfa önbelleğini temizler. Sıralamayı doğrudan SQL ile yazan eklentilerde hiçbir WordPress kancası tetiklenmediği için yeni sıra önbellek süresi dolana kadar görünmez; bu eklenti o boşluğu kapatır.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sort-order-cache-purge-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SOCP_VERSION', '1.0.0' );
define( 'ERU_SOCP_FILE', __FILE__ );
define( 'ERU_SOCP_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'sort-order-cache-purge-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_SOCP_PATH . 'includes/class-eru-socp.php';
		Eru_Socp::init();
	}
);
