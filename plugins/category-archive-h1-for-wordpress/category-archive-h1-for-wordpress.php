<?php
/**
 * Plugin Name:       Category Archive H1 for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/category-archive-h1-for-wordpress/
 * Description:       Kategori, etiket ve diğer sınıflandırma arşivleri için terim adından bağımsız, düzenlenebilir bir H1 başlığı alanı ekler. Arşiv sayfasında tam olarak bir H1 bulunmasını sağlar; temanın mevcut H1'ini çoğaltmaz.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       category-archive-h1-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CAH_VERSION', '1.0.0' );
define( 'ERU_CAH_FILE', __FILE__ );
define( 'ERU_CAH_PATH', plugin_dir_path( __FILE__ ) );

require_once ERU_CAH_PATH . 'includes/class-eru-cah.php';
require_once ERU_CAH_PATH . 'includes/class-eru-cah-settings.php';

add_action(
	'plugins_loaded',
	function () {
		Eru_Cah::init();
		Eru_Cah_Settings::init();
	}
);
