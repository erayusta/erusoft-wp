<?php
/**
 * Minimal SEO layer: Open Graph tags and Organization + WebSite JSON-LD on the front page.
 * Skipped automatically when an SEO plugin is active (filter: voltaj_seo_enabled).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Whether the theme should print its own meta tags.
 *
 * @return bool
 */
function voltaj_seo_enabled() {
	$plugin_active = defined('WPSEO_VERSION')          // Yoast SEO
		|| class_exists('RankMath')                    // Rank Math
		|| defined('SEOPRESS_VERSION')                 // SEOPress
		|| defined('AIOSEO_VERSION');                  // All in One SEO
	return (bool) apply_filters('voltaj_seo_enabled', !$plugin_active);
}

/**
 * Description for the current view (plain text, trimmed).
 *
 * @return string
 */
function voltaj_seo_description() {
	if (is_front_page()) {
		$text = voltaj_setting('hero_text') ?: voltaj_site_description();
	} elseif (is_singular()) {
		$text = voltaj_safe_excerpt(get_queried_object(), 30);
	} elseif (is_home()) {
		$text = voltaj_site_description();
	} elseif (is_category() || is_tag() || is_tax()) {
		$text = wp_strip_all_tags(term_description());
	} else {
		$text = voltaj_site_description();
	}
	return wp_trim_words(trim(preg_replace('/\s+/', ' ', (string) $text)), 30, '…');
}

/**
 * Image for the current view: featured image, else the hero image, else the custom logo.
 *
 * @return string
 */
function voltaj_seo_image() {
	$id = 0;
	if (is_singular() && has_post_thumbnail()) {
		$id = get_post_thumbnail_id();
	}
	if (!$id) {
		$id = (int) voltaj_setting('hero_image');
	}
	if (!$id) {
		$id = (int) get_theme_mod('custom_logo');
	}
	$src = $id ? wp_get_attachment_image_url($id, 'large') : '';
	return $src ? $src : '';
}

/**
 * Print Open Graph tags.
 */
function voltaj_seo_open_graph() {
	if (!voltaj_seo_enabled() || is_404() || is_search()) {
		return;
	}
	$tags = array(
		'og:locale'    => str_replace('-', '_', get_bloginfo('language')),
		'og:site_name' => voltaj_site_name(),
		'og:type'      => is_singular('post') ? 'article' : (is_singular('product') ? 'product' : 'website'),
		'og:title'     => wp_get_document_title(),
		'og:url'       => is_singular() ? get_permalink() : (is_front_page() ? home_url('/') : ''),
		'og:description' => voltaj_seo_description(),
		'og:image'     => voltaj_seo_image(),
	);
	$tags = apply_filters('voltaj_open_graph_tags', $tags);
	foreach ($tags as $property => $content) {
		if ($content !== '' && $content !== null) {
			$value = in_array($property, array('og:url', 'og:image'), true) ? esc_url($content) : esc_attr($content);
			printf('<meta property="%1$s" content="%2$s">' . "\n", esc_attr($property), $value); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
add_action('wp_head', 'voltaj_seo_open_graph', 6);

/**
 * Organization + WebSite JSON-LD on the front page. Only configured fields are included.
 */
function voltaj_seo_json_ld() {
	if (!voltaj_seo_enabled() || !is_front_page()) {
		return;
	}
	$home = home_url('/');
	$org  = array(
		'@type' => 'Organization',
		'@id'   => $home . '#organization',
		'name'  => voltaj_company_name(),
		'url'   => $home,
	);
	$logo = (int) get_theme_mod('custom_logo');
	if ($logo && ($src = wp_get_attachment_image_url($logo, 'full'))) {
		$org['logo'] = $src;
	}
	$email = voltaj_setting('contact_email');
	if ($email && is_email($email)) {
		$org['email'] = $email;
	}
	$phone = trim((string) voltaj_setting('contact_phone'));
	if ($phone !== '') {
		$org['telephone'] = $phone;
	}
	$address = trim((string) voltaj_setting('contact_address'));
	if ($address !== '') {
		$org['address'] = array('@type' => 'PostalAddress', 'streetAddress' => preg_replace('/\s*\n\s*/', ', ', $address));
	}
	$same = wp_list_pluck(voltaj_social_links(), 'url');
	if ($same) {
		$org['sameAs'] = array_values($same);
	}

	$site = array(
		'@type'     => 'WebSite',
		'@id'       => $home . '#website',
		'url'       => $home,
		'name'      => voltaj_site_name(),
		'publisher' => array('@id' => $home . '#organization'),
		'inLanguage' => get_bloginfo('language'),
	);

	$graph = apply_filters('voltaj_json_ld', array('@context' => 'https://schema.org', '@graph' => array($org, $site)));
	echo '<script type="application/ld+json">' . wp_json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action('wp_head', 'voltaj_seo_json_ld', 7);
