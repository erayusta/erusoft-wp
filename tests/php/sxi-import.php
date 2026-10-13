<?php
/**
 * Supplier XML Importer — mapping, create/update, dry run, price rules, missing handling, safe parsing, lock, schedule.
 *   wp eval-file tests/php/sxi-import.php   (plugin + WooCommerce active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_SXI_Runner' ) ) {
	echo "FAIL: class Eru_SXI_Runner missing (plugin not active?)\n";
	exit( 1 );
}

$sample  = dirname( __DIR__, 2 ) . '/plugins/supplier-xml-importer-for-woocommerce/samples/demo-feed.xml';
$tmpdir  = get_temp_dir();
$cleanup = array();
$feeds   = array();
$skus    = array( 'DEMO-SKU-1', 'DEMO-SKU-2', 'DEMO-SKU-3', 'DEMO-VAR-P', 'DEMO-VAR-1', 'DEMO-VAR-2' );
$by_sku  = fn( $sku ) => ( $id = wc_get_product_id_by_sku( $sku ) ) ? wc_get_product( $id ) : null;
$count   = function () use ( $skus ) {
	$n = 0;
	foreach ( $skus as $s ) {
		$n += wc_get_product_id_by_sku( $s ) ? 1 : 0;
	}
	return $n;
};
$drop_all = function () use ( $skus ) {
	foreach ( $skus as $s ) {
		$id = wc_get_product_id_by_sku( $s );
		if ( $id ) {
			$p = wc_get_product( $id );
			if ( $p && $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $c ) {
					wp_delete_post( $c, true );
				}
			}
			wp_delete_post( $id, true );
		}
	}
};
$drop_all();

$write_xml = function ( $xml ) use ( $tmpdir, &$cleanup ) {
	$f          = $tmpdir . 'eru-sxi-' . wp_generate_password( 8, false ) . '.xml';
	file_put_contents( $f, $xml );
	return $cleanup[] = $f;
};
$new_feed = function ( $file, array $over = array() ) use ( &$feeds ) {
	$feed                  = Eru_SXI_Feeds::defaults();
	$feed['name']          = 'Demo Besleme';
	$feed['source']        = 'file';
	$feed['item_path']     = 'product';
	$feed['map']           = array(
		'sku' => 'sku', 'name' => 'title', 'description' => 'description', 'price' => 'price',
		'sale_price' => '', 'stock' => 'stock', 'images' => 'images/image', 'categories' => 'category', 'parent_sku' => '',
	);
	$feed['attrs']         = "Renk=attributes/attribute[@name='Renk']\nBoyut=attributes/attribute[@name='Boyut']";
	$feed['images']        = 0; // Image URLs in the sample are fictional and never fetched.
	$feed['new_status']    = 'publish';
	$feed                  = array_replace_recursive( $feed, $over );
	$feed['id']            = '';
	$name                  = Eru_SXI_Feeds::store_file( $file, 'demo.xml', 'test' );
	$feed['file']          = is_wp_error( $name ) ? '' : $name;
	$id                    = Eru_SXI_Feeds::save( $feed );
	$feeds[]               = $id;
	return $id;
};
$set_file = function ( $feed_id, $file ) {
	$all = Eru_SXI_Feeds::all();
	Eru_SXI_Feeds::remove_file( $all[ $feed_id ]['file'] );
	$all[ $feed_id ]['file'] = Eru_SXI_Feeds::store_file( $file, 'demo.xml', 'test' );
	update_option( Eru_SXI_Feeds::OPTION, $all, false );
};

$fid = $new_feed( $sample );
$check( 'feed definition has no problems', array() === Eru_SXI_Feeds::problems( Eru_SXI_Feeds::get( $fid ) ) );

// 1. Dry run creates nothing.
$s = Eru_SXI_Runner::run_sync( $fid, 'dry' );
$check( 'dry run finishes', 'done' === $s['status'] );
$check( 'dry run plans 3 creations', 3 === $s['counts']['create'] && 3 === count( $s['preview'] ) );
$check( 'dry run created no products', 0 === $count() );
$check( 'dry run created no categories', ! term_exists( 'Demo Kategori', 'product_cat' ) );

// 2. Live import.
$s = Eru_SXI_Runner::run_sync( $fid, 'live' );
$check( 'live run: 3 created', 'done' === $s['status'] && 3 === $s['counts']['create'] && 0 === $s['counts']['update'] );
$p1 = $by_sku( 'DEMO-SKU-1' );
$p2 = $by_sku( 'DEMO-SKU-2' );
$p3 = $by_sku( 'DEMO-SKU-3' );
$check( 'SKU-1 name/price/stock', $p1 && 'Demo Ürün 1' === $p1->get_name() && '100.00' === wc_format_decimal( $p1->get_regular_price(), 2 ) && 12 === $p1->get_stock_quantity() );
$check( 'SKU-2 price "1.250,50" parsed to 1250.50 and stock 5', $p2 && 1250.5 === (float) $p2->get_regular_price() && 5 === $p2->get_stock_quantity() );
$check( 'SKU-3 stock 0 -> outofstock', $p3 && 0 === $p3->get_stock_quantity() && 'outofstock' === $p3->get_stock_status() );
$check( 'products follow the feed status setting', $p1 && 'publish' === $p1->get_status() );
$sub = get_term_by( 'name', 'Demo Alt Kategori', 'product_cat' );
$top = get_term_by( 'name', 'Demo Kategori', 'product_cat' );
$check( 'hierarchical category created', $sub && $top && (int) $sub->parent === (int) $top->term_id && in_array( (int) $sub->term_id, $p1->get_category_ids(), true ) );
$attrs = $p1->get_attributes();
$check( 'attributes imported', isset( $attrs['renk'] ) && array( 'Mavi' ) === $attrs['renk']->get_options() );

// 3. Same feed again: nothing to do, twice.
$s = Eru_SXI_Runner::run_sync( $fid, 'live' );
$check( 're-run: 0 created, 0 updated, 3 unchanged', 0 === $s['counts']['create'] && 0 === $s['counts']['update'] && 3 === $s['counts']['unchanged'] );

// 4. One changed price.
$changed = $write_xml( str_replace( '<price currency="TRY">59.90</price>', '<price currency="TRY">64.90</price>', file_get_contents( $sample ) ) );
$set_file( $fid, $changed );
$s = Eru_SXI_Runner::run_sync( $fid, 'dry' );
$check( 'dry run of changed feed: 1 update planned, product untouched', 1 === $s['counts']['update'] && '59.90' === wc_format_decimal( $by_sku( 'DEMO-SKU-3' )->get_regular_price(), 2 ) );
$s = Eru_SXI_Runner::run_sync( $fid, 'live' );
$check( 'changed price: 1 updated, 0 created, 2 unchanged', 1 === $s['counts']['update'] && 0 === $s['counts']['create'] && 2 === $s['counts']['unchanged'] );
$check( 'SKU-3 price now 64.90', 64.9 === (float) $by_sku( 'DEMO-SKU-3' )->get_regular_price() );

// 5. Price rules: +10 %, round to .90; stable on repeat.
$all                          = Eru_SXI_Feeds::all();
$all[ $fid ]['markup']        = 10;
$all[ $fid ]['rounding']      = '90';
update_option( Eru_SXI_Feeds::OPTION, $all, false );
$set_file( $fid, $sample );
Eru_SXI_Runner::run_sync( $fid, 'live' );
$check( 'markup 10 % + .90 rounding: 100 -> 109.90', '109.90' === wc_format_decimal( $by_sku( 'DEMO-SKU-1' )->get_regular_price(), 2 ) );
$s = Eru_SXI_Runner::run_sync( $fid, 'live' );
$check( 'rules are not applied twice on re-run', 0 === $s['counts']['update'] && '109.90' === wc_format_decimal( $by_sku( 'DEMO-SKU-1' )->get_regular_price(), 2 ) );
$check( 'rounding helper: 99 and int', 12.99 === Eru_SXI_Importer::apply_price_rules( 12.5, array_merge( Eru_SXI_Feeds::defaults(), array( 'rounding' => '99' ) ) ) && 13.0 === Eru_SXI_Importer::apply_price_rules( 12.6, array_merge( Eru_SXI_Feeds::defaults(), array( 'rounding' => 'int' ) ) ) );

// 6. Update only selected fields: stock only.
$all                              = Eru_SXI_Feeds::all();
$all[ $fid ]['markup']            = 0;
$all[ $fid ]['rounding']          = 'none';
$all[ $fid ]['update_fields']     = array( 'stock' );
update_option( Eru_SXI_Feeds::OPTION, $all, false );
$stock_changed = $write_xml( str_replace( array( '<stock>12</stock>', '<price currency="TRY">100.00</price>' ), array( '<stock>7</stock>', '<price currency="TRY">1.00</price>' ), file_get_contents( $sample ) ) );
$set_file( $fid, $stock_changed );
Eru_SXI_Runner::run_sync( $fid, 'live' );
$check( 'stock-only update: stock 7, price untouched', 7 === $by_sku( 'DEMO-SKU-1' )->get_stock_quantity() && '109.90' === wc_format_decimal( $by_sku( 'DEMO-SKU-1' )->get_regular_price(), 2 ) );

// 7. Missing products.
$all                          = Eru_SXI_Feeds::all();
$all[ $fid ]['update_fields'] = array_keys( Eru_SXI_Feeds::update_fields() );
$all[ $fid ]['missing']       = 'outofstock';
update_option( Eru_SXI_Feeds::OPTION, $all, false );
$two = $write_xml( preg_replace( '#<product>\s*<sku>DEMO-SKU-3</sku>.*?</product>#s', '', file_get_contents( $sample ) ) );
$set_file( $fid, $two );
$s = Eru_SXI_Runner::run_sync( $fid, 'dry' );
$check( 'dry run reports 1 missing and changes nothing', 1 === $s['counts']['missing'] && 'outofstock' !== $by_sku( 'DEMO-SKU-2' )->get_stock_status() );
$p3 = $by_sku( 'DEMO-SKU-3' );
$p3->set_stock_quantity( 5 );
$p3->set_stock_status( 'instock' );
$p3->save();
$s = Eru_SXI_Runner::run_sync( $fid, 'live' );
$p3 = $by_sku( 'DEMO-SKU-3' );
$check( 'missing -> out of stock', 1 === $s['counts']['missing'] && 0 === $p3->get_stock_quantity() && 'outofstock' === $p3->get_stock_status() );
$all                    = Eru_SXI_Feeds::all();
$all[ $fid ]['missing'] = 'draft';
update_option( Eru_SXI_Feeds::OPTION, $all, false );
Eru_SXI_Runner::run_sync( $fid, 'live' );
$check( 'missing -> draft; listed products untouched', 'draft' === $by_sku( 'DEMO-SKU-3' )->get_status() && 'publish' === $by_sku( 'DEMO-SKU-1' )->get_status() );

// 8. Wrong item path must not wipe the catalogue.
$all                      = Eru_SXI_Feeds::all();
$all[ $fid ]['item_path'] = 'nothing-here';
update_option( Eru_SXI_Feeds::OPTION, $all, false );
$s = Eru_SXI_Runner::run_sync( $fid, 'live' );
$check( 'no matching items -> run fails, nothing marked missing', 'failed' === $s['status'] && 0 === $s['counts']['missing'] && 'publish' === $by_sku( 'DEMO-SKU-1' )->get_status() );
$all[ $fid ]['item_path'] = 'catalog/product';
update_option( Eru_SXI_Feeds::OPTION, $all, false );
$s = Eru_SXI_Runner::run_sync( $fid, 'dry' );
$check( 'absolute item path "catalog/product" works', 'done' === $s['status'] && 2 === $s['counts']['create'] + $s['counts']['update'] + $s['counts']['unchanged'] + $s['counts']['skip'] );

// 9. Safe parsing.
$evil = $write_xml( '<?xml version="1.0"?><!DOCTYPE catalog [<!ENTITY x SYSTEM "file:///etc/passwd">]><catalog><product><sku>DEMO-SKU-1</sku><title>&x;</title><price>1</price></product></catalog>' );
$fe   = $new_feed( $evil );
$s    = Eru_SXI_Runner::run_sync( $fe, 'live' );
$check( 'DOCTYPE / external entity is rejected', 'failed' === $s['status'] && 0 === $s['counts']['update'] && false === strpos( $by_sku( 'DEMO-SKU-1' )->get_name(), 'root:' ) );
$laughs = $write_xml( '<?xml version="1.0"?><!DOCTYPE l [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;">]><catalog><product><sku>DEMO-SKU-1</sku><title>&b;</title></product></catalog>' );
$s = Eru_SXI_Runner::run_sync( $new_feed( $laughs ), 'dry' );
$check( 'entity expansion document is rejected', 'failed' === $s['status'] );
$broken = $write_xml( '<catalog><product><sku>DEMO-SKU-1</sku><title>x</product>' );
$s      = Eru_SXI_Runner::run_sync( $new_feed( $broken ), 'dry' );
$check( 'malformed XML fails cleanly', 'failed' === $s['status'] );
add_filter( 'eru_sxi_max_bytes', fn() => 200 );
$big = Eru_SXI_Feeds::store_file( $sample, 'a.xml', 't' );
remove_all_filters( 'eru_sxi_max_bytes' );
$check( 'oversize file refused at upload time', is_wp_error( $big ) && 'eru_sxi_size' === $big->get_error_code() );
add_filter( 'eru_sxi_max_bytes', fn() => 200 );
$r = Eru_SXI_Reader::parse( $sample, Eru_SXI_Feeds::get( $fid ), fn() => null );
remove_all_filters( 'eru_sxi_max_bytes' );
$check( 'size limit is filterable and enforced by the reader', is_wp_error( $r ) && 'eru_sxi_big' === $r->get_error_code() );
$not_xml = $tmpdir . 'eru-sxi-notxml.txt';
file_put_contents( $not_xml, 'hello' );
$cleanup[] = $not_xml;
$check( 'non-.xml upload refused', is_wp_error( Eru_SXI_Feeds::store_file( $not_xml, 'feed.txt', 't' ) ) );

// URL sources: internal / non-http hosts are blocked by wp_safe_remote_get / scheme check.
$fu = $new_feed( $sample, array( 'source' => 'url', 'url' => 'http://127.0.0.1/feed.xml' ) );
$s  = Eru_SXI_Runner::run_sync( $fu, 'dry' );
$check( 'feed URL pointing to an internal host is blocked', 'failed' === $s['status'] );
$r = Eru_SXI_Feeds::fetch( 'file:///etc/passwd', $tmpdir . 'eru-sxi-x.xml' );
$check( 'non-http scheme refused', is_wp_error( $r ) );

// 10. Lock / no overlap.
$fl = $new_feed( $sample );
$s  = Eru_SXI_Runner::start( $fl, 'dry' );
$check( 'start ok', is_array( $s ) );
$again = Eru_SXI_Runner::start( $fl, 'dry' );
$check( 'second start while running is refused', is_wp_error( $again ) && 'eru_sxi_running' === $again->get_error_code() );
Eru_SXI_Runner::lock( 'step_' . $fl );
$busy = Eru_SXI_Runner::step( $fl );
$check( 'step is skipped while the lock is held', ! empty( $busy['busy'] ) );
$check( 'lock cannot be taken twice', false === Eru_SXI_Runner::lock( 'step_' . $fl ) );
Eru_SXI_Runner::unlock( 'step_' . $fl );
Eru_SXI_Runner::cancel( $fl );
$check( 'cancel stops the run', 'cancelled' === Eru_SXI_Runner::state( $fl )['status'] );
update_option( 'eru_sxi_lock_stale', time() - 99999, false );
$check( 'stale lock is taken over', true === Eru_SXI_Runner::lock( 'stale' ) );
Eru_SXI_Runner::unlock( 'stale' );

// 11. Variations.
$var_xml = '<catalog>'
	. '<product><sku>DEMO-VAR-1</sku><parent>DEMO-VAR-P</parent><title>Demo Varyantlı</title><price>10</price><stock>4</stock><color>Mavi</color></product>'
	. '<product><sku>DEMO-VAR-2</sku><parent>DEMO-VAR-P</parent><title>Demo Varyantlı</title><price>12</price><stock>6</stock><color>Kırmızı</color></product>'
	. '</catalog>';
$fv = $new_feed(
	$write_xml( $var_xml ),
	array(
		'map'   => array( 'parent_sku' => 'parent', 'sku' => 'sku', 'name' => 'title', 'price' => 'price', 'stock' => 'stock', 'description' => '', 'images' => '', 'categories' => '' ),
		'attrs' => 'Renk=color',
	)
);
$s  = Eru_SXI_Runner::run_sync( $fv, 'live' );
$vp = $by_sku( 'DEMO-VAR-P' );
$v1 = $by_sku( 'DEMO-VAR-1' );
$check( 'variation import: variable parent + 2 variations', 2 === $s['counts']['create'] && $vp && $vp->is_type( 'variable' ) && 2 === count( $vp->get_children() ) );
$check( 'variation price/stock/attribute', $v1 && 10.0 === (float) $v1->get_regular_price() && 4 === $v1->get_stock_quantity() && array( 'renk' => 'Mavi' ) === $v1->get_attributes() );
$check( 'parent price range synced', 10.0 === (float) wc_get_product( $vp->get_id() )->get_variation_price( 'min' ) );
$s = Eru_SXI_Runner::run_sync( $fv, 'live' );
$check( 'variation re-run is a no-op', 0 === $s['counts']['create'] + $s['counts']['update'] && 2 === $s['counts']['unchanged'] );

// 12. Image de-duplication by source URL (no download happens).
$att = wp_insert_attachment( array( 'post_title' => 'Demo görsel', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), false );
update_post_meta( $att, Eru_SXI_Importer::META_SRC, 'https://example.com/images/demo-2.jpg' );
$fi = $new_feed( $sample, array( 'images' => 1, 'mode' => 'update_only', 'update_fields' => array( 'images' ) ) );
$s  = Eru_SXI_Runner::run_sync( $fi, 'live' );
$check( 'known source URL reuses the existing attachment', (int) $att === (int) $by_sku( 'DEMO-SKU-2' )->get_image_id() );
wp_delete_attachment( $att, true );

// 12b. A failed image download is retried after eru_sxi_image_retry_days (default 7), not on every run.
$ip   = new WC_Product_Simple();
$ip->set_name( 'Demo Görselsiz' );
$ip->save();
$urls = array( 'https://example.com/images/demo-yok.jpg' );
$hash = md5( implode( "\n", $urls ) );
$ip->update_meta_data( Eru_SXI_Importer::META_IMG_TRY, $hash . '|' . time() );
$check( 'recently failed image URL set is not retried', false === Eru_SXI_Importer::images_pending( $ip, $urls ) );
$ip->update_meta_data( Eru_SXI_Importer::META_IMG_TRY, $hash . '|' . ( time() - 8 * DAY_IN_SECONDS ) );
$check( 'failed image URL set is retried after 7 days', true === Eru_SXI_Importer::images_pending( $ip, $urls ) );
$ip->update_meta_data( Eru_SXI_Importer::META_IMG_TRY, $hash );
$check( 'legacy attempt record without a time is retried', true === Eru_SXI_Importer::images_pending( $ip, $urls ) );
$ip->update_meta_data( Eru_SXI_Importer::META_IMG_TRY, md5( 'baska' ) . '|' . time() );
$check( 'changed URL set is applied', true === Eru_SXI_Importer::images_pending( $ip, $urls ) );
$ip->delete( true );

// 13. Scheduling through Action Scheduler.
if ( function_exists( 'as_next_scheduled_action' ) ) {
	$all                    = Eru_SXI_Feeds::all();
	$all[ $fl ]['schedule'] = 'hourly';
	Eru_SXI_Feeds::save( $all[ $fl ] );
	$check( 'schedule creates a recurring action', false !== as_next_scheduled_action( 'eru_sxi_scheduled', array( $fl ), 'eru-sxi' ) );
	$all[ $fl ]['schedule'] = 'off';
	Eru_SXI_Feeds::save( $all[ $fl ] );
	$check( 'schedule off removes it', false === as_next_scheduled_action( 'eru_sxi_scheduled', array( $fl ), 'eru-sxi' ) );
}

// Clean up.
$drop_all();
foreach ( array( 'Demo Alt Kategori', 'Diğer', 'Demo Kategori' ) as $name ) {
	foreach ( (array) get_terms( array( 'taxonomy' => 'product_cat', 'name' => $name, 'hide_empty' => false, 'fields' => 'ids' ) ) as $tid ) {
		wp_delete_term( $tid, 'product_cat' );
	}
}
foreach ( $feeds as $id ) {
	Eru_SXI_Feeds::delete( $id );
}
foreach ( $cleanup as $f ) {
	if ( is_file( $f ) ) {
		unlink( $f );
	}
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
