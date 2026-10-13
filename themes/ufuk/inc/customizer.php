<?php
/**
 * Settings: one schema, read through ufuk_setting(), edited in the Customizer (Görünüm → Özelleştir → Ufuk).
 *
 * Defaults come from the schema below; theme-settings.json in the theme root may override those defaults
 * (it ships with the same neutral values). Saved Customizer values (theme mods) always win.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Customizer sections, in panel order.
 *
 * @return array<string,string> section id => title
 */
function ufuk_customizer_sections() {
	return array(
		'ufuk_colors'   => __('Renkler ve yazı tipi', 'ufuk'),
		'ufuk_header'   => __('Üst bilgi', 'ufuk'),
		'ufuk_hero'     => __('Ana sayfa: giriş', 'ufuk'),
		'ufuk_stats'    => __('Ana sayfa: rakamlar', 'ufuk'),
		'ufuk_sections' => __('Ana sayfa: bölümler', 'ufuk'),
		'ufuk_cta'      => __('Ana sayfa: çağrı bandı', 'ufuk'),
		'ufuk_contact'  => __('İletişim', 'ufuk'),
		'ufuk_social'   => __('Sosyal medya', 'ufuk'),
		'ufuk_footer'   => __('Alt bilgi', 'ufuk'),
	);
}

/**
 * The settings schema. Keys are stored as theme mods named "ufuk_<key>".
 *
 * Every entry: section, type (text|textarea|url|email|color|checkbox|select|page|image|number), label,
 * default, optional description and choices.
 *
 * @return array<string,array>
 */
