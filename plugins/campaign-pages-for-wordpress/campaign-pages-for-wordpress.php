<?php
/**
 * Plugin Name:       Campaign Pages for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/campaign-pages-for-wordpress/
 * Description:       A campaign post type with start and end dates, banner, call-to-action and product or category link. Ended campaigns hide themselves; an archive page and the [eru_campaigns] shortcode list the active ones.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       campaign-pages-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CP_VERSION', '1.0.0' );
define( 'ERU_CP_FILE', __FILE__ );
define( 'ERU_CP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_CP_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_CP_PATH . 'includes/class-eru-cp-settings.php';
require_once ERU_CP_PATH . 'includes/class-eru-cp-campaign.php';
require_once ERU_CP_PATH . 'includes/class-eru-cp-front.php';

register_activation_hook(
	__FILE__,
	function () {
		Eru_CP_Campaign::register();
		flush_rewrite_rules();
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

add_action(
	'plugins_loaded',
	function () {
		Eru_CP_Campaign::init();
		Eru_CP_Front::init();

		if ( is_admin() ) {
			require_once ERU_CP_PATH . 'includes/class-eru-cp-admin.php';
			Eru_CP_Admin::init();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CP_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=eru_campaign&page=eru-cp-settings' ) ) . '">' . esc_html__( 'Ayarlar', 'campaign-pages-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}
);
