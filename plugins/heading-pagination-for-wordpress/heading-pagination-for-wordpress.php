<?php
/**
 * Plugin Name:       Heading Pagination for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/heading-pagination-for-wordpress/
 * Description:       Splits long posts into pages at a chosen heading level, with a table of contents and previous/next links. Posts without enough headings or words are left untouched.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       heading-pagination-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_HP_VERSION', '1.0.0' );
define( 'ERU_HP_FILE', __FILE__ );
define( 'ERU_HP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_HP_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_HP_PATH . 'includes/class-eru-hp-settings.php';
require_once ERU_HP_PATH . 'includes/class-eru-hp-splitter.php';
require_once ERU_HP_PATH . 'includes/class-eru-hp-front.php';

add_action(
	'plugins_loaded',
	function () {
		Eru_HP_Front::init();

		if ( is_admin() ) {
			require_once ERU_HP_PATH . 'includes/class-eru-hp-admin.php';
			Eru_HP_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_HP_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-hp' ) ) . '">' . esc_html__( 'Ayarlar', 'heading-pagination-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);
