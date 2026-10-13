<?php
/**
 * Plugin Name:       Wholesale Inquiry Box for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/wholesale-inquiry-box-for-woocommerce/
 * Description:       Adds a bulk-quote request box to product pages: a short form that e-mails the store owner, with an optional WhatsApp button. Disabled until you turn it on in the settings.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wholesale-inquiry-box-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_WIB_VERSION', '1.0.0' );
define( 'ERU_WIB_FILE', __FILE__ );
define( 'ERU_WIB_PATH', plugin_dir_path( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'wholesale-inquiry-box-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_WIB_PATH . 'includes/class-eru-wib-settings.php';
		require_once ERU_WIB_PATH . 'includes/class-eru-wib-form.php';
		Eru_WIB_Form::init();

		if ( is_admin() ) {
			require_once ERU_WIB_PATH . 'includes/class-eru-wib-admin.php';
			Eru_WIB_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_WIB_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-wib' ) ) . '">' . esc_html__( 'Ayarlar', 'wholesale-inquiry-box-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
