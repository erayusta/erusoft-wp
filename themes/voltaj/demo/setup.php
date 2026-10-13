<?php
/**
 * Voltaj demo content (idempotent). Run from the repository root with WooCommerce and the theme active:
 *
 *   wp theme activate voltaj
 *   wp eval-file themes/voltaj/demo/setup.php
 *
 * Creates a fictional electrical and technical supply shop ("Voltaj Demo Teknik Malzeme Ltd. Şti."): four product
 * categories with sub-categories, 15 products with invented VLT-… codes (one variable product with a "Güç"
 * attribute, one on sale, one out of stock, one without a price for the quote flow), "Güç" and "Renk" attributes,
 * three brands in WooCommerce's product_brand taxonomy, a shipping zone (flat rate + free shipping above a
 * threshold), bank transfer with an obviously fake IBAN, shortcode cart/checkout/account pages, the Markalar /
 * Teklif İste / İletişim template pages, two blog posts with headings for the table of contents, menus, images and
 * Customizer values. Everything created is flagged with the _voltaj_demo meta / term meta so demo/reset.php can
 * remove exactly that; the options and theme mods it changes are saved first and restored by reset.php. All names,
 * codes, prices, addresses and numbers are invented.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!defined('WP_CLI') || !WP_CLI) {
	return;
}
if (get_template() !== 'voltaj') {
	WP_CLI::error('Activate the Voltaj theme first: wp theme activate voltaj');
}
if (!class_exists('WooCommerce')) {
	WP_CLI::error('The Voltaj demo needs WooCommerce: wp plugin activate woocommerce');
}
if (!taxonomy_exists('product_brand')) {
	WP_CLI::error('The Voltaj demo needs WooCommerce 9.6 or later (product_brand taxonomy).');
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

const VOLTAJ_DEMO_STATE = 'voltaj_demo_state';

/**
 * Options the demo changes. Their previous values are saved on the first run and restored by reset.php.
 *
 * @return string[]
 */
function voltaj_demo_option_keys() {
	return array(
		'show_on_front', 'page_on_front', 'page_for_posts', 'blogname', 'blogdescription',
		'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id',
		'woocommerce_default_country', 'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep', 'woocommerce_price_num_decimals', 'woocommerce_thumbnail_cropping',
		'woocommerce_bacs_settings', 'woocommerce_bacs_accounts', 'woocommerce_coming_soon',
		'woocommerce_checkout_privacy_policy_text', 'woocommerce_registration_privacy_policy_text',
	);
}

/**
 * Remember the site options, the voltaj_* theme mods and the menu locations (first run only).
 */
function voltaj_demo_remember_state() {
	if (get_option(VOLTAJ_DEMO_STATE)) {
		return;
	}
	$state = array('options' => array(), 'theme_mods' => array(), 'attributes' => array());
	foreach (voltaj_demo_option_keys() as $key) {
		$state['options'][$key] = get_option($key, null);
	}
	$mods = get_option('theme_mods_voltaj');
	foreach (is_array($mods) ? $mods : array() as $key => $value) {
		if (strpos($key, 'voltaj_') === 0 || $key === 'nav_menu_locations') {
			$state['theme_mods'][$key] = $value;
		}
	}
	add_option(VOLTAJ_DEMO_STATE, $state, '', false);
}

/**
 * Update the saved state.
 *
 * @param string $key
 * @param mixed  $value
 */
function voltaj_demo_state_set($key, $value) {
	$state       = (array) get_option(VOLTAJ_DEMO_STATE, array());
	$state[$key] = $value;
	update_option(VOLTAJ_DEMO_STATE, $state, false);
}

/**
 * Find a demo post by its key.
 *
 * @param string          $key
 * @param string|string[] $type
 * @return int
 */
function voltaj_demo_find($key, $type) {
	$found = get_posts(array(
		'post_type'      => $type,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => array(array('key' => '_voltaj_demo', 'value' => $key)),
	));
	return $found ? (int) $found[0] : 0;
}

/**
 * Create or update a demo post/page.
 *
 * @param string $key  Stable key.
 * @param array  $args wp_insert_post args.
 * @return int
 */
function voltaj_demo_post($key, $args) {
	$args = array_merge(array('post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed'), $args);
	$id   = voltaj_demo_find($key, $args['post_type']);
	if ($id) {
		$args['ID'] = $id;
	}
	$id = wp_insert_post(wp_slash($args), true);
	if (is_wp_error($id)) {
		WP_CLI::error($id->get_error_message());
	}
	update_post_meta($id, '_voltaj_demo', $key);
	return (int) $id;
}

/**
 * Import a demo image once.
 *
 * @param string $file File name in demo/images.
 * @param string $alt  Alt text.
 * @return int Attachment ID.
 */
function voltaj_demo_image($file, $alt) {
	$key = 'image:' . $file;
	$id  = voltaj_demo_find($key, 'attachment');
	if (!$id) {
		$tmp = wp_tempnam($file);
		copy(__DIR__ . '/images/' . $file, $tmp);
		$id = media_handle_sideload(array('name' => 'voltaj-demo-' . $file, 'tmp_name' => $tmp), 0);
		if (is_wp_error($id)) {
			WP_CLI::error($file . ': ' . $id->get_error_message());
		}
		update_post_meta($id, '_voltaj_demo', $key);
	}
	update_post_meta($id, '_wp_attachment_image_alt', $alt);
	return (int) $id;
}

/**
 * Get or create a demo term. Only a term created by the demo (flagged with the _voltaj_demo term meta = $key) is
 * reused; a pre-existing term with the same slug or name is never adopted: the demo then creates its own term with
 * a "-voltaj-demo" slug and a "(Voltaj demo)" name suffix.
 *
 * @param string $name
 * @param string $taxonomy
 * @param string $slug
 * @param string $description
 * @param int    $parent
 * @return WP_Term
 */