function ufuk_settings_schema() {
	static $schema = null;
	if ($schema !== null) {
		return $schema;
	}

	$schema = array(
		// Colours and font.
		'brand_color'    => array('section' => 'ufuk_colors', 'type' => 'color', 'default' => '#2946E0', 'label' => __('Ana renk', 'ufuk'), 'description' => __('Düğmeler, bağlantılar ve vurgular. Beyaz yazının okunabilmesi için koyu bir ton seçin.', 'ufuk')),
		'accent_color'   => array('section' => 'ufuk_colors', 'type' => 'color', 'default' => '#14C79A', 'label' => __('İkincil renk', 'ufuk'), 'description' => __('Onay işaretleri, liste noktaları ve degrade geçişleri.', 'ufuk')),
		'color_scheme'   => array('section' => 'ufuk_colors', 'type' => 'select', 'default' => 'auto', 'label' => __('Renk modu', 'ufuk'), 'choices' => array(
			'auto'  => __('Açık + koyu (ziyaretçi seçer, sistem ayarını izler)', 'ufuk'),
			'light' => __('Yalnızca açık', 'ufuk'),
		)),
		'use_webfont'    => array('section' => 'ufuk_colors', 'type' => 'checkbox', 'default' => true, 'label' => __('Temayla gelen yazı tipini kullan (Plus Jakarta Sans)', 'ufuk'), 'description' => __('Kapalıyken cihazın sistem yazı tipi kullanılır.', 'ufuk')),

		// Header.
		'header_cta_text' => array('section' => 'ufuk_header', 'type' => 'text', 'default' => '', 'label' => __('Üst bilgi düğmesi metni', 'ufuk'), 'description' => __('Metin ve bağlantı birlikte doluysa menünün yanında görünür.', 'ufuk')),
		'header_cta_url'  => array('section' => 'ufuk_header', 'type' => 'url', 'default' => '', 'label' => __('Üst bilgi düğmesi bağlantısı', 'ufuk')),

		// Hero.
		'hero_kicker'       => array('section' => 'ufuk_hero', 'type' => 'text', 'default' => '', 'label' => __('Üst etiket', 'ufuk'), 'description' => __('Boşsa site sloganı kullanılır.', 'ufuk')),
		'hero_title'        => array('section' => 'ufuk_hero', 'type' => 'text', 'default' => '', 'label' => __('Başlık', 'ufuk'), 'description' => __('Boşsa site başlığı kullanılır.', 'ufuk')),
		'hero_text'         => array('section' => 'ufuk_hero', 'type' => 'textarea', 'default' => '', 'label' => __('Açıklama', 'ufuk')),
		'hero_image'        => array('section' => 'ufuk_hero', 'type' => 'image', 'default' => 0, 'label' => __('Görsel', 'ufuk'), 'description' => __('Boşsa soyut bir renk alanı gösterilir.', 'ufuk')),
		'hero_button_text'  => array('section' => 'ufuk_hero', 'type' => 'text', 'default' => '', 'label' => __('Birincil düğme metni', 'ufuk')),
		'hero_button_url'   => array('section' => 'ufuk_hero', 'type' => 'url', 'default' => '', 'label' => __('Birincil düğme bağlantısı', 'ufuk')),
		'hero_button2_text' => array('section' => 'ufuk_hero', 'type' => 'text', 'default' => '', 'label' => __('İkincil düğme metni', 'ufuk')),
		'hero_button2_url'  => array('section' => 'ufuk_hero', 'type' => 'url', 'default' => '', 'label' => __('İkincil düğme bağlantısı', 'ufuk')),

		// Home sections.
		'show_stats'     => array('section' => 'ufuk_sections', 'type' => 'checkbox', 'default' => true, 'label' => __('Rakamlar bandını göster', 'ufuk')),
		'show_services'  => array('section' => 'ufuk_sections', 'type' => 'checkbox', 'default' => true, 'label' => __('Hizmetler bölümünü göster', 'ufuk')),
		'services_page'  => array('section' => 'ufuk_sections', 'type' => 'page', 'default' => 0, 'label' => __('Hizmetler sayfası', 'ufuk'), 'description' => __('Bu sayfanın alt sayfaları hizmet kartı olarak listelenir (sıra: sayfa niteliklerindeki "Sıra").', 'ufuk')),
		'services_title' => array('section' => 'ufuk_sections', 'type' => 'text', 'default' => '', 'label' => __('Hizmetler başlığı', 'ufuk'), 'description' => __('Boşsa seçilen sayfanın başlığı kullanılır.', 'ufuk')),
		'services_intro' => array('section' => 'ufuk_sections', 'type' => 'textarea', 'default' => '', 'label' => __('Hizmetler giriş metni', 'ufuk')),
		'show_about'     => array('section' => 'ufuk_sections', 'type' => 'checkbox', 'default' => true, 'label' => __('Hakkımızda bölümünü göster', 'ufuk')),
		'about_page'     => array('section' => 'ufuk_sections', 'type' => 'page', 'default' => 0, 'label' => __('Hakkımızda sayfası', 'ufuk'), 'description' => __('Başlık, özet ve öne çıkan görsel bu sayfadan alınır.', 'ufuk')),
		'about_points'   => array('section' => 'ufuk_sections', 'type' => 'textarea', 'default' => '', 'label' => __('Öne çıkan maddeler', 'ufuk'), 'description' => __('Her satıra bir madde.', 'ufuk')),
		'show_posts'     => array('section' => 'ufuk_sections', 'type' => 'checkbox', 'default' => true, 'label' => __('Son yazıları göster', 'ufuk')),
		'posts_title'    => array('section' => 'ufuk_sections', 'type' => 'text', 'default' => '', 'label' => __('Son yazılar başlığı', 'ufuk')),
		'posts_count'    => array('section' => 'ufuk_sections', 'type' => 'number', 'default' => 3, 'label' => __('Yazı sayısı', 'ufuk'), 'min' => 1, 'max' => 12),
		'show_cta'       => array('section' => 'ufuk_sections', 'type' => 'checkbox', 'default' => true, 'label' => __('Çağrı bandını göster', 'ufuk')),
		'show_contact'   => array('section' => 'ufuk_sections', 'type' => 'checkbox', 'default' => true, 'label' => __('İletişim bölümünü göster', 'ufuk')),

		// Figures.
		'stat_1_value' => array('section' => 'ufuk_stats', 'type' => 'text', 'default' => '', 'label' => __('1. değer', 'ufuk'), 'description' => __('Yalnızca doğrulayabileceğiniz gerçek bilgiler girin. Değer ve etiketi dolu olan kutular görünür.', 'ufuk')),
		'stat_1_label' => array('section' => 'ufuk_stats', 'type' => 'text', 'default' => '', 'label' => __('1. etiket', 'ufuk')),
		'stat_2_value' => array('section' => 'ufuk_stats', 'type' => 'text', 'default' => '', 'label' => __('2. değer', 'ufuk')),
		'stat_2_label' => array('section' => 'ufuk_stats', 'type' => 'text', 'default' => '', 'label' => __('2. etiket', 'ufuk')),
		'stat_3_value' => array('section' => 'ufuk_stats', 'type' => 'text', 'default' => '', 'label' => __('3. değer', 'ufuk')),
		'stat_3_label' => array('section' => 'ufuk_stats', 'type' => 'text', 'default' => '', 'label' => __('3. etiket', 'ufuk')),
		'stat_4_value' => array('section' => 'ufuk_stats', 'type' => 'text', 'default' => '', 'label' => __('4. değer', 'ufuk')),
		'stat_4_label' => array('section' => 'ufuk_stats', 'type' => 'text', 'default' => '', 'label' => __('4. etiket', 'ufuk')),

		// Call to action.
		'cta_title'       => array('section' => 'ufuk_cta', 'type' => 'text', 'default' => '', 'label' => __('Başlık', 'ufuk'), 'description' => __('Boşsa bant gösterilmez.', 'ufuk')),
		'cta_text'        => array('section' => 'ufuk_cta', 'type' => 'textarea', 'default' => '', 'label' => __('Metin', 'ufuk')),
		'cta_button_text' => array('section' => 'ufuk_cta', 'type' => 'text', 'default' => '', 'label' => __('Düğme metni', 'ufuk')),
		'cta_button_url'  => array('section' => 'ufuk_cta', 'type' => 'url', 'default' => '', 'label' => __('Düğme bağlantısı', 'ufuk')),

		// Contact.
		'company_name'      => array('section' => 'ufuk_contact', 'type' => 'text', 'default' => '', 'label' => __('Firma unvanı', 'ufuk'), 'description' => __('Alt bilgideki telif satırında ve yapılandırılmış veride kullanılır. Boşsa site başlığı kullanılır.', 'ufuk')),
		'contact_email'     => array('section' => 'ufuk_contact', 'type' => 'email', 'default' => '', 'label' => __('E-posta', 'ufuk')),
		'contact_phone'     => array('section' => 'ufuk_contact', 'type' => 'text', 'default' => '', 'label' => __('Telefon', 'ufuk')),
		'contact_address'   => array('section' => 'ufuk_contact', 'type' => 'textarea', 'default' => '', 'label' => __('Adres', 'ufuk')),
		'contact_hours'     => array('section' => 'ufuk_contact', 'type' => 'text', 'default' => '', 'label' => __('Çalışma saatleri', 'ufuk')),
		'contact_page'      => array('section' => 'ufuk_contact', 'type' => 'page', 'default' => 0, 'label' => __('İletişim sayfası', 'ufuk'), 'description' => __('404 sayfasındaki "Bize ulaşın" düğmesi bu sayfaya gider.', 'ufuk')),
		'contact_form'      => array('section' => 'ufuk_contact', 'type' => 'checkbox', 'default' => true, 'label' => __('Temanın iletişim formunu göster', 'ufuk'), 'description' => __('Mesajlar kaydedilmez, yalnızca e-postayla iletilir.', 'ufuk')),
		'form_recipient'    => array('section' => 'ufuk_contact', 'type' => 'email', 'default' => '', 'label' => __('Form mesajlarının gideceği e-posta', 'ufuk'), 'description' => __('Boşsa yönetici e-posta adresi kullanılır.', 'ufuk')),
		'contact_shortcode' => array('section' => 'ufuk_contact', 'type' => 'text', 'default' => '', 'label' => __('Form kısa kodu', 'ufuk'), 'description' => __('Bir form eklentisinin kısa kodunu girerseniz temanın formu yerine o gösterilir.', 'ufuk')),

		// Social.
		'social_instagram' => array('section' => 'ufuk_social', 'type' => 'url', 'default' => '', 'label' => 'Instagram'),
		'social_facebook'  => array('section' => 'ufuk_social', 'type' => 'url', 'default' => '', 'label' => 'Facebook'),
		'social_x'         => array('section' => 'ufuk_social', 'type' => 'url', 'default' => '', 'label' => 'X'),
		'social_linkedin'  => array('section' => 'ufuk_social', 'type' => 'url', 'default' => '', 'label' => 'LinkedIn'),
		'social_youtube'   => array('section' => 'ufuk_social', 'type' => 'url', 'default' => '', 'label' => 'YouTube'),

		// Footer.
		'footer_text' => array('section' => 'ufuk_footer', 'type' => 'textarea', 'default' => '', 'label' => __('Kısa tanıtım metni', 'ufuk'), 'description' => __('Alt bilgide logonun altında görünür. Boşsa site sloganı kullanılır.', 'ufuk')),
	);

	// Optional shipped defaults file: only overrides defaults of keys that exist in the schema.
	$file = UFUK_DIR . '/theme-settings.json';
	if (is_readable($file)) {
		$json = json_decode((string) file_get_contents($file), true);
		if (is_array($json)) {
			foreach ($json as $key => $value) {
				if (isset($schema[$key]) && is_scalar($value)) {
					$schema[$key]['default'] = ufuk_sanitize_value($value, $schema[$key]);
				}
			}
		}
	}

	return $schema;
}

