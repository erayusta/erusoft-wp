<?php
/**
 * Template helpers: icons, menus, contact details, admin-only hints.
 *
 * @package Ufuk
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
function ufuk_get_icon($name) {
	$stroke = '<svg class="ufuk-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>';
	$paths  = array(
		'check'        => '<polyline points="20 6 9 17 4 12"/>',
		'mail'         => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6 12 13 2 6"/>',
		'phone'        => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
		'map-pin'      => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
		'clock'        => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		'building'     => '<rect x="4" y="2" width="16" height="20" rx="1"/><line x1="9" y1="8" x2="9" y2="8"/><line x1="15" y1="8" x2="15" y2="8"/><line x1="9" y1="12" x2="9" y2="12"/><line x1="15" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="9" y2="16"/><line x1="15" y1="16" x2="15" y2="16"/><line x1="10" y1="22" x2="10" y2="18"/><line x1="14" y1="18" x2="14" y2="22"/>',
		'arrow-right'  => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
		'search'       => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
		'menu'         => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
		'x'            => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
		'moon'         => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
		'sun'          => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
		'edit'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
		'instagram'    => '<rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.5" y2="6.5"/>',
		'facebook'     => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
		'linkedin'     => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4V8h4v1.7A4.5 4.5 0 0 1 16 8z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
		'youtube'      => '<path d="M22.5 8.5s-.2-1.6-.9-2.3c-.8-.9-1.8-.9-2.2-1C16.4 5 12 5 12 5s-4.4 0-7.4.2c-.4 0-1.4.1-2.2 1-.7.7-.9 2.3-.9 2.3S1.3 10.4 1.3 12.2v1.6c0 1.9.2 3.7.2 3.7s.2 1.6.9 2.3c.8.9 1.9.9 2.4 1 1.7.2 7.2.2 7.2.2s4.4 0 7.4-.3c.4 0 1.4-.1 2.2-1 .7-.7.9-2.3.9-2.3s.2-1.9.2-3.7v-1.6c0-1.9-.2-3.7-.2-3.7z"/><polygon points="10 15 15 12 10 9"/>',
	);
	if ($name === 'x-logo') {
		return '<svg class="ufuk-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.9 2H22l-7.6 8.7L23.3 22h-7l-5.5-6.7L4.5 22H1.4l8.1-9.3L1 2h7.2l5 6.1L18.9 2zm-1.2 18h1.7L7.4 4H5.6l12.1 16z"/></svg>';
	}
	return isset($paths[$name]) ? sprintf($stroke, $paths[$name]) : '';
}

/**
 * Echo an icon. The markup is a fixed internal string.
 *
 * @param string $name Icon name.
 */