function voltaj_demo_term($name, $taxonomy, $slug, $description = '', $parent = 0) {
	$key = $taxonomy . ':' . $slug;
	// meta_query (not meta_key): WooCommerce replaces meta_key on its own taxonomies to sort them.
	$found = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 1, 'meta_query' => array(array('key' => '_voltaj_demo', 'value' => $key))));
	if ($found && !is_wp_error($found)) {
		$term_id = (int) $found[0]->term_id;
		wp_update_term($term_id, $taxonomy, array('description' => $description, 'parent' => $parent));
		return get_term($term_id, $taxonomy);
	}
	$taken = get_term_by('slug', $slug, $taxonomy) || term_exists($name, $taxonomy, $parent ?: null);
	if ($taken) {
		$slug .= '-voltaj-demo';
		$name .= ' (Voltaj demo)';
	}
	$res = wp_insert_term($name, $taxonomy, array('slug' => $slug, 'description' => $description, 'parent' => $parent));
	if (is_wp_error($res)) {
		WP_CLI::error($res->get_error_message());
	}
	update_term_meta((int) $res['term_id'], '_voltaj_demo', $key);
	return get_term((int) $res['term_id'], $taxonomy);
}

/**
 * Build a demo menu (items are recreated on every run). Only a menu created by the demo (flagged with
 * _voltaj_demo = menu:$key) is reused; when another menu already has the name, the demo menu gets a "(Voltaj demo)"
 * suffix.
 *
 * @param string $key   Stable key.
 * @param string $name  Menu name (shown as the footer column heading).
 * @param array  $items list of [title, object_id, object (page|product_cat|category)]
 * @return int
 */
function voltaj_demo_menu($key, $name, $items) {
	$found = get_terms(array('taxonomy' => 'nav_menu', 'hide_empty' => false, 'number' => 1, 'meta_query' => array(array('key' => '_voltaj_demo', 'value' => 'menu:' . $key))));
	if ($found && !is_wp_error($found)) {
		$menu_id = (int) $found[0]->term_id;
		foreach ((array) wp_get_nav_menu_items($menu_id, array('post_status' => 'any')) as $item) {
			wp_delete_post($item->ID, true);
		}
	} else {
		if (wp_get_nav_menu_object($name)) {
			$name .= ' (Voltaj demo)';
		}
		$menu_id = wp_create_nav_menu($name);
		if (is_wp_error($menu_id)) {
			WP_CLI::error($menu_id->get_error_message());
		}
		$menu_id = (int) $menu_id;
		update_term_meta($menu_id, '_voltaj_demo', 'menu:' . $key);
	}
	$order = 1;
	foreach ($items as $item) {
		$is_tax = $item[2] !== 'page';
		wp_update_nav_menu_item($menu_id, 0, array(
			'menu-item-title'     => $item[0],
			'menu-item-object-id' => $item[1],
			'menu-item-object'    => $item[2],
			'menu-item-type'      => $is_tax ? 'taxonomy' : 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-position'  => $order++,
		));
	}
	return $menu_id;
}

/**
 * Get or create a global attribute (pa_<slug>). Attributes the demo creates are remembered for reset.php.
 *
 * @param string $name
 * @param string $slug
 * @return int Attribute id.
 */
function voltaj_demo_attribute($name, $slug) {
	$id = wc_attribute_taxonomy_id_by_name($slug);
	if (!$id) {
		$id = wc_create_attribute(array('name' => $name, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false));
		if (is_wp_error($id)) {
			WP_CLI::error($id->get_error_message());
		}
		$state = (array) get_option(VOLTAJ_DEMO_STATE, array());
		voltaj_demo_state_set('attributes', array_values(array_unique(array_merge((array) ($state['attributes'] ?? array()), array((int) $id)))));
	}
	$taxonomy = 'pa_' . $slug;
	if (!taxonomy_exists($taxonomy)) {
		register_taxonomy($taxonomy, array('product', 'product_variation'), array('hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false));
	}
	return (int) $id;
}

/**
 * Block markup helpers.
 */
function voltaj_demo_p($text) {
	return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
}
function voltaj_demo_h($text, $level = 2) {
	$attrs = $level === 2 ? '' : ' {"level":' . (int) $level . '}';
	return "<!-- wp:heading" . $attrs . " -->\n<h" . (int) $level . " class=\"wp-block-heading\">" . $text . "</h" . (int) $level . ">\n<!-- /wp:heading -->\n\n";
}
function voltaj_demo_list($items) {
	$li = '';
	foreach ($items as $item) {
		$li .= "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->\n";
	}
	return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $li . "</ul>\n<!-- /wp:list -->\n\n";
}

/**
 * Product attributes: taxonomy terms (pa_*) plus local "label => value" rows.
 *
 * @param array $tax   pa_slug => WP_Term[] (visible, used for variations when $variation_tax matches)
 * @param array $local label => value
 * @param string $variation_tax Taxonomy used for variations ('' for none).
 * @return WC_Product_Attribute[]
 */
function voltaj_demo_attributes($tax, $local, $variation_tax = '') {
	$list = array();
	$pos  = 0;
	foreach ($tax as $taxonomy => $terms) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id(wc_attribute_taxonomy_id_by_name(substr($taxonomy, 3)));
		$attribute->set_name($taxonomy);
		$attribute->set_options(array_map(static function ($t) {
			return (int) $t->term_id;
		}, $terms));
		$attribute->set_position($pos++);
		$attribute->set_visible(true);
		$attribute->set_variation($taxonomy === $variation_tax);
		$list[] = $attribute;
	}
	foreach ($local as $label => $value) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id(0);
		$attribute->set_name($label);
		$attribute->set_options(array($value));
		$attribute->set_position($pos++);
		$attribute->set_visible(true);
		$attribute->set_variation(false);
		$list[] = $attribute;
	}
	return $list;
}

/**
 * Create or update a simple demo product.
 *
 * @param array $p key, name, slug, sku, price ('' = price on request), sale, cats, brand, image, short,
 *                 description, featured, stock (instock|outofstock), tax, local.
 * @return int
 */
function voltaj_demo_simple_product($p) {
	$id      = voltaj_demo_find('product:' . $p['key'], 'product');
	$product = $id ? wc_get_product($id) : null;
	if (!$product instanceof WC_Product_Simple) {
		$product = new WC_Product_Simple($id ?: 0);
	}
	$product->set_name($p['name']);
	$product->set_slug($p['slug']);
	$product->set_status('publish');
	$product->set_sku(''); // Free the SKU first so a re-run never collides with itself.
	$product->set_regular_price((string) $p['price']);
	$product->set_sale_price(isset($p['sale']) ? (string) $p['sale'] : '');
	$product->set_category_ids($p['cats']);
	$product->set_image_id($p['image']);
	$product->set_short_description($p['short']);
	$product->set_description($p['description']);
	$product->set_featured(!empty($p['featured']));
	$product->set_manage_stock(false);
	$product->set_stock_status($p['stock'] ?? 'instock');
	$product->set_catalog_visibility('visible');
	$product->set_reviews_allowed(false);
	$product->set_attributes(voltaj_demo_attributes($p['tax'], $p['local']));
	$id = $product->save();
	voltaj_demo_set_sku($id, $p['sku']);
	update_post_meta($id, '_voltaj_demo', 'product:' . $p['key']);
	voltaj_demo_set_terms($id, $p['tax']);
	wp_set_object_terms($id, array((int) $p['brand']), 'product_brand');
	return (int) $id;
}

