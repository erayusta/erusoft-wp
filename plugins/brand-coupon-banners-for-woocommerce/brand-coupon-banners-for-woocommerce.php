<?php
/**
 * Plugin Name:       Brand Coupon Banners for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/brand-coupon-banners-for-woocommerce/
 * Description:       Marka veya kategoriye bağlı kupon kampanyalarını tarihli banner olarak gösterir. Marka sınıflandırması ayarlardan seçilir; banner görsel veya metin olabilir, istenirse WooCommerce kuponu otomatik oluşturulur.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       brand-coupon-banners-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_BCB_VERSION', '1.0.0' );
define( 'ERU_BCB_FILE', __FILE__ );
define( 'ERU_BCB_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_BCB_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'brand-coupon-banners-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_BCB_PATH . 'includes/class-eru-bcb.php';
		require_once ERU_BCB_PATH . 'includes/class-eru-bcb-admin.php';
		require_once ERU_BCB_PATH . 'includes/class-eru-bcb-front.php';
		Eru_Bcb::init();
		Eru_Bcb_Admin::init();
		Eru_Bcb_Front::init();
	}
);
