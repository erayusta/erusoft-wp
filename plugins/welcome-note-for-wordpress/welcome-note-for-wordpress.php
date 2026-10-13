<?php
/**
 * Plugin Name:       Welcome Note for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/welcome-note-for-wordpress/
 * Description:       A dismissible welcome note for chosen user roles in the admin area, and an optional front-end announcement bar. Dismissal is remembered per user.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       welcome-note-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_WN_VERSION', '1.0.0' );
define( 'ERU_WN_FILE', __FILE__ );
define( 'ERU_WN_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_WN_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_WN_PATH . 'includes/class-eru-wn-settings.php';
require_once ERU_WN_PATH . 'includes/class-eru-wn-note.php';

add_action(
	'plugins_loaded',
	function () {
		Eru_WN_Note::init();

		if ( is_admin() ) {
			require_once ERU_WN_PATH . 'includes/class-eru-wn-admin.php';
			Eru_WN_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_WN_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-wn' ) ) . '">' . esc_html__( 'Ayarlar', 'welcome-note-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);
