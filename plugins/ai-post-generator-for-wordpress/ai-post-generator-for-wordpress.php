<?php
/**
 * Plugin Name:       AI Post Generator for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/ai-post-generator-for-wordpress/
 * Description:       Generate post drafts from a topic and keywords with OpenAI, Anthropic or Perplexity. You bring your own API key; output is saved as a draft (or pending) post only. No request is ever sent without a key.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-post-generator-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_APG_VERSION', '1.0.0' );
define( 'ERU_APG_FILE', __FILE__ );
define( 'ERU_APG_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_APG_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_APG_PATH . 'includes/class-eru-apg-settings.php';
require_once ERU_APG_PATH . 'includes/class-eru-apg-client.php';
require_once ERU_APG_PATH . 'includes/class-eru-apg-generator.php';

add_action(
	'plugins_loaded',
	function () {
		if ( is_admin() ) {
			require_once ERU_APG_PATH . 'includes/class-eru-apg-admin.php';
			Eru_APG_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_APG_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-apg' ) ) . '">' . esc_html__( 'Ayarlar', 'ai-post-generator-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);
