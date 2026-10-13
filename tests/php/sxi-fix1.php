<?php
/**
 * Supplier XML Importer — fix round 1: Action Scheduler continuation, resume, create_only + missing,
 * >50 missing, image failure handling, cancel race, lock cleanup.
 *   wp eval-file tests/php/sxi-fix1.php
 */
$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
if ( ! class_exists( 'Eru_SXI_Runner' ) ) {
	echo "FAIL: plugin not active\n";
	exit( 1 );
}
$sample = dirname( __DIR__, 2 ) . '/plugins/supplier-xml-importer-for-woocommerce/samples/demo-feed.xml';
$feeds  = array();
$by_sku = fn( $sku ) => ( $id = wc_get_product_id_by_sku( $sku ) ) ? wc_get_product( $id ) : null;
$drop   = function () {
	foreach ( array( 'DEMO-SKU-1', 'DEMO-SKU-2', 'DEMO-SKU-3' ) as $s ) {
		$id = wc_get_product_id_by_sku( $s );
		if ( $id ) {
			wp_delete_post( $id, true );
		}
	}
	global $wpdb;
	foreach ( (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE 'DEMO-OLD-%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
};
$drop();
$new_feed = function ( array $over = array() ) use ( &$feeds, $sample ) {
	$feed                 = Eru_SXI_Feeds::defaults();
	$feed['name']         = 'Demo Besleme';
	$feed['source']       = 'file';
	$feed['item_path']    = 'product';
	$feed['map']          = array( 'sku' => 'sku', 'name' => 'title', 'description' => 'description', 'price' => 'price', 'sale_price' => '', 'stock' => 'stock', 'images' => 'images/image', 'categories' => '', 'parent_sku' => '' );
	$feed['images']       = 0;
	$feed['new_status']   = 'publish';
	$feed                 = array_replace_recursive( $feed, $over );
	$feed['file']         = Eru_SXI_Feeds::store_file( $sample, 'demo.xml', 'fix' );
	$id                   = Eru_SXI_Feeds::save( $feed );
	return $feeds[]       = $id;
};

// 1. Scheduled run continues through Action Scheduler (one step per action, batch size 1).
add_filter( 'eru_sxi_batch_size', fn() => 1 );
add_filter( 'eru_sxi_action_budget', fn() => 0 );
$f1 = $new_feed( array( 'schedule' => 'off' ) );
Eru_SXI_Runner::scheduled( $f1 ); // Feed schedule is off: must not start.
$check( 'scheduled handler ignores feeds with schedule off', null === Eru_SXI_Runner::state( $f1 ) );
$all                 = Eru_SXI_Feeds::all();
$all[ $f1 ]['schedule'] = 'daily';
Eru_SXI_Feeds::save( $all[ $f1 ] );
Eru_SXI_Runner::scheduled( $f1 );
$guard = 40;
while ( $guard-- && Eru_SXI_Runner::is_active( Eru_SXI_Runner::state( $f1 ) ) ) {
	ActionScheduler_QueueRunner::instance()->run();
}
$s = Eru_SXI_Runner::state( $f1 );
$check( 'scheduled run completes via Action Scheduler (3 created)', $s && 'done' === $s['status'] && 3 === $s['counts']['create'] );
remove_all_filters( 'eru_sxi_action_budget' );

// 2. Single action loops steps within its time budget.
$drop();
add_filter( 'eru_sxi_action_budget', fn() => 20 );
$f2 = $new_feed();
Eru_SXI_Runner::start( $f2, 'live', 'schedule' );
Eru_SXI_Runner::batch_action( $f2 );
$s = Eru_SXI_Runner::state( $f2 );
$check( 'one action drives the run to completion within the budget', 'done' === $s['status'] );
remove_all_filters( 'eru_sxi_action_budget' );

// 3. Resume at pos.
$drop();
$f3 = $new_feed();
Eru_SXI_Runner::start( $f3, 'live' );
Eru_SXI_Runner::step( $f3 );
$s = Eru_SXI_Runner::step( $f3 );
$check( 'after one batch: pos > 0 and 1 created', $s['pos'] > 0 && 1 === $s['counts']['create'] );
$s = Eru_SXI_Runner::step( $f3 );
$check( 'next request continues at pos (2 created)', 2 === $s['counts']['create'] );
while ( Eru_SXI_Runner::is_active( $s ) ) {
	$s = Eru_SXI_Runner::step( $f3 );
}
$check( 'resumed run ends with 3 created, no duplicates', 3 === $s['counts']['create'] && 'done' === $s['status'] );
remove_all_filters( 'eru_sxi_batch_size' );

// 4. create_only + missing must not touch products that are in the feed.
$drop();
$f4 = $new_feed( array( 'mode' => 'create_update', 'missing' => 'outofstock' ) );
Eru_SXI_Runner::run_sync( $f4, 'live' ); // Products now belong to this feed.
$all                 = Eru_SXI_Feeds::all();
$all[ $f4 ]['mode']  = 'create_only';
update_option( Eru_SXI_Feeds::OPTION, $all, false );
$s  = Eru_SXI_Runner::run_sync( $f4, 'live' );
$p1 = $by_sku( 'DEMO-SKU-1' );
$check( 'create_only run: in-feed products keep their stock, none counted missing', 0 === $s['counts']['missing'] && 12 === $p1->get_stock_quantity() && 'instock' === $p1->get_stock_status() );
$all[ $f4 ]['mode']    = 'update_only';
$all[ $f4 ]['map']['price'] = 'nope-price';
$all[ $f4 ]['missing'] = 'draft';
update_option( Eru_SXI_Feeds::OPTION, $all, false );
Eru_SXI_Runner::run_sync( $f4, 'live' );
$check( 'rows that skip/error are still seen: nothing drafted', 'publish' === $by_sku( 'DEMO-SKU-2' )->get_status() && 'publish' === $by_sku( 'DEMO-SKU-1' )->get_status() );

// 5. More than 50 missing products.
global $wpdb;
$f5 = $new_feed( array( 'missing' => 'outofstock' ) );
for ( $i = 1; $i <= 60; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'Demo Eski ' . $i );
	$p->set_sku( 'DEMO-OLD-' . $i );
	$p->set_regular_price( '5' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 3 );
	$p->set_status( 'publish' );
	$p->save();
	update_post_meta( $p->get_id(), '_eru_sxi_feed', $f5 );
}
$s = Eru_SXI_Runner::run_sync( $f5, 'live' );
$zero = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->postmeta} s ON s.post_id=m.post_id AND s.meta_key='_sku' AND s.meta_value LIKE 'DEMO-OLD-%' WHERE m.meta_key='_stock_status' AND m.meta_value='outofstock'" );
$check( '60 missing products are all handled across pages', 60 === $s['counts']['missing'] && 60 === $zero );

