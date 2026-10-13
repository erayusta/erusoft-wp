<?php
/**
 * Plugin Name:       Primary Category for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/primary-category-for-woocommerce/
 * Description:       Choose a primary category per product. It drives the %product_cat% permalink segment and the breadcrumb, and is available through eru_pc_get_primary(). Yoast SEO and Rank Math primary categories are read as a fallback, never written.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       primary-category-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PC_VERSION', '1.0.0' );
define( 'ERU_PC_FILE', __FILE__ );
define( 'ERU_PC_URL', plugin_dir_url( __FILE__ ) );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'primary-category-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-pc.php';
		Eru_PC::init();
	}
);

/**
 * Primary category of a product.
 *
 * Order: this plugin's choice, then the Yoast SEO / Rank Math primary category (read-only). Only categories
 * the product is actually assigned to count. Returns null when nothing is chosen.
 *
 * @param int    $product_id Product (parent) ID.
 * @param string $taxonomy   Taxonomy, default product_cat.
 * @return WP_Term|null
 */
function eru_pc_get_primary( $product_id, $taxonomy = 'product_cat' ) {
	return class_exists( 'Eru_PC' ) ? Eru_PC::get_primary( (int) $product_id, $taxonomy ) : null;
}
