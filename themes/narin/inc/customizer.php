<?php
/**
 * Settings: one schema, read through narin_setting(), edited in the Customizer (Görünüm → Özelleştir → Narin).
 * Every setting is a theme mod named "narin_<key>" with a neutral default; empty text renders nothing.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Customizer sections, in panel order.
 *
 * @return array<string,string> section id => title
 */
function narin_customizer_sections() {
	return array(
		'narin_colors'  => __('Renkler ve yazı tipleri', 'narin'),
		'narin_header'  => __('Duyuru çubuğu', 'narin'),
		'narin_hero'    => __('Ana sayfa: giriş', 'narin'),
		'narin_home'    => __('Ana sayfa: bölümler', 'narin'),
		'narin_shop'    => __('Mağaza', 'narin'),
		'narin_contact' => __('Firma ve iletişim', 'narin'),
		'narin_social'  => __('Sosyal medya', 'narin'),
		'narin_footer'  => __('Alt bilgi', 'narin'),
	);
}

/**
 * The settings schema.
 *
 * Every entry: section, type (text|textarea|url|email|color|checkbox|image|number), label, default,
 * optional description, min/max.
 *
 * @return array<string,array>
 */
function narin_settings_schema() {
	static $schema = null;
	if ($schema !== null) {
		return $schema;
	}

	$schema = array(
		// Colours and fonts.
		'brand_color'  => array('section' => 'narin_colors', 'type' => 'color', 'default' => '#2F4A3E', 'label' => __('Ana renk', 'narin'), 'description' => __('Düğmeler, bağlantılar, duyuru çubuğu ve alt bilgi. Beyaz yazının okunabilmesi için koyu bir ton seçin.', 'narin')),
		'accent_color' => array('section' => 'narin_colors', 'type' => 'color', 'default' => '#8A7BAA', 'label' => __('Vurgu rengi', 'narin'), 'description' => __('İndirim etiketleri, ilerleme çubuğu ve küçük süslemeler.', 'narin')),
		'bg_color'     => array('section' => 'narin_colors', 'type' => 'color', 'default' => '#F5F4EF', 'label' => __('Zemin rengi', 'narin'), 'description' => __('Sayfa arka planı. Açık bir ton seçin.', 'narin')),
		'use_webfont'  => array('section' => 'narin_colors', 'type' => 'checkbox', 'default' => true, 'label' => __('Temayla gelen yazı tiplerini kullan (Cormorant Garamond + Jost)', 'narin'), 'description' => __('Kapalıyken cihazın sistem yazı tipleri kullanılır.', 'narin')),

		// Announcement bar.
		'announcement_text' => array('section' => 'narin_header', 'type' => 'text', 'default' => '', 'label' => __('Duyuru metni', 'narin'), 'description' => __('Sayfanın en üstünde ince bir çubukta görünür. Boşsa çubuk gösterilmez.', 'narin')),
		'announcement_url'  => array('section' => 'narin_header', 'type' => 'url', 'default' => '', 'label' => __('Duyuru bağlantısı (isteğe bağlı)', 'narin')),

		// Hero.
		'hero_kicker'       => array('section' => 'narin_hero', 'type' => 'text', 'default' => '', 'label' => __('Üst etiket', 'narin')),
		'hero_title'        => array('section' => 'narin_hero', 'type' => 'text', 'default' => '', 'label' => __('Başlık', 'narin'), 'description' => __('Boşsa site başlığı kullanılır.', 'narin')),
		'hero_text'         => array('section' => 'narin_hero', 'type' => 'textarea', 'default' => '', 'label' => __('Açıklama', 'narin'), 'description' => __('Boşsa site sloganı kullanılır.', 'narin')),
		'hero_image'        => array('section' => 'narin_hero', 'type' => 'image', 'default' => 0, 'label' => __('Görsel', 'narin'), 'description' => __('Dikey (4:5) bir görsel en iyi sonucu verir. Boşsa yalnızca metin gösterilir.', 'narin')),
		'hero_button_text'  => array('section' => 'narin_hero', 'type' => 'text', 'default' => '', 'label' => __('Birincil düğme metni', 'narin')),
		'hero_button_url'   => array('section' => 'narin_hero', 'type' => 'url', 'default' => '', 'label' => __('Birincil düğme bağlantısı', 'narin')),
		'hero_button2_text' => array('section' => 'narin_hero', 'type' => 'text', 'default' => '', 'label' => __('İkincil düğme metni', 'narin')),
		'hero_button2_url'  => array('section' => 'narin_hero', 'type' => 'url', 'default' => '', 'label' => __('İkincil düğme bağlantısı', 'narin')),

		// Home sections.
		'show_categories'  => array('section' => 'narin_home', 'type' => 'checkbox', 'default' => true, 'label' => __('Öne çıkan kategorileri göster', 'narin'), 'description' => __('WooCommerce etkinken, en çok ürünü olan dört ana kategori.', 'narin')),
		'categories_title' => array('section' => 'narin_home', 'type' => 'text', 'default' => '', 'label' => __('Kategoriler başlığı', 'narin')),
		'show_products'    => array('section' => 'narin_home', 'type' => 'checkbox', 'default' => true, 'label' => __('Yeni ürünleri göster', 'narin')),
		'products_title'   => array('section' => 'narin_home', 'type' => 'text', 'default' => '', 'label' => __('Yeni ürünler başlığı', 'narin')),
		'products_count'   => array('section' => 'narin_home', 'type' => 'number', 'default' => 4, 'min' => 2, 'max' => 12, 'label' => __('Ürün sayısı', 'narin')),
		'show_posts'       => array('section' => 'narin_home', 'type' => 'checkbox', 'default' => true, 'label' => __('Son yazıları göster', 'narin')),
		'posts_title'      => array('section' => 'narin_home', 'type' => 'text', 'default' => '', 'label' => __('Son yazılar başlığı', 'narin')),
		'highlights'       => array('section' => 'narin_home', 'type' => 'textarea', 'default' => '', 'label' => __('Öne çıkan bilgiler', 'narin'), 'description' => __('Ana sayfanın altında bir şerit olarak görünür. Her satıra bir madde; isterseniz "Başlık | kısa açıklama" biçiminde yazın. Yalnızca gerçekten sunduğunuz koşulları yazın. Boşsa şerit gösterilmez.', 'narin')),

		// Shop.
		'free_shipping_bar' => array('section' => 'narin_shop', 'type' => 'checkbox', 'default' => true, 'label' => __('Ücretsiz kargo ilerleme çubuğu', 'narin'), 'description' => __('Ürün sayfasında ve sepette gösterilir. Eşik, müşterinin kargo bölgesindeki "Ücretsiz gönderim" yönteminden okunur; böyle bir yöntem yoksa ya da kupon gerektiriyorsa çubuk gizlenir.', 'narin')),
		'shipping_note'     => array('section' => 'narin_shop', 'type' => 'text', 'default' => '', 'label' => __('Kargo notu', 'narin'), 'description' => __('Ürün sayfasında ve ödeme düğmesinin üstünde gösterilir (ör. siparişlerin kaç iş günü içinde kargoya verildiği). Boşsa gösterilmez.', 'narin')),
		'product_points'    => array('section' => 'narin_shop', 'type' => 'textarea', 'default' => '', 'label' => __('Ürün sayfası maddeleri', 'narin'), 'description' => __('Sepete ekle düğmesinin altında onay işaretli liste (ör. iade koşulu, paketleme). Her satıra bir madde. Boşsa gösterilmez.', 'narin')),
		'recently_viewed'   => array('section' => 'narin_shop', 'type' => 'checkbox', 'default' => true, 'label' => __('"Son baktıklarınız" şeridini göster', 'narin'), 'description' => __('Ziyaretçinin baktığı ürünler yalnızca kendi tarayıcısında (localStorage) tutulur, sunucuya gönderilmez.', 'narin')),
		'sticky_buy_bar'    => array('section' => 'narin_shop', 'type' => 'checkbox', 'default' => true, 'label' => __('Mobilde sabit satın alma çubuğu', 'narin'), 'description' => __('Ürün sayfasında sepete ekle düğmesi ekrandan çıkınca altta görünür; sepette ve ödemede toplam tutarla birlikte devam düğmesi gösterilir.', 'narin')),

		// Company and contact.
		'company_name'      => array('section' => 'narin_contact', 'type' => 'text', 'default' => '', 'label' => __('Firma unvanı', 'narin'), 'description' => __('Alt bilgideki telif satırında ve yapılandırılmış veride kullanılır. Boşsa site başlığı kullanılır.', 'narin')),
		'contact_address'   => array('section' => 'narin_contact', 'type' => 'textarea', 'default' => '', 'label' => __('Adres', 'narin')),
		'contact_phone'     => array('section' => 'narin_contact', 'type' => 'text', 'default' => '', 'label' => __('Telefon', 'narin')),
		'contact_email'     => array('section' => 'narin_contact', 'type' => 'email', 'default' => '', 'label' => __('E-posta', 'narin')),
		'contact_hours'     => array('section' => 'narin_contact', 'type' => 'text', 'default' => '', 'label' => __('Çalışma saatleri', 'narin')),
		'contact_shortcode' => array('section' => 'narin_contact', 'type' => 'text', 'default' => '', 'label' => __('İletişim formu kısa kodu', 'narin'), 'description' => __('"İletişim" sayfa şablonunda bilgilerin yanında gösterilir; bir form eklentisinin kısa kodunu girin. Tema kendi formunu içermez.', 'narin')),

		// Social.
		'social_instagram' => array('section' => 'narin_social', 'type' => 'url', 'default' => '', 'label' => 'Instagram'),
		'social_facebook'  => array('section' => 'narin_social', 'type' => 'url', 'default' => '', 'label' => 'Facebook'),
		'social_x'         => array('section' => 'narin_social', 'type' => 'url', 'default' => '', 'label' => 'X'),
		'social_youtube'   => array('section' => 'narin_social', 'type' => 'url', 'default' => '', 'label' => 'YouTube'),
		'social_linkedin'  => array('section' => 'narin_social', 'type' => 'url', 'default' => '', 'label' => 'LinkedIn'),

		// Footer.
		'footer_text' => array('section' => 'narin_footer', 'type' => 'textarea', 'default' => '', 'label' => __('Kısa tanıtım metni', 'narin'), 'description' => __('Alt bilgide logonun altında görünür. Boşsa site sloganı kullanılır.', 'narin')),
		'footer_note' => array('section' => 'narin_footer', 'type' => 'text', 'default' => '', 'label' => __('Alt satır notu', 'narin'), 'description' => __('Telif satırının yanında küçük bir not (ör. kabul edilen ödeme yöntemleri). Boşsa gösterilmez.', 'narin')),
	);

	return $schema;
}