// 6. Image failure (internal host is refused) is recorded and not reported as an update forever.
$drop();
$xml = str_replace( 'https://example.com/images/demo-1.jpg', 'http://127.0.0.1/blocked.jpg', file_get_contents( $sample ) );
$tmp = get_temp_dir() . 'eru-sxi-img.xml';
file_put_contents( $tmp, $xml );
$f6                     = $new_feed( array( 'images' => 1, 'schedule' => 'off' ) );
$all                    = Eru_SXI_Feeds::all();
Eru_SXI_Feeds::remove_file( $all[ $f6 ]['file'] );
$all[ $f6 ]['file']     = Eru_SXI_Feeds::store_file( $tmp, 'demo.xml', 'fix' );
$all[ $f6 ]['map']['images'] = 'images/image[contains(., "127.0.0.1")]';
update_option( Eru_SXI_Feeds::OPTION, $all, false );
$s = Eru_SXI_Runner::run_sync( $f6, 'live' );
$check( 'image from internal host is refused, product still created', $s['counts']['create'] >= 1 && 0 === $s['counts']['error'] && ! $by_sku( 'DEMO-SKU-1' )->get_image_id() );
$s = Eru_SXI_Runner::run_sync( $f6, 'live' );
$check( 'failed image is not re-attempted as an update on every run', 0 === $s['counts']['update'] );
unlink( $tmp );

// 7. Cancel during an in-flight step must stick.
$drop();
$f7 = $new_feed();
Eru_SXI_Runner::start( $f7, 'live' );
Eru_SXI_Runner::step( $f7 ); // staging
add_filter( 'eru_sxi_row', function ( $row ) use ( $f7 ) {
	Eru_SXI_Runner::cancel( $f7 );
	return $row;
} );
Eru_SXI_Runner::step( $f7 );
remove_all_filters( 'eru_sxi_row' );
$check( 'cancel during a running step is not overwritten', 'cancelled' === Eru_SXI_Runner::state( $f7 )['status'] );

// 8. forget() clears the start/step locks.
Eru_SXI_Runner::lock( 'step_' . $f7 );
Eru_SXI_Runner::lock( 'start_' . $f7 );
Eru_SXI_Runner::forget( $f7 );
$check( 'forget() removes start and step locks', false === get_option( 'eru_sxi_lock_step_' . $f7 ) && false === get_option( 'eru_sxi_lock_start_' . $f7 ) );

$drop();
foreach ( $feeds as $id ) {
	Eru_SXI_Feeds::delete( $id );
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