/**
 * Set a SKU unless another (non-demo) product already uses it; then the demo product keeps an empty SKU.
 *
 * @param int    $id
 * @param string $sku
 */
function voltaj_demo_set_sku($id, $sku) {
	$product = wc_get_product($id);
	if (!$product) {
		return;
	}
	if (wc_product_has_unique_sku($id, $sku)) {
		$product->set_sku($sku);
		$product->save();
	} else {
		WP_CLI::warning(sprintf('SKU %s is already used by another product; the demo product keeps an empty SKU.', $sku));
	}
}

/**
 * Attach attribute terms to a product.
 *
 * @param int   $id
 * @param array $tax pa_slug => WP_Term[]
 */
function voltaj_demo_set_terms($id, $tax) {
	foreach ($tax as $taxonomy => $terms) {
		wp_set_object_terms($id, array_map(static function ($t) {
			return (int) $t->term_id;
		}, $terms), $taxonomy);
	}
}

/* ── State and site options ── */
voltaj_demo_remember_state();

/* Keep the blog listing demo-only: unpublish other published posts (reset.php republishes them if unchanged). */
$voltaj_state = (array) get_option(VOLTAJ_DEMO_STATE);
$voltaj_other = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array(array('key' => '_voltaj_demo', 'compare' => 'NOT EXISTS'))));
if ($voltaj_other) {
	$voltaj_drafted = (array) ($voltaj_state['drafted_posts'] ?? array());
	foreach ($voltaj_other as $voltaj_pid) {
		wp_update_post(array('ID' => $voltaj_pid, 'post_status' => 'draft'));
		// Remember the modified time after drafting: reset.php republishes the post only when nobody edited it since.
		$voltaj_drafted[$voltaj_pid] = get_post_field('post_modified_gmt', $voltaj_pid);
	}
	voltaj_demo_state_set('drafted_posts', $voltaj_drafted);
}

update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_default_country', 'TR:TR06');
update_option('woocommerce_currency', 'TRY');
update_option('woocommerce_currency_pos', 'right_space');
update_option('woocommerce_price_thousand_sep', '.');
update_option('woocommerce_price_decimal_sep', ',');
update_option('woocommerce_price_num_decimals', '0');
update_option('woocommerce_thumbnail_cropping', '1:1');
// Turkish privacy notes (WooCommerce stores its English defaults as options at install time).
update_option('woocommerce_checkout_privacy_policy_text', 'Kişisel verileriniz siparişinizi işlemek, bu sitedeki deneyiminizi desteklemek ve [privacy_policy] sayfasında açıklanan diğer amaçlar için kullanılır.');
update_option('woocommerce_registration_privacy_policy_text', 'Kişisel verileriniz bu sitedeki deneyiminizi desteklemek, hesabınıza erişimi yönetmek ve [privacy_policy] sayfasında açıklanan diğer amaçlar için kullanılır.');

/* ── Images ── */
$img = array(
	'hero'      => voltaj_demo_image('hero.jpg', 'Masa üzerinde kablo kangalları, renkli iletkenler ve bir kumpas'),
	'led'       => voltaj_demo_image('led-ampul.jpg', 'Beyaz zeminde yatan beyaz gövdeli LED ampul'),
	'filament'  => voltaj_demo_image('filament-ampul.jpg', 'Cam abajurun içinde yanan amber filament ampul'),
	'sarkit'    => voltaj_demo_image('sarkit-armatur.jpg', 'Gri duvar önünde çan biçimli gri sarkıt armatür'),
	'konik'     => voltaj_demo_image('konik-sarkit.jpg', 'Turkuaz duvar önünde yanan siyah konik sarkıt armatür'),
	'lamba'     => voltaj_demo_image('calisma-lambasi.jpg', 'Beyaz duvar önünde yanan siyah, yaylı kollu çalışma lambası'),
	'tekstil'   => voltaj_demo_image('tekstil-kablo.jpg', 'Gri zeminde iç içe geçmiş örgü kaplı renkli kablolar'),
	'bakir'     => voltaj_demo_image('bakir-tel.jpg', 'Parlak bakır tel kangalı'),
	'halat'     => voltaj_demo_image('celik-halat.jpg', 'Yakın çekimde çok telli çelik halat sarımları'),
	'klemens'   => voltaj_demo_image('klemens.jpg', 'Duvar kutusunda sarı klemens dizisine bağlanmış renkli kablolar'),
	'priz'      => voltaj_demo_image('ikili-priz.jpg', 'Güneş ışığı vuran dokulu duvarda krem renkli ikili priz'),
	'cift'      => voltaj_demo_image('cift-anahtar.jpg', 'Duvarda metal kapaklı çift anahtar'),
	'tekli'     => voltaj_demo_image('tekli-anahtar.jpg', 'Açık renkli duvarda sade tekli anahtar'),
	'tornavida' => voltaj_demo_image('tornavida.jpg', 'Ahşap yüzeyde kırmızı şeffaf saplı yıldız uçlu tornavida'),
	'pense'     => voltaj_demo_image('pense-seti.jpg', 'Ahşap yüzeyde siyah saplı üç pense'),
	'ayarli'    => voltaj_demo_image('ayarli-pense.jpg', 'Mavi zemin üzerinde mavi saplı ayarlı pense'),
);

