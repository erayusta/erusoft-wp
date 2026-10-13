<?php
/**
 * Voltaj theme bootstrap: theme supports, assets (per-area CSS), widget areas and includes.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

define('VOLTAJ_VERSION', '1.0.0');
define('VOLTAJ_DIR', get_template_directory());
define('VOLTAJ_URI', get_template_directory_uri());

if (!isset($content_width)) {
	$content_width = 780;
}

/**
 * Whether WooCommerce is active. Every WooCommerce call in the theme is behind this check.
 *
 * @return bool
 */
function voltaj_wc_active() {
	return class_exists('WooCommerce');
}

require VOLTAJ_DIR . '/inc/customizer.php';
require VOLTAJ_DIR . '/inc/template-tags.php';
require VOLTAJ_DIR . '/inc/catalog-menu.php';
require VOLTAJ_DIR . '/inc/forms.php';
require VOLTAJ_DIR . '/inc/blog.php';
require VOLTAJ_DIR . '/inc/seo.php';

if (voltaj_wc_active()) {
	require VOLTAJ_DIR . '/inc/woocommerce.php';
	require VOLTAJ_DIR . '/inc/shop-filters.php';
}

/**
 * Theme supports, menus and image sizes.
 */
function voltaj_setup() {
	load_theme_textdomain('voltaj', VOLTAJ_DIR . '/languages');

	add_theme_support('title-tag');
	add_theme_support('post-thumbnails');
	add_theme_support('automatic-feed-links');
	add_theme_support('responsive-embeds');
	add_theme_support('align-wide');
	add_theme_support('wp-block-styles');
	add_theme_support('customize-selective-refresh-widgets');
	add_theme_support('custom-logo', array(
		'height'      => 48,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	));
	add_theme_support('html5', array(
		'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets',
	));

	add_theme_support('woocommerce', array(
		'thumbnail_image_width' => 480,
		'single_image_width'    => 900,
		'product_grid'          => array(
			'default_rows'    => 4,
			'min_rows'        => 1,
			'default_columns' => 4,
			'min_columns'     => 2,
			'max_columns'     => 5,
		),
	));
	add_theme_support('wc-product-gallery-zoom');
	add_theme_support('wc-product-gallery-lightbox');
	add_theme_support('wc-product-gallery-slider');

	register_nav_menus(array(
		'primary'  => __('Ana menü (katalog panelinin altında ve mobil çekmecede)', 'voltaj'),
		'catalog'  => __('Katalog paneli (boşsa ürün kategorilerinden oluşturulur)', 'voltaj'),
		'footer-1' => __('Alt bilgi: 1. sütun', 'voltaj'),
		'footer-2' => __('Alt bilgi: 2. sütun', 'voltaj'),
		'footer-3' => __('Alt bilgi: 3. sütun', 'voltaj'),
	));

	add_image_size('voltaj-tile', 480, 480, true);
	add_image_size('voltaj-post', 720, 450, true);
}
add_action('after_setup_theme', 'voltaj_setup');

/**
 * Widget areas.
 */
function voltaj_widgets_init() {
	$common = array(
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	);
	register_sidebar(array_merge($common, array(
		'name'        => __('Blog kenar çubuğu', 'voltaj'),
		'id'          => 'sidebar-blog',
		'description' => __('Blog yazılarında içindekiler tablosunun altında görünür.', 'voltaj'),
	)));
	if (voltaj_wc_active()) {
		register_sidebar(array_merge($common, array(
			'name'        => __('Mağaza filtreleri', 'voltaj'),
			'id'          => 'shop-filters',
			'description' => __('Mağaza ve kategori sayfalarındaki filtre sütununda, nitelik filtrelerinin altında görünür (ör. fiyat filtresi).', 'voltaj'),
		)));
	}
}
add_action('widgets_init', 'voltaj_widgets_init');

/**
 * Per-area stylesheets for the current view.
 *
 * @return string[] File names (without .css) under assets/css/.
 */
function voltaj_css_areas() {
	$areas = array('base', 'layout');
	$shop  = voltaj_wc_active();

	if (is_front_page()) {
		$areas[] = 'home';
	}
	if ($shop || is_front_page()) {
		$areas[] = 'cards';
	}
	if ($shop && (is_shop() || is_product_taxonomy() || (is_search() && get_query_var('post_type') === 'product'))) {
		$areas[] = 'shop';
	}
	if ($shop && is_product()) {
		$areas[] = 'product';
	}
	if ($shop && (is_cart() || is_checkout() || is_account_page())) {
		$areas[] = 'checkout';
	}
	if (is_singular('post') || is_home() || is_archive() || is_search() || is_front_page()) {
		$areas[] = 'blog';
	}
	if (is_page() || is_404()) {
		$areas[] = 'pages';
	}
	return array_values(array_unique(apply_filters('voltaj_css_areas', $areas)));
}

/**
 * Front-end styles and scripts.
 */