function ufuk_icon($name) {
	echo ufuk_get_icon($name); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Site title as plain text (core stores it HTML-escaped), trimmed; escape at output.
 *
 * @return string
 */
function ufuk_site_name() {
	return trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
}

/**
 * Tagline as plain text (core stores it HTML-escaped), trimmed; escape at output.
 *
 * @return string
 */
function ufuk_site_description() {
	return trim(wp_specialchars_decode((string) get_bloginfo('description'), ENT_QUOTES));
}

/**
 * Configured social profiles.
 *
 * @return array<int,array{url:string,label:string,icon:string}>
 */
function ufuk_social_links() {
	$networks = array(
		'instagram' => array('Instagram', 'instagram'),
		'facebook'  => array('Facebook', 'facebook'),
		'x'         => array('X', 'x-logo'),
		'linkedin'  => array('LinkedIn', 'linkedin'),
		'youtube'   => array('YouTube', 'youtube'),
	);
	$links = array();
	foreach ($networks as $key => $meta) {
		$url = ufuk_setting('social_' . $key);
		if ($url) {
			$links[] = array('url' => $url, 'label' => $meta[0], 'icon' => $meta[1]);
		}
	}
	return apply_filters('ufuk_social_links', $links);
}

/**
 * Social icon row (nothing when no profile is configured).
 */
function ufuk_social_row() {
	$links = ufuk_social_links();
	if (!$links) {
		return;
	}
	echo '<ul class="social-row">';
	foreach ($links as $link) {
		printf(
			'<li><a href="%1$s" target="_blank" rel="noopener me"><span class="screen-reader-text">%2$s</span>%3$s</a></li>',
			esc_url($link['url']),
			/* translators: %s: social network name */
			esc_html(sprintf(__('%s (yeni sekmede açılır)', 'ufuk'), $link['label'])),
			ufuk_get_icon($link['icon']) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
	echo '</ul>';
}

/**
 * Display name for copyright and structured data.
 *
 * @return string
 */
function ufuk_company_name() {
	$name = trim((string) ufuk_setting('company_name'));
	return $name !== '' ? $name : ufuk_site_name();
}

/**
 * A `tel:` href from a phone number as typed (digits and a leading + only). Empty when no digits.
 *
 * @param string $phone
 * @return string
 */
function ufuk_tel_href($phone) {
	$digits = preg_replace('/[^\d+]/', '', (string) $phone);
	return preg_match('/\d{3,}/', $digits) ? 'tel:' . $digits : '';
}

/**
 * Contact detail rows that have a value.
 *
 * @return array<int,array{key:string,icon:string,label:string,html:string}>
 */
function ufuk_contact_items() {
	$items = array();
	$email = ufuk_setting('contact_email');
	if ($email && is_email($email)) {
		$items[] = array('key' => 'email', 'icon' => 'mail', 'label' => __('E-posta', 'ufuk'), 'html' => sprintf('<a href="mailto:%1$s">%2$s</a>', esc_attr(antispambot($email)), esc_html(antispambot($email))));
	}
	$phone = ufuk_setting('contact_phone');
	if ($phone !== '') {
		$href    = ufuk_tel_href($phone);
		$items[] = array('key' => 'phone', 'icon' => 'phone', 'label' => __('Telefon', 'ufuk'), 'html' => $href ? sprintf('<a href="%1$s">%2$s</a>', esc_attr($href), esc_html($phone)) : esc_html($phone));
	}
	$address = ufuk_setting('contact_address');
	if ($address !== '') {
		$items[] = array('key' => 'address', 'icon' => 'map-pin', 'label' => __('Adres', 'ufuk'), 'html' => nl2br(esc_html($address)));
	}
	$hours = ufuk_setting('contact_hours');
	if ($hours !== '') {
		$items[] = array('key' => 'hours', 'icon' => 'clock', 'label' => __('Çalışma saatleri', 'ufuk'), 'html' => esc_html($hours));
	}
	return $items;
}

/**
 * Admin-only hint shown where a home section has nothing to display. Visitors never see it.
 *
 * @param string $message Plain text message.
 * @param string $section Customizer section to focus.
 */
function ufuk_admin_hint($message, $section) {
	if (!current_user_can('edit_theme_options')) {
		return;
	}
	printf(
		'<div class="container"><p class="ufuk-admin-hint">%1$s <span>%2$s</span> <a href="%3$s">%4$s</a></p></div>',
		ufuk_get_icon('edit'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html($message),
		esc_url(ufuk_customizer_link($section)),
		esc_html__('Özelleştir', 'ufuk')
	);
}

/**
 * Button link, rendered only when both text and URL are set.
 *
 * @param string $text
 * @param string $url
 * @param string $class Extra classes.
 * @param bool   $arrow Append an arrow icon.
 */
function ufuk_button($text, $url, $class = 'btn-primary', $arrow = false) {
	if ($text === '' || $url === '') {
		return;
	}
	printf(
		'<a class="btn %1$s" href="%2$s">%3$s%4$s</a>',
		esc_attr($class),
		esc_url($url),
		esc_html($text),
		$arrow ? ufuk_get_icon('arrow-right') : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}

/**
 * Menu fallback when no menu is assigned: top-level pages (up to 6); admins also get a link to the menu screen.
 *
 * @param array $args wp_nav_menu arguments.
 */
function ufuk_fallback_menu($args = array()) {
	$pages = get_pages(array(
		'parent'      => 0,
		'sort_column' => 'menu_order,post_title',
		'number'      => 6,
	));
	echo '<ul class="menu">';
	echo '<li class="menu-item"><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Ana sayfa', 'ufuk') . '</a></li>';
	foreach ($pages as $page) {
		if ((int) get_option('page_on_front') === $page->ID) {
			continue;
		}
		printf('<li class="menu-item"><a href="%1$s">%2$s</a></li>', esc_url(get_permalink($page)), esc_html(get_the_title($page)));
	}
	if (current_user_can('edit_theme_options')) {
		printf('<li class="menu-item ufuk-menu-hint"><a href="%1$s">%2$s</a></li>', esc_url(admin_url('nav-menus.php')), esc_html__('+ Menü oluştur', 'ufuk'));
	}
	echo '</ul>';
}

/**
 * Primary menu (desktop and mobile share the markup).
 */
function ufuk_primary_menu() {
	wp_nav_menu(array(
		'theme_location' => 'primary',
		'container'      => false,
		'menu_class'     => 'menu',
		'depth'          => 2,
		'fallback_cb'    => 'ufuk_fallback_menu',
	));
}

/**
 * Link to the configured contact page, or empty.
 *
 * @return string
 */
function ufuk_contact_page_url() {
	$id = (int) ufuk_setting('contact_page');
	return $id && get_post_status($id) === 'publish' ? get_permalink($id) : '';
}

/**
 * Site branding: custom logo, else the site title with an initial mark.
 *
 * @param bool $link Wrap in a home link.
 */
function ufuk_branding($link = true) {
	$name = ufuk_site_name();
	if (has_custom_logo()) {
		$logo_id = (int) get_theme_mod('custom_logo');
		$img     = wp_get_attachment_image($logo_id, 'full', false, array('class' => 'custom-logo', 'alt' => $name));
	} else {
		$initial = $name !== '' ? mb_substr($name, 0, 1) : '';
		$img     = $initial !== '' ? '<span class="brand-mark" aria-hidden="true">' . esc_html($initial) . '</span>' : '';
	}
	$inner = $img . (has_custom_logo() ? '<span class="screen-reader-text">' . esc_html($name) . '</span>' : '<span class="brand-name">' . esc_html($name) . '</span>');
	if ($link) {
		printf('<a class="site-branding" href="%1$s" rel="home">%2$s</a>', esc_url(home_url('/')), $inner); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		printf('<span class="site-branding">%s</span>', $inner); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Pagination for listings.
 */
function ufuk_pagination() {
	$links = paginate_links(array(
		'prev_text' => __('&laquo; Önceki', 'ufuk'),
		'next_text' => __('Sonraki &raquo;', 'ufuk'),
		'type'      => 'plain',
	));
	if ($links) {
		echo '<nav class="pagination" aria-label="' . esc_attr__('Sayfalama', 'ufuk') . '">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Published child pages of a page, in menu order.
 *
 * @param int $parent Parent page ID.
 * @return WP_Post[]
 */
function ufuk_service_pages($parent) {
	return get_pages(array(
		'parent'      => (int) $parent,
		'sort_column' => 'menu_order,post_title',
		'number'      => (int) apply_filters('ufuk_services_limit', 8),
	));
}

/**
 * Numbered service cards.
 *
 * @param WP_Post[] $pages
 * @param string    $heading Heading tag for card titles.
 */
function ufuk_service_cards($pages, $heading = 'h3') {
	if (!$pages) {
		return;
	}
	$heading = in_array($heading, array('h2', 'h3'), true) ? $heading : 'h3';
	$cols    = count($pages) % 3 === 0 ? 3 : (count($pages) >= 4 ? 4 : max(2, count($pages)));
	echo '<ul class="grid grid-' . (int) $cols . ' service-grid">';
	foreach (array_values($pages) as $i => $page) {
		$excerpt = ufuk_safe_excerpt($page, 20);
		echo '<li class="card service-card reveal" data-reveal-delay="' . (int) ($i % 4 * 80) . '">';
		if (has_post_thumbnail($page)) {
			echo '<div class="service-thumb">' . get_the_post_thumbnail($page, 'ufuk-card', array('loading' => 'lazy', 'alt' => '')) . '</div>';
		}
		echo '<span class="service-number" aria-hidden="true">' . esc_html(sprintf('%02d', $i + 1)) . '</span>';
		printf('<%1$s class="card-title"><a href="%2$s">%3$s</a></%1$s>', $heading, esc_url(get_permalink($page)), esc_html(get_the_title($page))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ($excerpt) {
			echo '<p>' . esc_html($excerpt) . '</p>';
		}
		echo '<span class="card-more" aria-hidden="true">' . esc_html__('Ayrıntılar', 'ufuk') . ufuk_get_icon('arrow-right') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</li>';
	}
	echo '</ul>';
}

/**
 * Plain-text summary of a post: its manual excerpt, else trimmed content. Empty for password-protected posts,
 * so protected content never leaks into cards, sections or meta tags.
 *
 * @param WP_Post $post
 * @param int     $words
 * @return string
 */
function ufuk_safe_excerpt($post, $words = 30) {
	$post = get_post($post);
	if (!$post || post_password_required($post)) {
		return '';
	}
	$text = has_excerpt($post) ? (string) $post->post_excerpt : strip_shortcodes((string) $post->post_content);
	return wp_trim_words(wp_strip_all_tags(excerpt_remove_blocks($text)), $words, '…');
}
