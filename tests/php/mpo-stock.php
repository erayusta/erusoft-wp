<?php
/**
 * Marketplace Orders for WooCommerce — stock bridge: rules math, exclusions and untracked stock (a), debounced
 * flush (b), import with "reduce" + stock push: one decrement, one push (c), plus observe mode, failure backoff,
 * a change during a push, listings refresh, the scheduler (inert default, pending-only re-arm) and the Trendyol
 * price-inventory / Hepsiburada listing calls (invented hosts, invented bodies).
 *
 *   wp eval-file tests/php/mpo-stock.php
 */

require __DIR__ . '/mpo-adapter-helpers.php';
mpo_require_plugin();
foreach ( array( 'Eru_MPO_Rules', 'Eru_MPO_Stock_Bridge', 'Eru_MPO_Adapter_Trendyol', 'Eru_MPO_Adapter_Hepsiburada' ) as $mpo_c ) {
	if ( ! class_exists( $mpo_c ) ) {
		echo "FAIL: {$mpo_c} missing\n";
		exit( 1 );
	}
}

/** Fake marketplace that records pushes and serves listing pages. */
class MPO_Bridge_Fake extends MPO_Fake_Adapter {
	/** @var array[] [kind, items] */
	public $pushes = array();
	/** @var array<int,array> Listing pages. */
	public $listing_pages = array();
	/** @var WP_Error|null Next push answer. */
	public $fail = null;
	/** @var callable|null Runs inside the next push. */
	public $during = null;
	/** @var int|null Listing page that answers an error. */
	public $listing_error_page = null;

	private function answer( $kind, array $items ) {
		$this->pushes[] = array( $kind, $items );
		if ( $this->during ) {
			$fn           = $this->during;
			$this->during = null;
			$fn();
		}
		if ( $this->fail ) {
			$e          = $this->fail;
			$this->fail = null;
			return $e;
		}
		return array(
			'ok'     => count( $items ),
			'failed' => array(),
		);
	}

	public function push_inventory( array $items ) {
		return $this->answer( 'stock', $items );
	}

	public function push_price( array $items ) {
		return $this->answer( 'price', $items );
	}

	public function fetch_listings( $page ) {
		if ( $page === $this->listing_error_page ) {
			return new WP_Error( 'eru_mpo_http', 'HTTP 503: Demo hata', array( 'retryable' => true ) );
		}
		return array(
			'items' => array_map( array( 'Eru_MPO_Listing_DTO', 'from_array' ), $this->listing_pages[ $page ] ?? array() ),
			'more'  => isset( $this->listing_pages[ $page + 1 ] ),
		);
	}
}

global $wpdb;
$router  = new MPO_Router();
$ty_host = 'https://api.demo-pazaryeri.invalid/integration';
$hb_host = 'https://oms.demo-pazaryeri.invalid';
$hb_list = 'https://listing.demo-pazaryeri.invalid';
add_filter( 'eru_mpo_trendyol_base_url', fn() => $ty_host );
add_filter( 'eru_mpo_hepsiburada_base_url', fn() => $hb_host );
$hb_list_on = true;
add_filter(
	'eru_mpo_hepsiburada_listing_url',
	function () use ( &$hb_list_on, $hb_list ) {
		return $hb_list_on ? $hb_list : '';
	}
);

$fake = new MPO_Bridge_Fake( 'demo' );
add_filter(
	'eru_mpo_adapters',
	function ( $a ) use ( $fake ) {
		$a['demo'] = $fake;
		return $a;
	}
);
Eru_MPO_Registry::reset();

$old       = array();
$opt_names = array( Eru_MPO_Settings::OPTION, 'eru_mpo_mp_demo', 'eru_mpo_mp_trendyol', 'eru_mpo_mp_hepsiburada' );
foreach ( $opt_names as $n ) {
	$old[ $n ] = get_option( $n, null );
}
$failed = false;
$tag    = mpo_run_tag();
$sku    = fn( $s ) => 'MPO-TEST-' . $tag . '-' . $s;
$terms  = array();
$t      = $wpdb->prefix . 'eru_mpo_';
$creds  = array(
	'seller_id'  => '1000',
	'api_key'    => 'demo-' . 'key-' . strtolower( $tag ),
	'api_secret' => 'demo-' . 'secret-' . strtolower( $tag ),
);
$hb_pw  = 'demo-' . 'pw-' . strtolower( $tag );

$queue = function ( $pid, $mp = 'demo', $kind = 'stock' ) use ( $wpdb, $t ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t}sync_queue WHERE product_id = %d AND marketplace = %s AND kind = %s", $pid, $mp, $kind ) ); // phpcs:ignore
};
$qcount = function ( $mp = 'demo' ) use ( $wpdb, $t ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t}sync_queue WHERE marketplace = %s", $mp ) ); // phpcs:ignore
};
$items_for = function ( $sku_value, $kind = 'stock' ) use ( $fake ) {
	$out = array();
	foreach ( $fake->pushes as $p ) {
		if ( $p[0] !== $kind ) {
			continue;
		}
		foreach ( $p[1] as $it ) {
			if ( ( $it['sku'] ?? '' ) === $sku_value ) {
				$out[] = $it;
			}
		}
	}
	return $out;
};
$flush_pending = fn( $mp = 'demo' ) => mpo_pending_args( 'eru_mpo_stock_flush', array( $mp ) );
$set_stock     = function ( $pid, $qty ) {
	wc_update_product_stock( wc_get_product( $pid ), $qty );
};

