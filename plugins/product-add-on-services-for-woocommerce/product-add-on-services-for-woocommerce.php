<?php
/**
 * Plugin Name:       Product Add-On Services for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/product-add-on-services-for-woocommerce/
 * Description:       Lets customers pick paid add-on services (warranty, gift wrap, installation...) on the product page. Groups apply to all products, categories, tags or single products; prices are fixed or a percentage of the product price, per unit or once per cart line.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-add-on-services-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_AOS_VERSION', '1.0.0' );
define( 'ERU_AOS_FILE', __FILE__ );
define( 'ERU_AOS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_AOS_URL', plugin_dir_url( __FILE__ ) );

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
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'product-add-on-services-for-woocommerce' ) . '</p></div>'
			);
			return;
		}

		require_once ERU_AOS_PATH . 'includes/class-eru-aos-groups.php';
		require_once ERU_AOS_PATH . 'includes/class-eru-aos-cart.php';
		require_once ERU_AOS_PATH . 'includes/class-eru-aos-front.php';

		Eru_AOS_Groups::init();
		Eru_AOS_Cart::init();
		Eru_AOS_Front::init();

		if ( is_admin() ) {
			require_once ERU_AOS_PATH . 'includes/class-eru-aos-admin.php';
			Eru_AOS_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_AOS_PATH . 'includes/class-eru-aos-cli.php';
			WP_CLI::add_command( 'eru-aos', 'Eru_AOS_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_AOS_FILE ),
			function ( $links ) {
				array_unshift(
					$links,
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Eru_AOS_Groups::CPT ) ) . '">' . esc_html__( 'Hizmet grupları', 'product-add-on-services-for-woocommerce' ) . '</a>',
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=products&section=eru_aos' ) ) . '">' . esc_html__( 'Ayarlar', 'product-add-on-services-for-woocommerce' ) . '</a>'
				);
				return $links;
			}
		);
	}
);
