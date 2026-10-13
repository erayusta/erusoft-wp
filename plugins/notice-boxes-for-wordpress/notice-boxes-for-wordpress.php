<?php
/**
 * Plugin Name:       Notice Boxes for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/notice-boxes-for-wordpress/
 * Description:       Info, warning, success and error boxes in three style presets, as a shortcode and a Gutenberg block (no build step) with dark-mode aware styling.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       notice-boxes-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_NB_VERSION', '1.0.0' );
define( 'ERU_NB_FILE', __FILE__ );
define( 'ERU_NB_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_NB_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_NB_PATH . 'includes/class-eru-nb-settings.php';
require_once ERU_NB_PATH . 'includes/class-eru-nb-render.php';

add_action(
	'plugins_loaded',
	function () {
		Eru_NB_Render::init();

		if ( is_admin() ) {
			require_once ERU_NB_PATH . 'includes/class-eru-nb-admin.php';
			Eru_NB_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_NB_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-nb' ) ) . '">' . esc_html__( 'Ayarlar', 'notice-boxes-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);
