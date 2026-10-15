<?php
/**
 * Product Push for Trendyol — item builder: validation matrix, variable products (one item per
 * variation, shared productMainId), exact float encoding, list-price multiplier, deepest category map,
 * bulk capability, admin.js DOM safety. Every HTTP call is answered by the router below from invented
 * fixtures; anything else exits 1.
 *
 *   wp plugin activate product-push-for-trendyol
 *   wp eval-file tests/php/ppt-builder.php
 */

$check = function ( $label, $ok, $extra = '' ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . ( $ok || '' === $extra ? '' : ' — ' . $extra ) . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

// Invented credentials, generated per run (never literals).
$demo_key    = 'k' . wp_generate_password( 12, false );
$demo_secret = 's' . wp_generate_password( 16, false );
$f_key       = 'k' . wp_generate_password( 12, false );
$f_secret    = 's' . wp_generate_password( 16, false );

foreach ( array( 'Eru_PPT_Settings', 'Eru_PPT_Client', 'Eru_PPT_Categories', 'Eru_PPT_Builder', 'Eru_PPT_Pusher', 'Eru_PPT_Bulk' ) as $eru_ppt_class ) {
	if ( ! class_exists( $eru_ppt_class ) ) {
		echo "FAIL: {$eru_ppt_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}

// ---------------------------------------------------------------------------------------------
// HTTP router (invented category tree and attributes of the documented shape).
// ---------------------------------------------------------------------------------------------
$reqs   = array();
$router = function ( $pre, $args, $url ) use ( &$reqs ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( wp_parse_url( home_url(), PHP_URL_HOST ) === $host && false !== strpos( $url, 'wp-cron.php' ) ) {
		return new WP_Error( 'eru_ppt_test', 'loopback cron blocked in tests' );
	}
	$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
	$reqs[] = array( 'method' => $args['method'] ?? 'GET', 'url' => $url );
	$json   = fn( $data, $code = 200 ) => array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
	if ( 'apigw.trendyol.com' === $host ) {
		if ( '/integration/product/product-categories' === $path ) {
			return $json(
				array(
					'categories' => array(
						array(
							'id'            => 5000,
							'name'          => 'Demo Ev',
							'subCategories' => array(
								array( 'id' => 5001, 'name' => 'Demo Kupa', 'subCategories' => array() ),
								array( 'id' => 5002, 'name' => 'Demo Termos', 'subCategories' => array() ),
							),
						),
					),
				)
			);
		}
		if ( preg_match( '#^/integration/product/categories/(500[12])/attributes$#', $path, $m ) ) {
			return $json(
				array(
					'id'                 => (int) $m[1],
					'name'               => 'Demo',
					'categoryAttributes' => array(
						array( 'allowCustom' => false, 'attribute' => array( 'id' => 47, 'name' => 'Renk' ), 'attributeValues' => array( array( 'id' => 701, 'name' => 'Siyah' ), array( 'id' => 702, 'name' => 'Beyaz' ) ), 'categoryId' => (int) $m[1], 'required' => true, 'varianter' => false, 'slicer' => true ),
						array( 'allowCustom' => false, 'attribute' => array( 'id' => 338, 'name' => 'Boyut' ), 'attributeValues' => array( array( 'id' => 801, 'name' => 'S' ), array( 'id' => 802, 'name' => 'M' ), array( 'id' => 803, 'name' => 'L' ) ), 'categoryId' => (int) $m[1], 'required' => true, 'varianter' => true, 'slicer' => false ),
						array( 'allowCustom' => true, 'attribute' => array( 'id' => 12, 'name' => 'Materyal' ), 'attributeValues' => array(), 'categoryId' => (int) $m[1], 'required' => false, 'varianter' => false, 'slicer' => false ),
					),
				)
			);
		}
	}
	echo 'FAIL unmocked HTTP ' . $url . "\n";
	exit( 1 );
};
add_filter( 'pre_http_request', $router, 10, 3 );

// ---------------------------------------------------------------------------------------------
// Clean leftovers of an aborted run, then fixtures.
// ---------------------------------------------------------------------------------------------
$cleanup = function () {
	foreach ( get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_eru_ppt_test', 'meta_value' => 'builder' ) ) as $id ) { // phpcs:ignore
		$p = wc_get_product( $id );
		if ( $p ) {
			$p->delete( true );
		}
	}
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_eru_ppt_test', 'meta_value' => 'builder' ) ) as $id ) { // phpcs:ignore
		wp_delete_attachment( $id, true );
	}
	foreach ( array( 'demo-ppt-kategori', 'demo-ppt-alt-kategori' ) as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) {
			wp_delete_term( $t->term_id, 'product_cat' );
		}
	}
	$aid = wc_attribute_taxonomy_id_by_name( 'demo_ppt_boyut' );
	if ( $aid ) {
		wc_delete_attribute( $aid );
	}
	if ( taxonomy_exists( 'pa_demo_ppt_boyut' ) ) {
		unregister_taxonomy( 'pa_demo_ppt_boyut' ); // Registered at init from a leftover row; blocks re-creation.
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'eru_ppt_push', null, 'eru-ppt' );
	}
	delete_option( 'eru_ppt_settings' );
	delete_option( 'eru_ppt_batches' );
	Eru_PPT_Categories::flush();
	Eru_PPT_Settings::flush();
};
$cleanup();

