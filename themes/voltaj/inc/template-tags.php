<?php
/**
 * Template helpers: icons, branding, menus, contact details, page lookups, admin-only hints.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Inline SVG icon (Feather icon paths, MIT, see assets/icons/LICENSE), drawn with square caps. Decorative.
 *
 * @param string $name Icon name.
 * @return string
 */
function voltaj_get_icon($name) {
	$stroke = '<svg class="vj-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true" focusable="false">%s</svg>';
	$paths  = array(
		'search'        => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.2" y2="16.2"/>',
		'user'          => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		'cart'          => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
		'grid'          => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
		'menu'          => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
		'x'             => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		'chevron-down'  => '<polyline points="6 9 12 15 18 9"/>',
		'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
		'arrow-right'   => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
		'check'         => '<polyline points="20 6 9 17 4 12"/>',
		'copy'          => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		'file-text'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
		'list'          => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
		'sliders'       => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
		'package'       => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
		'truck'         => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
		'tool'          => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
		'shield'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'headphones'    => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
		'credit-card'   => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
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
		return '<svg class="vj-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.9 2H22l-7.6 8.7L23.3 22h-7l-5.5-6.7L4.5 22H1.4l8.1-9.3L1 2h7.2l5 6.1L18.9 2zm-1.2 18h1.7L7.4 4H5.6l12.1 16z"/></svg>';
	}
	return isset($paths[$name]) ? sprintf($stroke, $paths[$name]) : '';
}

/**
 * Echo an icon. The markup is a fixed internal string.
 *
 * @param string $name Icon name.
 */
