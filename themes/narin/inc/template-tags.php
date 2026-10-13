<?php
/**
 * Template helpers: icons, branding, menus, contact details, admin-only hints.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Inline SVG icon (Feather icon paths, MIT, see assets/icons/LICENSE). Decorative: aria-hidden.
 *
 * @param string $name Icon name.
 * @return string
 */
function narin_get_icon($name) {
	$stroke = '<svg class="narin-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>';
	$paths  = array(
		'search'        => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
		'user'          => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		'bag'           => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
		'menu'          => '<line x1="3" y1="7" x2="21" y2="7"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="17" x2="21" y2="17"/>',
		'x'             => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		'chevron-down'  => '<polyline points="6 9 12 15 18 9"/>',
		'arrow-right'   => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
		'check'         => '<polyline points="20 6 9 17 4 12"/>',
		'truck'         => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
		'sliders'       => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
		'file-text'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
		'home'          => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
		'mail'          => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6 12 13 2 6"/>',
		'phone'         => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
		'map-pin'       => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
		'clock'         => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		'edit'          => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
		'instagram'     => '<rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.5" y2="6.5"/>',
		'facebook'      => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
		'youtube'       => '<path d="M22.5 8.5s-.2-1.6-.9-2.3c-.8-.9-1.8-.9-2.2-1C16.4 5 12 5 12 5s-4.4 0-7.4.2c-.4 0-1.4.1-2.2 1-.7.7-.9 2.3-.9 2.3S1.3 10.4 1.3 12.2v1.6c0 1.9.2 3.7.2 3.7s.2 1.6.9 2.3c.8.9 1.9.9 2.4 1 1.7.2 7.2.2 7.2.2s4.4 0 7.4-.3c.4 0 1.4-.1 2.2-1 .7-.7.9-2.3.9-2.3s.2-1.9.2-3.7v-1.6c0-1.9-.2-3.7-.2-3.7z"/><polygon points="10 15 15 12 10 9"/>',
		'linkedin'      => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4V8h4v1.7A4.5 4.5 0 0 1 16 8z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
	);
	if ($name === 'x-logo') {
		return '<svg class="narin-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.9 2H22l-7.6 8.7L23.3 22h-7l-5.5-6.7L4.5 22H1.4l8.1-9.3L1 2h7.2l5 6.1L18.9 2zm-1.2 18h1.7L7.4 4H5.6l12.1 16z"/></svg>';
	}
	return isset($paths[$name]) ? sprintf($stroke, $paths[$name]) : '';
}

/**
 * Echo an icon. The markup is a fixed internal string.
 *
 * @param string $name Icon name.
 */