Eru_PPT_Settings::update(
	array(
		'enabled'           => 'yes',
		'environment'       => 'live',
		'seller_id'         => '100001',
		'api_key'           => $demo_key,
		'api_secret'        => $demo_secret,
		'brand_id'          => 900,
		'brand_name'        => 'Demo Marka',
		'cargo_company_id'  => 11,
		'vat_rate'          => 20,
		'delivery_duration' => 2,
		'list_multiplier'   => '1',
		'barcode_source'    => 'sku',
		'barcode_prefix'    => '',
		'push_quantity'     => 'stock',
	)
);

// Attachments: URLs are made https by a filter (demo runs on http); one test drops the filter.
$https = fn( $u ) => preg_replace( '#^http://#', 'https://', (string) $u );
add_filter( 'wp_get_attachment_url', $https );
$img = function ( $name ) {
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $name . '.jpg' );
	update_post_meta( $id, '_eru_ppt_test', 'builder' );
	return $id;
};
$img_a = $img( 'demo-ppt-a' );
$img_b = $img( 'demo-ppt-b' );

$parent_cat = wp_insert_term( 'Demo PPT Kategori', 'product_cat', array( 'slug' => 'demo-ppt-kategori' ) )['term_id'];
$child_cat  = wp_insert_term( 'Demo PPT Alt Kategori', 'product_cat', array( 'slug' => 'demo-ppt-alt-kategori', 'parent' => $parent_cat ) )['term_id'];
Eru_PPT_Settings::update(
	array(
		'category_map' => array(
			array( 'wc' => $parent_cat, 'ty' => 5000, 'name' => 'Demo Ev' ),
			array( 'wc' => $child_cat, 'ty' => 5001, 'name' => 'Demo Ev > Demo Kupa' ),
		),
	)
);

$simple = function ( array $o = array() ) use ( $img_a, $child_cat ) {
	$p = new WC_Product_Simple();
	$p->set_name( $o['name'] ?? 'Demo Kupa' );
	$p->set_sku( $o['sku'] ?? 'DEMO-PPT-' . wp_generate_password( 6, false ) );
	$p->set_regular_price( $o['price'] ?? '100' );
	if ( isset( $o['sale'] ) ) {
		$p->set_sale_price( $o['sale'] );
	}
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 7 );
	$p->set_image_id( $img_a );
	$p->set_category_ids( $o['cats'] ?? array( $child_cat ) );
	$p->set_description( 'Demo açıklama.' );
	$p->update_meta_data( '_eru_ppt_test', 'builder' );
	$p->update_meta_data( '_eru_ppt_attributes', $o['attrs'] ?? array( 47 => array( 'v' => 701 ), 338 => array( 'v' => 802 ) ) );
	$p->save();
	return $p;
};
$build = fn( $p ) => Eru_PPT_Builder::build( wc_get_product( $p->get_id() ) );
$has   = function ( array $errors, $needle ) {
	foreach ( $errors as $e ) {
		if ( false !== mb_stripos( $e, $needle ) ) {
			return true;
		}
	}
	return false;
};

// ---------------------------------------------------------------------------------------------
// (a) Validation matrix.
// ---------------------------------------------------------------------------------------------
$p = $simple();
$r = $build( $p );
$check( '(a) baseline simple product builds one item without errors', array() === $r['errors'] && 1 === count( $r['items'] ), implode( ' | ', $r['errors'] ) );