$cleanup = function () use ( &$terms, $old, $wpdb, $t ) {
	static $ran = false;
	if ( $ran ) {
		return;
	}
	$ran = true;
	mpo_reset();
	foreach ( array( 'trendyol', 'hepsiburada' ) as $mp ) {
		mpo_adapter_cleanup( $mp, 'mpo.test.stock@example.com' );
	}
	foreach ( array( 'demo', 'trendyol', 'hepsiburada' ) as $mp ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}sync_queue WHERE marketplace = %s", $mp ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}listings WHERE marketplace = %s AND wc_sku LIKE %s", $mp, 'MPO-TEST-%' ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}log WHERE marketplace = %s AND remote_ref = ''", $mp ) ); // phpcs:ignore
		foreach ( array( 'bridge', 'full', 'conn', 'listings' ) as $k ) {
			delete_option( 'eru_mpo_sync_' . $k . '_' . $mp );
		}
	}
	mpo_delete_products();
	foreach ( $terms as $tt ) {
		wp_delete_term( $tt[0], $tt[1] );
	}
	foreach ( $old as $n => $v ) {
		if ( null === $v ) {
			delete_option( $n );
		} else {
			update_option( $n, $v, false );
		}
	}
	Eru_MPO_Settings::reset_cache();
	Eru_MPO_Cron::unschedule_all();
};
register_shutdown_function( $cleanup );