function narin_icon($name) {
	echo narin_get_icon($name); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Site title as plain text (core stores it HTML-escaped), trimmed; escape at output.
 *
 * @return string
 */
function narin_site_name() {
	return trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
}

/**
 * Tagline as plain text (core stores it HTML-escaped), trimmed; escape at output.
 *
 * @return string
 */
function narin_site_description() {
	return trim(wp_specialchars_decode((string) get_bloginfo('description'), ENT_QUOTES));
}

/**
 * Site branding: the Customizer logo, else the site title typeset in the display font.
 *
 * @param string $tag  Wrapper element when not linking.
 * @param bool   $link Wrap in a home link.
 */
function narin_branding($tag = 'span', $link = true) {
	$name = narin_site_name();
	if (has_custom_logo()) {
		$inner = wp_get_attachment_image((int) get_theme_mod('custom_logo'), 'full', false, array('class' => 'custom-logo', 'alt' => $name));
		$inner .= '<span class="screen-reader-text">' . esc_html($name) . '</span>';
	} else {
		$inner = '<span class="brand-name">' . esc_html($name) . '</span>';
	}
	if ($link) {
		printf('<a class="site-branding" href="%1$s" rel="home">%2$s</a>', esc_url(home_url('/')), $inner); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		$tag = in_array($tag, array('span', 'div', 'p'), true) ? $tag : 'span';
		printf('<%1$s class="site-branding">%2$s</%1$s>', $tag, $inner); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Announcement text (trimmed; empty means the bar is hidden).
 *
 * @return string
 */
function narin_announcement_text() {
	return trim((string) narin_setting('announcement_text'));
}

/**
 * Primary menu fallback when no menu is assigned: top-level product categories (with WooCommerce) or
 * top-level pages; administrators also get a link to the menu screen.
 *
 * @param array $args wp_nav_menu arguments.
 */
function narin_fallback_menu($args = array()) {
	$items = array();
	if (narin_wc_active()) {
		$shop = wc_get_page_permalink('shop');
		if ($shop) {
			$items[] = array($shop, __('Mağaza', 'narin'));
		}
		$terms = get_terms(array(
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
			'number'     => 4,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'exclude'    => array((int) get_option('default_product_cat')),
		));
		foreach (is_wp_error($terms) ? array() : $terms as $term) {
			$link = get_term_link($term);
			if (!is_wp_error($link)) {
				$items[] = array($link, $term->name);
			}
		}
	}
	if (!$items) {
		$excluded = array((int) get_option('page_on_front'));
		foreach (get_pages(array('parent' => 0, 'sort_column' => 'menu_order,post_title', 'number' => 6)) as $page) {
			if (!in_array($page->ID, $excluded, true)) {
				$items[] = array(get_permalink($page), get_the_title($page));
			}
		}
	}
	echo '<ul class="menu">';
	foreach ($items as $item) {
		printf('<li class="menu-item"><a href="%1$s">%2$s</a></li>', esc_url($item[0]), esc_html($item[1]));
	}
	if (current_user_can('edit_theme_options')) {
		printf('<li class="menu-item narin-menu-hint"><a href="%1$s">%2$s</a></li>', esc_url(admin_url('nav-menus.php')), esc_html__('+ Menü oluştur', 'narin'));
	}
	echo '</ul>';
}

/**
 * Primary menu (desktop bar and mobile drawer share the markup).
 *
 * @param string $id Unique menu id.
 */
function narin_primary_menu($id = 'primary-menu') {
	wp_nav_menu(array(
		'theme_location' => 'primary',
		'container'      => false,
		'menu_class'     => 'menu',
		'menu_id'        => $id,
		'depth'          => 2,
		'fallback_cb'    => 'narin_fallback_menu',
	));
}

/**
 * Footer menu column (nothing when no menu is assigned to the location).
 *
 * @param string $location footer-1 … footer-3.
 */
function narin_footer_menu($location) {
	if (!has_nav_menu($location)) {
		return;
	}
	$locations = get_nav_menu_locations();
	$menu      = isset($locations[$location]) ? wp_get_nav_menu_object($locations[$location]) : null;
	$title     = $menu ? $menu->name : '';
	echo '<nav class="footer-col" aria-label="' . esc_attr($title) . '">';
	if ($title !== '') {
		echo '<h2 class="footer-heading">' . esc_html($title) . '</h2>';
	}
	wp_nav_menu(array('theme_location' => $location, 'container' => false, 'menu_class' => 'menu', 'depth' => 1));
	echo '</nav>';
}

/**
 * Configured social profiles.
 *
 * @return array<int,array{url:string,label:string,icon:string}>
 */
function narin_social_links() {
	$networks = array(
		'instagram' => array('Instagram', 'instagram'),
		'facebook'  => array('Facebook', 'facebook'),
		'x'         => array('X', 'x-logo'),
		'youtube'   => array('YouTube', 'youtube'),
		'linkedin'  => array('LinkedIn', 'linkedin'),
	);
	$links = array();
	foreach ($networks as $key => $meta) {
		$url = narin_setting('social_' . $key);
		if ($url) {
			$links[] = array('url' => $url, 'label' => $meta[0], 'icon' => $meta[1]);
		}
	}
	return apply_filters('narin_social_links', $links);
}

/**
 * Social icon row (nothing when no profile is configured).
 */
function narin_social_row() {
	$links = narin_social_links();
	if (!$links) {
		return;
	}
	echo '<ul class="social-row">';
	foreach ($links as $link) {
		printf(
			'<li><a href="%1$s" target="_blank" rel="noopener me"><span class="screen-reader-text">%2$s</span>%3$s</a></li>',
			esc_url($link['url']),
			/* translators: %s: social network name */
			esc_html(sprintf(__('%s (yeni sekmede açılır)', 'narin'), $link['label'])),
			narin_get_icon($link['icon']) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
	echo '</ul>';
}

/**
 * Name for copyright and structured data.
 *
 * @return string
 */
function narin_company_name() {
	$name = trim((string) narin_setting('company_name'));
	return $name !== '' ? $name : narin_site_name();
}

/**
 * A `tel:` href from a phone number as typed (digits and a leading + only). Empty when there are no digits.
 *
 * @param string $phone
 * @return string
 */
function narin_tel_href($phone) {
	$digits = preg_replace('/[^\d+]/', '', (string) $phone);
	return preg_match('/\d{3,}/', $digits) ? 'tel:' . $digits : '';
}

/**
 * Contact detail rows that have a value.
 *
 * @return array<int,array{key:string,icon:string,label:string,html:string}>
 */
function narin_contact_items() {
	$items   = array();
	$address = trim((string) narin_setting('contact_address'));
	if ($address !== '') {
		$items[] = array('key' => 'address', 'icon' => 'map-pin', 'label' => __('Adres', 'narin'), 'html' => nl2br(esc_html($address)));
	}
	$phone = trim((string) narin_setting('contact_phone'));
	if ($phone !== '') {
		$href    = narin_tel_href($phone);
		$items[] = array('key' => 'phone', 'icon' => 'phone', 'label' => __('Telefon', 'narin'), 'html' => $href ? sprintf('<a href="%1$s">%2$s</a>', esc_attr($href), esc_html($phone)) : esc_html($phone));
	}
	$email = narin_setting('contact_email');
	if ($email && is_email($email)) {
		$items[] = array('key' => 'email', 'icon' => 'mail', 'label' => __('E-posta', 'narin'), 'html' => sprintf('<a href="mailto:%1$s">%2$s</a>', esc_attr(antispambot($email)), esc_html(antispambot($email))));
	}
	$hours = trim((string) narin_setting('contact_hours'));
	if ($hours !== '') {
		$items[] = array('key' => 'hours', 'icon' => 'clock', 'label' => __('Çalışma saatleri', 'narin'), 'html' => esc_html($hours));
	}
	return $items;
}

/**
 * Contact list markup.
 *
 * @param string $class List class.
 */
function narin_contact_list($class = 'contact-list') {
	$items = narin_contact_items();
	if (!$items) {
		return;
	}
	echo '<ul class="' . esc_attr($class) . '">';
	foreach ($items as $item) {
		printf(
			'<li class="%1$s">%2$s<span class="screen-reader-text">%3$s:</span> <span>%4$s</span></li>',
			esc_attr('contact-' . $item['key']),
			narin_get_icon($item['icon']), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html($item['label']),
			$item['html'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in narin_contact_items().
		);
	}
	echo '</ul>';
}

/**
 * Admin-only hint shown where a section has nothing to display. Visitors never see it.
 *
 * @param string $message Plain text message.
 * @param string $section Customizer section to focus.
 */
function narin_admin_hint($message, $section) {
	if (!current_user_can('edit_theme_options')) {
		return;
	}
	printf(
		'<div class="container"><p class="narin-admin-hint">%1$s <span>%2$s</span> <a href="%3$s">%4$s</a></p></div>',
		narin_get_icon('edit'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html($message),
		esc_url(narin_customizer_link($section)),
		esc_html__('Özelleştir', 'narin')
	);
}

/**
 * Button link, rendered only when both text and URL are set.
 *
 * @param string $text
 * @param string $url
 * @param string $class Extra classes.
 */
function narin_button($text, $url, $class = 'btn-primary') {
	if ($text === '' || $url === '') {
		return;
	}
	printf('<a class="btn %1$s" href="%2$s">%3$s</a>', esc_attr($class), esc_url($url), esc_html($text));
}

/**
 * Pagination for post listings.
 */
function narin_pagination() {
	$links = paginate_links(array(
		'prev_text' => __('&lsaquo; Önceki', 'narin'),
		'next_text' => __('Sonraki &rsaquo;', 'narin'),
		'type'      => 'plain',
	));
	if ($links) {
		echo '<nav class="pagination" aria-label="' . esc_attr__('Sayfalama', 'narin') . '">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Plain-text summary of a post: its manual excerpt, else trimmed content. Empty for password-protected posts.
 *
 * @param WP_Post|int $post
 * @param int         $words
 * @return string
 */
function narin_safe_excerpt($post, $words = 24) {
	$post = get_post($post);
	if (!$post || post_password_required($post)) {
		return '';
	}
	$text = has_excerpt($post) ? (string) $post->post_excerpt : strip_shortcodes((string) $post->post_content);
	return wp_trim_words(wp_strip_all_tags(excerpt_remove_blocks($text)), $words, '…');
}

/**
 * Post card used on the blog listing, the front page and below single posts.
 *
 * @param WP_Post|int $post
 * @param string      $heading h2 or h3.
 */
function narin_post_card($post, $heading = 'h3') {
	$post    = get_post($post);
	$heading = in_array($heading, array('h2', 'h3'), true) ? $heading : 'h3';
	$cats    = get_the_category($post->ID);
	echo '<article class="post-card">';
	if (has_post_thumbnail($post)) {
		printf('<a class="post-card__media" href="%1$s" tabindex="-1" aria-hidden="true">%2$s</a>', esc_url(get_permalink($post)), get_the_post_thumbnail($post, 'narin-card', array('loading' => 'lazy', 'alt' => ''))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '<p class="post-card__meta">';
	if ($cats) {
		echo esc_html($cats[0]->name) . ' · ';
	}
	echo '<time datetime="' . esc_attr(get_the_date('c', $post)) . '">' . esc_html(get_the_date('', $post)) . '</time></p>';
	printf('<%1$s class="post-card__title"><a href="%2$s">%3$s</a></%1$s>', $heading, esc_url(get_permalink($post)), esc_html(get_the_title($post))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$excerpt = narin_safe_excerpt($post, 20);
	if ($excerpt !== '') {
		echo '<p class="post-card__excerpt">' . esc_html($excerpt) . '</p>';
	}
	echo '</article>';
}

/**
 * Cart item count (0 when WooCommerce or the cart is not available).
 *
 * @return int
 */
function narin_cart_count() {
	return (narin_wc_active() && function_exists('WC') && WC()->cart) ? (int) WC()->cart->get_cart_contents_count() : 0;
}
