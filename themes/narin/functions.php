<?php
/**
 * Narin theme bootstrap: theme supports, assets, widget areas and includes.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}

define('NARIN_VERSION', '1.0.0');
define('NARIN_DIR', get_template_directory());
define('NARIN_URI', get_template_directory_uri());

if (!isset($content_width)) {
	$content_width = 760;
}

require NARIN_DIR . '/inc/customizer.php';
require NARIN_DIR . '/inc/template-tags.php';
require NARIN_DIR . '/inc/seo.php';

/**
 * Whether WooCommerce is active. Every WooCommerce call in the theme is behind this check.
 *
 * @return bool
 */
function narin_wc_active() {
	return class_exists('WooCommerce');
}

if (narin_wc_active()) {
	require NARIN_DIR . '/inc/woocommerce.php';
}

/**
 * Theme supports, menus and image sizes.
 */
function narin_setup() {
	load_theme_textdomain('narin', NARIN_DIR . '/languages');

	add_theme_support('title-tag');
	add_theme_support('post-thumbnails');
	add_theme_support('automatic-feed-links');
	add_theme_support('responsive-embeds');
	add_theme_support('align-wide');
	add_theme_support('wp-block-styles');
	add_theme_support('customize-selective-refresh-widgets');
	add_theme_support('custom-logo', array(
		'height'      => 60,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	));
	add_theme_support('html5', array(
		'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets',
	));

	add_theme_support('woocommerce', array(
		'thumbnail_image_width' => 600,
		'single_image_width'    => 900,
		'product_grid'          => array(
			'default_rows'    => 4,
			'min_rows'        => 1,
			'default_columns' => 3,
			'min_columns'     => 2,
			'max_columns'     => 4,
		),
	));
	add_theme_support('wc-product-gallery-zoom');
	add_theme_support('wc-product-gallery-lightbox');
	add_theme_support('wc-product-gallery-slider');

	register_nav_menus(array(
		'primary'  => __('Ana menü', 'narin'),
		'footer-1' => __('Alt bilgi: 1. sütun', 'narin'),
		'footer-2' => __('Alt bilgi: 2. sütun', 'narin'),
		'footer-3' => __('Alt bilgi: 3. sütun', 'narin'),
	));

	add_image_size('narin-card', 720, 480, true);
	add_image_size('narin-hero', 1200, 1500, true);
}
add_action('after_setup_theme', 'narin_setup');

/**
 * Widget areas.
 */
function narin_widgets_init() {
	$common = array(
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	);
	register_sidebar(array_merge($common, array(
		'name'        => __('Blog kenar çubuğu', 'narin'),
		'id'          => 'sidebar-1',
		'description' => __('Blog yazısı sayfalarında, içeriğin altında görünür.', 'narin'),
	)));
	if (narin_wc_active()) {
		register_sidebar(array_merge($common, array(
			'name'        => __('Mağaza filtreleri', 'narin'),
			'id'          => 'shop-filters',
			'description' => __('Mağaza ve kategori sayfalarındaki filtre panelinde, kategorilerin altında görünür (ör. fiyat veya nitelik filtresi).', 'narin'),
		)));
	}
}
add_action('widgets_init', 'narin_widgets_init');

/**
 * Front-end styles and scripts.
 */
function narin_assets() {
	wp_enqueue_style('narin-style', get_stylesheet_uri(), array(), NARIN_VERSION);

	wp_enqueue_script('narin-main', NARIN_URI . '/assets/js/main.js', array(), NARIN_VERSION, array('strategy' => 'defer', 'in_footer' => true));
	wp_localize_script('narin-main', 'narinTheme', array(
		'recentKey'   => 'narin_recent',
		'recentLimit' => 8,
		'i18n'        => array(
			'openSubmenu' => __('Alt menüyü aç', 'narin'),
			'decrease'    => __('Azalt', 'narin'),
			'increase'    => __('Artır', 'narin'),
		),
	));

	if (is_singular() && comments_open() && get_option('thread_comments')) {
		wp_enqueue_script('comment-reply');
	}
}
add_action('wp_enqueue_scripts', 'narin_assets');

/**
 * Preload the two latin font files when the bundled web fonts are in use.
 */
function narin_preload_fonts() {
	if (!narin_setting('use_webfont')) {
		return;
	}
	foreach (array('cormorant-garamond-latin-wght-normal.woff2', 'jost-latin-wght-normal.woff2') as $file) {
		printf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url(NARIN_URI . '/fonts/' . $file));
	}
}
add_action('wp_head', 'narin_preload_fonts', 2);

/**
 * Mark the document as JS-capable before paint.
 */
function narin_head_script() {
	echo "<script>document.documentElement.className=document.documentElement.className.replace(/\\bno-js\\b/,'')+' js';</script>\n";
}
add_action('wp_head', 'narin_head_script', 0);

/**
 * Body classes.
 *
 * @param string[] $classes
 * @return string[]
 */
function narin_body_classes($classes) {
	if (!narin_setting('use_webfont')) {
		$classes[] = 'narin-system-font';
	}
	if (narin_announcement_text() !== '') {
		$classes[] = 'has-announcement';
	}
	if (!narin_wc_active()) {
		$classes[] = 'narin-no-shop';
	}
	return $classes;
}
add_filter('body_class', 'narin_body_classes');

/**
 * Excerpt length and suffix.
 *
 * @return int
 */
function narin_excerpt_length() {
	return 22;
}
add_filter('excerpt_length', 'narin_excerpt_length');

/**
 * @return string
 */
function narin_excerpt_more() {
	return '…';
}
add_filter('excerpt_more', 'narin_excerpt_more');

/**
 * Fallback favicon (main colour + first letter of the site title) while no Site Icon is set.
 */
function narin_fallback_favicon() {
	if (has_site_icon()) {
		return;
	}
	$name    = narin_site_name();
	$initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '';
	$svg     = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="32" fill="%1$s"/><text x="32" y="43" font-family="Georgia,serif" font-size="32" fill="#fff" text-anchor="middle">%2$s</text></svg>',
		esc_attr(sanitize_hex_color(narin_setting('brand_color')) ?: '#2F4A3E'),
		esc_html($initial)
	);
	printf('<link rel="icon" href="%s">' . "\n", esc_attr('data:image/svg+xml,' . rawurlencode($svg)));
}
add_action('wp_head', 'narin_fallback_favicon', 3);

/**
 * No emoji detection script on the front end: it loads images from an external CDN and the theme makes no
 * external requests. Emoji still display with the visitor's system font.
 */
function narin_disable_emoji() {
	remove_action('wp_head', 'print_emoji_detection_script', 7);
	remove_action('wp_print_styles', 'print_emoji_styles');
}
add_action('init', 'narin_disable_emoji');
