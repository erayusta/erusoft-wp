<?php
/**
 * Plugin Name:       Product Ribbons for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/product-ribbons-for-woocommerce/
 * Description:       Ürün görsellerine köşegen şerit ekler. Kategori, etiket, indirim, yeni ürün ve düşük stok kuralları ayarlardan tanımlanır; istenirse ürün bazında elle geçersiz kılınır.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-ribbons-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PR_VERSION', '1.0.0' );
define( 'ERU_PR_FILE', __FILE__ );
define( 'ERU_PR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_PR_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'product-ribbons-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_PR_PATH . 'includes/class-eru-pr.php';
		require_once ERU_PR_PATH . 'includes/class-eru-pr-admin.php';
		Eru_Pr::init();
		Eru_Pr_Admin::init();
	}
);
