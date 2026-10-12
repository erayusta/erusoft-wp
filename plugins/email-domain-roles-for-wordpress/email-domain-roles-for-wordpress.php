<?php
/**
 * Plugin Name:       Email Domain Roles for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/email-domain-roles-for-wordpress/
 * Description:       Yeni kayıt olan kullanıcıya e-posta alan adına göre otomatik rol atar. Eşleme tablosu ayarlardan yönetilir; varsayılan olarak boştur.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       email-domain-roles-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_EDR_VERSION', '1.0.0' );
define( 'ERU_EDR_FILE', __FILE__ );
define( 'ERU_EDR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERU_EDR_URL', plugin_dir_url( __FILE__ ) );

require_once ERU_EDR_PATH . 'includes/class-eru-edr.php';
require_once ERU_EDR_PATH . 'includes/class-eru-edr-settings.php';

add_action(
	'plugins_loaded',
	function () {
		Eru_Edr::init();
		Eru_Edr_Settings::init();
	}
);