function voltaj_assets() {
	$prev = array();
	foreach (voltaj_css_areas() as $area) {
		$rel = '/assets/css/' . $area . '.css';
		if (!file_exists(VOLTAJ_DIR . $rel)) {
			continue;
		}
		wp_enqueue_style('voltaj-' . $area, VOLTAJ_URI . $rel, $prev, VOLTAJ_VERSION);
		$prev = array('voltaj-base');
	}

	wp_enqueue_script('voltaj-main', VOLTAJ_URI . '/assets/js/main.js', array(), VOLTAJ_VERSION, array('strategy' => 'defer', 'in_footer' => true));

	$config = array(
		'recentKey'   => 'voltaj_recent',
		'recentLimit' => 8,
		'i18n'        => array(
			'copied'     => __('Kopyalandı', 'voltaj'),
			'copyFailed' => __('Kopyalanamadı, lütfen elle seçin.', 'voltaj'),
			'added'      => __('Sepete eklendi', 'voltaj'),
			'viewCart'   => __('Sepete git', 'voltaj'),
			'close'      => __('Kapat', 'voltaj'),
			'sending'    => __('Gönderiliyor…', 'voltaj'),
			'formFailed' => __('Form gönderilemedi. Lütfen daha sonra tekrar deneyin.', 'voltaj'),
			'decrease'   => __('Azalt', 'voltaj'),
			'increase'   => __('Artır', 'voltaj'),
		),
		'formAjax'    => admin_url('admin-ajax.php'),
	);
	if (voltaj_wc_active()) {
		$config['cartUrl'] = wc_get_cart_url();
	}
	wp_localize_script('voltaj-main', 'voltajTheme', $config);

	if (is_singular() && comments_open() && get_option('thread_comments')) {
		wp_enqueue_script('comment-reply');
	}
}
add_action('wp_enqueue_scripts', 'voltaj_assets');

/**
 * Bundled font faces (printed inline so the URLs resolve to the theme directory) and preload of the latin files.
 */
function voltaj_font_faces() {
	if (!voltaj_setting('use_webfont')) {
		return;
	}
	$latin = 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD';
	$ext   = 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF';
	$faces = array(
		array('Archivo', 'archivo', '100 900'),
		array('JetBrains Mono', 'jetbrains-mono', '100 800'),
	);
	$css = '';
	foreach ($faces as $face) {
		foreach (array('latin-ext' => $ext, 'latin' => $latin) as $subset => $range) {
			$css .= sprintf(
				'@font-face{font-family:"%1$s";font-style:normal;font-display:swap;font-weight:%2$s;src:url("%3$s") format("woff2");unicode-range:%4$s;}',
				$face[0],
				$face[2],
				esc_url(VOLTAJ_URI . '/fonts/' . $face[1] . '-' . $subset . '-wght-normal.woff2'),
				$range
			);
		}
	}
	foreach (array('archivo', 'jetbrains-mono') as $file) {
		printf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url(VOLTAJ_URI . '/fonts/' . $file . '-latin-wght-normal.woff2'));
	}
	echo '<style id="voltaj-fonts">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed strings and escaped URLs.
}
add_action('wp_head', 'voltaj_font_faces', 2);

/**
 * Mark the document as JS-capable before paint.
 */
function voltaj_head_script() {
	echo "<script>document.documentElement.className=document.documentElement.className.replace(/\\bno-js\\b/,'')+' js';</script>\n";
}
add_action('wp_head', 'voltaj_head_script', 0);

/**
 * Body classes.
 *
 * @param string[] $classes
 * @return string[]
 */
function voltaj_body_classes($classes) {
	if (!voltaj_setting('use_webfont')) {
		$classes[] = 'voltaj-system-font';
	}
	if (voltaj_announcement_text() !== '') {
		$classes[] = 'has-announcement';
	}
	if (!voltaj_wc_active()) {
		$classes[] = 'voltaj-no-shop';
	}
	return $classes;
}
add_filter('body_class', 'voltaj_body_classes');

/**
 * Excerpt length and suffix.
 *
 * @return int
 */
function voltaj_excerpt_length() {
	return 24;
}
add_filter('excerpt_length', 'voltaj_excerpt_length');

/**
 * @return string
 */
function voltaj_excerpt_more() {
	return '…';
}
add_filter('excerpt_more', 'voltaj_excerpt_more');

/**
 * Fallback favicon (graphite square with an accent bar and the site initial) while no Site Icon is set.
 */
function voltaj_fallback_favicon() {
	if (has_site_icon()) {
		return;
	}
	$name    = voltaj_site_name();
	$initial = $name !== '' ? voltaj_upper_tr(mb_substr($name, 0, 1, 'UTF-8')) : '';
	$svg     = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="8" fill="%1$s"/><rect x="10" y="50" width="44" height="5" fill="%2$s"/><text x="32" y="42" font-family="Arial,sans-serif" font-weight="700" font-size="32" fill="#fff" text-anchor="middle">%3$s</text></svg>',
		esc_attr(voltaj_color('base_color')),
		esc_attr(voltaj_color('accent_color')),
		esc_html($initial)
	);
	printf('<link rel="icon" href="%s">' . "\n", esc_attr('data:image/svg+xml,' . rawurlencode($svg)));
}
add_action('wp_head', 'voltaj_fallback_favicon', 3);

/**
 * No emoji detection script on the front end (it loads images from an external CDN).
 */
function voltaj_disable_emoji() {
	remove_action('wp_head', 'print_emoji_detection_script', 7);
	remove_action('wp_print_styles', 'print_emoji_styles');
}
add_action('init', 'voltaj_disable_emoji');