try {
	mpo_reset();
	mpo_delete_products();
	foreach ( array( 'demo', 'trendyol', 'hepsiburada' ) as $mp ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}sync_queue WHERE marketplace = %s", $mp ) ); // phpcs:ignore
	}

	// (a) Rules math in minor units.
	$r = Eru_MPO_Rules::sanitize(
		array(
			'stock_floor'      => 5,
			'stock_ceiling'    => 50,
			'price_multiplier' => '1.15',
			'price_add'        => '5',
			'rounding'         => '90',
		)
	);
	mpo_check( '(a) stock 3 with floor 5 -> 0; 7 stays 7', 0 === Eru_MPO_Rules::stock_qty( 3, $r ) && 7 === Eru_MPO_Rules::stock_qty( 7, $r ) );
	mpo_check( '(a) ceiling 50 on 80 -> 50', 50 === Eru_MPO_Rules::stock_qty( 80, $r ) );
	mpo_check( '(a) 100 x 1.15 + 5 rounded to .90 -> 119.90', 11990 === Eru_MPO_Rules::price_minor( 10000, $r ) );
	mpo_check( '(a) rounding: .99 -> 119.99, none -> 120.00, 119.50 -> 119.90', 11999 === Eru_MPO_Rules::round_minor( 12000, '99' ) && 12000 === Eru_MPO_Rules::round_minor( 12000, 'none' ) && 11990 === Eru_MPO_Rules::round_minor( 11950, '90' ) );
	$r2 = Eru_MPO_Rules::sanitize( array( 'price_multiplier' => '1,1' ) );
	mpo_check( '(a) 19.99 x 1.1 = 21.99 exactly (no float drift), "1,1" accepted', 2199 === Eru_MPO_Rules::price_minor( 1999, $r2 ) && '1.1' === (string) $r2['price_multiplier'] );
	$bad = Eru_MPO_Rules::sanitize(
		array(
			'price_multiplier' => '-3',
			'stock_floor'      => '-2',
			'rounding'         => 'x',
			'excluded_skus'    => " A-1 \r\nB-2*\n\nA-1",
		)
	);
	mpo_check( '(a) sanitising: bad multiplier -> 1, negative floor -> 0, unknown rounding -> none, SKU list trimmed and unique', '1' === (string) $bad['price_multiplier'] && 0 === $bad['stock_floor'] && 'none' === $bad['rounding'] && array( 'A-1', 'B-2*' ) === $bad['excluded_skus'] );

	$cat     = wp_insert_term( 'MPO Test Kat ' . $tag, 'product_cat' );
	$terms[] = array( (int) $cat['term_id'], 'product_cat' );
	$child   = wp_insert_term( 'MPO Test Alt ' . $tag, 'product_cat', array( 'parent' => (int) $cat['term_id'] ) );
	$terms[] = array( (int) $child['term_id'], 'product_cat' );
	$p_ex    = mpo_product( $sku( 'EX' ), 10, '100' );
	$pe      = wc_get_product( $p_ex );
	$pe->set_category_ids( array( (int) $child['term_id'] ) );
	$pe->save();
	$p_sk  = mpo_product( $sku( 'SKUX-1' ), 10, '100' );
	$p_un  = mpo_product( $sku( 'UN' ), 0, '100' );
	$pu    = wc_get_product( $p_un );
	$pu->set_manage_stock( false );
	$pu->set_stock_status( 'instock' );
	$pu->save();
	$rx = Eru_MPO_Rules::sanitize(
		array(
			'excluded_cats' => array( (int) $cat['term_id'] ),
			'excluded_skus' => $sku( 'SKUX' ) . '*',
			'untracked_qty' => 7,
		)
	);
	mpo_check( '(a) a product in a child of an excluded category is excluded', Eru_MPO_Rules::is_excluded( wc_get_product( $p_ex ), $rx ) );
	mpo_check( '(a) excluded SKU pattern with * matches', Eru_MPO_Rules::is_excluded( wc_get_product( $p_sk ), $rx ) && ! Eru_MPO_Rules::is_excluded( wc_get_product( $p_un ), $rx ) );
	$par = new WC_Product_Variable();
	$par->set_name( 'Demo Varyantlı ' . $tag );
	$par->set_sku( $sku( 'PAR' ) );
	$par_id = $par->save();
	$var    = new WC_Product_Variation();
	$var->set_parent_id( $par_id );
	$var->set_sku( $sku( 'PAR-M' ) );
	$var->set_regular_price( '100' );
	$var_id = $var->save();
	mpo_check( '(a) an excluded parent SKU excludes its variations', Eru_MPO_Rules::is_excluded( wc_get_product( $var_id ), Eru_MPO_Rules::sanitize( array( 'excluded_skus' => $sku( 'PAR' ) ) ) ) && ! Eru_MPO_Rules::is_excluded( wc_get_product( $var_id ), Eru_MPO_Rules::sanitize( array( 'excluded_skus' => $sku( 'OTHER' ) ) ) ) );
	mpo_check( '(a) untracked in-stock product -> the untracked quantity (7)', 7 === Eru_MPO_Rules::stock_for( wc_get_product( $p_un ), $rx ) );
	$pu = wc_get_product( $p_un );
	$pu->set_stock_status( 'outofstock' );
	$pu->save();
	mpo_check( '(a) untracked out-of-stock product -> 0', 0 === Eru_MPO_Rules::stock_for( wc_get_product( $p_un ), $rx ) );
	if ( taxonomy_exists( 'product_brand' ) && 'product_brand' === Eru_MPO_Rules::brand_taxonomy() ) {
		$br      = wp_insert_term( 'MPO Test Marka ' . $tag, 'product_brand' );
		$terms[] = array( (int) $br['term_id'], 'product_brand' );
		wp_set_object_terms( $p_un, array( (int) $br['term_id'] ), 'product_brand' );
		mpo_check( '(a) excluded brand (product_brand) -> excluded', Eru_MPO_Rules::is_excluded( wc_get_product( $p_un ), Eru_MPO_Rules::sanitize( array( 'excluded_brands' => array( (int) $br['term_id'] ) ) ) ) );
	}
	$p_pr = mpo_product( $sku( 'PR' ), 10, '100' );
	$pp   = wc_get_product( $p_pr );
	$pp->set_sale_price( '80' );
	$pp->save();
	$prices = Eru_MPO_Rules::prices_for( wc_get_product( $p_pr ), $r );
	mpo_check( '(a) prices: sale 80 -> 96.90, list (regular 100) -> 119.90', is_array( $prices ) && 96.9 === $prices['price'] && 119.9 === $prices['list_price'] );
	$draft = wc_get_product( $p_pr );
	$draft->set_status( 'draft' );
	$draft->save();
	mpo_check( '(a) an unpublished product is pushed with stock 0', 0 === Eru_MPO_Rules::stock_for( wc_get_product( $p_pr ), $r ) );

	// Inert: master off, or both pushes off -> no queue row and no job.
	$p_b = mpo_product( $sku( 'B' ), 10, '149.90' );
	Eru_MPO_Settings::update( array( 'enabled' => 'no' ) );
	Eru_MPO_Settings::mp_update(
		'demo',
		array(
			'enabled'    => 'yes',
			'push_stock' => 'yes',
			'push_price' => 'no',
		)
	);
	$set_stock( $p_b, 9 );
	Eru_MPO_Stock_Bridge::ensure();
	mpo_check( 'inert: master switch off -> no queue row and no job of the group', 0 === $qcount() && array() === mpo_pending_group() );
	mpo_settings();
	Eru_MPO_Settings::mp_update(
		'demo',
		array(
			'push_stock' => 'no',
			'push_price' => 'no',
		)
	);
	$set_stock( $p_b, 9 );
	Eru_MPO_Stock_Bridge::ensure();
	mpo_check( 'inert: master on but both pushes off -> no queue row, no sweep or listings job', 0 === $qcount() && array() === mpo_pending( 'eru_mpo_stock_sweep' ) && array() === mpo_pending( 'eru_mpo_listings_refresh' ) );

	// Scheduler: one sweep and one listings chain while a push is on; removed when off.
	Eru_MPO_Settings::mp_update( 'demo', array( 'push_stock' => 'yes' ) );
	Eru_MPO_Stock_Bridge::ensure();
	Eru_MPO_Stock_Bridge::ensure();
	mpo_check( 'scheduler: one 10-minute sweep and one hourly listings refresh for demo (ensure twice adds nothing)', 1 === count( mpo_pending( 'eru_mpo_stock_sweep' ) ) && 1 === count( mpo_pending_args( 'eru_mpo_listings_refresh', array( 'demo' ) ) ) );
	$sw = mpo_pending( 'eru_mpo_stock_sweep' )[0];
	mpo_check( 'scheduler: the sweep is due in about 10 minutes', abs( mpo_action_time( $sw ) - ( time() + 600 ) ) <= 5 );
	ActionScheduler::store()->log_execution( $sw ); // The sweep is now "running".
	Eru_MPO_Stock_Bridge::sweep();
	$pend = mpo_pending( 'eru_mpo_stock_sweep' );
	ActionScheduler::store()->mark_complete( $sw );
	mpo_check( 'scheduler: a running sweep re-arms exactly one pending successor (pending-only check)', 1 === count( $pend ) && $pend[0] !== $sw );
	Eru_MPO_Settings::mp_update( 'demo', array( 'push_stock' => 'no' ) );
	Eru_MPO_Stock_Bridge::ensure();
	mpo_check( 'scheduler: both pushes off again -> sweep and listings chain removed', array() === mpo_pending( 'eru_mpo_stock_sweep' ) && array() === mpo_pending( 'eru_mpo_listings_refresh' ) );
	Eru_MPO_Settings::mp_update( 'demo', array( 'push_stock' => 'yes' ) );
	Eru_MPO_Stock_Bridge::ensure();

	// (a) Exclusions at the bridge: an excluded product is never queued nor pushed.
	Eru_MPO_Rules::update( 'demo', array( 'excluded_cats' => array( (int) $cat['term_id'] ) ) );
	$wpdb->query( "DELETE FROM {$t}sync_queue WHERE marketplace = 'demo'" ); // phpcs:ignore
	$set_stock( $p_ex, 4 );
	mpo_check( '(a) excluded category: no queue row on a stock change', null === $queue( $p_ex ) );
	Eru_MPO_Rules::update( 'demo', array( 'excluded_cats' => array() ) );
	$set_stock( $p_ex, 3 );
	Eru_MPO_Rules::update( 'demo', array( 'excluded_cats' => array( (int) $cat['term_id'] ) ) );
	$fake->pushes = array();
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	mpo_check( '(a) excluded after queueing: flush pushes nothing for it and drops the row', array() === $items_for( $sku( 'EX' ) ) && null === $queue( $p_ex ) );
	Eru_MPO_Rules::update( 'demo', array( 'excluded_cats' => array() ) );

	// (b) Debounce: five changes -> one row, one pending flush 15 s out, one push carrying the last value.
	$wpdb->query( "DELETE FROM {$t}sync_queue WHERE marketplace = 'demo'" ); // phpcs:ignore
	as_unschedule_all_actions( 'eru_mpo_stock_flush', array( 'demo' ), 'eru-mpo' );
	$fake->pushes = array();
	$first = array();
	foreach ( array( 8, 7, 6, 5, 4 ) as $q ) {
		$set_stock( $p_b, $q );
		$first = $first ? $first : $flush_pending();
	}
	$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t}sync_queue WHERE product_id = %d AND marketplace = 'demo'", $p_b ) ); // phpcs:ignore
	$fl   = $flush_pending();
	mpo_check( '(b) five stock changes -> one queue row (stock) and one pending flush', 1 === $rows && 1 === count( $fl ) );
	mpo_check( '(b) later changes join the pending flush of the first one (not pushed back)', $first === $fl );
	mpo_check( '(b) the flush is debounced 15 seconds', abs( mpo_action_time( $fl[0] ) - ( time() + 15 ) ) <= 3 );
	mpo_check( '(b) nothing was pushed inside the request', array() === $fake->pushes );
	mpo_run_action( $fl[0] );
	$its = $items_for( $sku( 'B' ) );
	mpo_check( '(b) running the flush -> one push with one item carrying the last stock (4)', 1 === count( $fake->pushes ) && 1 === count( $its ) && 4 === (int) $its[0]['quantity'] );
	mpo_check( '(b) the row is gone and no further flush is pending', null === $queue( $p_b ) && array() === $flush_pending() );

	// A change during the push keeps its row (and a flush) for the next round.
	$fake->pushes = array();
	$set_stock( $p_b, 6 );
	$fake->during = function () use ( $set_stock, $p_b ) {
		$set_stock( $p_b, 2 );
	};
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	mpo_check( 'a stock change during the push keeps its row and a pending flush', null !== $queue( $p_b ) && 1 === count( $flush_pending() ) );
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	$its = $items_for( $sku( 'B' ) );
	mpo_check( 'the next flush pushes the newer value (6, then 2)', 2 === count( $its ) && 6 === (int) $its[0]['quantity'] && 2 === (int) $its[1]['quantity'] && null === $queue( $p_b ) );

	// Failure: retryable error -> row kept with backoff, nothing re-sent inside it, then sent.
	$fake->pushes = array();
	as_unschedule_all_actions( 'eru_mpo_stock_flush', array( 'demo' ), 'eru-mpo' );
	$set_stock( $p_b, 3 );
	$fake->fail = new WP_Error( 'eru_mpo_http', 'HTTP 503: Demo hata', array( 'retryable' => true ) );
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	$row = $queue( $p_b );
	$fl  = $flush_pending();
	mpo_check( 'failure: row kept, one attempt, the error noted, retried about a minute later', $row && 1 === (int) $row->attempts && false !== strpos( (string) $row->last_error, 'Demo hata' ) && strtotime( $row->next_try_at . ' UTC' ) > time() && 1 === count( $fl ) && abs( mpo_action_time( $fl[0] ) - ( time() + 60 ) ) <= 5 );
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	mpo_check( 'failure: inside the backoff nothing is sent again', 1 === count( $fake->pushes ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$t}sync_queue SET next_try_at = %s WHERE product_id = %d", gmdate( 'Y-m-d H:i:s', time() - 5 ), $p_b ) ); // phpcs:ignore
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	mpo_check( 'failure: after the backoff the item is sent and the row removed', 2 === count( $fake->pushes ) && null === $queue( $p_b ) );

	// Observe mode: logged, never pushed.
	Eru_MPO_Settings::mp_update( 'demo', array( 'observe' => 'yes' ) );
	$fake->pushes = array();
	$since        = gmdate( 'Y-m-d H:i:s', time() - 1 );
	$set_stock( $p_b, 1 );
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	$obs = array_filter( mpo_log_rows( 'demo', $since ), fn( $l ) => false !== strpos( $l->message, 'gönderilirdi' ) );
	mpo_check( 'observe mode: no push, one "gönderilirdi" log row, queue emptied', array() === $fake->pushes && 1 === count( $obs ) && null === $queue( $p_b ) );
	Eru_MPO_Settings::mp_update( 'demo', array( 'observe' => 'no' ) );

	// Listings refresh: rows upserted and matched by SKU, stale rows dropped, push identity from the listing.
	$wpdb->insert( "{$t}listings", array( 'marketplace' => 'demo', 'wc_sku' => $sku( 'STALE' ), 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore
	$fake->listing_pages = array(
		0 => array(
			array(
				'wc_sku'     => $sku( 'B' ),
				'remote_sku' => 'DEMO-RS-' . $tag,
				'barcode'    => 'DEMO-BC-' . $tag,
				'remote_id'  => '9001',
				'salable'    => true,
				'stock'      => 1,
				'price'      => 149.9,
			),
		),
		1 => array(
			array(
				'wc_sku'  => $sku( 'NOPE' ),
				'barcode' => 'DEMO-BC2-' . $tag,
				'salable' => false,
			),
		),
	);
	$lr = Eru_MPO_Stock_Bridge::refresh_listings( 'demo' );
	$lb = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t}listings WHERE marketplace = 'demo' AND wc_sku = %s", $sku( 'B' ) ) ); // phpcs:ignore
	$ln = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t}listings WHERE marketplace = 'demo' AND wc_sku = %s", $sku( 'NOPE' ) ) ); // phpcs:ignore
	$ls = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t}listings WHERE marketplace = 'demo' AND wc_sku = %s", $sku( 'STALE' ) ) ); // phpcs:ignore
	mpo_check( 'listings: two pages read, rows stored (remote SKU, barcode, id, salable, stock, price)', 2 === (int) $lr['listings'] && $lb && (int) $lb->wc_product_id === $p_b && 'DEMO-BC-' . $tag === $lb->barcode && '9001' === $lb->remote_id && 1 === (int) $lb->salable && 1 === (int) $lb->remote_stock );
	mpo_check( 'listings: an unknown SKU is kept without a product, not salable; a stale row is dropped', $ln && 0 === (int) $ln->wc_product_id && 0 === (int) $ln->salable && null === $ls );
	$ri = Eru_MPO_Stock_Bridge::remote_item( wc_get_product( $p_b ), 'demo' );
	mpo_check( 'listings: the push identity uses the listing barcode', $sku( 'B' ) === $ri['sku'] && 'DEMO-BC-' . $tag === $ri['barcode'] );
	$lst = Eru_MPO_Stock_Bridge::state( 'listings', 'demo' );
	mpo_check( 'listings: a complete run is recorded (complete flag + completed_at)', ! empty( $lst['complete'] ) && 'complete' === ( $lst['status'] ?? '' ) && '' !== (string) ( $lst['completed_at'] ?? '' ) );

	// I2: after a COMPLETE refresh an unlisted product waits (row kept), the listed one is pushed.
	$p_nl         = mpo_product( $sku( 'NL' ), 10, '100' );
	$fake->pushes = array();
	$wpdb->query( "DELETE FROM {$t}sync_queue WHERE marketplace = 'demo'" ); // phpcs:ignore
	$set_stock( $p_nl, 7 );
	$set_stock( $p_b, 5 );
	$sl  = Eru_MPO_Stock_Bridge::flush( 'demo' );
	$rnl = $queue( $p_nl );
	mpo_check( 'complete listings: an unlisted product is not pushed, its row stays queued as waiting (not deleted, not failed)', array() === $items_for( $sku( 'NL' ) ) && 1 === count( $items_for( $sku( 'B' ) ) ) && $rnl && strtotime( $rnl->next_try_at . ' UTC' ) > time() + HOUR_IN_SECONDS && 0 === (int) $rnl->attempts && 0 === $sl['failed'] && 1 === Eru_MPO_Stock_Bridge::queue_counts( 'demo' )['unlisted'] );
	// The product gets listed: the next complete refresh releases the waiting row and the flush pushes it.
	$fake->listing_pages[1][] = array(
		'wc_sku'  => $sku( 'NL' ),
		'barcode' => 'DEMO-BC3-' . $tag,
	);
	Eru_MPO_Stock_Bridge::refresh_listings( 'demo' );
	$rnl = $queue( $p_nl );
	mpo_check( 'a complete refresh releases waiting rows (due again) and arms a flush', $rnl && null === $rnl->next_try_at && 1 <= count( $flush_pending() ) );
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	mpo_check( 'the released row is pushed once the product is listed', 1 === count( $items_for( $sku( 'NL' ) ) ) && 7 === (int) $items_for( $sku( 'NL' ) )[0]['quantity'] && null === $queue( $p_nl ) );

	// I2: a PARTIAL refresh (one page per run, a continuation pending) never makes the bridge skip.
	add_filter( 'eru_mpo_listings_pages_per_run', $one_page = fn() => 1 );
	$p_n2         = mpo_product( $sku( 'N2' ), 10, '100' );
	$fake->pushes = array();
	$lp           = Eru_MPO_Stock_Bridge::refresh_listings( 'demo' );
	$nx           = mpo_pending_args( 'eru_mpo_listings_next', array( 'demo', 1 ) );
	mpo_check( 'partial refresh: one page, not complete, a continuation is queued (no page cap)', 'continued' === $lp['result'] && empty( Eru_MPO_Stock_Bridge::state( 'listings', 'demo' )['complete'] ) && 1 === count( $nx ) );
	$set_stock( $p_n2, 6 );
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	mpo_check( 'partial refresh + stock change on an unlisted product: the update is sent, not dropped', 1 === count( $items_for( $sku( 'N2' ) ) ) && 6 === (int) $items_for( $sku( 'N2' ) )[0]['quantity'] );
	mpo_run_action( $nx[0] );
	mpo_check( 'the continuation finishes the run: complete again, nothing more pending', ! empty( Eru_MPO_Stock_Bridge::state( 'listings', 'demo' )['complete'] ) && array() === mpo_pending( 'eru_mpo_listings_next' ) );
	remove_filter( 'eru_mpo_listings_pages_per_run', $one_page );
	// An error mid-run leaves the run incomplete: no skip, and the earlier rows are not dropped.
	$fake->listing_error_page = 1;
	$le                       = Eru_MPO_Stock_Bridge::refresh_listings( 'demo' );
	$fake->listing_error_page = null;
	$fake->pushes             = array();
	$set_stock( $p_n2, 4 );
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	mpo_check( 'a refresh that fails mid-run is incomplete: rows kept, the unlisted product is still sent', 'error' === $le['result'] && empty( Eru_MPO_Stock_Bridge::state( 'listings', 'demo' )['complete'] ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t}listings WHERE marketplace = 'demo' AND wc_sku = %s", $sku( 'NOPE' ) ) ) && 1 === count( $items_for( $sku( 'N2' ) ) ) ); // phpcs:ignore

	// Permanent deletion cleans the product's queue and listing rows.
	$set_stock( $p_nl, 3 );
	$wpdb->update( "{$t}listings", array( 'wc_product_id' => $p_nl ), array( 'marketplace' => 'demo', 'wc_sku' => $sku( 'NL' ) ) ); // phpcs:ignore
	wc_get_product( $p_nl )->delete( true );
	mpo_check( 'permanent deletion removes the product\'s queue and listing rows', null === $queue( $p_nl ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t}listings WHERE wc_product_id = %d", $p_nl ) ) ); // phpcs:ignore

	$wpdb->query( "DELETE FROM {$t}listings WHERE marketplace = 'demo'" ); // phpcs:ignore -- no listings again: everything is tried.
	delete_option( 'eru_mpo_sync_listings_demo' );
	$fake->pushes = array();

	// (c) Import with "reduce" + stock push: WooCommerce stock reduced once, the push carries the new stock once.
	$p_c = mpo_product( $sku( 'C' ), 10, '149.90' );
	mpo_settings( array( 'stock_mode' => 'reduce' ) );
	$wpdb->query( "DELETE FROM {$t}sync_queue WHERE marketplace = 'demo'" ); // phpcs:ignore
	as_unschedule_all_actions( 'eru_mpo_stock_flush', array( 'demo' ), 'eru-mpo' );
	$fake->pushes = array();
	$ref_c        = 'DEMO-PKG-' . $tag . '-C';
	$dto_c        = fn( $status ) => mpo_dto(
		'order-basic.json',
		array(
			'{{REF}}'    => $ref_c,
			'{{SKU}}'    => $sku( 'C' ),
			'{{STATUS}}' => $status,
			'{{TCKN}}'   => mpo_fake_tckn(),
		)
	);
	$ic = Eru_MPO_Importer::import( $fake, $dto_c( 'new' ) );
	mpo_check( '(c) imported in reduce mode: WooCommerce stock 10 -> 8, one queue row', 'created' === $ic['result'] && 8 === mpo_stock( $p_c ) && null !== $queue( $p_c ) && 1 === count( $flush_pending() ) );
	mpo_run_action( $flush_pending()[0] );
	mpo_check( '(c) one push for the product, carrying 8', 1 === count( $items_for( $sku( 'C' ) ) ) && 8 === (int) $items_for( $sku( 'C' ) )[0]['quantity'] );
	$u1 = Eru_MPO_Importer::import( $fake, $dto_c( 'new' ) );
	$u2 = Eru_MPO_Importer::import( $fake, $dto_c( 'shipped' ) );
	mpo_check( '(c) re-import and a status update: stock still 8, no new queue row, no flush, still one push', 'unchanged' === $u1['result'] && 'updated' === $u2['result'] && 8 === mpo_stock( $p_c ) && null === $queue( $p_c ) && array() === $flush_pending() && 1 === count( $items_for( $sku( 'C' ) ) ) );
	$warn = implode( ' ', Eru_MPO_Stock_Bridge::warnings() );
	mpo_check( '(c) P9: "reduce" + stock push on -> double-decrement warning naming the marketplace', false !== strpos( $warn, 'Demo Pazaryeri' ) && false !== stripos( $warn, 'çift' ) );
	mpo_settings( array( 'stock_mode' => 'leave' ) );
	$warn = implode( ' ', Eru_MPO_Stock_Bridge::warnings() );
	mpo_check( '(c) P9: "leave" + stock push on -> warning that pushes overwrite the marketplace\'s own decrement', false !== strpos( $warn, 'Demo Pazaryeri' ) && false !== stripos( $warn, 'WooCommerce stoğu' ) );
	mpo_settings( array( 'stock_mode' => 'reduce' ) );
	$u3 = Eru_MPO_Importer::import( $fake, $dto_c( 'cancelled' ) );
	Eru_MPO_Stock_Bridge::flush( 'demo' );
	$its = $items_for( $sku( 'C' ) );
	mpo_check( '(c) cancel restores 10 once and that value is pushed once', 'updated' === $u3['result'] && 10 === mpo_stock( $p_c ) && 2 === count( $its ) && 10 === (int) $its[1]['quantity'] );
	mpo_settings();

	// Trendyol price-inventory and approved products (invented host and bodies).
	update_option(
		'eru_mpo_mp_trendyol',
		array(
			'enabled'    => 'no',
			'seller_id'  => $creds['seller_id'],
			'api_key'    => $creds['api_key'],
			'api_secret' => $creds['api_secret'],
		),
		false
	);
	Eru_MPO_Settings::reset_cache();
	$ty     = Eru_MPO_Registry::get( 'trendyol' );
	$bodies = array();
	$router->on(
		'POST',
		'#^' . preg_quote( $ty_host, '#' ) . '/inventory/sellers/1000/products/price-and-inventory$#',
		function ( $req ) use ( &$bodies ) {
			$bodies[] = json_decode( (string) $req['body'], true );
			return mpo_resp( 200, array( 'batchRequestId' => 'demo-batch-' . count( $bodies ) ) );
		}
	);
	$res = $ty->push_inventory(
		array(
			array(
				'sku'      => 'S1',
				'barcode'  => 'DEMO-BC-1',
				'quantity' => 3,
			),
			array(
				'sku'      => 'S2',
				'barcode'  => '',
				'quantity' => 1,
			),
		)
	);
	mpo_check( 'trendyol: inventory body {items:[{barcode, quantity}]}, an item without barcode fails locally', is_array( $res ) && 1 === $res['ok'] && isset( $res['failed']['S2'] ) && array( 'items' => array( array( 'barcode' => 'DEMO-BC-1', 'quantity' => 3 ) ) ) === $bodies[0] );
	$res = $ty->push_price(
		array(
			array(
				'sku'        => 'S1',
				'barcode'    => 'DEMO-BC-1',
				'price'      => 96.9,
				'list_price' => 119.9,
			),
		)
	);
	mpo_check( 'trendyol: price body {items:[{barcode, salePrice, listPrice}]}', is_array( $res ) && 1 === $res['ok'] && array( 'items' => array( array( 'barcode' => 'DEMO-BC-1', 'salePrice' => 96.9, 'listPrice' => 119.9 ) ) ) === $bodies[1] );
	$last = end( $router->calls );
	mpo_check( 'trendyol: Basic auth and User-Agent in headers, no credential in the URL', 0 === strpos( (string) ( $last['headers']['Authorization'] ?? '' ), 'Basic ' ) && '1000 - SelfIntegration' === ( $last['headers']['User-Agent'] ?? '' ) && false === strpos( $last['url'], $creds['api_key'] ) );
	$router->on( 'POST', '#/price-and-inventory$#', fn() => mpo_resp( 503, array( 'errors' => array( array( 'message' => 'Demo hata' ) ) ) ) );
	$e = $ty->push_inventory(
		array(
			array(
				'sku'      => 'S1',
				'barcode'  => 'DEMO-BC-1',
				'quantity' => 1,
			),
		)
	);
	mpo_check( 'trendyol: 503 -> retryable error', is_wp_error( $e ) && Eru_MPO_Http::is_retryable( $e ) );
	$router->on(
		'GET',
		'#^' . preg_quote( $ty_host, '#' ) . '/product/sellers/1000/products/approved\?#',
		function ( $req ) use ( $tag ) {
			$p = (int) ( $req['query']['page'] ?? 0 );
			if ( 0 === $p ) {
				return mpo_resp(
					200,
					array(
						'page'       => 0,
						'totalPages' => 2,
						'content'    => array(
							array(
								'contentId' => 5001,
								'variants'  => array(
									array(
										'barcode'   => 'DEMO-BC-V1',
										'stockCode' => 'MPO-TEST-' . $tag . '-V1',
										'quantity'  => 4,
										'salePrice' => 99.9,
										'onSale'    => true,
									),
									array(
										'barcode'   => 'DEMO-BC-V2',
										'stockCode' => 'MPO-TEST-' . $tag . '-V2',
										'quantity'  => 0,
										'salePrice' => 99.9,
										'archived'  => true,
									),
								),
							),
						),
					)
				);
			}
			return mpo_resp(
				200,
				array(
					'page'       => 1,
					'totalPages' => 2,
					'content'    => array(
						array(
							'contentId' => 5002,
							'barcode'   => 'DEMO-BC-P1',
							'stockCode' => 'MPO-TEST-' . $tag . '-P1',
							'quantity'  => 2,
							'salePrice' => 49.9,
						),
					),
				)
			);
		}
	);
	$l0 = $ty->fetch_listings( 0 );
	$l1 = $ty->fetch_listings( 1 );
	mpo_check( 'trendyol: approved products page 0 has two variants (archived one not salable), more pages', is_array( $l0 ) && true === $l0['more'] && 2 === count( $l0['items'] ) && 'DEMO-BC-V1' === $l0['items'][0]->barcode && 'MPO-TEST-' . $tag . '-V1' === $l0['items'][0]->wc_sku && 4 === $l0['items'][0]->stock && true === $l0['items'][0]->salable && false === $l0['items'][1]->salable && '5001' === $l0['items'][0]->remote_id );
	mpo_check( 'trendyol: last page without variants -> one listing, no more', is_array( $l1 ) && false === $l1['more'] && 1 === count( $l1['items'] ) && 49.9 === $l1['items'][0]->price );

	// Trendyol through the bridge: listing barcode, rules on the price, one POST each.
	$router->on(
		'POST',
		'#^' . preg_quote( $ty_host, '#' ) . '/inventory/sellers/1000/products/price-and-inventory$#',
		function ( $req ) use ( &$bodies ) {
			$bodies[] = json_decode( (string) $req['body'], true );
			return mpo_resp( 200, array( 'batchRequestId' => 'demo-batch-' . count( $bodies ) ) );
		}
	);
	$prod_ty = mpo_product( $sku( 'T' ), 10, '100' );
	$wpdb->insert( "{$t}listings", array( 'marketplace' => 'trendyol', 'wc_product_id' => $prod_ty, 'wc_sku' => $sku( 'T' ), 'barcode' => 'DEMO-BC-T-' . $tag, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
	Eru_MPO_Settings::mp_update(
		'trendyol',
		array(
			'push_stock' => 'yes',
			'push_price' => 'yes',
		)
	);
	Eru_MPO_Rules::update(
		'trendyol',
		array(
			'price_multiplier' => '1.15',
			'price_add'        => '5',
			'rounding'         => '90',
		)
	);
	$bodies = array();
	$set_stock( $prod_ty, 6 );
	$ty_prod = wc_get_product( $prod_ty );
	$ty_prod->set_regular_price( '200' );
	$ty_prod->save();
	$sum = Eru_MPO_Stock_Bridge::flush( 'trendyol' );
	$inv = array_values( array_filter( $bodies, fn( $b ) => isset( $b['items'][0]['quantity'] ) ) );
	$prc = array_values( array_filter( $bodies, fn( $b ) => isset( $b['items'][0]['salePrice'] ) ) );
	mpo_check( 'bridge -> trendyol: one inventory POST with the listing barcode and 6', 1 === count( $inv ) && array( array( 'barcode' => 'DEMO-BC-T-' . $tag, 'quantity' => 6 ) ) === $inv[0]['items'] );
	mpo_check( 'bridge -> trendyol: one price POST, 200 x 1.15 + 5 = 235 -> 234.90 (sale and list)', 1 === count( $prc ) && 234.9 === $prc[0]['items'][0]['salePrice'] && 234.9 === $prc[0]['items'][0]['listPrice'] && 0 === $qcount( 'trendyol' ) );

	// Hepsiburada listing calls: listing host required, stock-uploads / price-uploads bodies, listings page.
	update_option(
		'eru_mpo_mp_hepsiburada',
		array(
			'enabled'      => 'no',
			'merchant_id'  => 'demo-merchant-0001',
			'password'     => $hb_pw,
			'dev_username' => 'demo-dev',
		),
		false
	);
	Eru_MPO_Settings::reset_cache();
	$hb         = Eru_MPO_Registry::get( 'hepsiburada' );
	$hb_list_on = false;
	$n0         = count( $router->calls );
	$e          = $hb->push_inventory(
		array(
			array(
				'sku'      => 'S1',
				'barcode'  => '',
				'quantity' => 2,
			),
		)
	);
	mpo_check( 'hepsiburada: without a listing API address -> not configured, no request', is_wp_error( $e ) && 'eru_mpo_not_configured' === $e->get_error_code() && count( $router->calls ) === $n0 );
	Eru_MPO_Settings::mp_update( 'hepsiburada', array( 'push_stock' => 'yes' ) );
	$off_without = ! Eru_MPO_Stock_Bridge::active( 'hepsiburada' );
	$hb_list_on  = true;
	$on_with     = Eru_MPO_Stock_Bridge::active( 'hepsiburada' );
	$hb_list_on  = false;
	Eru_MPO_Settings::mp_update( 'hepsiburada', array( 'push_stock' => 'no' ) );
	mpo_check( 'hepsiburada: the bridge stays off (nothing queued) until the listing API address is set', $off_without && $on_with );
	$hb_list_on = true;
	$hbodies    = array();
	$router->on(
		'POST',
		'#^' . preg_quote( $hb_list, '#' ) . '/listings/merchantid/demo-merchant-0001/(stock|price)-uploads$#',
		function ( $req ) use ( &$hbodies ) {
			preg_match( '#/(stock|price)-uploads$#', $req['url'], $m );
			$hbodies[ $m[1] ] = json_decode( (string) $req['body'], true );
			return mpo_resp( 200, array( 'id' => 'demo-track-1' ) );
		}
	);
	$r1 = $hb->push_inventory(
		array(
			array(
				'sku'      => 'S1',
				'barcode'  => '',
				'quantity' => 2,
			),
		)
	);
	$r2 = $hb->push_price(
		array(
			array(
				'sku'        => 'S1',
				'barcode'    => '',
				'price'      => 96.9,
				'list_price' => 119.9,
			),
		)
	);
	mpo_check( 'hepsiburada: stock-uploads [{merchantSku, availableStock}] and price-uploads [{merchantSku, price}]', is_array( $r1 ) && is_array( $r2 ) && 1 === $r1['ok'] && array( array( 'merchantSku' => 'S1', 'availableStock' => 2 ) ) === $hbodies['stock'] && array( array( 'merchantSku' => 'S1', 'price' => 96.9 ) ) === $hbodies['price'] );
	$hlast = end( $router->calls );
	mpo_check( 'hepsiburada: Basic auth and the developer username as User-Agent', 0 === strpos( (string) ( $hlast['headers']['Authorization'] ?? '' ), 'Basic ' ) && 'demo-dev' === ( $hlast['headers']['User-Agent'] ?? '' ) && false === strpos( $hlast['url'], $hb_pw ) );
	$router->on(
		'GET',
		'#^' . preg_quote( $hb_list, '#' ) . '/listings/merchantid/demo-merchant-0001\?#',
		fn( $req ) => mpo_resp(
			200,
			array(
				'totalCount' => 1,
				'listings'   => array(
					array(
						'merchantSku'    => 'MPO-TEST-' . $tag . '-H1',
						'hepsiburadaSku' => 'DEMOHB' . $tag,
						'isSalable'      => true,
						'availableStock' => 3,
						'price'          => 59.9,
					),
				),
			)
		)
	);
	$hl = $hb->fetch_listings( 0 );
	mpo_check( 'hepsiburada: listings page -> merchantSku, hepsiburadaSku, salable, stock, price; no more', is_array( $hl ) && false === $hl['more'] && 1 === count( $hl['items'] ) && 'MPO-TEST-' . $tag . '-H1' === $hl['items'][0]->wc_sku && 'DEMOHB' . $tag === $hl['items'][0]->remote_sku && 3 === $hl['items'][0]->stock && 59.9 === $hl['items'][0]->price );
	$lq = end( $router->calls )['query'];
	mpo_check( 'hepsiburada: listings read with offset/limit', '0' === (string) ( $lq['offset'] ?? '' ) && (int) ( $lq['limit'] ?? 0 ) > 0 );
} catch ( Throwable $e ) {
	$failed = true;
	if ( 'mpo-helpers.php' !== basename( $e->getFile() ) ) { // mpo_check() already printed its FAIL line.
		echo 'FAIL: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	$cleanup();
}
if ( $failed ) {
	exit( 1 );
}
echo "ALL PASS\n";
