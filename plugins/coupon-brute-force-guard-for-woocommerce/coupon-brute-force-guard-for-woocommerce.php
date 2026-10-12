<?php
/**
 * Plugin Name:       Coupon Brute-Force Guard for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/coupon-brute-force-guard-for-woocommerce/
 * Description:       Kupon kodu deneme-yanılma saldırılarını durdurur: art arda başarısız denemelerde istemciyi geçici olarak kilitler ve kupon hata mesajlarının kupon varlığını sızdırmasını engeller.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coupon-brute-force-guard-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CBG_VERSION', '1.0.0' );
define( 'ERU_CBG_FILE', __FILE__ );
define( 'ERU_CBG_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'coupon-brute-force-guard-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_CBG_PATH . 'includes/class-eru-cbg-guard.php';
		Eru_Cbg_Guard::init();

		add_filter(
			'woocommerce_get_settings_pages',
			function ( $pages ) {
				require_once ERU_CBG_PATH . 'includes/class-eru-cbg-settings.php';
				$pages[] = new Eru_Cbg_Settings();
				return $pages;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CBG_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=eru_cbg' ) ) . '">' . esc_html__( 'Ayarlar', 'coupon-brute-force-guard-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