/**
 * Read a setting: saved theme mod, else the default.
 *
 * @param string $key Schema key without the narin_ prefix.
 * @return mixed
 */
function narin_setting($key) {
	$schema = narin_settings_schema();
	if (!isset($schema[$key])) {
		return '';
	}
	$value = get_theme_mod('narin_' . $key, $schema[$key]['default']);
	return apply_filters('narin_setting', $value, $key);
}

/**
 * Sanitise a value for its schema field.
 *
 * @param mixed $value
 * @param array $field
 * @return mixed
 */
function narin_sanitize_value($value, $field) {
	switch ($field['type']) {
		case 'color':
			return sanitize_hex_color($value) ?: $field['default'];
		case 'checkbox':
			return (bool) $value;
		case 'url':
			return esc_url_raw((string) $value);
		case 'email':
			return sanitize_email((string) $value);
		case 'textarea':
			return sanitize_textarea_field((string) $value);
		case 'image':
			return absint($value);
		case 'number':
			$n   = absint($value);
			$min = isset($field['min']) ? $field['min'] : 0;
			$max = isset($field['max']) ? $field['max'] : PHP_INT_MAX;
			return max($min, min($max, $n));
		default:
			return sanitize_text_field((string) $value);
	}
}

/**
 * Register the "Narin" panel, its sections and every setting.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function narin_customize_register($wp_customize) {
	$wp_customize->add_panel('narin', array(
		'title'       => __('Narin', 'narin'),
		'description' => __('Tema ayarları. Boş bırakılan alanlar sitede hiçbir şey göstermez.', 'narin'),
		'priority'    => 30,
	));

	$priority = 10;
	foreach (narin_customizer_sections() as $id => $title) {
		$wp_customize->add_section($id, array(
			'title'    => $title,
			'panel'    => 'narin',
			'priority' => $priority += 10,
		));
	}

	foreach (narin_settings_schema() as $key => $field) {
		$setting_id = 'narin_' . $key;
		$wp_customize->add_setting($setting_id, array(
			'default'           => $field['default'],
			'type'              => 'theme_mod',
			'capability'        => 'edit_theme_options',
			'transport'         => 'refresh',
			'sanitize_callback' => static function ($value) use ($field) {
				return narin_sanitize_value($value, $field);
			},
		));

		$args = array(
			'label'       => $field['label'],
			'section'     => $field['section'],
			'description' => isset($field['description']) ? $field['description'] : '',
		);

		switch ($field['type']) {
			case 'color':
				$wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $setting_id, $args));
				break;
			case 'image':
				$args['mime_type'] = 'image';
				$wp_customize->add_control(new WP_Customize_Media_Control($wp_customize, $setting_id, $args));
				break;
			case 'number':
				$args['type']        = 'number';
				$args['input_attrs'] = array('min' => $field['min'], 'max' => $field['max'], 'step' => 1);
				$wp_customize->add_control($setting_id, $args);
				break;
			default:
				$args['type'] = $field['type'];
				$wp_customize->add_control($setting_id, $args);
		}
	}
}
add_action('customize_register', 'narin_customize_register');

/**
 * Print the colour settings as CSS custom properties and the theme-color meta.
 */