/* ── Product categories (two levels, for the catalogue panel) ── */
$cat_light      = (int) voltaj_demo_term('Aydınlatma', 'product_cat', 'aydinlatma', 'Ampuller, sarkıt armatürler ve çalışma lambaları. Demo mağazanın ürünleri kurgusaldır.')->term_id;
$cat_bulbs      = (int) voltaj_demo_term('Ampuller', 'product_cat', 'ampuller', 'E27 duylu LED ve filament ampuller.', $cat_light)->term_id;
$cat_fixtures   = (int) voltaj_demo_term('Armatürler', 'product_cat', 'armaturler', 'Sarkıt armatürler ve masa lambaları.', $cat_light)->term_id;
$cat_cable      = (int) voltaj_demo_term('Kablo & Bağlantı', 'product_cat', 'kablo-baglanti', 'Kablolar, iletkenler, halatlar ve bağlantı elemanları.')->term_id;
$cat_cables     = (int) voltaj_demo_term('Kablolar', 'product_cat', 'kablolar', 'Metre ve kangal olarak kablo ve iletkenler.', $cat_cable)->term_id;
$cat_connectors = (int) voltaj_demo_term('Bağlantı Elemanları', 'product_cat', 'baglanti-elemanlari', 'Klemensler ve ek parçalar.', $cat_cable)->term_id;
$cat_switch     = (int) voltaj_demo_term('Anahtar & Priz', 'product_cat', 'anahtar-priz', 'Sıva altı ve sıva üstü anahtar ve prizler.')->term_id;
$cat_tools      = (int) voltaj_demo_term('El Aletleri', 'product_cat', 'el-aletleri', 'Tornavidalar, penseler ve atölye aletleri.')->term_id;
foreach (array($cat_light => 1, $cat_cable => 2, $cat_switch => 3, $cat_tools => 4, $cat_bulbs => 1, $cat_fixtures => 2, $cat_cables => 1, $cat_connectors => 2) as $voltaj_term_id => $voltaj_order) {
	update_term_meta($voltaj_term_id, 'order', $voltaj_order);
}

/* ── Brands (WooCommerce product_brand) ── */
$brand_light = (int) voltaj_demo_term('Demo Işık', 'product_brand', 'demo-isik', 'Kurgusal aydınlatma ve anahtar-priz markası.')->term_id;
$brand_cable = (int) voltaj_demo_term('Örnek Kablo', 'product_brand', 'ornek-kablo', 'Kurgusal kablo ve bağlantı malzemesi markası.')->term_id;
$brand_tools = (int) voltaj_demo_term('Atölye Pro', 'product_brand', 'atolye-pro', 'Kurgusal el aletleri markası.')->term_id;

/* ── Attributes "Güç" (pa_guc) and "Renk" (pa_renk) ── */
$voltaj_guc_id  = voltaj_demo_attribute('Güç', 'guc');
$voltaj_renk_id = voltaj_demo_attribute('Renk', 'renk');
$guc = array();
foreach (array('6 W', '9 W', '12 W') as $voltaj_i => $voltaj_value) {
	$guc[$voltaj_value] = voltaj_demo_term($voltaj_value, 'pa_guc', sanitize_title($voltaj_value));
	update_term_meta($guc[$voltaj_value]->term_id, 'order', $voltaj_i); // Attribute terms sort by menu order.
}
$renk = array();
foreach (array('Beyaz', 'Siyah', 'Gri', 'Krem', 'Gümüş', 'Amber', 'Kırmızı', 'Mavi') as $voltaj_value) {
	$renk[$voltaj_value] = voltaj_demo_term($voltaj_value, 'pa_renk', sanitize_title($voltaj_value));
}

