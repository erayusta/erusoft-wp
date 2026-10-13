<?php
/**
 * Narin demo content (idempotent). Run from the repository root with WooCommerce and the theme active:
 *
 *   wp theme activate narin
 *   wp eval-file themes/narin/demo/setup.php
 *
 * Creates a fictional cosmetics and self-care shop ("Narin Demo Kozmetik Ltd. Şti."): 3 product categories
 * with 4 products each (one variable product with a "Hacim" attribute), a shipping zone (flat rate + free
 * shipping above a threshold), bank transfer, shop/cart/checkout/account pages as WooCommerce shortcode pages,
 * three posts, menus, images and Customizer values. Everything created is flagged with the _narin_demo
 * meta / term meta so demo/reset.php can remove exactly that; the options it changes are saved first and
 * restored by reset.php. All names, prices, addresses and numbers are invented.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!defined('WP_CLI') || !WP_CLI) {
	return;
}
if (get_template() !== 'narin') {
	WP_CLI::error('Activate the Narin theme first: wp theme activate narin');
}
if (!class_exists('WooCommerce')) {
	WP_CLI::error('The Narin demo needs WooCommerce: wp plugin activate woocommerce');
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

const NARIN_DEMO_STATE = 'narin_demo_state';

/**
 * Options the demo changes. Their previous values are saved on the first run and restored by reset.php.
 *
 * @return string[]
 */
function narin_demo_option_keys() {
	return array(
		'show_on_front', 'page_on_front', 'page_for_posts', 'blogname', 'blogdescription',
		'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id',
		'woocommerce_default_country', 'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep', 'woocommerce_price_num_decimals', 'woocommerce_thumbnail_cropping',
		'woocommerce_thumbnail_cropping_custom_width', 'woocommerce_thumbnail_cropping_custom_height',
		'woocommerce_bacs_settings', 'woocommerce_coming_soon',
	);
}

/**
 * Remember the site options (first run only).
 */
function narin_demo_remember_state() {
	if (get_option(NARIN_DEMO_STATE)) {
		return;
	}
	$state = array('options' => array(), 'theme_mods' => array());
	foreach (narin_demo_option_keys() as $key) {
		$state['options'][$key] = get_option($key, null);
	}
	// Theme mods the demo writes (narin_* and menu locations), so reset.php can put the owner's values back.
	$mods = get_option('theme_mods_narin');
	foreach (is_array($mods) ? $mods : array() as $key => $value) {
		if (strpos($key, 'narin_') === 0 || $key === 'nav_menu_locations') {
			$state['theme_mods'][$key] = $value;
		}
	}
	add_option(NARIN_DEMO_STATE, $state, '', false);
}

/**
 * Update the saved state.
 *
 * @param string $key
 * @param mixed  $value
 */
function narin_demo_state_set($key, $value) {
	$state       = (array) get_option(NARIN_DEMO_STATE, array());
	$state[$key] = $value;
	update_option(NARIN_DEMO_STATE, $state, false);
}

/**
 * Find a demo post by its key.
 *
 * @param string          $key
 * @param string|string[] $type
 * @return int
 */