function voltaj_icon($name) {
	echo voltaj_get_icon($name); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Site title as plain text (core stores it HTML-escaped), trimmed; escape at output.
 *
 * @return string
 */
function voltaj_site_name() {
	return trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
}

/**
 * Tagline as plain text (core stores it HTML-escaped), trimmed; escape at output.
 *
 * @return string
 */
function voltaj_site_description() {
	return trim(wp_specialchars_decode((string) get_bloginfo('description'), ENT_QUOTES));
}

/**
 * Site branding: the Customizer logo, else the site title as a typeset wordmark.
 *
 * @param bool $link Wrap in a home link.
 */
function voltaj_branding($link = true) {
	$name = voltaj_site_name();
	if (has_custom_logo()) {
		$inner  = wp_get_attachment_image((int) get_theme_mod('custom_logo'), 'full', false, array('class' => 'custom-logo', 'alt' => ''));
		$inner .= '<span class="screen-reader-text">' . esc_html($name) . '</span>';
	} else {
		$inner = '<span class="vj-wordmark">' . esc_html($name) . '</span>';
	}
	if ($link) {
		printf('<a class="vj-brand" href="%1$s" rel="home">%2$s</a>', esc_url(home_url('/')), $inner); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		printf('<div class="vj-brand">%s</div>', $inner); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Announcement text (trimmed; empty means the strip is hidden).
 *
 * @return string
 */
function voltaj_announcement_text() {
	return trim((string) voltaj_setting('announcement_text'));
}

/**
 * Search form used in the header and the home search band.
 *
 * @param string $variant header|hero|page.
 */
function voltaj_search_form($variant = 'header') {
	$id          = wp_unique_id('vj-search-');
	$shop        = voltaj_wc_active();
	$placeholder = trim((string) voltaj_setting('search_placeholder'));
	if ($placeholder === '') {
		$placeholder = $shop ? __('Ürün adı ile arayın', 'voltaj') : __('Sitede arayın', 'voltaj');
	}
	?>
	<form role="search" method="get" class="vj-search vj-search--<?php echo esc_attr($variant); ?>" action="<?php echo esc_url(home_url('/')); ?>">
		<label class="screen-reader-text" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($shop ? __('Ürün ara', 'voltaj') : __('Ara', 'voltaj')); ?></label>
		<input type="search" id="<?php echo esc_attr($id); ?>" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" autocomplete="off" required>
		<?php if ($shop) : ?>
			<input type="hidden" name="post_type" value="product">
		<?php endif; ?>
		<button type="submit" class="vj-search__submit">
			<?php voltaj_icon('search'); ?><span class="<?php echo $variant === 'hero' ? 'vj-search__label' : 'screen-reader-text'; ?>"><?php esc_html_e('Ara', 'voltaj'); ?></span>
		</button>
	</form>
	<?php
}

/**
 * Primary menu fallback when no menu is assigned: top-level pages; administrators also get a link to the menu screen.
 *
 * @param array $args wp_nav_menu arguments.
 */
function voltaj_fallback_menu($args = array()) {
	$items    = array();
	$excluded = array((int) get_option('page_on_front'));
	if (voltaj_wc_active()) {
		foreach (array('cart', 'checkout', 'myaccount') as $wc_page) {
			$excluded[] = (int) wc_get_page_id($wc_page);
		}
	}
	foreach (get_pages(array('parent' => 0, 'sort_column' => 'menu_order,post_title', 'number' => 8)) as $page) {
		if (!in_array((int) $page->ID, $excluded, true)) {
			$items[] = array(get_permalink($page), get_the_title($page));
		}
	}
	$items = array_slice($items, 0, 6);
	$class = !empty($args['menu_class']) ? $args['menu_class'] : 'menu';
	echo '<ul class="' . esc_attr($class) . '">';
	foreach ($items as $item) {
		printf('<li class="menu-item"><a href="%1$s">%2$s</a></li>', esc_url($item[0]), esc_html($item[1]));
	}
	if (current_user_can('edit_theme_options')) {
		printf('<li class="menu-item vj-menu-hint"><a href="%1$s">%2$s</a></li>', esc_url(admin_url('nav-menus.php')), esc_html__('+ Menü oluştur', 'voltaj'));
	}
	echo '</ul>';
}

/**
 * Primary menu (one level, used in the catalogue panel and the footer).
 *
 * @param string $id    Unique menu id.
 * @param string $class Menu class.
 */
function voltaj_primary_menu($id, $class = 'vj-links') {
	wp_nav_menu(array(
		'theme_location' => 'primary',
		'container'      => false,
		'menu_class'     => $class,
		'menu_id'        => $id,
		'depth'          => 1,
		'fallback_cb'    => 'voltaj_fallback_menu',
	));
}

/**
 * Footer menu column (nothing when no menu is assigned to the location).
 *
 * @param string $location footer-1 … footer-3.
 */
function voltaj_footer_menu($location) {
	if (!has_nav_menu($location)) {
		return;
	}
	$locations = get_nav_menu_locations();
	$menu      = isset($locations[$location]) ? wp_get_nav_menu_object($locations[$location]) : null;
	$title     = $menu ? $menu->name : '';
	echo '<nav class="vj-footer__col" aria-label="' . esc_attr($title) . '">';
	if ($title !== '') {
		echo '<h2 class="vj-footer__heading">' . esc_html($title) . '</h2>';
	}
	wp_nav_menu(array('theme_location' => $location, 'container' => false, 'menu_class' => 'vj-footer__menu', 'depth' => 1));
	echo '</nav>';
}

/**
 * Configured social profiles.
 *
 * @return array<int,array{url:string,label:string,icon:string}>
 */
function voltaj_social_links() {
	$networks = array(
		'instagram' => array('Instagram', 'instagram'),
		'facebook'  => array('Facebook', 'facebook'),
		'x'         => array('X', 'x-logo'),
		'youtube'   => array('YouTube', 'youtube'),
		'linkedin'  => array('LinkedIn', 'linkedin'),
	);
	$links = array();
	foreach ($networks as $key => $meta) {
		$url = voltaj_setting('social_' . $key);
		if ($url) {
			$links[] = array('url' => $url, 'label' => $meta[0], 'icon' => $meta[1]);
		}
	}
	return apply_filters('voltaj_social_links', $links);
}

/**
 * Social icon row (nothing when no profile is configured).
 */
function voltaj_social_row() {
	$links = voltaj_social_links();
	if (!$links) {
		return;
	}
	echo '<ul class="vj-social">';
	foreach ($links as $link) {
		printf(
			'<li><a href="%1$s" target="_blank" rel="noopener me"><span class="screen-reader-text">%2$s</span>%3$s</a></li>',
			esc_url($link['url']),
			/* translators: %s: social network name */
			esc_html(sprintf(__('%s (yeni sekmede açılır)', 'voltaj'), $link['label'])),
			voltaj_get_icon($link['icon']) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
	echo '</ul>';
}

/**
 * Name for copyright and structured data.
 *
 * @return string
 */
function voltaj_company_name() {
	$name = trim((string) voltaj_setting('company_name'));
	return $name !== '' ? $name : voltaj_site_name();
}

/**
 * A `tel:` href from a phone number as typed (digits and a leading + only). Empty when there are no digits.
 *
 * @param string $phone
 * @return string
 */
function voltaj_tel_href($phone) {
	$digits = preg_replace('/[^\d+]/', '', (string) $phone);
	return preg_match('/\d{3,}/', $digits) ? 'tel:' . $digits : '';
}

/**
 * Contact detail rows that have a value.
 *
 * @return array<int,array{key:string,icon:string,label:string,html:string}>
 */
function voltaj_contact_items() {
	$items   = array();
	$address = trim((string) voltaj_setting('contact_address'));
	if ($address !== '') {
		$items[] = array('key' => 'address', 'icon' => 'map-pin', 'label' => __('Adres', 'voltaj'), 'html' => nl2br(esc_html($address)));
	}
	$phone = trim((string) voltaj_setting('contact_phone'));
	if ($phone !== '') {
		$href    = voltaj_tel_href($phone);
		$items[] = array('key' => 'phone', 'icon' => 'phone', 'label' => __('Telefon', 'voltaj'), 'html' => $href ? sprintf('<a href="%1$s">%2$s</a>', esc_attr($href), esc_html($phone)) : esc_html($phone));
	}
	$email = voltaj_setting('contact_email');
	if ($email && is_email($email)) {
		$items[] = array('key' => 'email', 'icon' => 'mail', 'label' => __('E-posta', 'voltaj'), 'html' => sprintf('<a href="mailto:%1$s">%2$s</a>', esc_attr(antispambot($email)), esc_html(antispambot($email))));
	}
	$hours = trim((string) voltaj_setting('contact_hours'));
	if ($hours !== '') {
		$items[] = array('key' => 'hours', 'icon' => 'clock', 'label' => __('Çalışma saatleri', 'voltaj'), 'html' => esc_html($hours));
	}
	return $items;
}

/**
 * Contact list markup.
 *
 * @param string $class List class.
 */
function voltaj_contact_list($class = 'vj-contact-list') {
	$items = voltaj_contact_items();
	if (!$items) {
		return;
	}
	echo '<ul class="' . esc_attr($class) . '">';
	foreach ($items as $item) {
		printf(
			'<li class="%1$s">%2$s<span class="screen-reader-text">%3$s:</span> <span>%4$s</span></li>',
			esc_attr('is-' . $item['key']),
			voltaj_get_icon($item['icon']), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html($item['label']),
			$item['html'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in voltaj_contact_items().
		);
	}
	echo '</ul>';
}

/**
 * URL of the first published page that uses a page template ('' when none).
 *
 * @param string $template Template file, e.g. page-templates/quote.php.
 * @return string
 */
function voltaj_template_page_url($template) {
	static $cache = array();
	if (!isset($cache[$template])) {
		$pages = get_pages(array(
			'meta_key'    => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => $template, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'      => 1,
			'post_status' => 'publish',
		));
		$cache[$template] = $pages ? (string) get_permalink($pages[0]) : '';
	}
	return (string) apply_filters('voltaj_template_page_url', $cache[$template], $template);
}

/**
 * Quote-request page URL, optionally pre-filled with a product.
 *
 * @param int $product_id
 * @return string
 */
function voltaj_quote_url($product_id = 0) {
	$url = voltaj_template_page_url('page-templates/quote.php');
	if ($url !== '' && $product_id) {
		$url = add_query_arg('urun', (int) $product_id, $url);
	}
	return $url;
}

/**
 * The configured brand taxonomy when it exists ('' otherwise).
 *
 * @return string
 */
function voltaj_brand_taxonomy() {
	$tax = (string) apply_filters('voltaj_brand_taxonomy', voltaj_setting('brand_taxonomy'));
	return ($tax !== '' && taxonomy_exists($tax)) ? $tax : '';
}

/**
 * Brand terms (with products), ordered by name.
 *
 * @param int $limit 0 = all.
 * @return WP_Term[]
 */
function voltaj_brand_terms($limit = 0) {
	$tax = voltaj_brand_taxonomy();
	if ($tax === '') {
		return array();
	}
	$terms = get_terms(array(
		'taxonomy'   => $tax,
		'hide_empty' => true,
		'orderby'    => 'name',
		'number'     => $limit,
	));
	return is_wp_error($terms) ? array() : $terms;
}

/**
 * Uppercase with Turkish rules (i → İ, ı → I), so initials and letter groups match the language.
 *
 * @param string $text
 * @return string
 */
function voltaj_upper_tr($text) {
	return mb_strtoupper(strtr((string) $text, array('i' => 'İ', 'ı' => 'I')), 'UTF-8');
}

/**
 * Letter group of a brand name: its first character, uppercased the Turkish way; '#' for digits and symbols.
 *
 * @param string $name
 * @return string
 */
function voltaj_brand_letter($name) {
	$first = voltaj_upper_tr(mb_substr(trim((string) $name), 0, 1, 'UTF-8'));
	return preg_match('/^\p{L}$/u', $first) ? $first : '#';
}

/**
 * Sort letter groups in Turkish alphabetical order (other letters after Z, '#' last).
 *
 * @param string $a
 * @param string $b
 * @return int
 */
function voltaj_brand_letter_cmp($a, $b) {
	static $order = null;
	if ($order === null) {
		$order = array_flip(preg_split('//u', 'ABCÇDEFGĞHIİJKLMNOÖPQRSŞTUÜVWXYZ', -1, PREG_SPLIT_NO_EMPTY));
	}
	$rank = static function ($l) use ($order) {
		return $l === '#' ? 2000 : (isset($order[$l]) ? $order[$l] : 1000 + mb_ord($l, 'UTF-8') % 900);
	};
	return $rank($a) <=> $rank($b) ?: strcmp($a, $b);
}

/**
 * Anchor-safe id fragment for a letter group (ASCII letters as is, others as a code point).
 *
 * @param string $letter
 * @return string
 */
function voltaj_brand_letter_id($letter) {
	if ($letter === '#') {
		return 'diger';
	}
	return preg_match('/^[A-Z]$/', $letter) ? strtolower($letter) : 'u' . dechex(mb_ord($letter, 'UTF-8'));
}

/**
 * Brand logo for a term (WooCommerce stores it as thumbnail_id term meta), or '' when none.
 *
 * @param WP_Term $term
 * @param string  $size
 * @return string
 */
function voltaj_brand_logo($term, $size = 'medium') {
	$id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
	return $id ? (string) wp_get_attachment_image($id, $size, false, array('alt' => '', 'loading' => 'lazy')) : '';
}

/**
 * Admin-only hint shown where a section has nothing to display. Visitors never see it.
 *
 * @param string $message Plain text message.
 * @param string $section Customizer section to focus.
 */
function voltaj_admin_hint($message, $section) {
	if (!current_user_can('edit_theme_options')) {
		return;
	}
	printf(
		'<p class="vj-admin-hint">%1$s <span>%2$s</span> <a href="%3$s">%4$s</a></p>',
		voltaj_get_icon('edit'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html($message),
		esc_url(voltaj_customizer_link($section)),
		esc_html__('Özelleştir', 'voltaj')
	);
}

/**
 * Section heading with an optional "see all" link.
 *
 * @param string $title
 * @param string $link_url
 * @param string $link_text
 */
function voltaj_section_head($title, $link_url = '', $link_text = '') {
	echo '<div class="vj-section__head"><h2 class="vj-section__title">' . esc_html($title) . '</h2>';
	if ($link_url !== '' && $link_text !== '') {
		printf('<a class="vj-section__more" href="%1$s">%2$s%3$s</a>', esc_url($link_url), esc_html($link_text), voltaj_get_icon('arrow-right')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div>';
}

/**
 * Pagination for post listings.
 */
function voltaj_pagination() {
	$links = paginate_links(array(
		'prev_text' => __('&lsaquo; Önceki', 'voltaj'),
		'next_text' => __('Sonraki &rsaquo;', 'voltaj'),
		'type'      => 'plain',
	));
	if ($links) {
		echo '<nav class="vj-pagination" aria-label="' . esc_attr__('Sayfalama', 'voltaj') . '">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Plain-text summary of a post: its manual excerpt, else trimmed content. Empty for password-protected posts.
 *
 * @param WP_Post|int $post
 * @param int         $words
 * @return string
 */
function voltaj_safe_excerpt($post, $words = 24) {
	$post = get_post($post);
	if (!$post || post_password_required($post)) {
		return '';
	}
	$text = has_excerpt($post) ? (string) $post->post_excerpt : strip_shortcodes((string) $post->post_content);
	$text = preg_replace('#</(h[1-6]|p|li|div|td|th)>|<br\s*/?>#i', ' ', excerpt_remove_blocks($text));
	return wp_trim_words(wp_strip_all_tags($text), $words, '…');
}

/**
 * Post card used on the blog listing, the front page and below single posts.
 *
 * @param WP_Post|int $post
 * @param string      $heading h2 or h3.
 */
function voltaj_post_card($post, $heading = 'h3') {
	$post    = get_post($post);
	$heading = in_array($heading, array('h2', 'h3'), true) ? $heading : 'h3';
	$cats    = get_the_category($post->ID);
	echo '<article class="vj-post-card">';
	if (has_post_thumbnail($post)) {
		printf('<a class="vj-post-card__media" href="%1$s" tabindex="-1" aria-hidden="true">%2$s</a>', esc_url(get_permalink($post)), get_the_post_thumbnail($post, 'voltaj-post', array('loading' => 'lazy', 'alt' => ''))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '<div class="vj-post-card__body"><p class="vj-post-card__meta">';
	if ($cats) {
		echo '<span>' . esc_html($cats[0]->name) . '</span>';
	}
	echo '<time datetime="' . esc_attr(get_the_date('c', $post)) . '">' . esc_html(get_the_date('', $post)) . '</time></p>';
	printf('<%1$s class="vj-post-card__title"><a href="%2$s">%3$s</a></%1$s>', $heading, esc_url(get_permalink($post)), esc_html(get_the_title($post))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$excerpt = voltaj_safe_excerpt($post, 20);
	if ($excerpt !== '') {
		echo '<p class="vj-post-card__excerpt">' . esc_html($excerpt) . '</p>';
	}
	echo '</div></article>';
}

/**
 * Cart item count (0 when WooCommerce or the cart is not available).
 *
 * @return int
 */
function voltaj_cart_count() {
	return (voltaj_wc_active() && function_exists('WC') && WC()->cart) ? (int) WC()->cart->get_cart_contents_count() : 0;
}