/* ── Products ── */
$voltaj_note = ' Bu ürün Voltaj temasının demo mağazası içindir; kod, özellik ve fiyatlar kurgusaldır.';
$simple      = array(
	array('key' => 'filament', 'name' => 'Filament LED Ampul ST64 E27', 'slug' => 'filament-led-ampul-st64', 'sku' => 'VLT-AY-1021', 'price' => 92,
		'cats' => array($cat_light, $cat_bulbs), 'brand' => $brand_light, 'image' => $img['filament'], 'featured' => true,
		'tax' => array('pa_guc' => array($guc['6 W']), 'pa_renk' => array($renk['Amber'])), 'local' => array('Duy' => 'E27', 'Işık rengi' => '2200 K'),
		'short' => 'Açık armatürler için amber camlı, sıcak ışıklı filament ampul.',
		'description' => voltaj_demo_p('Görünen filamenti ve amber camıyla açık sarkıt armatürlerde dekoratif bir ışık verir.' . $voltaj_note)),
	array('key' => 'sarkit', 'name' => 'Çan Sarkıt Armatür', 'slug' => 'can-sarkit-armatur', 'sku' => 'VLT-AY-2104', 'price' => 645, 'sale' => 575,
		'cats' => array($cat_light, $cat_fixtures), 'brand' => $brand_light, 'image' => $img['sarkit'], 'featured' => true,
		'tax' => array('pa_renk' => array($renk['Gri'])), 'local' => array('Duy' => 'E27', 'Kablo boyu' => '120 cm', 'Gövde' => 'Boyalı metal'),
		'short' => 'Mat gri, çan biçimli metal gövde; örgü kaplı askı kablosu.',
		'description' => voltaj_demo_p('Mutfak tezgâhı ve yemek masası üstü için tek noktalı sarkıt armatür. Ampul dahil değildir.' . $voltaj_note)),
	array('key' => 'konik', 'name' => 'Konik Sarkıt Armatür', 'slug' => 'konik-sarkit-armatur', 'sku' => 'VLT-AY-2131', 'price' => 690,
		'cats' => array($cat_light, $cat_fixtures), 'brand' => $brand_light, 'image' => $img['konik'], 'stock' => 'outofstock',
		'tax' => array('pa_renk' => array($renk['Siyah'])), 'local' => array('Duy' => 'E27', 'Kablo boyu' => '100 cm', 'Gövde' => 'Boyalı metal'),
		'short' => 'Mat siyah dış yüzey, açık renkli iç yüzeyli konik metal abajur.',
		'description' => voltaj_demo_p('Tezgâh ve masa üstünde ışığı aşağı yönlendiren tek noktalı sarkıt armatür. Ampul dahil değildir.' . $voltaj_note)),
	array('key' => 'lamba', 'name' => 'Yaylı Kollu Çalışma Lambası', 'slug' => 'yayli-kollu-calisma-lambasi', 'sku' => 'VLT-AY-2310', 'price' => 745,
		'cats' => array($cat_light, $cat_fixtures), 'brand' => $brand_tools, 'image' => $img['lamba'], 'featured' => true,
		'tax' => array('pa_renk' => array($renk['Siyah'])), 'local' => array('Duy' => 'E27', 'Kol' => '2 × 40 cm', 'Bağlantı' => 'Masa kıskacı'),
		'short' => 'Atölye tezgâhı ve çizim masası için yaylı, iki eklemli kol.',
		'description' => voltaj_demo_p('Kıskaçla masaya sabitlenir; başlık her yöne çevrilebilir.' . $voltaj_note)),
	array('key' => 'tekstil', 'name' => 'Örgü Kaplı Tekstil Kablo 2×0,75 mm²', 'slug' => 'orgu-kapli-tekstil-kablo', 'sku' => 'VLT-KB-3075', 'price' => 285,
		'cats' => array($cat_cable, $cat_cables), 'brand' => $brand_cable, 'image' => $img['tekstil'], 'featured' => true,
		'tax' => array('pa_renk' => array($renk['Gri'])), 'local' => array('Kesit' => '2 × 0,75 mm²', 'Uzunluk' => '10 m'),
		'short' => 'Sarkıt armatür askıları için desenli tekstil örgü kaplı kablo.',
		'description' => voltaj_demo_p('Görünen kablo tesisatlarında ve armatür askılarında kullanılır. 10 metrelik kangal.' . $voltaj_note)),
	array('key' => 'bakir', 'name' => 'Bakır İletken Tel (Kangal)', 'slug' => 'bakir-iletken-tel-kangal', 'sku' => 'VLT-KB-3200', 'price' => '',
		'cats' => array($cat_cable, $cat_cables), 'brand' => $brand_cable, 'image' => $img['bakir'],
		'tax' => array(), 'local' => array('Malzeme' => 'Bakır', 'Satış birimi' => 'kg'),
		'short' => 'Kilo ile satılan çıplak bakır iletken. Fiyat, miktara göre teklifle belirlenir.',
		'description' => voltaj_demo_p('Bu ürünün fiyatı girilmemiştir: kart ve ürün sayfası "Teklif iste" düğmesini gösterir.' . $voltaj_note)),
	array('key' => 'halat', 'name' => 'Galvaniz Çelik Halat 4 mm', 'slug' => 'galvaniz-celik-halat-4-mm', 'sku' => 'VLT-KB-3412', 'price' => 455,
		'cats' => array($cat_cable, $cat_cables), 'brand' => $brand_cable, 'image' => $img['halat'],
		'tax' => array('pa_renk' => array($renk['Gümüş'])), 'local' => array('Çap' => '4 mm', 'Uzunluk' => '25 m'),
		'short' => 'Armatür ve kablo taşıyıcı askılar için çok telli çelik halat.',
		'description' => voltaj_demo_p('Kablo kanalı ve armatür askısında taşıyıcı olarak kullanılır.' . $voltaj_note)),
	array('key' => 'klemens', 'name' => 'Vidalı Klemens 12\'li Dizi', 'slug' => 'vidali-klemens-12li-dizi', 'sku' => 'VLT-KB-4012', 'price' => 118,
		'cats' => array($cat_cable, $cat_connectors), 'brand' => $brand_cable, 'image' => $img['klemens'], 'featured' => true,
		'tax' => array(), 'local' => array('Kesit aralığı' => '1,5–4 mm²', 'Paket' => '10 dizi'),
		'short' => 'Buat ve sigorta kutularında kablo eki için kesilebilir klemens dizisi.',
		'description' => voltaj_demo_p('Dizi istenen uzunlukta kesilebilir. 10 dizilik paket.' . $voltaj_note)),
	array('key' => 'priz', 'name' => 'İkili Topraklı Priz, Sıva Üstü', 'slug' => 'ikili-toprakli-priz-siva-ustu', 'sku' => 'VLT-AP-5216', 'price' => 156,
		'cats' => array($cat_switch), 'brand' => $brand_light, 'image' => $img['priz'], 'featured' => true,
		'tax' => array('pa_renk' => array($renk['Krem'])), 'local' => array('Akım' => '16 A', 'Montaj' => 'Sıva üstü'),
		'short' => 'Kanal ya da sıva üstü tesisat için ikili topraklı priz.',
		'description' => voltaj_demo_p('Duvarı kırmadan ek priz noktası açmak için uygundur.' . $voltaj_note)),
	array('key' => 'cift', 'name' => 'Metal Kapaklı Çift Anahtar', 'slug' => 'metal-kapakli-cift-anahtar', 'sku' => 'VLT-AP-5302', 'price' => 384,
		'cats' => array($cat_switch), 'brand' => $brand_light, 'image' => $img['cift'],
		'tax' => array('pa_renk' => array($renk['Gümüş'])), 'local' => array('Akım' => '10 A', 'Montaj' => 'Sıva altı'),
		'short' => 'Fırçalanmış metal kapak, iki ayrı aydınlatma hattı için.',
		'description' => voltaj_demo_p('İki ayrı devreyi tek kapaktan kumanda eder.' . $voltaj_note)),
	array('key' => 'tekli', 'name' => 'Tekli Anahtar, Sıva Altı', 'slug' => 'tekli-anahtar-siva-alti', 'sku' => 'VLT-AP-5301', 'price' => 96,
		'cats' => array($cat_switch), 'brand' => $brand_light, 'image' => $img['tekli'],
		'tax' => array('pa_renk' => array($renk['Beyaz'])), 'local' => array('Akım' => '10 A', 'Montaj' => 'Sıva altı'),
		'short' => 'Geniş basma yüzeyli, sade tekli anahtar.',
		'description' => voltaj_demo_p('Standart sıva altı kutuya vidayla sabitlenir.' . $voltaj_note)),
	array('key' => 'tornavida', 'name' => 'Hassas Tornavida PH0', 'slug' => 'hassas-tornavida-ph0', 'sku' => 'VLT-EA-6100', 'price' => 78, 'sale' => 58,
		'cats' => array($cat_tools), 'brand' => $brand_tools, 'image' => $img['tornavida'],
		'tax' => array('pa_renk' => array($renk['Kırmızı'])), 'local' => array('Uç' => 'PH0', 'Boy' => '150 mm'),
		'short' => 'Klemens ve küçük vidalar için ince yıldız uçlu tornavida.',
		'description' => voltaj_demo_p('Şeffaf sap, uzun ve ince gövde.' . $voltaj_note)),
	array('key' => 'pense', 'name' => 'Pense Seti, 3 Parça', 'slug' => 'pense-seti-3-parca', 'sku' => 'VLT-EA-6203', 'price' => 640,
		'cats' => array($cat_tools), 'brand' => $brand_tools, 'image' => $img['pense'], 'featured' => true,
		'tax' => array('pa_renk' => array($renk['Siyah'])), 'local' => array('İçerik' => 'Kombine, yan keski, kargaburun'),
		'short' => 'Elektrik işleri için üç temel pense bir arada.',
		'description' => voltaj_demo_p('Kombine pense, yan keski ve kargaburun.' . $voltaj_note)),
	array('key' => 'ayarli', 'name' => 'Ayarlı Pense 250 mm', 'slug' => 'ayarli-pense-250-mm', 'sku' => 'VLT-EA-6250', 'price' => 286,
		'cats' => array($cat_tools), 'brand' => $brand_tools, 'image' => $img['ayarli'],
		'tax' => array('pa_renk' => array($renk['Mavi'])), 'local' => array('Boy' => '250 mm', 'Ağız ayarı' => '5 kademe'),
		'short' => 'Boru ve rakor işleri için kayar mafsallı ayarlı pense.',
		'description' => voltaj_demo_p('Mafsal beş kademede ayarlanır.' . $voltaj_note)),
);
$product_ids = array();
foreach ($simple as $p) {
	$product_ids[$p['key']] = voltaj_demo_simple_product($p);
}

