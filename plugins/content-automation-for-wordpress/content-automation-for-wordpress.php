<?php
/**
 * Plugin Name:       Content Automation for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/content-automation-for-wordpress/
 * Description:       A scheduled content pipeline: a queue of topics is turned into posts by OpenAI, Anthropic or Perplexity on a WP-Cron schedule, saved as drafts by default. Bring your own API key; nothing runs until you enable it.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       content-automation-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CA_VERSION', '1.0.0' );
define( 'ERU_CA_FILE', __FILE__ );
define( 'ERU_CA_PATH', plugin_dir_path( __FILE__ ) );

require_once ERU_CA_PATH . 'includes/class-eru-ca-settings.php';
require_once ERU_CA_PATH . 'includes/class-eru-ca-client.php';
require_once ERU_CA_PATH . 'includes/class-eru-ca-queue.php';
require_once ERU_CA_PATH . 'includes/class-eru-ca-runner.php';

register_activation_hook( __FILE__, array( 'Eru_CA_Runner', 'sync_schedule' ) );
register_deactivation_hook( __FILE__, array( 'Eru_CA_Runner', 'clear_schedule' ) );

add_action(
	'plugins_loaded',
	function () {
		Eru_CA_Runner::init();

		if ( is_admin() ) {
			require_once ERU_CA_PATH . 'includes/class-eru-ca-admin.php';
			Eru_CA_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CA_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-ca' ) ) . '">' . esc_html__( 'Ayarlar', 'content-automation-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);