function narin_print_color_vars() {
	$schema = narin_settings_schema();
	$brand  = sanitize_hex_color(narin_setting('brand_color')) ?: $schema['brand_color']['default'];
	$accent = sanitize_hex_color(narin_setting('accent_color')) ?: $schema['accent_color']['default'];
	$bg     = sanitize_hex_color(narin_setting('bg_color')) ?: $schema['bg_color']['default'];
	printf('<meta name="theme-color" content="%s">' . "\n", esc_attr($bg));
	printf(
		'<style id="narin-colors">:root{--narin-brand:%1$s;--narin-accent:%2$s;--narin-bg:%3$s;}</style>' . "\n",
		esc_html($brand),
		esc_html($accent),
		esc_html($bg)
	);
}
add_action('wp_head', 'narin_print_color_vars', 5);

/**
 * Customizer link that focuses a section (for the admin-only hints on the front end).
 *
 * @param string $section Section id.
 * @return string
 */
function narin_customizer_link($section) {
	return add_query_arg(
		array(
			'autofocus[section]' => $section,
			'url'                => rawurlencode(home_url('/')),
		),
		admin_url('customize.php')
	);
}

/**
 * Non-empty lines of a textarea setting, each split into a title and an optional description ("Başlık | açıklama").
 *
 * @param string $key Schema key.
 * @return array<int,array{title:string,text:string}>
 */
function narin_setting_lines($key) {
	$lines = preg_split('/\r\n|\r|\n/', (string) narin_setting($key));
	$items = array();
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}
		$parts   = array_map('trim', explode('|', $line, 2));
		$items[] = array('title' => $parts[0], 'text' => isset($parts[1]) ? $parts[1] : '');
	}
	return $items;
}