$p2 = $simple( array( 'cats' => array() ) );
$r  = $build( $p2 );
$check( '(a) missing category → "Trendyol kategorisi seçilmemiş"', 1 === count( $r['errors'] ) && $has( $r['errors'], 'Trendyol kategorisi seçilmemiş' ), implode( ' | ', $r['errors'] ) );

Eru_PPT_Settings::update( array( 'brand_id' => 0 ) );
$r = $build( $p );
$check( '(a) missing brand → "Trendyol markası seçilmemiş"', 1 === count( $r['errors'] ) && $has( $r['errors'], 'Trendyol markası seçilmemiş' ), implode( ' | ', $r['errors'] ) );
Eru_PPT_Settings::update( array( 'brand_id' => 900 ) );

$p3 = $simple( array( 'sku' => '' ) );
$r  = $build( $p3 );
$check( '(a) missing barcode (SKU source, no prefix) → "Barkod eksik"', 1 === count( $r['errors'] ) && $has( $r['errors'], 'Barkod eksik' ), implode( ' | ', $r['errors'] ) );
Eru_PPT_Settings::update( array( 'barcode_prefix' => 'DMB' ) );
$r = $build( $p3 );
$check( '(a) barcode fallback prefix + id fills the gap', array() === $r['errors'] && 'DMB' . $p3->get_id() === $r['items'][0]['barcode'], implode( ' | ', $r['errors'] ) );
Eru_PPT_Settings::update( array( 'barcode_prefix' => '' ) );

$p4 = $simple( array( 'attrs' => array( 338 => array( 'v' => 802 ) ) ) );
$r  = $build( $p4 );
$check( '(a) missing required attribute → "Zorunlu özellik eksik: Renk"', 1 === count( $r['errors'] ) && $has( $r['errors'], 'Zorunlu özellik eksik: Renk' ), implode( ' | ', $r['errors'] ) );

remove_filter( 'wp_get_attachment_url', $https );
$r = $build( $p );
add_filter( 'wp_get_attachment_url', $https );
$check( '(a) non-https image → "https"', 1 === count( $r['errors'] ) && $has( $r['errors'], 'Görsel adresi https' ), implode( ' | ', $r['errors'] ) );

$p5 = $simple( array( 'price' => '0' ) );
$r  = $build( $p5 );
$check( '(a) price 0 → "Fiyat 0\'dan büyük olmalı"', 1 === count( $r['errors'] ) && $has( $r['errors'], 'Fiyat 0' ), implode( ' | ', $r['errors'] ) );

$p6 = $simple( array( 'attrs' => array( 47 => array( 'c' => 'Mor' ), 338 => array( 'v' => 802 ) ) ) );
$r  = $build( $p6 );
$check( '(a) free text on a list-only attribute is refused', $has( $r['errors'], 'serbest değer kabul etmiyor' ), implode( ' | ', $r['errors'] ) );

Eru_PPT_Settings::update( array( 'cargo_company_id' => 0 ) );
$r = $build( $p );
$check( '(a) missing cargo company → "Kargo firması"', 1 === count( $r['errors'] ) && $has( $r['errors'], 'Kargo firması' ), implode( ' | ', $r['errors'] ) );
Eru_PPT_Settings::update( array( 'cargo_company_id' => 11 ) );