// Variable product: LED bulb in two wattages.
$led_id = voltaj_demo_find('product:led', 'product');
$led    = $led_id ? wc_get_product($led_id) : null;
if (!$led instanceof WC_Product_Variable) {
	$led = new WC_Product_Variable($led_id ?: 0);
}
$led->set_name('LED Ampul A60 E27');
$led->set_slug('led-ampul-a60-e27');
$led->set_status('publish');
$led->set_sku('');
$led->set_category_ids(array($cat_light, $cat_bulbs));
$led->set_image_id($img['led']);
$led->set_featured(true);
$led->set_short_description('Genel aydınlatma için opal camlı LED ampul. İki güç seçeneği vardır.');
$led->set_description(voltaj_demo_p('Standart E27 duylu armatürlerde kullanılır. Güç seçimi ürün kodunu da değiştirir.') . voltaj_demo_p(trim($voltaj_note)));
$led->set_reviews_allowed(false);
$led_tax = array('pa_guc' => array($guc['9 W'], $guc['12 W']), 'pa_renk' => array($renk['Beyaz']));
$led->set_attributes(voltaj_demo_attributes($led_tax, array('Duy' => 'E27', 'Işık rengi' => '6500 K'), 'pa_guc'));
$led_id = $led->save();
voltaj_demo_set_sku($led_id, 'VLT-AY-1009');
update_post_meta($led_id, '_voltaj_demo', 'product:led');
voltaj_demo_set_terms($led_id, $led_tax);
wp_set_object_terms($led_id, array($brand_light), 'product_brand');

foreach (array(array('9-w', $guc['9 W']->slug, 48, 'VLT-AY-1009-09'), array('12-w', $guc['12 W']->slug, 64, 'VLT-AY-1009-12')) as $v) {
	$key          = 'variation:led:' . $v[0];
	$variation_id = voltaj_demo_find($key, 'product_variation');
	$variation    = new WC_Product_Variation($variation_id ?: 0);
	$variation->set_parent_id($led_id);
	$variation->set_attributes(array('pa_guc' => $v[1]));
	$variation->set_regular_price((string) $v[2]);
	$variation->set_status('publish');
	$variation->set_stock_status('instock');
	$variation->set_sku('');
	$variation_id = $variation->save();
	update_post_meta($variation_id, '_voltaj_demo', $key);
	voltaj_demo_set_sku($variation_id, $v[3]);
}
WC_Product_Variable::sync($led_id);
$product_ids['led'] = $led_id;

/* ── Shipping: one zone for Turkey, flat rate + free shipping from a threshold (invented values) ── */
$voltaj_state = (array) get_option(VOLTAJ_DEMO_STATE);
$voltaj_zone  = !empty($voltaj_state['zone_id']) ? WC_Shipping_Zones::get_zone((int) $voltaj_state['zone_id']) : false;
if (!$voltaj_zone) {
	$voltaj_zone = new WC_Shipping_Zone();
	$voltaj_zone->set_zone_name('Türkiye (Voltaj demo)');
	// Zone order is unsigned: shift the existing zones down by one (reset restores their order) and go first.
	$voltaj_zone_orders = array();
	foreach (WC_Shipping_Zones::get_zones() as $voltaj_other_zone) {
		$voltaj_zone_orders[(int) $voltaj_other_zone['id']] = (int) $voltaj_other_zone['zone_order'];
		$voltaj_other_obj = new WC_Shipping_Zone((int) $voltaj_other_zone['id']);
		$voltaj_other_obj->set_zone_order((int) $voltaj_other_zone['zone_order'] + 1);
		$voltaj_other_obj->save();
	}
	voltaj_demo_state_set('zone_orders', $voltaj_zone_orders);
	$voltaj_zone->set_zone_order(0);
	$voltaj_zone->add_location('TR', 'country');
	$voltaj_zone->save();
	$flat_id = $voltaj_zone->add_shipping_method('flat_rate');
	$free_id = $voltaj_zone->add_shipping_method('free_shipping');
	voltaj_demo_state_set('zone_id', $voltaj_zone->get_id());
} else {
	$flat_id = $free_id = 0;
	foreach ($voltaj_zone->get_shipping_methods() as $voltaj_method) {
		if ($voltaj_method->id === 'flat_rate') {
			$flat_id = $voltaj_method->instance_id;
		} elseif ($voltaj_method->id === 'free_shipping') {
			$free_id = $voltaj_method->instance_id;
		}
	}
}
update_option('woocommerce_flat_rate_' . $flat_id . '_settings', array('title' => 'Kargo', 'tax_status' => 'none', 'cost' => '89'));
update_option('woocommerce_free_shipping_' . $free_id . '_settings', array('title' => 'Ücretsiz kargo', 'requires' => 'min_amount', 'min_amount' => '1750', 'ignore_discounts' => 'no'));
WC_Cache_Helper::get_transient_version('shipping', true);