/**
 * Read a setting: saved theme mod, else the default.
 *
 * @param string $key Schema key without the ufuk_ prefix.
 * @return mixed
 */
function ufuk_setting($key) {
	$schema = ufuk_settings_schema();
	if (!isset($schema[$key])) {
		return '';
	}
	$value = get_theme_mod('ufuk_' . $key, $schema[$key]['default']);
	return apply_filters('ufuk_setting', $value, $key);
}

/**
 * Sanitise a value for its schema field.
 *
 * @param mixed $value
 * @param array $field
 * @return mixed
 */
function ufuk_sanitize_value($value, $field) {
	switch ($field['type']) {
		case 'color':
			return sanitize_hex_color($value) ?: $field['default'];
		case 'checkbox':
			return (bool) $value;
		case 'select':
			return is_string($value) && isset($field['choices'][$value]) ? $value : $field['default'];
		case 'url':
			return esc_url_raw((string) $value);
		case 'email':
			return sanitize_email((string) $value);
		case 'textarea':
			return sanitize_textarea_field((string) $value);
		case 'page':
		case 'image':
			return absint($value);
		case 'number':
			$n = absint($value);
			$min = isset($field['min']) ? $field['min'] : 0;
			$max = isset($field['max']) ? $field['max'] : PHP_INT_MAX;
			return max($min, min($max, $n));
		default:
			return sanitize_text_field((string) $value);
	}
}

