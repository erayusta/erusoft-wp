<?php
/**
 * Settings: one schema, read through voltaj_setting(), edited in the Customizer (Görünüm → Özelleştir → Voltaj).
 * Every setting is a theme mod named "voltaj_<key>" with a neutral default; empty text renders nothing.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Customizer sections, in panel order.
 *
 * @return array<string,string> section id => title
 */
function voltaj_customizer_sections() {
	return array(
		'voltaj_colors'  => __('Renkler ve yazı tipleri', 'voltaj'),
		'voltaj_header'  => __('Duyuru şeridi', 'voltaj'),
		'voltaj_hero'    => __('Ana sayfa: arama bandı', 'voltaj'),
		'voltaj_home'    => __('Ana sayfa: bölümler', 'voltaj'),
		'voltaj_shop'    => __('Mağaza ve markalar', 'voltaj'),
		'voltaj_forms'   => __('Teklif ve iletişim formları', 'voltaj'),
		'voltaj_contact' => __('Firma bilgileri', 'voltaj'),
		'voltaj_social'  => __('Sosyal medya', 'voltaj'),
		'voltaj_footer'  => __('Alt bilgi', 'voltaj'),
	);
}

/**
 * Product taxonomies that can act as "brands": product_brand (WooCommerce 9.6+) and any other non-attribute
 * product taxonomy registered by a plugin.
 *
 * @return array<string,string> taxonomy => label
 */
function voltaj_brand_taxonomy_choices() {
	$choices = array('' => __('Kapalı (marka gösterme)', 'voltaj'));
	$skip    = array('product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class');
	foreach (get_object_taxonomies('product', 'objects') as $name => $tax) {
		if (in_array($name, $skip, true) || strpos($name, 'pa_') === 0 || !$tax->public) {
			continue;
		}
		$choices[$name] = $tax->labels->name . ' (' . $name . ')';
	}
	if (!isset($choices['product_brand'])) {
		$choices['product_brand'] = __('Markalar (product_brand)', 'voltaj');
	}
	return $choices;
}

/**
 * The settings schema.
 *
 * Every entry: section, type (text|textarea|url|email|color|checkbox|image|number|select), label, default,
 * optional description, min/max, choices (callable name for select).
 *
 * @return array<string,array>
 */