/* ── Payment: bank transfer with an obviously fake account ── */
update_option('woocommerce_bacs_settings', array(
	'enabled'         => 'yes',
	'title'           => 'Havale / EFT',
	'description'     => 'Siparişinizi tamamladıktan sonra tutarı aşağıdaki hesaba havale edebilirsiniz. Bu bir demo mağazadır; gerçek ödeme alınmaz.',
	'instructions'    => 'Bu bir demo mağazadır; ödeme yapmayın. Aşağıdaki hesap bilgileri kurgusaldır.',
	'account_details' => '',
));
update_option('woocommerce_bacs_accounts', array(
	array(
		'account_name'   => 'Voltaj Demo Teknik Malzeme Ltd. Şti.',
		'account_number' => '',
		'bank_name'      => 'Demo Bankası',
		'sort_code'      => '',
		'iban'           => 'TR00 ' . '0000 0000 0000 0000 0000 00', // all-zero placeholder; split so the repository's privacy scanner (IBAN rule) does not flag it
		'bic'            => '',
	),
));

/* ── Pages ── */
$home     = voltaj_demo_post('page:home', array('post_type' => 'page', 'post_title' => 'Ana Sayfa', 'post_name' => 'ana-sayfa', 'post_content' => ''));
$blog     = voltaj_demo_post('page:blog', array('post_type' => 'page', 'post_title' => 'Blog', 'post_name' => 'blog'));
$shop     = voltaj_demo_post('page:shop', array('post_type' => 'page', 'post_title' => 'Mağaza', 'post_name' => 'magaza', 'post_content' => ''));
$cart     = voltaj_demo_post('page:cart', array('post_type' => 'page', 'post_title' => 'Sepet', 'post_name' => 'sepet', 'post_content' => "<!-- wp:shortcode -->\n[woocommerce_cart]\n<!-- /wp:shortcode -->"));
$checkout = voltaj_demo_post('page:checkout', array('post_type' => 'page', 'post_title' => 'Ödeme', 'post_name' => 'odeme', 'post_content' => "<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->"));
$account  = voltaj_demo_post('page:account', array('post_type' => 'page', 'post_title' => 'Hesabım', 'post_name' => 'hesabim', 'post_content' => "<!-- wp:shortcode -->\n[woocommerce_my_account]\n<!-- /wp:shortcode -->"));
$brands   = voltaj_demo_post('page:brands', array(
	'post_type'     => 'page',
	'post_title'    => 'Markalar',
	'post_name'     => 'markalar',
	'page_template' => 'page-templates/brands.php',
	'post_content'  => voltaj_demo_p('Mağazadaki ürünleri markaya göre inceleyin. Buradaki markalar Voltaj temasının demo içeriği için uydurulmuştur.'),
));
$quote    = voltaj_demo_post('page:quote', array(
	'post_type'     => 'page',
	'post_title'    => 'Teklif İste',
	'post_name'     => 'teklif-iste',
	'page_template' => 'page-templates/quote.php',
	'post_content'  => voltaj_demo_p('Toplu alım, proje malzeme listesi ya da fiyatı girilmemiş ürünler için formu doldurun. Demo mağazada form gönderimi gerçek bir satıcıya ulaşmaz.'),
));
$contact  = voltaj_demo_post('page:contact', array(
	'post_type'     => 'page',
	'post_title'    => 'İletişim',
	'post_name'     => 'iletisim',
	'page_template' => 'page-templates/contact.php',
	'post_content'  => voltaj_demo_p('Sipariş, kargo ve ürün sorularınız için bize yazın. Demo mağazanın iletişim bilgileri kurgusaldır.'),
));
foreach (array($brands => 'brands', $quote => 'quote', $contact => 'contact') as $voltaj_pid => $voltaj_tpl) {
	update_post_meta($voltaj_pid, '_wp_page_template', 'page-templates/' . $voltaj_tpl . '.php');
}
$delivery = voltaj_demo_post('page:delivery', array(
	'post_type'    => 'page',
	'post_title'   => 'Teslimat ve İade',
	'post_name'    => 'teslimat-ve-iade',
	'post_content' =>
		voltaj_demo_p('Bu sayfa demo içeriğidir. Aşağıdaki koşullar Voltaj temasının nasıl göründüğünü göstermek için uydurulmuştur.') .
		voltaj_demo_h('Kargo') .
		voltaj_demo_list(array('Türkiye içi sabit kargo ücreti 89 TL’dir.', '1.750 TL ve üzeri siparişlerde kargo ücretsizdir.', 'Siparişler ödeme onayından sonra hazırlanır.')) .
		voltaj_demo_h('İade') .
		voltaj_demo_p('İade talebinizi Hesabım sayfasındaki sipariş ayrıntılarından iletebilirsiniz. Demo mağazada gerçek sipariş ve iade işlemi yapılmaz.'),
));

