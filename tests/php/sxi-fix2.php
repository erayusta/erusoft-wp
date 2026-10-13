<?php
/**
 * Supplier XML Importer — fix round 2: cross-process cancel, token locks, action budget, seen-set memo.
 *   wp eval-file tests/php/sxi-fix2.php
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
global $wpdb;
$sample = dirname( __DIR__, 2 ) . '/plugins/supplier-xml-importer-for-woocommerce/samples/demo-feed.xml';
$feeds  = array();
$drop   = function () use ( $wpdb ) {
	foreach ( (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND (meta_value LIKE 'DEMO-SKU-%' OR meta_value LIKE 'DEMO-OLD-%')" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
};
$new_feed = function ( array $over = array() ) use ( &$feeds, $sample ) {
	$feed               = Eru_SXI_Feeds::defaults();
	$feed['name']       = 'Demo Besleme';
	$feed['source']     = 'file';
	$feed['item_path']  = 'product';
	$feed['map']        = array( 'sku' => 'sku', 'name' => 'title', 'description' => '', 'price' => 'price', 'sale_price' => '', 'stock' => 'stock', 'images' => '', 'categories' => '', 'parent_sku' => '' );
	$feed['images']     = 0;
	$feed['new_status'] = 'publish';
	$feed               = array_replace_recursive( $feed, $over );
	$feed['file']       = Eru_SXI_Feeds::store_file( $sample, 'demo.xml', 'fix2' );
	return $feeds[]     = Eru_SXI_Feeds::save( $feed );
};
$drop();

// 1. A cancel written straight to the database (another process) survives the running step's save.
$f1 = $new_feed();
Eru_SXI_Runner::start( $f1, 'live' );
Eru_SXI_Runner::step( $f1 ); // staging
add_filter( 'eru_sxi_row', function ( $row ) use ( $f1, $wpdb ) {
	$name = 'eru_sxi_run_' . $f1;
	$s    = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
	$s['status'] = 'cancelled';
	$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $s ) ), array( 'option_name' => $name ) );
	return $row;
} );
Eru_SXI_Runner::step( $f1 );
remove_all_filters( 'eru_sxi_row' );
$db = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'eru_sxi_run_' . $f1 ) ) );
$check( 'cancel written directly to the DB is not overwritten by the step', 'cancelled' === $db['status'] );

// 2. Token locks.
$check( 'lock acquired', true === Eru_SXI_Runner::lock( 'tok' ) );
$check( 'second acquire refused', false === Eru_SXI_Runner::lock( 'tok' ) );
$wpdb->update( $wpdb->options, array( 'option_value' => 'someone-else|' . time() ), array( 'option_name' => 'eru_sxi_lock_tok' ) );
Eru_SXI_Runner::unlock( 'tok' );
$check( 'unlock does not delete a lock now owned by someone else', false !== $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name = 'eru_sxi_lock_tok'" ) );
$wpdb->delete( $wpdb->options, array( 'option_name' => 'eru_sxi_lock_tok' ) );
Eru_SXI_Runner::lock( 'tok2' );
Eru_SXI_Runner::unlock( 'tok2' );
$check( 'owner unlock removes its lock', null === $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name = 'eru_sxi_lock_tok2'" ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => 'eru_sxi_lock_old', 'option_value' => 'x|' . ( time() - 99999 ), 'autoload' => 'no' ) );
$check( 'expired token lock is taken over', true === Eru_SXI_Runner::lock( 'old' ) );
Eru_SXI_Runner::unlock( 'old' );

// 3. Action budget: do not start a step that would overshoot.
$drop();
$f3 = $new_feed();
add_filter( 'eru_sxi_batch_size', fn() => 1 );
add_filter( 'eru_sxi_action_budget', fn() => 1 );
add_filter( 'eru_sxi_row', function ( $row ) {
	usleep( 450000 );
	return $row;
} );
Eru_SXI_Runner::start( $f3, 'live', 'schedule' );
$t = microtime( true );
Eru_SXI_Runner::batch_action( $f3 );
$el = microtime( true ) - $t;
remove_all_filters( 'eru_sxi_row' );
remove_all_filters( 'eru_sxi_action_budget' );
remove_all_filters( 'eru_sxi_batch_size' );
$check( 'action stops before overshooting the budget (elapsed < 1.2 s)', $el < 1.2 );
as_unschedule_all_actions( 'eru_sxi_batch', array( $f3 ), 'eru-sxi' );

// 4. seen set is loaded once per finalizing run, not per page.
$drop();
$f4 = $new_feed( array( 'missing' => 'outofstock' ) );
for ( $i = 1; $i <= 120; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'Demo Eski ' . $i );
	$p->set_sku( 'DEMO-OLD-' . $i );
	$p->set_regular_price( '5' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 3 );
	$p->set_status( 'publish' );
	$p->save();
	update_post_meta( $p->get_id(), '_eru_sxi_feed', $f4 );
}
Eru_SXI_Runner::$seen_loads = 0;
$s = Eru_SXI_Runner::run_sync( $f4, 'live' );
$check( '120 missing handled across 3 pages', 120 === $s['counts']['missing'] );
$check( 'seen set read once for the whole run', 1 === Eru_SXI_Runner::$seen_loads );

$drop();
foreach ( $feeds as $id ) {
	Eru_SXI_Feeds::delete( $id );
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
