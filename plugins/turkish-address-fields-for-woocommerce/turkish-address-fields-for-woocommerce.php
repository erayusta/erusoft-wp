<?php
/**
 * Plugin Name:       Turkish Address Fields for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/turkish-address-fields-for-woocommerce/
 * Description:       Turkish address fields for the classic WooCommerce checkout and My Account: province → district → neighbourhood → street pickers from bundled public address data (no external API), address line cleanup, door-number check, optional phone validation and order address lines built from the selected parts.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       turkish-address-fields-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_TAF_VERSION', '1.0.0' );
define( 'ERU_TAF_FILE', __FILE__ );
define( 'ERU_TAF_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_TAF_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'turkish-address-fields-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_TAF_PATH . 'includes/class-eru-taf-settings.php';
		require_once ERU_TAF_PATH . 'includes/class-eru-taf-normalize.php';
		require_once ERU_TAF_PATH . 'includes/class-eru-taf-data.php';
		require_once ERU_TAF_PATH . 'includes/class-eru-taf-checkout.php';

		Eru_TAF_Checkout::init();

		if ( is_admin() ) {
			require_once ERU_TAF_PATH . 'includes/class-eru-taf-admin.php';
			Eru_TAF_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_TAF_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-taf' ) ) . '">' . esc_html__( 'Ayarlar', 'turkish-address-fields-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
