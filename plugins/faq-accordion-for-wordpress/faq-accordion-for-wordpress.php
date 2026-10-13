<?php
/**
 * Plugin Name:       FAQ Accordion for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/faq-accordion-for-wordpress/
 * Description:       Manage frequently asked questions as sortable items grouped by category and show them with the accessible [eru_faq] accordion shortcode. Optional FAQPage JSON-LD is printed once per page.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       faq-accordion-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_FAQ_VERSION', '1.0.0' );
define( 'ERU_FAQ_FILE', __FILE__ );
define( 'ERU_FAQ_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_FAQ_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_FAQ_PATH . 'includes/class-eru-faq-settings.php';
require_once ERU_FAQ_PATH . 'includes/class-eru-faq-post-type.php';
require_once ERU_FAQ_PATH . 'includes/class-eru-faq-front.php';

add_action(
	'plugins_loaded',
	function () {
		Eru_FAQ_Post_Type::init();
		Eru_FAQ_Front::init();

		if ( is_admin() ) {
			require_once ERU_FAQ_PATH . 'includes/class-eru-faq-admin.php';
			Eru_FAQ_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_FAQ_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=eru_faq&page=eru-faq-settings' ) ) . '">' . esc_html__( 'Ayarlar', 'faq-accordion-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);