// ---------------------------------------------------------------------------------------------
// (b) Variable product: one item per variation, shared productMainId, distinct barcodes, mapped values.
// ---------------------------------------------------------------------------------------------
$attr_id = wc_create_attribute( array( 'name' => 'Demo PPT Boyut', 'slug' => 'demo_ppt_boyut', 'type' => 'select' ) );
$tax     = 'pa_demo_ppt_boyut';
register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false ) );
$term_ids = array();
foreach ( array( 's' => 'S', 'm' => 'M', 'l' => 'L' ) as $slug => $name ) {
	$t                 = wp_insert_term( $name, $tax, array( 'slug' => $slug ) );
	$term_ids[ $slug ] = is_wp_error( $t ) ? (int) $t->get_error_data() : $t['term_id'];
}
Eru_PPT_Settings::update(
	array(
		'attribute_map' => array(
			array( 'cat' => 5001, 'wc' => $tax, 'ty' => 338, 'values' => array( 's' => 801, 'm' => 802, 'l' => 803 ) ),
		),
	)
);
$var = new WC_Product_Variable();
$var->set_name( 'Demo Varyantlı Kupa' );
$var->set_sku( 'DEMO-PPT-V' . wp_generate_password( 4, false ) );
$var->set_category_ids( array( $child_cat ) );
$var->set_image_id( $img_a );
$var->set_gallery_image_ids( array( $img_b ) );
$var->set_description( 'Demo açıklama.' );
$wa = new WC_Product_Attribute();
$wa->set_id( $attr_id );
$wa->set_name( $tax );
$wa->set_options( array_values( $term_ids ) );
$wa->set_visible( true );
$wa->set_variation( true );
$var->set_attributes( array( $wa ) );
$var->update_meta_data( '_eru_ppt_test', 'builder' );
$var->update_meta_data( '_eru_ppt_attributes', array( 47 => array( 'v' => 701 ) ) );
$var->save();
foreach ( array( 's', 'm', 'l' ) as $slug ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var->get_id() );
	$v->set_attributes( array( $tax => $slug ) );
	$v->set_sku( $var->get_sku() . '-' . strtoupper( $slug ) );
	$v->set_regular_price( '150' );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 3 );
	$v->update_meta_data( '_eru_ppt_test', 'builder' );
	$v->save();
}
WC_Product_Variable::sync( $var->get_id() );
$r     = $build( $var );
$mains = array_unique( array_column( $r['items'], 'productMainId' ) );
$bars  = array_unique( array_column( $r['items'], 'barcode' ) );
$check( '(b) 3 variations → 3 items, no errors', 3 === count( $r['items'] ) && array() === $r['errors'], implode( ' | ', $r['errors'] ) );
$check( '(b) one shared productMainId = parent SKU', array( $var->get_sku() ) === array_values( $mains ) );
$check( '(b) 3 distinct barcodes', 3 === count( $bars ) );
$sizes = array();
foreach ( $r['items'] as $it ) {
	foreach ( $it['attributes'] as $a ) {
		if ( 338 === $a['attributeId'] ) {
			$sizes[ $it['barcode'] ] = $a['attributeValueId'] ?? null;
		}
	}
}
$check(
	'(b) variant attribute values mapped per variation (S→801, M→802, L→803)',
	array( 801, 802, 803 ) === array( $sizes[ $var->get_sku() . '-S' ] ?? 0, $sizes[ $var->get_sku() . '-M' ] ?? 0, $sizes[ $var->get_sku() . '-L' ] ?? 0 ),
	wp_json_encode( $sizes )
);
$check( '(b) parent image and gallery go to each variation item over https', 2 === count( $r['items'][0]['images'] ) && 0 === strpos( $r['items'][0]['images'][0]['url'], 'https://' ) );
$check( '(b) objects map barcodes back to variation ids', 3 === count( array_unique( $r['objects'] ) ) && ! in_array( $var->get_id(), $r['objects'], true ) );

$var->set_sku( '' );
$var->save();
$r = $build( $var );
$check( '(b) parent without SKU → productMainId ppt-<parent id>', array( 'ppt-' . $var->get_id() ) === array_values( array_unique( array_column( $r['items'], 'productMainId' ) ) ) );

// Same label on both sides maps without a value line; an unmapped, differently named value on a
// list-only attribute → error naming the value.
Eru_PPT_Settings::update( array( 'attribute_map' => array( array( 'cat' => 5001, 'wc' => $tax, 'ty' => 338, 'values' => array( 's' => 801, 'm' => 802 ) ) ) ) );
$r = $build( $var );
$check( '(b) value with the same label as a Trendyol value maps automatically (L → 803)', array() === $r['errors'] && in_array( 803, array_column( array_merge( ...array_column( $r['items'], 'attributes' ) ), 'attributeValueId' ), true ), implode( ' | ', $r['errors'] ) );
wp_update_term( $term_ids['l'], $tax, array( 'name' => 'Demo Büyük' ) );
$r = $build( $var );
wp_update_term( $term_ids['l'], $tax, array( 'name' => 'L' ) );
$check( '(b) unmapped variation value is reported with the variation', $has( $r['errors'], '"Boyut" için "Demo Büyük" değeri eşlenmemiş' ) && $has( $r['errors'], 'Varyasyon #' ) && 1 === count( $r['errors'] ), implode( ' | ', $r['errors'] ) );
Eru_PPT_Settings::update( array( 'attribute_map' => array( array( 'cat' => 5001, 'wc' => $tax, 'ty' => 338, 'values' => array( 's' => 801, 'm' => 802, 'l' => 803 ) ) ) ) );