function narin_demo_find($key, $type) {
	$found = get_posts(array(
		'post_type'      => $type,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_narin_demo',
		'meta_value'     => $key,
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
function narin_demo_post($key, $args) {
	$args = array_merge(array('post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed'), $args);
	$id   = narin_demo_find($key, $args['post_type']);
	if ($id) {
		$args['ID'] = $id;
	}
	$id = wp_insert_post(wp_slash($args), true);
	if (is_wp_error($id)) {
		WP_CLI::error($id->get_error_message());
	}
	update_post_meta($id, '_narin_demo', $key);
	return (int) $id;
}

/**
 * Import a demo image once.
 *
 * @param string $file File name in demo/images.
 * @param string $alt  Alt text.
 * @return int Attachment ID.
 */
function narin_demo_image($file, $alt) {
	$key = 'image:' . $file;
	$id  = narin_demo_find($key, 'attachment');
	if (!$id) {
		$tmp = wp_tempnam($file);
		copy(__DIR__ . '/images/' . $file, $tmp);
		$id = media_handle_sideload(array('name' => 'narin-demo-' . $file, 'tmp_name' => $tmp), 0);
		if (is_wp_error($id)) {
			WP_CLI::error($file . ': ' . $id->get_error_message());
		}
		update_post_meta($id, '_narin_demo', $key);
	}
	update_post_meta($id, '_wp_attachment_image_alt', $alt);
	return (int) $id;
}

/**
 * Get or create a demo term. Only a term created by the demo (flagged with the _narin_demo term meta = $key) is
 * reused; a pre-existing term with the same slug or name is never adopted: the demo then creates its own term
 * with a "-narin-demo" slug and a "(Narin demo)" name suffix.
 *
 * @param string $name
 * @param string $taxonomy
 * @param string $slug
 * @param string $description
 * @return WP_Term
 */
function narin_demo_term($name, $taxonomy, $slug, $description = '') {
	$key   = $taxonomy . ':' . $slug;
	// meta_query (not meta_key): WooCommerce replaces meta_key on its own taxonomies to sort them.
	$found = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 1, 'meta_query' => array(array('key' => '_narin_demo', 'value' => $key))));
	if ($found && !is_wp_error($found)) {
		$term_id = (int) $found[0]->term_id;
		wp_update_term($term_id, $taxonomy, array('description' => $description));
		return get_term($term_id, $taxonomy);
	}
	$taken = get_term_by('slug', $slug, $taxonomy) || term_exists($name, $taxonomy);
	if ($taken) {
		$slug .= '-narin-demo';
		$name .= ' (Narin demo)';
	}
	$res = wp_insert_term($name, $taxonomy, array('slug' => $slug, 'description' => $description));
	if (is_wp_error($res)) {
		WP_CLI::error($res->get_error_message());
	}
	update_term_meta((int) $res['term_id'], '_narin_demo', $key);
	return get_term((int) $res['term_id'], $taxonomy);
}

/**
 * Build a demo menu (items are recreated on every run). Only a menu created by the demo (flagged with
 * _narin_demo = $key) is reused; when another menu already has the name, the demo menu gets a "(Narin demo)" suffix.
 *
 * @param string $key   Stable key.
 * @param string $name  Menu name (shown as the footer column heading).
 * @param array  $items list of [title, object_id, object (page|product_cat|category)]
 * @return int
 */
function narin_demo_menu($key, $name, $items) {
	$found = get_terms(array('taxonomy' => 'nav_menu', 'hide_empty' => false, 'number' => 1, 'meta_key' => '_narin_demo', 'meta_value' => 'menu:' . $key));
	if ($found && !is_wp_error($found)) {
		$menu_id = (int) $found[0]->term_id;
		foreach ((array) wp_get_nav_menu_items($menu_id, array('post_status' => 'any')) as $item) {
			wp_delete_post($item->ID, true);
		}
	} else {
		if (wp_get_nav_menu_object($name)) {
			$name .= ' (Narin demo)';
		}
		$menu_id = wp_create_nav_menu($name);
		if (is_wp_error($menu_id)) {
			WP_CLI::error($menu_id->get_error_message());
		}
		$menu_id = (int) $menu_id;
		update_term_meta($menu_id, '_narin_demo', 'menu:' . $key);
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
 * Block markup helpers.
 */
function narin_demo_p($text) {
	return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
}
function narin_demo_h($text) {
	return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $text . "</h2>\n<!-- /wp:heading -->\n\n";
}
function narin_demo_list($items) {
	$li = '';
	foreach ($items as $item) {
		$li .= "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->\n";
	}
	return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $li . "</ul>\n<!-- /wp:list -->\n\n";
}

/**
 * Create or update a simple demo product.
 *
 * @param array $p key, name, slug, price, sale, cat, image, short, description, featured.
 * @return int
 */
function narin_demo_simple_product($p) {
	$id      = narin_demo_find('product:' . $p['key'], 'product');
	$product = $id ? wc_get_product($id) : new WC_Product_Simple();
	if (!$product instanceof WC_Product_Simple) {
		$product = new WC_Product_Simple($id ?: 0);
	}
	$product->set_name($p['name']);
	$product->set_slug($p['slug']);
	$product->set_status('publish');
	$product->set_regular_price((string) $p['price']);
	$product->set_sale_price(isset($p['sale']) ? (string) $p['sale'] : '');
	$product->set_category_ids(array($p['cat']));
	$product->set_image_id($p['image']);
	$product->set_short_description($p['short']);
	$product->set_description($p['description']);
	$product->set_featured(!empty($p['featured']));
	$product->set_manage_stock(false);
	$product->set_stock_status('instock');
	$product->set_catalog_visibility('visible');
	$product->set_reviews_allowed(false);
	$id = $product->save();
	update_post_meta($id, '_narin_demo', 'product:' . $p['key']);
	return (int) $id;
}

/* ── State and site options ── */
narin_demo_remember_state();

/* Keep the blog listing demo-only: unpublish other published posts (reset.php republishes them if unchanged). */
$narin_state = (array) get_option(NARIN_DEMO_STATE);
$narin_other = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array(array('key' => '_narin_demo', 'compare' => 'NOT EXISTS'))));
if ($narin_other) {
	$narin_drafted = (array) ($narin_state['drafted_posts'] ?? array());
	foreach ($narin_other as $narin_pid) {
		wp_update_post(array('ID' => $narin_pid, 'post_status' => 'draft'));
		// Remember the modified time after drafting: reset.php republishes the post only when nobody edited it since.
		$narin_drafted[$narin_pid] = get_post_field('post_modified_gmt', $narin_pid);
	}
	narin_demo_state_set('drafted_posts', $narin_drafted);
}

update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_default_country', 'TR:TR06');
update_option('woocommerce_currency', 'TRY');
update_option('woocommerce_currency_pos', 'right_space');
update_option('woocommerce_price_thousand_sep', '.');
update_option('woocommerce_price_decimal_sep', ',');
update_option('woocommerce_price_num_decimals', '0');
update_option('woocommerce_thumbnail_cropping', 'custom');
update_option('woocommerce_thumbnail_cropping_custom_width', '4');
update_option('woocommerce_thumbnail_cropping_custom_height', '5');
$narin_thumb = wc_get_image_size('thumbnail');
add_image_size('woocommerce_thumbnail', $narin_thumb['width'], $narin_thumb['height'], $narin_thumb['crop']);

/* ── Images ── */
$img = array(
	'hero'      => narin_demo_image('hero.jpg', 'Kahverengi seramik vazoda okaliptüs dalları'),
	'serum'     => narin_demo_image('yuz-serumu.jpg', 'Mermer zemin üzerinde damlalıklı kahverengi cam şişe'),
	'krem'      => narin_demo_image('nemlendirici-krem.jpg', 'Taşın üzerinde kapağı açık beyaz krem kavanozu'),
	'gece'      => narin_demo_image('gece-kremi.jpg', 'Koyu zemin önünde taş üzerinde açık krem kavanozu'),
	'yag'       => narin_demo_image('yuz-yagi.jpg', 'Ahşap ayak üzerinde damlalıklı kahverengi cam şişe'),
	'vkrem'     => narin_demo_image('vucut-kremi.jpg', 'Bej kutunun yanında buzlu cam krem kavanozu'),
	'sabun'     => narin_demo_image('dogal-sabun.jpg', 'Üst üste dizilmiş dört beyaz sabun kalıbı'),
	'tuz'       => narin_demo_image('banyo-tuzu.jpg', 'Kurutulmuş mor çiçek yapraklı banyo tuzu kavanozları'),
	'vyag'      => narin_demo_image('vucut-yagi.jpg', 'Mermer altlık üzerinde altın kapaklı beyaz damlalıklı şişe'),
	'mum'       => narin_demo_image('mum-seti.jpg', 'Gri duvar önünde yanan dört ince beyaz mum'),
	'cicek'     => narin_demo_image('kuru-cicek.jpg', 'Krem tonlarında kuru çiçek buketi'),
	'okaliptus' => narin_demo_image('okaliptus.jpg', 'Beyaz zemin üzerinde okaliptüs dalları'),
	'esans'     => narin_demo_image('esans-yagi.jpg', 'Beyaz zemin üzerinde içinde kurutulmuş bitkiler olan damlalıklı şişe'),
	'doku1'     => narin_demo_image('doku-krem.jpg', 'Bej zemin üzerinde beyaz krem dokusu'),
	'doku2'     => narin_demo_image('doku-yag.jpg', 'Şeffaf yuvarlak tabaklarda iç içe geçmiş yağ ve jel dokuları'),
	'bitki'     => narin_demo_image('bitkiler.jpg', 'Beyaz zemin üzerinde yan yana dizilmiş kurutulmuş yapraklar ve çiçekler'),
);

/* ── Product categories ── */
$cat_skin_term = narin_demo_term('Cilt Bakımı', 'product_cat', 'cilt-bakimi', 'Yüz için serum, krem ve yağlar. Demo mağazanın ürünleri kurgusaldır.');
$cat_skin      = (int) $cat_skin_term->term_id;
$cat_body_term = narin_demo_term('Vücut ve Banyo', 'product_cat', 'vucut-ve-banyo', 'Banyo ritüelleri ve vücut bakımı için seçkiler.');
$cat_body      = (int) $cat_body_term->term_id;
$cat_home_term = narin_demo_term('Ev ve Koku', 'product_cat', 'ev-ve-koku', 'Mumlar, kuru çiçekler ve evde sakin bir köşe için küçük dokunuşlar.');
$cat_home      = (int) $cat_home_term->term_id;

/* ── Attribute "Hacim" (pa_hacim) ── */
$narin_attr_id = wc_attribute_taxonomy_id_by_name('hacim');
if (!$narin_attr_id) {
	$narin_attr_id = wc_create_attribute(array('name' => 'Hacim', 'slug' => 'hacim', 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false));
	if (is_wp_error($narin_attr_id)) {
		WP_CLI::error($narin_attr_id->get_error_message());
	}
	narin_demo_state_set('created_attribute', (int) $narin_attr_id);
}
if (!taxonomy_exists('pa_hacim')) {
	register_taxonomy('pa_hacim', array('product', 'product_variation'), array('hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false));
}
$hacim_30 = narin_demo_term('30 ml', 'pa_hacim', '30-ml');
$hacim_50 = narin_demo_term('50 ml', 'pa_hacim', '50-ml');

/* ── Products ── */
$simple = array(
	array('key' => 'krem', 'name' => 'Nemlendirici Yüz Kremi', 'slug' => 'nemlendirici-yuz-kremi', 'price' => 385, 'cat' => $cat_skin, 'image' => $img['krem'], 'featured' => true,
		'short' => 'Gündüz bakımı için hafif, hızlı emilen bir krem.',
		'description' => narin_demo_p('Temizlenmiş cilde sabah ve akşam az miktarda uygulayın. Bu ürün Narin temasının demo mağazası için hazırlandı; içerik ve fiyat kurgusaldır.')),
	array('key' => 'gece', 'name' => 'Gece Bakım Kremi', 'slug' => 'gece-bakim-kremi', 'price' => 465, 'cat' => $cat_skin, 'image' => $img['gece'],
		'short' => 'Akşam rutininin son adımı için yoğun dokulu krem.',
		'description' => narin_demo_p('Yatmadan önce yüz ve boyun bölgesine nazikçe yayın. Demo üründür, satışa sunulmaz.')),
	array('key' => 'yag', 'name' => 'Bitkisel Yüz Yağı', 'slug' => 'bitkisel-yuz-yagi', 'price' => 340, 'sale' => 295, 'cat' => $cat_skin, 'image' => $img['yag'],
		'short' => 'Birkaç damlası yeterli olan, ipeksi bitkisel yağ karışımı.',
		'description' => narin_demo_p('Kreminizden önce iki üç damlayı avuçlarınızda ısıtıp yüzünüze bastırarak uygulayın. Demo üründür.')),
	array('key' => 'vkrem', 'name' => 'Vücut Bakım Kremi', 'slug' => 'vucut-bakim-kremi', 'price' => 310, 'cat' => $cat_body, 'image' => $img['vkrem'],
		'short' => 'Duş sonrası için yumuşak dokulu vücut kremi.',
		'description' => narin_demo_p('Nemli cilde dairesel hareketlerle uygulayın. Demo üründür, içerik bilgisi kurgusaldır.')),
	array('key' => 'sabun', 'name' => 'El Yapımı Sabun', 'slug' => 'el-yapimi-sabun', 'price' => 135, 'cat' => $cat_body, 'image' => $img['sabun'], 'featured' => true,
		'short' => 'Kalıp sabun; dört adetlik paket.',
		'description' => narin_demo_p('Kuru bir sabunlukta saklayın. Demo üründür.')),
	array('key' => 'tuz', 'name' => 'Çiçekli Banyo Tuzu', 'slug' => 'cicekli-banyo-tuzu', 'price' => 210, 'cat' => $cat_body, 'image' => $img['tuz'],
		'short' => 'Kurutulmuş çiçek yapraklarıyla karıştırılmış banyo tuzu.',
		'description' => narin_demo_p('Ilık banyo suyuna iki üç kaşık ekleyin. Demo üründür.')),
	array('key' => 'vyag', 'name' => 'Vücut Yağı', 'slug' => 'vucut-yagi', 'price' => 365, 'cat' => $cat_body, 'image' => $img['vyag'],
		'short' => 'Masaj ve duş sonrası bakım için hafif yağ.',
		'description' => narin_demo_p('Avuçlarınızda ısıtarak cildinize yayın. Demo üründür.')),
	array('key' => 'mum', 'name' => 'İnce Mum Seti', 'slug' => 'ince-mum-seti', 'price' => 280, 'sale' => 240, 'cat' => $cat_home, 'image' => $img['mum'],
		'short' => 'Dört adet ince, kokusuz masa mumu.',
		'description' => narin_demo_p('Yanarken gözetimsiz bırakmayın. Demo üründür.')),
	array('key' => 'cicek', 'name' => 'Kuru Çiçek Buketi', 'slug' => 'kuru-cicek-buketi', 'price' => 450, 'cat' => $cat_home, 'image' => $img['cicek'], 'featured' => true,
		'short' => 'Krem tonlarında, vazoya hazır kuru çiçek buketi.',
		'description' => narin_demo_p('Doğrudan güneş ışığından ve nemden uzak tutun. Demo üründür.')),
	array('key' => 'okaliptus', 'name' => 'Okaliptüs Demeti', 'slug' => 'okaliptus-demeti', 'price' => 175, 'cat' => $cat_home, 'image' => $img['okaliptus'],
		'short' => 'Banyo ya da vazo için taze görünümlü okaliptüs dalları.',
		'description' => narin_demo_p('Duş başlığına asabilir ya da vazoda kullanabilirsiniz. Demo üründür.')),
	array('key' => 'esans', 'name' => 'Oda Esansı', 'slug' => 'oda-esansi', 'price' => 225, 'cat' => $cat_home, 'image' => $img['esans'],
		'short' => 'Buhurdan ve difüzörler için damlalıklı esans.',
		'description' => narin_demo_p('Buhurdanınıza birkaç damla ekleyin. Demo üründür.')),
);
$product_ids = array();
foreach ($simple as $p) {
	$product_ids[$p['key']] = narin_demo_simple_product($p);
}

// Variable product: face serum in two volumes.
$serum_id = narin_demo_find('product:serum', 'product');
$serum    = $serum_id ? wc_get_product($serum_id) : null;
if (!$serum instanceof WC_Product_Variable) {
	$serum = new WC_Product_Variable($serum_id ?: 0);
}
$serum->set_name('Yüz Serumu');
$serum->set_slug('yuz-serumu');
$serum->set_status('publish');
$serum->set_category_ids(array($cat_skin));
$serum->set_image_id($img['serum']);
$serum->set_featured(true);
$serum->set_short_description('Sabah ve akşam rutini için hafif dokulu serum. İki hacim seçeneği vardır.');
$serum->set_description(narin_demo_p('Temiz cilde birkaç damla uygulayıp hafifçe bastırın. Ardından kreminizi kullanın.') . narin_demo_p('Bu ürün Narin temasının demo mağazası içindir; hacim ve fiyatlar kurgusaldır.'));
$serum->set_reviews_allowed(false);
$narin_attribute = new WC_Product_Attribute();
$narin_attribute->set_id((int) $narin_attr_id);
$narin_attribute->set_name('pa_hacim');
$narin_attribute->set_options(array((int) $hacim_30->term_id, (int) $hacim_50->term_id));
$narin_attribute->set_visible(true);
$narin_attribute->set_variation(true);
$serum->set_attributes(array($narin_attribute));
$serum_id = $serum->save();
update_post_meta($serum_id, '_narin_demo', 'product:serum');
wp_set_object_terms($serum_id, array((int) $hacim_30->term_id, (int) $hacim_50->term_id), 'pa_hacim');

foreach (array(array('30-ml', $hacim_30->slug, 420), array('50-ml', $hacim_50->slug, 590)) as $v) {
	$key          = 'variation:serum:' . $v[0];
	$variation_id = narin_demo_find($key, 'product_variation');
	$variation    = new WC_Product_Variation($variation_id ?: 0);
	$variation->set_parent_id($serum_id);
	$variation->set_attributes(array('pa_hacim' => $v[1]));
	$variation->set_regular_price((string) $v[2]);
	$variation->set_status('publish');
	$variation->set_stock_status('instock');
	$variation_id = $variation->save();
	update_post_meta($variation_id, '_narin_demo', $key);
}
WC_Product_Variable::sync($serum_id);
$product_ids['serum'] = $serum_id;

/* ── Shipping: one zone for Turkey, flat rate + free shipping from a threshold (invented values) ── */
$narin_state = (array) get_option(NARIN_DEMO_STATE);
$narin_zone  = !empty($narin_state['zone_id']) ? WC_Shipping_Zones::get_zone((int) $narin_state['zone_id']) : false;
if (!$narin_zone) {
	$narin_zone = new WC_Shipping_Zone();
	$narin_zone->set_zone_name('Türkiye (Narin demo)');
	// Zone order is unsigned: shift the existing zones down by one (reset restores their order) and go first.
	$narin_zone_orders = array();
	foreach (WC_Shipping_Zones::get_zones() as $narin_other_zone) {
		$narin_zone_orders[(int) $narin_other_zone['id']] = (int) $narin_other_zone['zone_order'];
		$narin_other = new WC_Shipping_Zone((int) $narin_other_zone['id']);
		$narin_other->set_zone_order((int) $narin_other_zone['zone_order'] + 1);
		$narin_other->save();
	}
	narin_demo_state_set('zone_orders', $narin_zone_orders);
	$narin_zone->set_zone_order(0);
	$narin_zone->add_location('TR', 'country');
	$narin_zone->save();
	$flat_id = $narin_zone->add_shipping_method('flat_rate');
	$free_id = $narin_zone->add_shipping_method('free_shipping');
	narin_demo_state_set('zone_id', $narin_zone->get_id());
} else {
	$flat_id = $free_id = 0;
	foreach ($narin_zone->get_shipping_methods() as $narin_method) {
		if ($narin_method->id === 'flat_rate') {
			$flat_id = $narin_method->instance_id;
		} elseif ($narin_method->id === 'free_shipping') {
			$free_id = $narin_method->instance_id;
		}
	}
}
update_option('woocommerce_flat_rate_' . $flat_id . '_settings', array('title' => 'Kargo', 'tax_status' => 'none', 'cost' => '89'));
update_option('woocommerce_free_shipping_' . $free_id . '_settings', array('title' => 'Ücretsiz kargo', 'requires' => 'min_amount', 'min_amount' => '1750', 'ignore_discounts' => 'no'));
WC_Cache_Helper::get_transient_version('shipping', true);

/* ── Payment: bank transfer only for the demo checkout ── */
update_option('woocommerce_bacs_settings', array(
	'enabled'         => 'yes',
	'title'           => 'Doğrudan banka havalesi',
	'description'     => 'Siparişinizi tamamladıktan sonra ödemeyi banka hesabımıza havale ile yapabilirsiniz. Bu bir demo mağazadır; gerçek ödeme alınmaz.',
	'instructions'    => 'Bu bir demo mağazadır; ödeme yapmayın.',
	'account_details' => '',
));

/* ── Pages ── */
$home    = narin_demo_post('page:home', array('post_type' => 'page', 'post_title' => 'Ana Sayfa', 'post_name' => 'ana-sayfa', 'post_content' => ''));
$blog    = narin_demo_post('page:blog', array('post_type' => 'page', 'post_title' => 'Günlük', 'post_name' => 'gunluk'));
$shop    = narin_demo_post('page:shop', array('post_type' => 'page', 'post_title' => 'Mağaza', 'post_name' => 'magaza', 'post_content' => ''));
$cart    = narin_demo_post('page:cart', array('post_type' => 'page', 'post_title' => 'Sepet', 'post_name' => 'sepet', 'post_content' => "<!-- wp:shortcode -->\n[woocommerce_cart]\n<!-- /wp:shortcode -->"));
$checkout = narin_demo_post('page:checkout', array('post_type' => 'page', 'post_title' => 'Ödeme', 'post_name' => 'odeme', 'post_content' => "<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->"));
$account = narin_demo_post('page:account', array('post_type' => 'page', 'post_title' => 'Hesabım', 'post_name' => 'hesabim', 'post_content' => "<!-- wp:shortcode -->\n[woocommerce_my_account]\n<!-- /wp:shortcode -->"));
$about   = narin_demo_post('page:about', array(
	'post_type'    => 'page',
	'post_title'   => 'Hakkımızda',
	'post_name'    => 'hakkimizda',
	'post_content' =>
		narin_demo_p('Narin Demo Kozmetik, Narin temasının demo mağazası için tasarlanmış <strong>kurgusal</strong> bir firmadır. Burada gördüğünüz unvan, adres, telefon, e-posta, ürün ve fiyatlar gerçek değildir.') .
		narin_demo_h('Nasıl seçiyoruz?') .
		narin_demo_p('Mağazadaki her ürün, temanın ürün sayfası, kategori ve sepet görünümlerini göstermek için hazırlandı. Kendi mağazanızda bu metnin yerine kendi hikâyenizi yazabilirsiniz.') .
		narin_demo_list(array('Sade, okunaklı ürün sayfaları', 'Kategoriye göre süzülebilen mağaza', 'Kargo eşiğini gösteren ilerleme çubuğu')),
));
set_post_thumbnail($about, $img['bitki']);
$delivery = narin_demo_post('page:delivery', array(
	'post_type'    => 'page',
	'post_title'   => 'Teslimat ve İade',
	'post_name'    => 'teslimat-ve-iade',
	'post_content' =>
		narin_demo_p('Bu sayfa demo içeriğidir. Aşağıdaki koşullar Narin temasının nasıl göründüğünü göstermek için uydurulmuştur.') .
		narin_demo_h('Kargo') .
		narin_demo_list(array('Türkiye içi sabit kargo ücreti 89 TL’dir.', '1.750 TL ve üzeri siparişlerde kargo ücretsizdir.', 'Siparişler ödeme onayından sonra 2 iş günü içinde kargoya verilir.')) .
		narin_demo_h('İade') .
		narin_demo_p('İade talebinizi Hesabım sayfasındaki sipariş ayrıntılarından iletebilirsiniz. Demo mağazada gerçek sipariş ve iade işlemi yapılmaz.'),
));
$contact = narin_demo_post('page:contact', array(
	'post_type'     => 'page',
	'post_title'    => 'İletişim',
	'post_name'     => 'iletisim',
	'page_template' => 'page-templates/contact.php',
	'post_content'  => narin_demo_p('Sorularınız için aşağıdaki bilgilerden bize ulaşabilirsiniz. Demo mağazanın iletişim bilgileri kurgusaldır.'),
));
update_post_meta($contact, '_wp_page_template', 'page-templates/contact.php');

/* ── Posts ── */
$post_cat = (int) narin_demo_term('Bakım Notları', 'category', 'bakim-notlari')->term_id;
$posts    = array(
	array('aksam-rutini', 'Akşam bakımında sade bir sıra', $img['doku1'], '-12 days',
		'Uzun bir günün ardından birkaç adımlık, aceleye gelmeyen bir akşam rutini nasıl kurulur?',
		narin_demo_p('Akşam bakımının en iyi yanı acele etmemektir. Birkaç sade adım, günün sonunda kendinize ayırdığınız küçük bir ritüele dönüşebilir.') .
		narin_demo_h('Önce temizlik') .
		narin_demo_p('Günün yorgunluğunu ılık suyla ve nazik bir temizleyiciyle alın. Havluyla bastırarak kurulayın.') .
		narin_demo_h('Sonra katmanlar') .
		narin_demo_list(array('İnceden kalına: önce serum, sonra krem.', 'Her katmanın birkaç dakika yerleşmesine izin verin.', 'Son adımda yüz yağını avuçlarınızda ısıtın.')) .
		narin_demo_p('Bu yazı Narin temasının demo içeriğidir.'),
	),
	array('hacim-secimi', '30 ml mi, 50 ml mi? Doğru hacmi seçmek', $img['doku2'], '-6 days',
		'İlk kez denediğiniz bir ürün için küçük hacim, sevdiğiniz bir ürün için büyük hacim. Karar vermeyi kolaylaştıran birkaç soru.',
		narin_demo_p('Aynı ürünün iki hacmi arasında kararsız kaldığınızda kendinize şu soruları sorabilirsiniz.') .
		narin_demo_list(array('Ürünü daha önce kullandınız mı?', 'Günde kaç kez uyguluyorsunuz?', 'Yolculukta yanınıza alacak mısınız?')) .
		narin_demo_p('Demo mağazadaki Yüz Serumu, WooCommerce’in varyasyonlu ürün özelliğini göstermek için iki hacimle eklendi.'),
	),
	array('sakin-kose', 'Evde sakin bir köşe kurmak', $img['bitki'], '-2 days',
		'Bir mum, birkaç kuru dal ve iyi bir kitap. Evde kendinize ayırdığınız küçük bir alan için öneriler.',
		narin_demo_p('Sakin bir köşe için büyük bir alana ihtiyaç yoktur. Pencere kenarındaki bir koltuk ya da yatağınızın yanındaki küçük bir sehpa yeterlidir.') .
		narin_demo_h('Üç küçük dokunuş') .
		narin_demo_list(array('Yumuşak, sıcak bir ışık', 'Vazoda kuru çiçek ya da okaliptüs', 'Telefonu başka bir odada bırakmak')) .
		narin_demo_p('Bu yazı Narin temasının demo içeriğidir.'),
	),
);
foreach ($posts as $p) {
	$id = narin_demo_post('post:' . $p[0], array(
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
update_option('blogname', 'Narin');
update_option('blogdescription', 'Kozmetik ve kişisel bakım');
update_option('show_on_front', 'page');
update_option('page_on_front', $home);
update_option('page_for_posts', $blog);
update_option('woocommerce_shop_page_id', $shop);
update_option('woocommerce_cart_page_id', $cart);
update_option('woocommerce_checkout_page_id', $checkout);
update_option('woocommerce_myaccount_page_id', $account);

/* ── Menus ── */
$primary = narin_demo_menu('primary', 'Narin Demo Ana Menü', array(
	array('Mağaza', $shop, 'page'),
	array('Cilt Bakımı', $cat_skin, 'product_cat'),
	array('Vücut ve Banyo', $cat_body, 'product_cat'),
	array('Ev ve Koku', $cat_home, 'product_cat'),
	array('Günlük', $blog, 'page'),
	array('Hakkımızda', $about, 'page'),
));
$footer1 = narin_demo_menu('footer-1', 'Alışveriş', array(
	array('Tüm ürünler', $shop, 'page'),
	array('Cilt Bakımı', $cat_skin, 'product_cat'),
	array('Vücut ve Banyo', $cat_body, 'product_cat'),
	array('Ev ve Koku', $cat_home, 'product_cat'),
));
$footer2 = narin_demo_menu('footer-2', 'Yardım', array(
	array('Teslimat ve İade', $delivery, 'page'),
	array('Hesabım', $account, 'page'),
	array('İletişim', $contact, 'page'),
));
$footer3 = narin_demo_menu('footer-3', 'Kurumsal', array(
	array('Hakkımızda', $about, 'page'),
	array('Günlük', $blog, 'page'),
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
	'announcement_text' => '1.750 TL ve üzeri siparişlerde kargo ücretsiz · Bu bir demo mağazadır',
	'announcement_url'  => get_permalink($delivery),
	'hero_kicker'       => 'Kozmetik ve kişisel bakım',
	'hero_title'        => 'Sade bakım, özenli ritüeller',
	'hero_text'         => 'Cilt, vücut ve ev için az ama özenle seçilmiş ürünler. Bu mağaza Narin temasının demo içeriğidir.',
	'hero_image'        => $img['hero'],
	'hero_button_text'  => 'Mağazayı keşfet',
	'hero_button_url'   => get_permalink($shop),
	'hero_button2_text' => 'Bakım notları',
	'hero_button2_url'  => get_permalink($blog),
	'categories_title'  => 'Kategoriler',
	'products_title'    => 'Yeni gelenler',
	'products_count'    => 4,
	'posts_title'       => 'Bakım notları',
	'highlights'        => "Hediye paketi | İsterseniz sipariş notuna yazın, ücretsiz sararız\nGüvenli ödeme | Banka havalesiyle, kart bilgisi istenmez\nKolay iade | Açılmamış ürünleri geri gönderebilirsiniz\nBitki özlü formüller | Bitki yağları ve özleriyle hazırlanan bakım",
	'shipping_note'     => 'Siparişler 2 iş günü içinde kargoya verilir.',
	'product_points'    => "İade talebinizi Hesabım sayfasından iletebilirsiniz\nSiparişler geri dönüştürülebilir kutularla gönderilir",
	'company_name'      => 'Narin Demo Kozmetik Ltd. Şti.',
	'contact_address'   => "Örnek Mah. Demo Sok. No: 1\nÇankaya / Ankara",
	'contact_phone'     => $phone,
	'contact_email'     => 'ornek@example.com',
	'contact_hours'     => 'Hafta içi 09:00–17:30',
	'footer_text'       => 'Bu site Narin temasının demo mağazasıdır. Firma, ürün ve iletişim bilgileri kurgusaldır; gerçek sipariş alınmaz.',
	'footer_note'       => 'Ödeme: Havale / EFT',
);
foreach ($mods as $key => $value) {
	set_theme_mod('narin_' . $key, $value);
}

wc_delete_product_transients();
flush_rewrite_rules(false);

WP_CLI::success(sprintf('Narin demo ready: %d products, 3 categories, 9 pages, %d posts, %d images, 4 menus, 1 shipping zone.', count($product_ids), count($posts), count($img)));
