<?php
/**
 * Plugin Name:       Site Manager for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/site-manager-for-wordpress/
 * Description:       Three small, opt-in modules: a virtual llms.txt for AI assistants, LocalBusiness JSON-LD, and per-crawler AI rules for robots.txt. Everything is off until you switch it on.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-manager-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SM_VERSION', '1.0.0' );
define( 'ERU_SM_FILE', __FILE__ );
define( 'ERU_SM_PATH', plugin_dir_path( __FILE__ ) );

require_once ERU_SM_PATH . 'includes/class-eru-sm-settings.php';
require_once ERU_SM_PATH . 'includes/class-eru-sm-llms.php';
require_once ERU_SM_PATH . 'includes/class-eru-sm-schema.php';
require_once ERU_SM_PATH . 'includes/class-eru-sm-robots.php';

add_action(
	'plugins_loaded',
	function () {
		Eru_SM_Llms::init();
		Eru_SM_Schema::init();
		Eru_SM_Robots::init();

		if ( is_admin() ) {
			require_once ERU_SM_PATH . 'includes/class-eru-sm-admin.php';
			Eru_SM_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ERU_SM_PATH . 'includes/class-eru-sm-cli.php';
			WP_CLI::add_command( 'eru-sm', 'Eru_SM_CLI' );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_SM_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-sm' ) ) . '">' . esc_html__( 'Ayarlar', 'site-manager-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);

register_activation_hook(
	__FILE__,
	function () {
		Eru_SM_Llms::add_rules();
		flush_rewrite_rules( false );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		Eru_SM_Llms::remove_rules();
		flush_rewrite_rules( false );
	}
);
