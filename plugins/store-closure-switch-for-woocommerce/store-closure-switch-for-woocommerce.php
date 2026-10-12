<?php
/**
 * Plugin Name:       Store Closure Switch for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/store-closure-switch-for-woocommerce/
 * Description:       Mağazayı tek anahtarla satışa kapatır: sepete ekleme kalkar, sepet ve ödeme 503 + Retry-After döner. Yönetici ve mağaza yöneticileri etkilenmez. Varsayılan kapalıdır (yani mağaza açık).
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       store-closure-switch-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SCS_VERSION', '1.0.0' );
define( 'ERU_SCS_FILE', __FILE__ );
define( 'ERU_SCS_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'store-closure-switch-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_SCS_PATH . 'includes/class-eru-scs.php';
		Eru_Scs::init();

		add_filter(
			'woocommerce_get_settings_pages',
			function ( $pages ) {
				require_once ERU_SCS_PATH . 'includes/class-eru-scs-settings.php';
				$pages[] = new Eru_Scs_Settings();
				return $pages;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_SCS_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=eru_scs' ) ) . '">' . esc_html__( 'Ayarlar', 'store-closure-switch-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