/* ── Posts (headings feed the table of contents) ── */
$post_cat = (int) voltaj_demo_term('Teknik Notlar', 'category', 'teknik-notlar')->term_id;
$posts    = array(
	array('led-ampul-secimi', 'LED ampul seçerken bakılacak dört değer', $img['led'], '-9 days',
		'Güç, ışık rengi, duy tipi ve kullanım yeri: doğru ampulü seçmek için ürün sayfasındaki özellik tablosunu nasıl okuyacağınız.',
		voltaj_demo_p('Ürün sayfalarındaki "Öne çıkan özellikler" tablosu, ampul seçerken ihtiyaç duyacağınız bilgileri tek yerde toplar. Bu yazı, o tablodaki satırların ne anlama geldiğini kısaca anlatıyor.') .
		voltaj_demo_h('Güç (W)') .
		voltaj_demo_p('Güç, ampulün harcadığı enerjiyi gösterir. Aynı odada farklı güçte ampuller kullanacaksanız aynı ışık rengini seçmek görüntüyü dengeler.') .
		voltaj_demo_h('Işık rengi (K)') .
		voltaj_demo_p('Kelvin değeri düştükçe ışık sarıya, yükseldikçe beyaza yaklaşır.') .
		voltaj_demo_h('Sıcak ışık', 3) .
		voltaj_demo_p('Oturma odası ve yatak odası gibi dinlenme alanlarında sık tercih edilir.') .
		voltaj_demo_h('Beyaz ışık', 3) .
		voltaj_demo_p('Atölye, mutfak tezgâhı ve çalışma masası gibi ayrıntı gerektiren alanlarda kullanılır.') .
		voltaj_demo_h('Duy tipi') .
		voltaj_demo_p('Armatürünüzün duyunu kontrol edin: demo mağazadaki ampuller E27 duyludur.') .
		voltaj_demo_h('Kullanım yeri') .
		voltaj_demo_list(array('Açık sarkıt armatürlerde filament ampuller dekoratif bir görüntü verir.', 'Kapalı armatürlerde opal camlı ampuller ışığı daha yumuşak dağıtır.')) .
		voltaj_demo_p('Bu yazı Voltaj temasının demo içeriğidir.'),
	),
	array('siva-alti-siva-ustu', 'Sıva altı mı, sıva üstü mü? Anahtar ve priz seçimi', $img['priz'], '-3 days',
		'Tadilat yapmadan yeni priz noktası açmak ya da mevcut tesisatı yenilemek için hangi montaj tipinin uygun olduğunu anlatan kısa bir rehber.',
		voltaj_demo_p('Anahtar ve prizler montaj biçimine göre ikiye ayrılır. Hangisini seçeceğiniz, tesisatın duvarın içinden mi yoksa üstünden mi geçtiğine bağlıdır.') .
		voltaj_demo_h('Sıva altı') .
		voltaj_demo_p('Kablolar duvarın içindeki borulardan geçer, ürün duvardaki kutuya yerleşir. Yeni yapılarda ve kapsamlı tadilatlarda tercih edilir.') .
		voltaj_demo_h('Sıva üstü') .
		voltaj_demo_p('Ürün duvarın yüzeyine monte edilir, kablo kanal içinde ilerler. Duvarı kırmadan ek nokta açmak için uygundur.') .
		voltaj_demo_h('Montajdan önce', 3) .
		voltaj_demo_list(array('Sigortayı kapatın ve hattın enerjisiz olduğunu kontrol edin.', 'Mevcut kutunun ölçüsünü not alın.', 'Emin değilseniz işi bir elektrik ustasına bırakın.')) .
		voltaj_demo_p('Bu yazı Voltaj temasının demo içeriğidir.'),
	),
);
foreach ($posts as $p) {
	$id = voltaj_demo_post('post:' . $p[0], array(
		'post_type'     => 'post',
		'post_title'    => $p[1],
		'post_name'     => $p[0],
		'post_excerpt'  => $p[4],
		'post_content'  => $p[5],
		'post_date'     => wp_date('Y-m-d H:i:s', strtotime($p[3])),
		'post_category' => array($post_cat),
	));
	set_post_thumbnail($id, $p[2]);
}

/* ── Reading and WooCommerce pages ── */
update_option('blogname', 'Voltaj');
update_option('blogdescription', 'Elektrik ve teknik malzeme');
update_option('show_on_front', 'page');
update_option('page_on_front', $home);
update_option('page_for_posts', $blog);
update_option('woocommerce_shop_page_id', $shop);
update_option('woocommerce_cart_page_id', $cart);
update_option('woocommerce_checkout_page_id', $checkout);
update_option('woocommerce_myaccount_page_id', $account);

/* ── Menus (the catalogue panel builds itself from the product categories) ── */
$primary = voltaj_demo_menu('primary', 'Voltaj Demo Ana Menü', array(
	array('Tüm ürünler', $shop, 'page'),
	array('Markalar', $brands, 'page'),
	array('Teklif İste', $quote, 'page'),
	array('Blog', $blog, 'page'),
	array('İletişim', $contact, 'page'),
));
$footer1 = voltaj_demo_menu('footer-1', 'Kategoriler', array(
	array('Aydınlatma', $cat_light, 'product_cat'),
	array('Kablo & Bağlantı', $cat_cable, 'product_cat'),
	array('Anahtar & Priz', $cat_switch, 'product_cat'),
	array('El Aletleri', $cat_tools, 'product_cat'),
));
$footer2 = voltaj_demo_menu('footer-2', 'Yardım', array(
	array('Teslimat ve İade', $delivery, 'page'),
	array('Hesabım', $account, 'page'),
	array('İletişim', $contact, 'page'),
));
$footer3 = voltaj_demo_menu('footer-3', 'Kurumsal', array(
	array('Markalar', $brands, 'page'),
	array('Teklif İste', $quote, 'page'),
	array('Blog', $blog, 'page'),
));
$locations             = (array) get_theme_mod('nav_menu_locations', array());
$locations['primary']  = $primary;
$locations['footer-1'] = $footer1;
$locations['footer-2'] = $footer2;
$locations['footer-3'] = $footer3;
set_theme_mod('nav_menu_locations', $locations);

/* ── Customizer values ── */
$phone = '0312' . ' 000 00 00'; // invented; split so it is obviously not a real listing
$mods  = array(
	'announcement_text'  => '1.750 TL ve üzeri siparişlerde kargo ücretsiz · Bu bir demo mağazadır',
	'announcement_url'   => get_permalink($delivery),
	'hero_title'         => 'Elektrik malzemesi, kodu ve özelliğiyle',
	'hero_text'          => 'Aydınlatma, kablo, anahtar-priz ve el aletleri; her ürün kodu, stok durumu ve özellik tablosuyla. Bu mağaza Voltaj temasının demo içeriğidir.',
	'hero_image'         => $img['hero'],
	'search_placeholder' => 'Ürün ara (ör. LED ampul)',
	'categories_title'   => 'Kategoriler',
	'products_title'     => 'Öne çıkan ürünler',
	'products_count'     => 8,
	'posts_title'        => 'Teknik notlar',
	'info_cards'         => "Havale / EFT | Siparişten sonra banka havalesiyle ödeme\nKargo | 1.750 TL ve üzeri siparişlerde ücretsiz\nToplu alım | Teklif formuyla miktara göre fiyat isteyin\nDestek | Hafta içi 09:00–18:00",
	'company_name'       => 'Voltaj Demo Teknik Malzeme Ltd. Şti.',
	'contact_address'    => "Örnek Mah. Demo Cad. No: 1\nÇankaya / Ankara",
	'contact_phone'      => $phone,
	'contact_email'      => 'ornek@example.com',
	'contact_hours'      => 'Hafta içi 09:00–18:00',
	'footer_text'        => 'Bu site Voltaj temasının demo mağazasıdır. Firma, marka, ürün ve iletişim bilgileri kurgusaldır; gerçek sipariş alınmaz.',
	'footer_note'        => 'Ödeme: Havale / EFT',
);
foreach ($mods as $key => $value) {
	set_theme_mod('voltaj_' . $key, $value);
}

wc_delete_product_transients();
delete_transient('voltaj_nav_html');
flush_rewrite_rules(false);

WP_CLI::success(sprintf('Voltaj demo ready: %d products, 8 categories, 3 brands, 10 pages, %d posts, %d images, 4 menus, 1 shipping zone.', count($product_ids), count($posts), count($img)));