function voltaj_settings_schema() {
	static $schema = null;
	if ($schema !== null) {
		return $schema;
	}

	$schema = array(
		// Colours and fonts.
		'accent_color' => array('section' => 'voltaj_colors', 'type' => 'color', 'default' => '#22D3E6', 'label' => __('Vurgu rengi', 'voltaj'), 'description' => __('Düğmeler, odak halkası ve etkin durumlar. Üzerine koyu yazı gelir; açık ve parlak bir ton seçin.', 'voltaj')),
		'base_color'   => array('section' => 'voltaj_colors', 'type' => 'color', 'default' => '#1B1F24', 'label' => __('Ana (koyu) renk', 'voltaj'), 'description' => __('Başlık çubuğu, arama bandı, alt bilgi ve metin. Beyaz yazının okunabilmesi için koyu bir ton seçin.', 'voltaj')),
		'use_webfont'  => array('section' => 'voltaj_colors', 'type' => 'checkbox', 'default' => true, 'label' => __('Temayla gelen yazı tiplerini kullan (Archivo + JetBrains Mono)', 'voltaj'), 'description' => __('Kapalıyken cihazın sistem yazı tipleri kullanılır.', 'voltaj')),

		// Announcement strip.
		'announcement_text' => array('section' => 'voltaj_header', 'type' => 'text', 'default' => '', 'label' => __('Duyuru metni', 'voltaj'), 'description' => __('Başlık çubuğunun üstünde ince bir şeritte görünür. Boşsa şerit gösterilmez.', 'voltaj')),
		'announcement_url'  => array('section' => 'voltaj_header', 'type' => 'url', 'default' => '', 'label' => __('Duyuru bağlantısı (isteğe bağlı)', 'voltaj')),

		// Hero search band.
		'hero_title'       => array('section' => 'voltaj_hero', 'type' => 'text', 'default' => '', 'label' => __('Başlık', 'voltaj'), 'description' => __('Boşsa site başlığı kullanılır.', 'voltaj')),
		'hero_text'        => array('section' => 'voltaj_hero', 'type' => 'textarea', 'default' => '', 'label' => __('Açıklama', 'voltaj'), 'description' => __('Boşsa site sloganı kullanılır.', 'voltaj')),
		'hero_image'       => array('section' => 'voltaj_hero', 'type' => 'image', 'default' => 0, 'label' => __('Görsel (isteğe bağlı)', 'voltaj'), 'description' => __('Geniş ekranda sağda gösterilir. Kare ya da yatay bir ürün görseli uygundur.', 'voltaj')),
		'search_placeholder' => array('section' => 'voltaj_hero', 'type' => 'text', 'default' => '', 'label' => __('Arama kutusu ipucu', 'voltaj'), 'description' => __('Ör. "LED ampul, priz, pense…". Boşsa genel bir ipucu kullanılır. Varsayılan WordPress araması ürün adında ve açıklamasında arar.', 'voltaj')),

		// Home sections.
		'show_categories'  => array('section' => 'voltaj_home', 'type' => 'checkbox', 'default' => true, 'label' => __('Kategori ızgarasını göster', 'voltaj')),
		'categories_title' => array('section' => 'voltaj_home', 'type' => 'text', 'default' => '', 'label' => __('Kategoriler başlığı', 'voltaj')),
		'show_products'    => array('section' => 'voltaj_home', 'type' => 'checkbox', 'default' => true, 'label' => __('Öne çıkan ürünleri göster', 'voltaj'), 'description' => __('"Öne çıkan" işaretli ürünler; hiç yoksa en yeni ürünler.', 'voltaj')),
		'products_title'   => array('section' => 'voltaj_home', 'type' => 'text', 'default' => '', 'label' => __('Ürünler başlığı', 'voltaj')),
		'products_count'   => array('section' => 'voltaj_home', 'type' => 'number', 'default' => 8, 'min' => 4, 'max' => 16, 'label' => __('Ürün sayısı', 'voltaj')),
		'show_brands'      => array('section' => 'voltaj_home', 'type' => 'checkbox', 'default' => true, 'label' => __('Marka şeridini göster', 'voltaj'), 'description' => __('Marka taksonomisinde terim yoksa gösterilmez.', 'voltaj')),
		'show_info'        => array('section' => 'voltaj_home', 'type' => 'checkbox', 'default' => true, 'label' => __('Bilgi kartlarını göster', 'voltaj')),
		'info_cards'       => array('section' => 'voltaj_home', 'type' => 'textarea', 'default' => '', 'label' => __('Bilgi kartları', 'voltaj'), 'description' => __('Her satıra bir kart: "Başlık | kısa açıklama". Yalnızca gerçekten sunduğunuz koşulları yazın. Boşsa bölüm gösterilmez.', 'voltaj')),
		'show_posts'       => array('section' => 'voltaj_home', 'type' => 'checkbox', 'default' => true, 'label' => __('Son blog yazılarını göster', 'voltaj')),
		'posts_title'      => array('section' => 'voltaj_home', 'type' => 'text', 'default' => '', 'label' => __('Blog başlığı', 'voltaj')),

		// Shop and brands.
		'brand_taxonomy'  => array('section' => 'voltaj_shop', 'type' => 'select', 'default' => 'product_brand', 'choices' => 'voltaj_brand_taxonomy_choices', 'label' => __('Marka taksonomisi', 'voltaj'), 'description' => __('Markalar sayfası, ana sayfa marka şeridi ve ürün kodu satırı bu taksonomiyi kullanır. WooCommerce 9.6 ve sonrası "product_brand" taksonomisini kendisi sağlar.', 'voltaj')),
		'card_specs'      => array('section' => 'voltaj_shop', 'type' => 'number', 'default' => 3, 'min' => 0, 'max' => 6, 'label' => __('Ürün kartındaki özellik satırı sayısı', 'voltaj'), 'description' => __('Ürünün görünür niteliklerinden ilk N tanesi. 0 = gösterme.', 'voltaj')),
		'pdp_specs'       => array('section' => 'voltaj_shop', 'type' => 'number', 'default' => 8, 'min' => 0, 'max' => 20, 'label' => __('Ürün sayfası özellik tablosu satır sayısı', 'voltaj'), 'description' => __('0 = tabloyu gösterme (nitelikler yine "Ek bilgi" sekmesindedir).', 'voltaj')),
		'quote_link'      => array('section' => 'voltaj_shop', 'type' => 'checkbox', 'default' => true, 'label' => __('Ürün sayfasında "Toplu alım için teklif iste" bağlantısı', 'voltaj'), 'description' => __('"Teklif İste" şablonlu bir sayfa varsa gösterilir. Fiyatı girilmemiş ürünlerde düğme her zaman teklif formuna gider.', 'voltaj')),
		'recently_viewed' => array('section' => 'voltaj_shop', 'type' => 'checkbox', 'default' => true, 'label' => __('"Son baktıklarınız" şeridini göster', 'voltaj'), 'description' => __('Ziyaretçinin baktığı ürünler yalnızca kendi tarayıcısında (localStorage) tutulur, sunucuya gönderilmez.', 'voltaj')),
		'free_shipping_bar' => array('section' => 'voltaj_shop', 'type' => 'checkbox', 'default' => true, 'label' => __('Ücretsiz kargo ilerleme çubuğu', 'voltaj'), 'description' => __('Ürün sayfasındaki satın alma kutusunda ve sepette gösterilir. Eşik, müşterinin kargo bölgesindeki "Ücretsiz gönderim" yönteminden okunur; böyle bir yöntem yoksa ya da kupon gerektiriyorsa çubuk gizlenir.', 'voltaj')),
		'sticky_buy_bar'  => array('section' => 'voltaj_shop', 'type' => 'checkbox', 'default' => true, 'label' => __('Mobilde sabit satın alma çubuğu', 'voltaj'), 'description' => __('Ürün sayfasında satın alma kutusu ekrandan çıkınca altta görünür.', 'voltaj')),

		// Forms.
		'form_recipient'    => array('section' => 'voltaj_forms', 'type' => 'email', 'default' => '', 'label' => __('Form alıcısı e-posta adresi', 'voltaj'), 'description' => __('Teklif ve iletişim formları bu adrese gönderilir. Boşsa site yöneticisinin e-posta adresi kullanılır. Mesajlar veritabanında saklanmaz.', 'voltaj')),
		'contact_form'      => array('section' => 'voltaj_forms', 'type' => 'checkbox', 'default' => true, 'label' => __('"İletişim" şablonunda temanın formunu göster', 'voltaj')),
		'contact_shortcode' => array('section' => 'voltaj_forms', 'type' => 'text', 'default' => '', 'label' => __('İletişim formu kısa kodu (isteğe bağlı)', 'voltaj'), 'description' => __('Bir form eklentisinin kısa kodunu girerseniz temanın formu yerine o gösterilir.', 'voltaj')),
		'quote_intro'       => array('section' => 'voltaj_forms', 'type' => 'textarea', 'default' => '', 'label' => __('Teklif formu üst metni', 'voltaj'), 'description' => __('"Teklif İste" şablonunda formun üstünde görünür. Boşsa sayfa içeriği yeterlidir.', 'voltaj')),

		// Company.
		'company_name'    => array('section' => 'voltaj_contact', 'type' => 'text', 'default' => '', 'label' => __('Firma unvanı', 'voltaj'), 'description' => __('Telif satırında ve yapılandırılmış veride kullanılır. Boşsa site başlığı kullanılır.', 'voltaj')),
		'contact_address' => array('section' => 'voltaj_contact', 'type' => 'textarea', 'default' => '', 'label' => __('Adres', 'voltaj')),
		'contact_phone'   => array('section' => 'voltaj_contact', 'type' => 'text', 'default' => '', 'label' => __('Telefon', 'voltaj')),
		'contact_email'   => array('section' => 'voltaj_contact', 'type' => 'email', 'default' => '', 'label' => __('E-posta', 'voltaj')),
		'contact_hours'   => array('section' => 'voltaj_contact', 'type' => 'text', 'default' => '', 'label' => __('Çalışma saatleri', 'voltaj')),
		'tax_info'        => array('section' => 'voltaj_contact', 'type' => 'text', 'default' => '', 'label' => __('Vergi dairesi / numarası', 'voltaj'), 'description' => __('Alt bilgide firma bilgilerinin altında gösterilir. Boşsa gösterilmez.', 'voltaj')),

		// Social.
		'social_instagram' => array('section' => 'voltaj_social', 'type' => 'url', 'default' => '', 'label' => 'Instagram'),
		'social_facebook'  => array('section' => 'voltaj_social', 'type' => 'url', 'default' => '', 'label' => 'Facebook'),
		'social_x'         => array('section' => 'voltaj_social', 'type' => 'url', 'default' => '', 'label' => 'X'),
		'social_youtube'   => array('section' => 'voltaj_social', 'type' => 'url', 'default' => '', 'label' => 'YouTube'),
		'social_linkedin'  => array('section' => 'voltaj_social', 'type' => 'url', 'default' => '', 'label' => 'LinkedIn'),

		// Footer.
		'footer_text' => array('section' => 'voltaj_footer', 'type' => 'textarea', 'default' => '', 'label' => __('Kısa tanıtım metni', 'voltaj'), 'description' => __('Alt bilgide logonun altında görünür. Boşsa site sloganı kullanılır.', 'voltaj')),
		'footer_note' => array('section' => 'voltaj_footer', 'type' => 'text', 'default' => '', 'label' => __('Alt satır notu', 'voltaj'), 'description' => __('Telif satırının yanında küçük bir not (ör. kabul edilen ödeme yöntemleri). Boşsa gösterilmez.', 'voltaj')),
	);

	return $schema;
}

