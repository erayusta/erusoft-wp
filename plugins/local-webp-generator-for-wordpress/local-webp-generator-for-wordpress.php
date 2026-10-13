<?php
/**
 * Plugin Name:       Local WebP Generator for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/local-webp-generator-for-wordpress/
 * Description:       Creates a .webp copy of every uploaded JPEG/PNG and all its sizes on your own server (GD or Imagick), regenerates existing media in batches and serves the copies through <picture> markup. Originals are never touched; no external service.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       local-webp-generator-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_WEBP_VERSION', '1.0.0' );
define( 'ERU_WEBP_FILE', __FILE__ );
define( 'ERU_WEBP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_WEBP_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_WEBP_PATH . 'includes/class-eru-webp-settings.php';
require_once ERU_WEBP_PATH . 'includes/class-eru-webp-converter.php';
require_once ERU_WEBP_PATH . 'includes/class-eru-webp-bulk.php';
require_once ERU_WEBP_PATH . 'includes/class-eru-webp-serve.php';

add_action(
	'plugins_loaded',
	function () {
		Eru_WEBP_Converter::init();
		Eru_WEBP_Serve::init();

		if ( is_admin() ) {
			require_once ERU_WEBP_PATH . 'includes/class-eru-webp-admin.php';
			Eru_WEBP_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_WEBP_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-webp' ) ) . '">' . esc_html__( 'Ayarlar', 'local-webp-generator-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);
