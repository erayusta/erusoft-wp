<?php
/**
 * Marketplace Orders for WooCommerce — importer: one order per package (a), lock and pending map row (b),
 * isolated save and hook restore (c), stale map after a deleted order (d), unmatched lines (e), stock once and
 * restore on cancel (f), observe mode and master switch (h), raw payload retention and identity number (i).
 *
 *   wp eval-file tests/php/mpo-importer.php
 */

require __DIR__ . '/mpo-helpers.php';
mpo_require_plugin();
mpo_no_http();

global $wpdb, $wp_filter;
$old_settings = get_option( Eru_MPO_Settings::OPTION, null );
$old_mp       = get_option( 'eru_mpo_mp_demo', null );
$failed       = false;
$tag          = mpo_run_tag();
$sku          = 'MPO-TEST-' . $tag;
$pid          = 0;
$rate_id      = 0;
$extra_hooks  = array();
$mail         = mpo_mail_trap();
$fake         = mpo_fake_adapter();
$imported     = array();
$advanced     = array();
$cancelled    = array();
add_action(
	'eru_mpo_order_imported',
	function ( $order, $dto ) use ( &$imported ) {
		$imported[] = array( $order->get_id(), $dto->remote_ref );
	},
	10,
	2
);
add_action(
	'eru_mpo_order_status_advanced',
	function ( $order, $from, $to ) use ( &$advanced ) {
		$advanced[] = array( $order->get_id(), $from, $to );
	},
	10,
	3
);
add_action(
	'eru_mpo_order_cancelled_lines',
	function ( $order, $lines ) use ( &$cancelled ) {
		$cancelled[] = array( $order->get_id(), $lines );
	},
	10,
	2
);

/** DTO for a reference. */
$dto = function ( $ref, $status = 'new', array $over = array(), $fixture = 'order-basic.json', $tckn = '' ) use ( $sku ) {
	return mpo_dto(
		$fixture,
		array(
			'{{REF}}'    => $ref,
			'{{SKU}}'    => $sku,
			'{{STATUS}}' => $status,
			'{{TCKN}}'   => '' !== $tckn ? $tckn : mpo_fake_tckn(),
		),
		$over
	);
};
$ref = fn( $s ) => 'DEMO-PKG-' . $tag . '-' . $s;

// Clean-up runs once: in finally, and from a shutdown function after an exit() or a fatal error.
$final_cleanup = function () use ( &$extra_hooks, &$rate_id, $old_settings, $old_mp ) {
	global $wpdb;
	static $ran = false;
	if ( $ran ) {
		return;
	}
	$ran = true;
	foreach ( $extra_hooks as $h ) {
		remove_action( $h[0], $h[1] );
	}
	mpo_reset();
	mpo_delete_products();
	if ( $rate_id ) {
		WC_Tax::_delete_tax_rate( $rate_id );
	}
	if ( null === $old_settings ) {
		delete_option( Eru_MPO_Settings::OPTION );
	} else {
		update_option( Eru_MPO_Settings::OPTION, $old_settings, false );
	}
	if ( null === $old_mp ) {
		delete_option( 'eru_mpo_mp_demo' );
	} else {
		update_option( 'eru_mpo_mp_demo', $old_mp, false );
	}
	Eru_MPO_Settings::reset_cache();
};
register_shutdown_function( $final_cleanup );