// ---------------------------------------------------------------------------------------------
// (c) Exact floats and the list-price multiplier.
// ---------------------------------------------------------------------------------------------
$pf = $simple( array( 'price' => '194.35' ) );
$r  = $build( $pf );
ini_set( 'serialize_precision', '17' ); // phpcs:ignore WordPress.PHP.IniSet
$json = Eru_PPT_Builder::encode( array( 'items' => $r['items'] ) );
$check( '(c) 194.35 is encoded exactly', false !== strpos( $json, '"salePrice":194.35,' ) && false === strpos( $json, '194.3499' ), $json );
$check( '(c) serialize_precision restored after encoding', '17' === ini_get( 'serialize_precision' ) );
ini_set( 'serialize_precision', '-1' ); // phpcs:ignore WordPress.PHP.IniSet

Eru_PPT_Settings::update( array( 'list_multiplier' => '1.2' ) );
$pm   = $simple( array( 'price' => '100' ) );
$r    = $build( $pm );
$json = Eru_PPT_Builder::encode( $r['items'][0] );
$check( '(c) multiplier 1.2 on 100 → listPrice 120', 1 === preg_match( '/"listPrice":120[,}]/', $json ) && 1 === preg_match( '/"salePrice":100[,}]/', $json ), $json );
$pm->set_sale_price( '90' );
$pm->save();
$r = $build( $pm );
$check( '(c) sale price goes to salePrice, list stays regular × multiplier', 90 == $r['items'][0]['salePrice'] && 120 == $r['items'][0]['listPrice'] ); // phpcs:ignore Universal.Operators.StrictComparisons
$pm->update_meta_data( '_eru_ppt_list_price', '80' );
$pm->save_meta_data();
$r = $build( $pm );
$check( '(c) list price is never below the sale price', 90 == $r['items'][0]['listPrice'] ); // phpcs:ignore Universal.Operators.StrictComparisons
Eru_PPT_Settings::update( array( 'list_multiplier' => '1' ) );

Eru_PPT_Settings::update( array( 'push_quantity' => 'zero' ) );
$r = $build( $p );
$check( '(c) push quantity "0" sends quantity 0', 0 === $r['items'][0]['quantity'] );
Eru_PPT_Settings::update( array( 'push_quantity' => 'stock' ) );
$r = $build( $p );
$check( '(c) push quantity "stock" sends the stock', 7 === $r['items'][0]['quantity'] );

// eru_ppt_item filter.
$f = fn( $item ) => array_merge( $item, array( 'title' => 'Demo Filtrelenmiş' ) );
add_filter( 'eru_ppt_item', $f );
$r = $build( $p );
remove_filter( 'eru_ppt_item', $f );
$check( '(c) eru_ppt_item filter can change an item', 'Demo Filtrelenmiş' === $r['items'][0]['title'] );

// ---------------------------------------------------------------------------------------------
// (f) Category resolution: deepest mapped WC category, metabox override wins.
// ---------------------------------------------------------------------------------------------
$pc = $simple( array( 'cats' => array( $parent_cat, $child_cat ) ) );
$c  = Eru_PPT_Categories::resolve( wc_get_product( $pc->get_id() ) );
$check( '(f) product in parent + child → deepest mapping (5001)', 5001 === $c['id'] && 'map' === $c['source'], wp_json_encode( $c ) );
Eru_PPT_Settings::update( array( 'category_map' => array( array( 'wc' => $parent_cat, 'ty' => 5000, 'name' => 'Demo Ev' ) ) ) );
$pd = $simple( array( 'cats' => array( $child_cat ) ) );
$c  = Eru_PPT_Categories::resolve( wc_get_product( $pd->get_id() ) );
$check( '(f) only the parent mapped → ancestor mapping used (5000)', 5000 === $c['id'], wp_json_encode( $c ) );
$pc->update_meta_data( '_eru_ppt_category_id', 5002 );
$pc->update_meta_data( '_eru_ppt_category_name', 'Demo Ev > Demo Termos' );
$pc->save_meta_data();
$c = Eru_PPT_Categories::resolve( wc_get_product( $pc->get_id() ) );
$check( '(f) metabox override beats the map (5002)', 5002 === $c['id'] && 'override' === $c['source'], wp_json_encode( $c ) );

