<?php
/**
 * Plugin Name:       Campaign Engine for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/campaign-engine-for-woocommerce/
 * Description:       A condition-and-action campaign engine for WooCommerce: a four-step wizard with ready templates and live preview, AND/OR rules over cart, product, customer and time, percentage, fixed, campaign-price, tiered, buy-N set and free-shipping actions, spend-and-earn reward coupons with a My Account tab, storefront badges, gain box, smart cart offers and campaign pages, a status screen and WP-CLI.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.9
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       campaign-engine-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CE_VERSION', '1.0.0' );
define( 'ERU_CE_FILE', __FILE__ );
define( 'ERU_CE_PATH', plugin_dir_path( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// The engine needs PHP 8.1. On an older PHP nothing below is loaded, so the site keeps working.
if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Campaign Engine for WooCommerce PHP 8.1 veya üstünü gerektirir; eklenti çalışmıyor.', 'campaign-engine-for-woocommerce' ) . '</p></div>';
			}
		}
	);
	return;
}

require_once ERU_CE_PATH . 'src/Autoloader.php';
\Erusoft\CampaignEngine\Autoloader::register( ERU_CE_PATH . 'src' );

register_activation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			\Erusoft\CampaignEngine\Infrastructure\Plugin::activate();
		}
	}
);
register_deactivation_hook( __FILE__, array( \Erusoft\CampaignEngine\Infrastructure\Plugin::class, 'deactivate' ) );

// The WooCommerce layer: cart, shipping, checkout, orders, usage limits, rewards.
add_action( 'eru_ce_loaded', array( \Erusoft\CampaignEngine\Infrastructure\Plugin::class, 'boot' ), 5 );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Campaign Engine for WooCommerce için WooCommerce etkin olmalıdır.', 'campaign-engine-for-woocommerce' ) . '</p></div>';
					}
				}
			);
			return;
		}

		/**
		 * Fires once the engine is loaded and WooCommerce is active. The WooCommerce integration and
		 * the admin screens hook in here.
		 */
		do_action( 'eru_ce_loaded' );
	}
);