try {
	mpo_reset();
	mpo_delete_products();
	mpo_settings();
	$pid     = mpo_product( $sku, 10 );
	$rate_id = mpo_tax_rate( 20 );

	// (a) Same DTO twice -> one order; the second run only updates the status.
	$ra = $ref( 'A' );
	$r1 = Eru_MPO_Importer::import( $fake, $dto( $ra ) );
	mpo_check( '(a) first import creates an order', 'created' === $r1['result'] && $r1['order_id'] > 0 );
	$o = wc_get_order( $r1['order_id'] );
	mpo_check( '(a) created_via eru-mpo-demo and the three reference meta', 'eru-mpo-demo' === $o->get_created_via() && 'demo' === $o->get_meta( '_eru_mpo_marketplace' ) && $ra === $o->get_meta( '_eru_mpo_remote_ref' ) && $ra === $o->get_meta( '_eru_mpo_package_id' ) );
	mpo_check( '(a) own status eru-mpo-new, logical status meta new', 'eru-mpo-new' === $o->get_status() && 'new' === $o->get_meta( '_eru_mpo_status' ) );
	$items = array_values( $o->get_items() );
	mpo_check( '(a) one product line matched by SKU, qty 2', 1 === count( $items ) && $pid === $items[0]->get_product_id() && 2 === (int) $items[0]->get_quantity() );
	$line_gross = round( (float) $items[0]->get_total() + (float) $items[0]->get_total_tax(), 2 );
	mpo_check( '(a) line split into net + VAT at the store rate (249.83 + 49.97 = 299.80)', abs( $line_gross - 299.80 ) < 0.001 && abs( (float) $items[0]->get_total_tax() - 49.97 ) < 0.001 );
	$tax_items = array_values( $o->get_items( 'tax' ) );
	mpo_check( '(a) one tax line for the matched rate and cart tax 49.97', 1 === count( $tax_items ) && $rate_id === (int) $tax_items[0]->get_rate_id() && abs( (float) $o->get_cart_tax() - 49.97 ) < 0.001 );
	mpo_check( '(a) order total is the marketplace total 299.80', abs( (float) $o->get_total() - 299.80 ) < 0.001 );
	mpo_check( '(a) TR state from the il name (Ankara -> TR06, lower-case "izmir" -> TR35), ilçe as city', 'TR06' === $o->get_billing_state() && 'Çankaya' === $o->get_billing_city() && 'TR35' === $o->get_shipping_state() );
	mpo_check( '(a) commission kept as line meta (12.5 % of 299.80 = 37.48)', '12.5' === (string) $items[0]->get_meta( '_eru_mpo_commission_rate' ) && '37.48' === (string) $items[0]->get_meta( '_eru_mpo_commission_amount' ) );
	$row = mpo_map_row( $ra );
	mpo_check( '(a) map row done with the order id', $row && 'done' === $row->status && (int) $row->wc_order_id === $r1['order_id'] );
	$r2 = Eru_MPO_Importer::import( $fake, $dto( $ra ) );
	mpo_check( '(a) same DTO again -> unchanged, same order', 'unchanged' === $r2['result'] && $r2['order_id'] === $r1['order_id'] && array( $r1['order_id'] ) === mpo_order_ids( $ra ) );
	$r3 = Eru_MPO_Importer::import( $fake, $dto( $ra, 'shipped' ) );
	$o  = wc_get_order( $r1['order_id'] );
	mpo_check( '(a) status shipped -> updated in place: eru-mpo-shipped, still one line, one order', 'updated' === $r3['result'] && 'eru-mpo-shipped' === $o->get_status() && 1 === count( $o->get_items() ) && 1 === count( mpo_order_ids( $ra ) ) );
	$r4 = Eru_MPO_Importer::import( $fake, $dto( $ra, 'new' ) );
	mpo_check( '(a) a stale "new" never moves the order backwards', 'eru-mpo-shipped' === wc_get_order( $r1['order_id'] )->get_status() && 'updated' !== $r4['result'] );
	mpo_check( '(a) eru_mpo_order_imported fired once; status_advanced fired new -> shipped', 1 === count( array_filter( $imported, fn( $x ) => $x[1] === $ra ) ) && in_array( array( $r1['order_id'], 'new', 'shipped' ), $advanced, true ) );
	mpo_check( '(a) customer billing e-mail kept, no mail sent', 'demo.alici@example.com' === $o->get_billing_email() && 0 === count( $mail ) );

	// (b) Concurrency: the ref lock or a fresh pending row skips; a stale pending row is taken over once.
	$rb  = $ref( 'B' );
	$tok = Eru_MPO_Lock::acquire( Eru_MPO_Importer::lock_key( 'demo', $rb ), 300 );
	mpo_check( '(b) test holds the per-ref lock', is_string( $tok ) && '' !== $tok );
	mpo_check( '(b) a second acquire of the same lock fails (atomic)', false === Eru_MPO_Lock::acquire( Eru_MPO_Importer::lock_key( 'demo', $rb ), 300 ) );
	$rb1 = Eru_MPO_Importer::import( $fake, $dto( $rb ) );
	mpo_check( '(b) import while the lock is held -> locked, no order, no map row', 'locked' === $rb1['result'] && array() === mpo_order_ids( $rb ) && null === mpo_map_row( $rb ) );
	Eru_MPO_Lock::release( Eru_MPO_Importer::lock_key( 'demo', $rb ), $tok );
	mpo_check( '(b) claim inserts one pending row; a second claim of the same ref fails (UNIQUE)', Eru_MPO_Map::claim( 'demo', $rb ) && ! Eru_MPO_Map::claim( 'demo', $rb ) && 'pending' === mpo_map_row( $rb )->status );
	$wpdb->update( $wpdb->prefix . 'eru_mpo_orders', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 30 ) ), array( 'marketplace' => 'demo', 'remote_ref' => $rb ) ); // phpcs:ignore
	$rb2 = Eru_MPO_Importer::import( $fake, $dto( $rb ) );
	mpo_check( '(b) import while a fresh (30 s old) pending row exists -> skipped, no order', 'skipped' === $rb2['result'] && array() === mpo_order_ids( $rb ) );
	$wpdb->update( $wpdb->prefix . 'eru_mpo_orders', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'marketplace' => 'demo', 'remote_ref' => $rb ) ); // phpcs:ignore
	$rb3 = Eru_MPO_Importer::import( $fake, $dto( $rb ) );
	$rb4 = Eru_MPO_Importer::import( $fake, $dto( $rb ) );
	mpo_check( '(b) stale pending row taken over: exactly one order, then unchanged', 'created' === $rb3['result'] && 'unchanged' === $rb4['result'] && 1 === count( mpo_order_ids( $rb ) ) && 'done' === mpo_map_row( $rb )->status );

	// (b) Real concurrency: three processes import the same three packages at the same moment.
	$race   = array( $ref( 'R1' ), $ref( 'R2' ), $ref( 'R3' ) );
	$worker = __DIR__ . '/mpo-fixtures/import-worker.php';
	$start  = microtime( true ) + 3.0;
	$procs  = array();
	$pipes  = array();
	for ( $w = 0; $w < 3; $w++ ) {
		$cmd       = sprintf( 'MPO_REFS=%s MPO_START=%s WP_CLI_PHP_ARGS=%s wp --path=%s eval-file %s 2>/dev/null', escapeshellarg( implode( ',', $race ) ), $start, escapeshellarg( '-d error_reporting=24575' ), escapeshellarg( ABSPATH ), escapeshellarg( $worker ) );
		$procs[]   = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ) ), $pipes[ $w ] );
	}
	$out = '';
	foreach ( $pipes as $pp ) {
		$out .= stream_get_contents( $pp[1] );
	}
	foreach ( $procs as $pr ) {
		proc_close( $pr );
	}
	$one_each = true;
	foreach ( $race as $rr ) {
		$one_each = $one_each && 1 === count( mpo_order_ids( $rr ) ) && 'done' === mpo_map_row( $rr )->status;
	}
	mpo_check( '(b) three concurrent processes, three packages: one order each, 3 created in total (' . trim( str_replace( "\n", ' | ', $out ) ) . ')', $one_each && 3 === substr_count( $out, ' created' ) && 9 === substr_count( $out, 'DEMO-PKG-' ) );

	// (c) Isolated save: order-status listeners stay silent and stay registered; an exception restores every hook.
	mpo_settings( array( 'status_mode' => 'core' ) );
	$hits      = array(
		'processing' => 0,
		'new_order'  => 0,
		'changed'    => 0,
	);
	$l_proc    = function () use ( &$hits ) {
		++$hits['processing'];
	};
	$l_new     = function () use ( &$hits ) {
		++$hits['new_order'];
	};
	$l_changed = function () use ( &$hits ) {
		++$hits['changed'];
	};
	add_action( 'woocommerce_order_status_processing', $l_proc );
	add_action( 'woocommerce_new_order', $l_new );
	add_action( 'woocommerce_order_status_changed', $l_changed );
	$extra_hooks[] = array( 'woocommerce_order_status_processing', $l_proc );
	$extra_hooks[] = array( 'woocommerce_new_order', $l_new );
	$extra_hooks[] = array( 'woocommerce_order_status_changed', $l_changed );
	$count_cb_before = 0;
	foreach ( $wp_filter['woocommerce_new_order']->callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] ) && preg_match( '/OrderCountCacheService|Orders\\\\DataSynchronizer|OrdersVersionStringInvalidator/', get_class( $cb['function'][0] ) ) ) {
				++$count_cb_before;
			}
		}
	}
	$during = null;
	$peek   = function () use ( &$during ) {
		global $wp_filter;
		$during = array();
		foreach ( $wp_filter['woocommerce_new_order']->callbacks ?? array() as $cbs ) {
			foreach ( $cbs as $cb ) {
				$during[] = is_array( $cb['function'] ) && is_object( $cb['function'][0] ) ? get_class( $cb['function'][0] ) : 'other';
			}
		}
	};
	add_action( 'woocommerce_before_order_object_save', $peek );
	$sig_before = mpo_hook_signature();
	$rc         = $ref( 'C' );
	$rc1        = Eru_MPO_Importer::import( $fake, $dto( $rc ) );
	remove_action( 'woocommerce_before_order_object_save', $peek );
	$oc = wc_get_order( $rc1['order_id'] );
	mpo_check( '(c) status mode core: new marketplace order lands in processing', 'created' === $rc1['result'] && 'processing' === $oc->get_status() && 'new' === $oc->get_meta( '_eru_mpo_status' ) );
	mpo_check( '(c) processing / new_order / status_changed listeners were not called during import', 0 === $hits['processing'] && 0 === $hits['new_order'] && 0 === $hits['changed'] );
	mpo_check( '(c) no customer e-mail for the processing transition', 0 === count( $mail ) );
	mpo_check( '(c) the listeners are still registered afterwards', false !== has_action( 'woocommerce_order_status_processing', $l_proc ) && false !== has_action( 'woocommerce_new_order', $l_new ) );
	mpo_check( '(c) every touched hook is back exactly as before', $sig_before === mpo_hook_signature() );
	mpo_check( '(c) during save only WooCommerce\'s allow-listed callbacks (order-count cache, data synchroniser, cache invalidator) stayed on woocommerce_new_order', $count_cb_before >= 1 && is_array( $during ) && count( $during ) === $count_cb_before && array() === array_filter( $during, fn( $c ) => ! preg_match( '/OrderCountCacheService|Orders\\\\DataSynchronizer|OrdersVersionStringInvalidator/', $c ) ) );
	$keep_filter = function ( $keep, $cb, $hook ) use ( $l_new ) {
		return $keep || $cb === $l_new;
	};
	add_filter( 'eru_mpo_isolated_save_keep', $keep_filter, 10, 3 );
	$rc_k = Eru_MPO_Importer::import( $fake, $dto( $ref( 'CK' ) ) );
	remove_filter( 'eru_mpo_isolated_save_keep', $keep_filter, 10 );
	mpo_check( '(c) eru_mpo_isolated_save_keep keeps a chosen callback (new_order listener ran once, processing still silent)', 'created' === $rc_k['result'] && 1 === $hits['new_order'] && 0 === $hits['processing'] );

	// WooCommerce catches an Exception inside WC_Order::save() (logs it, returns the id or 0), but not an Error:
	// "-BOOMX" throws a RuntimeException (swallowed by WooCommerce), any other "-BOOM" ref throws an Error that
	// propagates through the isolated save.
	$boom = function ( $order ) {
		$r = $order instanceof WC_Order ? (string) $order->get_meta( '_eru_mpo_remote_ref' ) : '';
		if ( 0 === strpos( $r, 'DEMO-PKG-' ) && false !== strpos( $r, '-BOOMX' ) ) {
			throw new RuntimeException( 'demo listener failure' );
		}
		if ( 0 === strpos( $r, 'DEMO-PKG-' ) && false !== strpos( $r, '-BOOM' ) ) {
			throw new Error( 'demo listener error' );
		}
	};
	add_action( 'woocommerce_before_order_object_save', $boom );
	$sig_before = mpo_hook_signature();
	$rx         = $ref( 'BOOM1' );
	$rx1        = Eru_MPO_Importer::import( $fake, $dto( $rx ) );
	mpo_check( '(c) an Error thrown by a listener before insert propagates -> error result, every hook restored', 'error' === $rx1['result'] && $sig_before === mpo_hook_signature() );
	mpo_check( '(c) map row for the failed import is not done (error) and no order exists', 'error' === mpo_map_row( $rx )->status && array() === mpo_order_ids( $rx ) );
	$rz  = $ref( 'BOOMX' );
	$rz1 = Eru_MPO_Importer::import( $fake, $dto( $rz ) );
	mpo_check( '(c) an Exception that WooCommerce swallows before insert (no id) -> error, map row error, hooks restored', 'error' === $rz1['result'] && 'error' === mpo_map_row( $rz )->status && array() === mpo_order_ids( $rz ) && $sig_before === mpo_hook_signature() );
	remove_action( 'woocommerce_before_order_object_save', $boom );
	// An Error from a (kept) woocommerce_new_order listener: the row is inserted, the lines are not saved yet.
	$boom_new = function ( $id, $order = null ) use ( $boom ) {
		$boom( $order );
	};
	$keep_boom = fn( $k, $cb ) => $k || $cb === $boom_new;
	add_action( 'woocommerce_new_order', $boom_new, 5, 2 );
	add_filter( 'eru_mpo_isolated_save_keep', $keep_boom, 10, 2 );
	$ry  = $ref( 'BOOM2' );
	$ry1 = Eru_MPO_Importer::import( $fake, $dto( $ry ) );
	remove_action( 'woocommerce_new_order', $boom_new, 5 );
	remove_filter( 'eru_mpo_isolated_save_keep', $keep_boom, 10 );
	mpo_check( '(c) an Error thrown after the insert, before the lines are saved -> half-saved order removed, map row error, hooks restored', 'error' === $ry1['result'] && array() === mpo_order_ids( $ry ) && 'error' === mpo_map_row( $ry )->status && $sig_before === mpo_hook_signature() );
	$rx_wait = Eru_MPO_Importer::import( $fake, $dto( $rx ) );
	mpo_check( '(c) a retry inside the backoff is skipped', 'skipped' === $rx_wait['result'] && array() === mpo_order_ids( $rx ) );
	mpo_clear_backoff( $rx );
	$rx2 = Eru_MPO_Importer::import( $fake, $dto( $rx ) );
	mpo_check( '(c) next run after the error imports exactly once', 'created' === $rx2['result'] && 1 === count( mpo_order_ids( $rx ) ) && 'done' === mpo_map_row( $rx )->status );
	mpo_settings( array( 'status_mode' => 'core', 'mute_notifications' => 'no' ) );
	$rm = Eru_MPO_Importer::import( $fake, $dto( $ref( 'LOUD' ) ) );
	mpo_check( '(c) control: with "bildirimleri sustur" off the processing listener does run', 'created' === $rm['result'] && $hits['processing'] >= 1 );
	foreach ( $extra_hooks as $h ) {
		remove_action( $h[0], $h[1] );
	}
	$extra_hooks = array();
	mpo_settings();
	$mail->exchangeArray( array() );

	// (d) Deleted WC order -> stale map cleaned; the next run imports once.
	$rd  = $ref( 'D' );
	$rd1 = Eru_MPO_Importer::import( $fake, $dto( $rd ) );
	wc_get_order( $rd1['order_id'] )->delete( true );
	mpo_check( '(d) deleting the order removes its map row', null === mpo_map_row( $rd ) );
	$rd2 = Eru_MPO_Importer::import( $fake, $dto( $rd ) );
	mpo_check( '(d) re-import after delete creates one new order', 'created' === $rd2['result'] && $rd2['order_id'] !== $rd1['order_id'] && 1 === count( mpo_order_ids( $rd ) ) );
	remove_action( 'woocommerce_before_delete_order', array( 'Eru_MPO_Importer', 'on_delete' ) );
	wc_get_order( $rd2['order_id'] )->delete( true );
	add_action( 'woocommerce_before_delete_order', array( 'Eru_MPO_Importer', 'on_delete' ) );
	mpo_check( '(d) map row left stale on purpose (delete hook bypassed)', 'done' === mpo_map_row( $rd )->status && (int) mpo_map_row( $rd )->wc_order_id === $rd2['order_id'] );
	$rd3 = Eru_MPO_Importer::import( $fake, $dto( $rd ) );
	$rd4 = Eru_MPO_Importer::import( $fake, $dto( $rd ) );
	mpo_check( '(d) stale row cleaned, re-imported once, then unchanged', 'created' === $rd3['result'] && 'unchanged' === $rd4['result'] && 1 === count( mpo_order_ids( $rd ) ) && (int) mpo_map_row( $rd )->wc_order_id === $rd3['order_id'] );
	$stale_log = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_mpo_log WHERE marketplace = 'demo' AND remote_ref = %s AND message LIKE %s", $rd, '%' . $wpdb->esc_like( 'Bayat' ) . '%' ) ); // phpcs:ignore
	mpo_check( '(d) the clean-up is logged', $stale_log >= 1 );

	// (e) Unmatched SKU -> order flagged for review, line kept with name/qty/price, no fee lines.
	$re  = $ref( 'E' );
	$re1 = Eru_MPO_Importer::import( $fake, $dto( $re, 'new', array(), 'order-unmatched.json' ) );
	$oe  = wc_get_order( $re1['order_id'] );
	$ie  = array_values( $oe->get_items() );
	mpo_check( '(e) created and flagged review (result, map row, order meta)', 'created' === $re1['result'] && true === $re1['review'] && 1 === (int) mpo_map_row( $re )->review && 'yes' === $oe->get_meta( '_eru_mpo_review' ) );
	mpo_check( '(e) unmatched line kept: name, qty 3, gross 179.70, no product, flagged', 1 === count( $ie ) && 'Demo Kupa' === $ie[0]->get_name() && 3 === (int) $ie[0]->get_quantity() && 0 === (int) $ie[0]->get_product_id() && abs( (float) $ie[0]->get_total() + (float) $ie[0]->get_total_tax() - 179.70 ) < 0.001 && 'yes' === $ie[0]->get_meta( '_eru_mpo_unmatched' ) && ( 'DEMO-NOSKU-' . $re ) === $ie[0]->get_meta( '_eru_mpo_sku' ) );
	mpo_check( '(e) no fee lines, order total 179.70, city code 35 -> TR35', array() === $oe->get_fees() && abs( (float) $oe->get_total() - 179.70 ) < 0.001 && 'TR35' === $oe->get_billing_state() );

	// (f) Stock "reduce": 10 -> 8 for qty 2 once; re-import does not reduce again; cancel restores to 10.
	mpo_settings( array( 'stock_mode' => 'reduce' ) );
	wc_update_product_stock( $pid, 10, 'set' );
	$rf  = $ref( 'F' );
	$rf1 = Eru_MPO_Importer::import( $fake, $dto( $rf ) );
	mpo_check( '(f) import reduces stock 10 -> 8', 'created' === $rf1['result'] && 8 === mpo_stock( $pid ) );
	Eru_MPO_Importer::import( $fake, $dto( $rf ) );
	Eru_MPO_Importer::import( $fake, $dto( $rf, 'shipped' ) );
	mpo_check( '(f) re-import and status update do not reduce again (8)', 8 === mpo_stock( $pid ) );
	$rf2 = Eru_MPO_Importer::import( $fake, $dto( $rf, 'cancelled' ) );
	$of  = wc_get_order( $rf1['order_id'] );
	mpo_check( '(f) cancel restores 8 -> 10 and the order is eru-mpo-cancelled', 'updated' === $rf2['result'] && 10 === mpo_stock( $pid ) && 'eru-mpo-cancelled' === $of->get_status() );
	Eru_MPO_Importer::import( $fake, $dto( $rf, 'cancelled' ) );
	Eru_MPO_Importer::import( $fake, $dto( $rf, 'delivered' ) );
	mpo_check( '(f) repeated cancel and a later "delivered" change nothing (10, still cancelled)', 10 === mpo_stock( $pid ) && 'eru-mpo-cancelled' === wc_get_order( $rf1['order_id'] )->get_status() );
	$rg  = $ref( 'G' );
	$rg1 = Eru_MPO_Importer::import( $fake, $dto( $rg ) );
	mpo_check( '(f) second order reduces 10 -> 8', 8 === mpo_stock( $pid ) );
	$ev  = array(
		array(
			'id'    => 'DEMO-EV-' . $rg,
			'type'  => 'cancel_lines',
			'lines' => array(
				array(
					'remote_line_id' => 'DEMO-L1-' . $rg,
					'sku'            => $sku,
					'quantity'       => 1,
				),
			),
		),
	);
	$rg2 = Eru_MPO_Importer::import( $fake, $dto( $rg, 'new', array( 'events' => $ev ) ) );
	$rg3 = Eru_MPO_Importer::import( $fake, $dto( $rg, 'new', array( 'events' => $ev ) ) );
	$cl  = array_values( array_filter( $cancelled, fn( $c ) => $c[0] === $rg1['order_id'] ) );
	mpo_check( '(f) partial cancel event restores one unit (9) once; action fired once with the line', 'updated' === $rg2['result'] && 'unchanged' === $rg3['result'] && 9 === mpo_stock( $pid ) && 1 === count( $cl ) && 1 === (int) $cl[0][1][0]['quantity'] );
	mpo_settings();

	// (i) Raw payload in its own table with retention; identity number never in raw or meta unless opted in.
	$raw_a = Eru_MPO_Raw::get( 'demo', $ra );
	mpo_check( '(i) raw payload stored for the package, identity number masked', is_array( $raw_a ) && 'demo-raw-marker' === ( $raw_a['marker'] ?? '' ) && 1 !== preg_match( '/\d{11}/', (string) wp_json_encode( $raw_a ) ) );
	$meta_dump = '';
	foreach ( wc_get_order( $r1['order_id'] )->get_meta_data() as $m ) {
		$meta_dump .= $m->key . '=' . wp_json_encode( $m->value ) . "\n";
	}
	mpo_check( '(i) no raw payload and no identity number in order meta', false === strpos( $meta_dump, 'demo-raw-marker' ) && false === stripos( $meta_dump, '_raw' ) && false === strpos( $meta_dump, '_eru_mpo_identity_number' ) );
	$wpdb->update( $wpdb->prefix . 'eru_mpo_raw', array( 'stored_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'marketplace' => 'demo', 'remote_ref' => $ra ) ); // phpcs:ignore
	Eru_MPO_Raw::purge();
	mpo_check( '(i) purge drops the row older than 30 days and keeps a fresh one', null === Eru_MPO_Raw::get( 'demo', $ra ) && is_array( Eru_MPO_Raw::get( 'demo', $rb ) ) );
	$wpdb->update( $wpdb->prefix . 'eru_mpo_log', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS ) ), array( 'marketplace' => 'demo', 'remote_ref' => $rd ) ); // phpcs:ignore
	Eru_MPO_Logger::purge();
	mpo_check( '(i) log rows older than 14 days purged', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_mpo_log WHERE marketplace = 'demo' AND remote_ref = %s", $rd ) ) ); // phpcs:ignore
	mpo_settings( array( 'store_identity' => 'yes' ) );
	$tck = mpo_fake_tckn();
	$ri  = Eru_MPO_Importer::import( $fake, $dto( $ref( 'I' ), 'new', array(), 'order-basic.json', $tck ) );
	mpo_check( '(i) with "e-Arşiv için TC kimlik no sakla" on, the number is copied to order meta', $tck === wc_get_order( $ri['order_id'] )->get_meta( '_eru_mpo_identity_number' ) );
	$log_dump = (string) $wpdb->get_var( "SELECT GROUP_CONCAT(message SEPARATOR ' ') FROM {$wpdb->prefix}eru_mpo_log WHERE marketplace = 'demo'" ); // phpcs:ignore
	mpo_check( '(i) the identity number never reaches the log', false === strpos( $log_dump, $tck ) );
	mpo_settings();

	// (h) Observe mode: logs "aktarılırdı", creates nothing. Master off: nothing runs and nothing is scheduled.
	mpo_settings( array( 'observe' => 'yes' ) );
	$rh            = $ref( 'H' );
	$fake->orders  = array( $dto( $rh ) );
	$fake->fetches = 0;
	$ph            = Eru_MPO_Importer::pull( 'demo' );
	$obs           = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_mpo_log WHERE marketplace = 'demo' AND remote_ref = %s AND message LIKE %s", $rh, '%' . $wpdb->esc_like( 'aktarılırdı' ) . '%' ) ); // phpcs:ignore
	mpo_check( '(h) observe mode: one "aktarılırdı" log row, no order, no map row, no raw row', 1 === (int) $ph['observed'] && 1 === $obs && array() === mpo_order_ids( $rh ) && null === mpo_map_row( $rh ) && null === Eru_MPO_Raw::get( 'demo', $rh ) );
	mpo_reset();
	mpo_check( '(h) precondition: no retained raw/log rows on the demo site', ! Eru_MPO_Raw::has_rows() && ! Eru_MPO_Logger::has_rows() );
	mpo_settings( array( 'enabled' => 'no' ) );
	$fake->fetches = 0;
	$off           = Eru_MPO_Importer::pull( 'demo' );
	Eru_MPO_Cron::maybe_arm();
	mpo_check( '(h) master off: pull refuses before any fetch, and no Action Scheduler job exists', 'disabled' === $off['result'] && 0 === $fake->fetches && array() === mpo_pending_group() );
	mpo_settings();
	$on = Eru_MPO_Importer::pull( 'demo' );
	mpo_check( '(h) master on, observe off: pull imports the fetched package', 'ok' === $on['result'] && 1 === (int) $on['created'] && 1 === count( mpo_order_ids( $rh ) ) );
	mpo_check( '(h) once data exists the daily retention job is armed (pending-only, one)', 1 === count( mpo_pending( 'eru_mpo_daily' ) ) );
} catch ( Throwable $e ) {
	$failed = true;
	if ( ! $e instanceof RuntimeException ) {
		echo 'FAIL: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	$final_cleanup();
}
if ( $failed ) {
	exit( 1 );
}
echo "ALL PASS\n";