// Category cache: one tree transient, search over leaves, bounded attribute cache.
$hits = Eru_PPT_Categories::search( 'termos' );
$check( '(f) category search finds the leaf by path (case-insensitive Turkish)', 1 === count( $hits ) && 5002 === $hits[0]['id'] );
$n = count( $reqs );
Eru_PPT_Categories::search( 'tiş' );
$check( '(f) tree is cached (no second HTTP call)', count( $reqs ) === $n );
$check( '(f) attributes are not fetched for unknown category ids', is_wp_error( Eru_PPT_Categories::attributes( 999999 ) ) && count( $reqs ) === $n );

// ---------------------------------------------------------------------------------------------
// (h) Bulk handler refuses users without manage_woocommerce.
// ---------------------------------------------------------------------------------------------
$queued = fn() => count( as_get_scheduled_actions( array( 'hook' => 'eru_ppt_push', 'group' => 'eru-ppt', 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
wp_set_current_user( 0 );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-posts' );
$url                  = Eru_PPT_Bulk::handle( 'edit.php?post_type=product', 'eru_ppt_push', array( $p->get_id() ) );
$check( '(h) bulk push without capability → refused, nothing queued', 0 === $queued() && false !== strpos( $url, 'eru_ppt_denied=1' ), $url );
$sub = wp_insert_user( array( 'user_login' => 'demo_ppt_sub', 'user_pass' => wp_generate_password(), 'user_email' => 'ppt-sub@example.com', 'role' => 'subscriber' ) );
if ( is_wp_error( $sub ) ) {
	$sub = get_user_by( 'login', 'demo_ppt_sub' )->ID;
}
wp_set_current_user( $sub );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-posts' );
$url                  = Eru_PPT_Bulk::handle( 'edit.php?post_type=product', 'eru_ppt_validate', array( $p->get_id() ) );
$check( '(h) bulk validate as subscriber → refused', false !== strpos( $url, 'eru_ppt_denied=1' ), $url );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sub );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
wp_set_current_user( $admin );
$_REQUEST['_wpnonce'] = 'bad';
$url                  = Eru_PPT_Bulk::handle( 'edit.php?post_type=product', 'eru_ppt_push', array( $p->get_id() ) );
$check( '(h) bad nonce → refused', 0 === $queued() && false !== strpos( $url, 'eru_ppt_denied=1' ), $url );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-posts' );
$url                  = Eru_PPT_Bulk::handle( 'edit.php?post_type=product', 'eru_ppt_push', array( $p->get_id(), $var->get_id() ) );
$check( '(h) admin + nonce → queued in Action Scheduler (no HTTP in the request)', 1 === $queued() && false !== strpos( $url, 'eru_ppt_queued=2' ), $url );
$n   = count( $reqs );
$url = Eru_PPT_Bulk::handle( 'edit.php?post_type=product', 'eru_ppt_validate', array( $p->get_id(), $p2->get_id() ) );
$res = get_transient( 'eru_ppt_bulk_' . $admin );
$check( '(h) bulk validate stores a per-user result (1 ok, 1 with errors)', is_array( $res ) && 2 === count( $res['rows'] ) && false !== strpos( $url, 'eru_ppt_validated=2' ), wp_json_encode( $res ) );
delete_transient( 'eru_ppt_bulk_' . $admin );
unset( $_REQUEST['_wpnonce'] );

// Disabled integration: bulk push refuses and does not queue.
as_unschedule_all_actions( 'eru_ppt_push', null, 'eru-ppt' );
Eru_PPT_Settings::update( array( 'enabled' => 'no' ) );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-posts' );
$url                  = Eru_PPT_Bulk::handle( 'edit.php?post_type=product', 'eru_ppt_push', array( $p->get_id() ) );
unset( $_REQUEST['_wpnonce'] );
$check( '(h) master switch off → bulk push queues nothing', 0 === $queued() && false !== strpos( $url, 'eru_ppt_off=1' ), $url );
Eru_PPT_Settings::update( array( 'enabled' => 'yes' ) );

// ---------------------------------------------------------------------------------------------
// (i) admin.js builds DOM with textContent / createElement only.
// ---------------------------------------------------------------------------------------------
$js = (string) file_get_contents( ERU_PPT_PATH . 'assets/admin.js' );
$check( '(i) admin.js has no innerHTML / outerHTML / insertAdjacentHTML / document.write / jQuery .html()', '' !== $js && 0 === preg_match( '/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\.html\s*\(/', $js ) );
$check( '(i) admin.js renders API strings through textContent', false !== strpos( $js, 'textContent' ) && false !== strpos( $js, 'createElement' ) );

$cleanup();
remove_filter( 'pre_http_request', $router, 10 );
echo "ALL PASS\n";