/**
 * Read a setting: saved theme mod, else the default.
 *
 * @param string $key Schema key without the voltaj_ prefix.
 * @return mixed
 */
function voltaj_setting($key) {
	$schema = voltaj_settings_schema();
	if (!isset($schema[$key])) {
		return '';
	}
	$value = get_theme_mod('voltaj_' . $key, $schema[$key]['default']);
	return apply_filters('voltaj_setting', $value, $key);
}

/**
 * Sanitise a value for its schema field.
 *
 * @param mixed $value
 * @param array $field
 * @return mixed
 */
function voltaj_sanitize_value($value, $field) {
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
		case 'select':
			$choices = is_callable($field['choices']) ? call_user_func($field['choices']) : array();
			$value   = sanitize_key((string) $value);
			return array_key_exists($value, $choices) ? $value : $field['default'];
		default:
			return sanitize_text_field((string) $value);
	}
}

/**
 * Register the "Voltaj" panel, its sections and every setting.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function voltaj_customize_register($wp_customize) {
	$wp_customize->add_panel('voltaj', array(
		'title'       => __('Voltaj', 'voltaj'),
		'description' => __('Tema ayarları. Boş bırakılan alanlar sitede hiçbir şey göstermez.', 'voltaj'),
		'priority'    => 30,
	));

	$priority = 10;
	foreach (voltaj_customizer_sections() as $id => $title) {
		$wp_customize->add_section($id, array(
			'title'    => $title,
			'panel'    => 'voltaj',
			'priority' => $priority += 10,
		));
	}

	foreach (voltaj_settings_schema() as $key => $field) {
		$setting_id = 'voltaj_' . $key;
		$wp_customize->add_setting($setting_id, array(
			'default'           => $field['default'],
			'type'              => 'theme_mod',
			'capability'        => 'edit_theme_options',
			'transport'         => 'refresh',
			'sanitize_callback' => static function ($value) use ($field) {
				return voltaj_sanitize_value($value, $field);
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
			case 'select':
				$args['type']    = 'select';
				$args['choices'] = call_user_func($field['choices']);
				$wp_customize->add_control($setting_id, $args);
				break;
			default:
				$args['type'] = $field['type'];
				$wp_customize->add_control($setting_id, $args);
		}
	}
}
add_action('customize_register', 'voltaj_customize_register');

/**
 * A colour setting, re-sanitised at output (a filtered or stale value falls back to the schema default).
 *
 * @param string $key accent_color|base_color.
 * @return string Hex colour.
 */
function voltaj_color($key) {
	$schema = voltaj_settings_schema();
	$color  = sanitize_hex_color((string) voltaj_setting($key));
	return $color ? $color : (isset($schema[$key]) ? $schema[$key]['default'] : '#000000');
}

/**
 * Print the colour settings as CSS custom properties and the theme-color meta.
 */
function voltaj_print_color_vars() {
	$accent = voltaj_color('accent_color');
	$base   = voltaj_color('base_color');
	printf('<meta name="theme-color" content="%s">' . "\n", esc_attr($base));
	printf(
		'<style id="voltaj-colors">:root{--vj-accent:%1$s;--vj-base:%2$s;}</style>' . "\n",
		esc_html($accent),
		esc_html($base)
	);
}
add_action('wp_head', 'voltaj_print_color_vars', 5);

/**
 * Customizer link that focuses a section (for the admin-only hints on the front end).
 *
 * @param string $section Section id.
 * @return string
 */
function voltaj_customizer_link($section) {
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
function voltaj_setting_lines($key) {
	$lines = preg_split('/\r\n|\r|\n/', (string) voltaj_setting($key));
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
