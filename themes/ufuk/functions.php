<?php
/**
 * Ufuk theme bootstrap: theme supports, assets, widget areas and includes.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}

define('UFUK_VERSION', '1.0.0');
define('UFUK_DIR', get_template_directory());
define('UFUK_URI', get_template_directory_uri());

if (!isset($content_width)) {
	$content_width = 840;
}

require UFUK_DIR . '/inc/customizer.php';
require UFUK_DIR . '/inc/template-tags.php';
require UFUK_DIR . '/inc/contact.php';
require UFUK_DIR . '/inc/seo.php';

/**
 * Theme supports, menus and image sizes.
 */
function ufuk_setup() {
	load_theme_textdomain('ufuk', UFUK_DIR . '/languages');

	add_theme_support('title-tag');
	add_theme_support('post-thumbnails');
	add_theme_support('automatic-feed-links');
	add_theme_support('responsive-embeds');
	add_theme_support('align-wide');
	add_theme_support('wp-block-styles');
	add_theme_support('customize-selective-refresh-widgets');
	add_theme_support('custom-logo', array(
		'height'      => 48,
		'width'       => 160,
		'flex-height' => true,
		'flex-width'  => true,
	));
	add_theme_support('html5', array(
		'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets',
	));

	register_nav_menus(array(
		'primary' => __('Ana menü', 'ufuk'),
		'footer'  => __('Alt bilgi menüsü', 'ufuk'),
	));

	add_image_size('ufuk-card', 640, 400, true);
}
add_action('after_setup_theme', 'ufuk_setup');

/**
 * Widget areas.
 */
function ufuk_widgets_init() {
	$common = array(
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	);
	register_sidebar(array_merge($common, array(
		'name'        => __('Kenar çubuğu', 'ufuk'),
		'id'          => 'sidebar-1',
		'description' => __('Blog, arşiv ve arama sayfalarında görünür.', 'ufuk'),
	)));
	register_sidebar(array_merge($common, array(
		'name'        => __('Alt bilgi', 'ufuk'),
		'id'          => 'footer-1',
		'description' => __('Site alt bilgisinde, sütunların altında görünür.', 'ufuk'),
	)));
}
add_action('widgets_init', 'ufuk_widgets_init');

/**
 * Front-end styles and scripts.
 */
function ufuk_assets() {
	wp_enqueue_style('ufuk-style', get_stylesheet_uri(), array(), UFUK_VERSION);

	wp_enqueue_script('ufuk-main', UFUK_URI . '/assets/js/main.js', array(), UFUK_VERSION, true);
	wp_localize_script('ufuk-main', 'ufukTheme', array(
		'ajaxUrl'     => admin_url('admin-ajax.php'),
		'storageKey'  => 'ufuk_theme',
		'i18n'        => array(
			'sending'     => __('Gönderiliyor…', 'ufuk'),
			'error'       => __('Bir hata oluştu, lütfen tekrar deneyin.', 'ufuk'),
			'openSubmenu' => __('Alt menüyü aç', 'ufuk'),
		),
	));

	if (is_singular() && comments_open() && get_option('thread_comments')) {
		wp_enqueue_script('comment-reply');
	}
}
add_action('wp_enqueue_scripts', 'ufuk_assets');

/**
 * Preload the latin font file when the bundled web font is in use.
 */
function ufuk_preload_font() {
	if (!ufuk_setting('use_webfont')) {
		return;
	}
	printf(
		'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
		esc_url(UFUK_URI . '/fonts/plus-jakarta-sans-latin-wght-normal.woff2')
	);
}
add_action('wp_head', 'ufuk_preload_font', 2);

/**
 * Mark the document as JS-capable before paint, so reveal animations never hide content without JS.
 * Also applies the stored colour-scheme choice early to avoid a flash.
 */
function ufuk_head_script() {
	$auto = ufuk_setting('color_scheme') === 'auto';
	?>
	<script>
	(function(d){var r=d.documentElement;r.className=r.className.replace(/\bno-js\b/,'')+' js';<?php if ($auto) : ?>try{var t=localStorage.getItem('ufuk_theme');if(t==='dark'||t==='light'){r.setAttribute('data-theme',t);}}catch(e){}<?php endif; ?>})(document);
	</script>
	<?php
}
add_action('wp_head', 'ufuk_head_script', 0);

/**
 * Body classes.
 */
function ufuk_body_classes($classes) {
	if (is_active_sidebar('sidebar-1') && (is_home() || is_archive() || is_search())) {
		$classes[] = 'has-sidebar';
	}
	if (!ufuk_setting('use_webfont')) {
		$classes[] = 'ufuk-system-font';
	}
	return $classes;
}
add_filter('body_class', 'ufuk_body_classes');

/**
 * Excerpt tweaks.
 */
function ufuk_excerpt_length() {
	return 24;
}
add_filter('excerpt_length', 'ufuk_excerpt_length');

function ufuk_excerpt_more() {
	return '…';
}
add_filter('excerpt_more', 'ufuk_excerpt_more');

/**
 * Fallback favicon (brand colour + first letter of the site title) while no Site Icon is set.
 */
function ufuk_fallback_favicon() {
	if (has_site_icon()) {
		return;
	}
	$name    = ufuk_site_name();
	$initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '';
	$svg     = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="%1$s"/><text x="32" y="44" font-family="Arial,sans-serif" font-size="34" font-weight="700" fill="#fff" text-anchor="middle">%2$s</text></svg>',
		esc_attr(sanitize_hex_color(ufuk_setting('brand_color')) ?: '#2946E0'),
		esc_html($initial)
	);
	printf('<link rel="icon" href="%s">' . "\n", esc_attr('data:image/svg+xml,' . rawurlencode($svg)));
}
add_action('wp_head', 'ufuk_fallback_favicon', 3);