/**
 * Register the "Ufuk" panel, its sections and every setting.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function ufuk_customize_register($wp_customize) {
	$wp_customize->add_panel('ufuk', array(
		'title'       => __('Ufuk', 'ufuk'),
		'description' => __('Tema ayarları. Boş bırakılan alanlar sitede hiçbir şey göstermez.', 'ufuk'),
		'priority'    => 30,
	));

	$priority = 10;
	foreach (ufuk_customizer_sections() as $id => $title) {
		$wp_customize->add_section($id, array(
			'title'    => $title,
			'panel'    => 'ufuk',
			'priority' => $priority += 10,
		));
	}

	foreach (ufuk_settings_schema() as $key => $field) {
		$setting_id = 'ufuk_' . $key;
		$wp_customize->add_setting($setting_id, array(
			'default'           => $field['default'],
			'type'              => 'theme_mod',
			'capability'        => 'edit_theme_options',
			'transport'         => 'refresh',
			'sanitize_callback' => static function ($value) use ($field) {
				return ufuk_sanitize_value($value, $field);
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
			case 'page':
				$args['type'] = 'dropdown-pages';
				$args['allow_addition'] = true;
				$wp_customize->add_control($setting_id, $args);
				break;
			case 'select':
				$args['type'] = 'select';
				$args['choices'] = $field['choices'];
				$wp_customize->add_control($setting_id, $args);
				break;
			case 'number':
				$args['type'] = 'number';
				$args['input_attrs'] = array('min' => $field['min'], 'max' => $field['max'], 'step' => 1);
				$wp_customize->add_control($setting_id, $args);
				break;
			default:
				$args['type'] = $field['type'];
				$wp_customize->add_control($setting_id, $args);
		}
	}
}
add_action('customize_register', 'ufuk_customize_register');

/**
 * Print the colour settings as CSS custom properties and the theme-color meta.
 */
function ufuk_print_color_vars() {
	$schema = ufuk_settings_schema();
	$brand  = sanitize_hex_color(ufuk_setting('brand_color')) ?: sanitize_hex_color($schema['brand_color']['default']) ?: '#2946E0';
	$accent = sanitize_hex_color(ufuk_setting('accent_color')) ?: sanitize_hex_color($schema['accent_color']['default']) ?: '#14C79A';
	printf('<meta name="theme-color" content="%s">' . "\n", esc_attr($brand));
	printf(
		'<style id="ufuk-colors">:root{--ufuk-brand:%1$s;--ufuk-accent:%2$s;}</style>' . "\n",
		esc_html($brand),
		esc_html($accent)
	);
}
add_action('wp_head', 'ufuk_print_color_vars', 5);

/**
 * Customizer link that focuses a section (for the admin-only hints on the front end).
 *
 * @param string $section Section id.
 * @return string
 */
function ufuk_customizer_link($section) {
	return add_query_arg(
		array(
			'autofocus[section]' => $section,
			'url'                => rawurlencode(home_url('/')),
		),
		admin_url('customize.php')
	);
}
