<?php
/**
 * Marketplace Orders for WooCommerce — robustness:
 * - no half-saved orders: a swallowed exception mid-save, a total changed during save, a failed status save;
 * - stock "leave" mode holds outside the import as well;
 * - one failing package does not stop the pull (backoff on its map row);
 * - every DTO field is used: cancelled lines, order discount, shipping VAT, 11-digit tax numbers;
 * - an order saved before a crash is adopted with its post-import steps; trashed orders are not revived;
 * - il names in any casing; observe mode logs once a day; the purge keeps going; sales counts stay right.
 *
 *   wp eval-file tests/php/mpo-robustness.php
 */

require __DIR__ . '/mpo-helpers.php';
mpo_require_plugin();
mpo_no_http();

global $wpdb;
$old_settings = get_option( Eru_MPO_Settings::OPTION, null );
$old_mp       = get_option( 'eru_mpo_mp_demo', null );
$failed       = false;
$tag          = mpo_run_tag();
$sku1         = 'MPO-TEST-' . $tag . '-1';
$sku2         = 'MPO-TEST-' . $tag . '-2';
$p1           = 0;
$p2           = 0;
$rate_id      = 0;
$plain        = 0;
$fake         = mpo_fake_adapter();
$mail         = mpo_mail_trap();
$hooks        = array();
$imported     = array();
$advanced     = array();
$cancelled    = array();
add_action( 'eru_mpo_order_imported', function ( $o, $d ) use ( &$imported ) { $imported[] = $d->remote_ref; }, 10, 2 ); // phpcs:ignore
add_action( 'eru_mpo_order_status_advanced', function ( $o, $f, $t ) use ( &$advanced ) { $advanced[] = array( $o->get_id(), $f, $t ); }, 10, 3 ); // phpcs:ignore
add_action( 'eru_mpo_order_cancelled_lines', function ( $o, $l ) use ( &$cancelled ) { $cancelled[] = array( $o->get_id(), $l ); }, 10, 2 ); // phpcs:ignore


/** Fake marketplace that acknowledges (status trigger scenarios). */
if ( class_exists( 'MPO_Fake_Adapter' ) ) {
	class MPO_Ack_Fake extends MPO_Fake_Adapter {
		/** @var int Acknowledgements sent. */
		public $acks = 0;
		public function push_acknowledge( $order ) {
			++$this->acks;
			return true;
		}
		public function ack_profile() {
			return array(
				'advance' => 'accepted',
				'label'   => 'Demo bildirim',
			);
		}
	}
}
$ref = fn( $s ) => 'DEMO-PKG-' . $tag . '-' . $s;
/** Two-line DTO (2 × 149.90 + 1 × 89.90 = 389.70). */
$two = function ( $r, array $o = array() ) use ( $sku1, $sku2 ) {
	$o += array(
		'status'   => 'new',
		'l2'       => 'active',
		'ship'     => '0',
		'shipvat'  => '',
		'discount' => '0',
		'total'    => '389.70',
		'taxno'    => '',
		'bcity'    => 'Ankara',
		'scity'    => 'İzmir',
	);
	return mpo_dto(
		'order-two-lines.json',
		array(
			'{{REF}}'      => $r,
			'{{SKU}}'      => $sku1,
			'{{SKU2}}'     => $sku2,
			'{{STATUS}}'   => $o['status'],
			'{{L2STATUS}}' => $o['l2'],
			'{{SHIP}}'     => $o['ship'],
			'{{SHIPVAT}}'  => $o['shipvat'],
			'{{DISCOUNT}}' => $o['discount'],
			'{{TOTAL}}'    => $o['total'],
			'{{TAXNO}}'    => $o['taxno'],
			'{{BCITY}}'    => $o['bcity'],
			'{{SCITY}}'    => $o['scity'],
		)
	);
};
$hook = function ( $name, $cb, $prio = 10, $args = 1 ) use ( &$hooks ) {
	add_filter( $name, $cb, $prio, $args );
	$hooks[] = array( $name, $cb, $prio );
};
$unhook = function () use ( &$hooks ) {
	foreach ( $hooks as $h ) {
		remove_filter( $h[0], $h[1], $h[2] );
	}
	$hooks = array();
};
$restock = function () use ( &$p1, &$p2 ) {
	wc_update_product_stock( $p1, 10, 'set' );
	wc_update_product_stock( $p2, 10, 'set' );
};
$notes = function ( $id ) {
	return implode( "\n", array_map( fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $id ) ) ) );
};
$minor = fn( $v ) => (int) round( (float) $v * 100 );

// Clean-up runs once: in finally, and from a shutdown function after an exit() or a fatal error.
$final_cleanup = function () use ( $unhook, &$plain, &$rate_id, $old_settings, $old_mp ) {
	global $wpdb;
	static $ran = false;
	if ( $ran ) {
		return;
	}
	$ran = true;
	$unhook();
	if ( $plain && wc_get_order( $plain ) ) {
		wc_get_order( $plain )->delete( true );
	}
	mpo_reset();
	mpo_delete_products();
	if ( $rate_id ) {
		WC_Tax::_delete_tax_rate( $rate_id );
	}
	foreach ( array( Eru_MPO_Settings::OPTION => $old_settings, 'eru_mpo_mp_demo' => $old_mp ) as $k => $v ) {
		if ( null === $v ) {
			delete_option( $k );
		} else {
			update_option( $k, $v, false );
		}
	}
	Eru_MPO_Settings::reset_cache();
};
register_shutdown_function( $final_cleanup );

try {
	mpo_reset();
	mpo_delete_products();
	mpo_settings();
	$p1      = mpo_product( $sku1, 10 );
	$p2      = mpo_product( $sku2, 10, '89.90' );
	$rate_id = mpo_tax_rate( 20 );

	// I1 — a listener kept through the allow-list throws an Exception on woocommerce_new_order: WooCommerce swallows
	// it after the insert and before the lines are saved. The half order must not count as created.
	$r_noi   = $ref( 'NOI' );
	$thrower = function ( $id, $order = null ) use ( $r_noi ) {
		if ( $order instanceof WC_Order && $r_noi === $order->get_meta( '_eru_mpo_remote_ref' ) ) {
			throw new RuntimeException( 'demo exception after insert' );
		}
	};
	$hook( 'woocommerce_new_order', $thrower, 5, 2 );
	$hook( 'eru_mpo_isolated_save_keep', fn( $k, $cb ) => $k || $cb === $thrower, 10, 3 );
	$a = Eru_MPO_Importer::import( $fake, $two( $r_noi ) );
	mpo_check( 'I1 swallowed exception mid-save: error, half order deleted, map row error with a backoff', 'error' === $a['result'] && array() === mpo_order_ids( $r_noi ) && 'error' === mpo_map_row( $r_noi )->status && strtotime( mpo_map_row( $r_noi )->next_try_at . ' UTC' ) > time() );
	$unhook();
	$b = Eru_MPO_Importer::import( $fake, $two( $r_noi ) );
	mpo_check( 'I1 retry inside the backoff is skipped', 'skipped' === $b['result'] && array() === mpo_order_ids( $r_noi ) );
	mpo_clear_backoff( $r_noi );
	$c = Eru_MPO_Importer::import( $fake, $two( $r_noi ) );
	$o = wc_get_order( $c['order_id'] );
	mpo_check( 'I1 after the backoff the package is built once, with both lines', 'created' === $c['result'] && 1 === count( mpo_order_ids( $r_noi ) ) && 2 === count( $o->get_items() ) && 0 === (int) mpo_map_row( $r_noi )->attempts );

	// I1 — a listener changes the total during the save: the order is kept, flagged, both totals in a note.
	$r_tot = $ref( 'TOT' );
	$hook(
		'woocommerce_before_order_object_save',
		function ( $order ) use ( $r_tot ) {
			if ( $order instanceof WC_Order && ! $order->get_id() && $r_tot === $order->get_meta( '_eru_mpo_remote_ref' ) ) {
				$order->set_total( '1.00' );
			}
		}
	);
	$t = Eru_MPO_Importer::import( $fake, $two( $r_tot ) );
	$unhook();
	mpo_check( 'I1 total changed during save: order kept and flagged for review', 'created' === $t['result'] && true === $t['review'] && 1 === (int) mpo_map_row( $r_tot )->review && 'yes' === wc_get_order( $t['order_id'] )->get_meta( '_eru_mpo_review' ) );
	$n = $notes( $t['order_id'] );
	mpo_check( 'I1 the note gives both totals (1.00 and 389.70)', false !== strpos( $n, '1.00' ) && false !== strpos( $n, '389.70' ) );

	// I1 — the status save fails: advance() reports false, no stock restore, no action; the next run moves it.
	mpo_settings( array( 'stock_mode' => 'reduce' ) );
	$restock();
	$r_adv = $ref( 'ADV' );
	$x     = Eru_MPO_Importer::import( $fake, $two( $r_adv ) );
	mpo_check( 'I1 set-up: import reduced stock (8 / 9)', 'created' === $x['result'] && 8 === mpo_stock( $p1 ) && 9 === mpo_stock( $p2 ) );
	$hook(
		'woocommerce_before_order_object_save',
		function ( $order ) use ( $r_adv ) {
			if ( $order instanceof WC_Order && $r_adv === $order->get_meta( '_eru_mpo_remote_ref' ) && 'cancelled' === $order->get_meta( '_eru_mpo_status' ) ) {
				throw new RuntimeException( 'demo status save failure' );
			}
		}
	);
	$before = count( $advanced );
	$y      = Eru_MPO_Importer::import( $fake, $two( $r_adv, array( 'status' => 'cancelled' ) ) );
	$unhook();
	mpo_check( 'I1 failed status save: error result, stock not restored, no status action, map row stays done', 'error' === $y['result'] && 8 === mpo_stock( $p1 ) && 9 === mpo_stock( $p2 ) && count( $advanced ) === $before && 'done' === mpo_map_row( $r_adv )->status && '' !== (string) mpo_map_row( $r_adv )->error );
	mpo_check( 'I1 the stored status did not change', 'eru-mpo-new' === Eru_MPO_Importer::stored_status( $x['order_id'] ) );
	mpo_clear_backoff( $r_adv );
	$z = Eru_MPO_Importer::import( $fake, $two( $r_adv, array( 'status' => 'cancelled' ) ) );
	mpo_check( 'I1 next run cancels: stock back to 10 / 10, one status action, error cleared', 'updated' === $z['result'] && 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) && count( $advanced ) === $before + 1 && null === mpo_map_row( $r_adv )->error );

	// N1 — the cancel move is stored, but a woocommerce_update_order listener throws, so the save looks incomplete:
	// the move counts as done, stock is restored exactly once and the status action fires once.
	mpo_settings( array( 'stock_mode' => 'reduce' ) );
	$restock();
	$r_upd = $ref( 'UPD' );
	$u0    = Eru_MPO_Importer::import( $fake, $two( $r_upd ) );
	mpo_check( 'N1 set-up: import reduced stock (8 / 9)', 'created' === $u0['result'] && 8 === mpo_stock( $p1 ) && 9 === mpo_stock( $p2 ) );
	$hook(
		'woocommerce_update_order',
		function ( $id, $order = null ) use ( $r_upd ) {
			if ( $order instanceof WC_Order && $r_upd === $order->get_meta( '_eru_mpo_remote_ref' ) && 'eru-mpo-cancelled' === $order->get_status() ) {
				throw new RuntimeException( 'demo update_order failure' );
			}
		},
		10,
		2
	);
	$cancel_actions = function ( $oid ) use ( &$advanced ) {
		return count( array_filter( $advanced, fn( $a ) => $a[0] === $oid && 'cancelled' === $a[2] ) );
	};
	$u1             = Eru_MPO_Importer::import( $fake, $two( $r_upd, array( 'status' => 'cancelled' ) ) );
	mpo_clear_backoff( $r_upd );
	$u2 = Eru_MPO_Importer::import( $fake, $two( $r_upd, array( 'status' => 'cancelled' ) ) );
	$unhook();
	mpo_clear_backoff( $r_upd );
	$u3 = Eru_MPO_Importer::import( $fake, $two( $r_upd, array( 'status' => 'cancelled' ) ) );
	mpo_check( 'N1 stored cancel with an update_order listener throwing: status cancelled, stock 10 / 10 restored once, one status action', 'eru-mpo-cancelled' === Eru_MPO_Importer::stored_status( $u0['order_id'] ) && 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) && 1 === $cancel_actions( $u0['order_id'] ) && 'error' !== $u1['result'] );
	mpo_check( 'N1 later runs change nothing (stock 10 / 10, still one action)', 'updated' !== $u2['result'] && 'unchanged' === $u3['result'] && 10 === mpo_stock( $p1 ) && 1 === $cancel_actions( $u0['order_id'] ) );

	// N1 — a status event whose save fails before anything is stored: lifecycle stops for that order (the pending
	// change is never saved by a later save), the event stays unapplied, and the next run applies it once.
	$restock();
	$r_evf = $ref( 'EVF' );
	$v0    = Eru_MPO_Importer::import( $fake, $two( $r_evf ) );
	$once  = 0;
	$hook(
		'woocommerce_before_order_object_save',
		function ( $order ) use ( $r_evf, &$once ) {
			if ( $order instanceof WC_Order && $r_evf === $order->get_meta( '_eru_mpo_remote_ref' ) && 'cancelled' === $order->get_meta( '_eru_mpo_status' ) && 0 === $once++ ) {
				throw new RuntimeException( 'demo one-off save failure' );
			}
		}
	);
	$evd         = $two( $r_evf );
	$evd->events = array( Eru_MPO_Event_DTO::from_array( array( 'id' => 'DEMO-EV-CANCEL-' . $r_evf, 'type' => 'status', 'status' => 'cancelled' ) ) );
	$v1          = Eru_MPO_Importer::import( $fake, $evd );
	$applied     = wc_get_order( $v0['order_id'] )->get_meta( '_eru_mpo_events' );
	mpo_check( 'N1 failed status event: error, stored status still new, stock not restored (8 / 9), event not recorded, no action', 'error' === $v1['result'] && 'eru-mpo-new' === Eru_MPO_Importer::stored_status( $v0['order_id'] ) && 8 === mpo_stock( $p1 ) && 9 === mpo_stock( $p2 ) && ! in_array( 'DEMO-EV-CANCEL-' . $r_evf, (array) $applied, true ) && 0 === $cancel_actions( $v0['order_id'] ) );
	$unhook();
	mpo_clear_backoff( $r_evf );
	$v2 = Eru_MPO_Importer::import( $fake, $evd );
	$v3 = Eru_MPO_Importer::import( $fake, $evd );
	mpo_check( 'N1 next run applies the event once: cancelled, stock 10 / 10, one action', 'updated' === $v2['result'] && 'unchanged' === $v3['result'] && 'eru-mpo-cancelled' === Eru_MPO_Importer::stored_status( $v0['order_id'] ) && 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) && 1 === $cancel_actions( $v0['order_id'] ) );

	// N5 — two lines with the same SKU and no remote line id: each cancellation has its own event and line.
	$restock();
	$same = function ( $r, $s0, $s1 ) use ( $two, $sku1 ) {
		$d = $two( $r );
		foreach ( $d->lines as $l ) {
			$l->sku            = $sku1;
			$l->remote_line_id = '';
		}
		$d->lines[0]->status = $s0;
		$d->lines[1]->status = $s1;
		$d->totals->total    = 0.0;
		return $d;
	};
	$r_ss = $ref( 'SS' );
	$w0   = Eru_MPO_Importer::import( $fake, $same( $r_ss, 'active', 'active' ) );
	mpo_check( 'N5 set-up: two lines of one SKU reduce its stock 10 -> 7', 'created' === $w0['result'] && 7 === mpo_stock( $p1 ) );
	Eru_MPO_Importer::import( $fake, $same( $r_ss, 'active', 'cancelled' ) );
	mpo_check( 'N5 second line cancelled: its one unit restored (8)', 8 === mpo_stock( $p1 ) );
	Eru_MPO_Importer::import( $fake, $same( $r_ss, 'cancelled', 'cancelled' ) );
	$w3 = Eru_MPO_Importer::import( $fake, $same( $r_ss, 'cancelled', 'cancelled' ) );
	mpo_check( 'N5 first line cancelled too: its two units restored (10); a further run changes nothing', 10 === mpo_stock( $p1 ) && 'unchanged' === $w3['result'] && 2 === count( array_filter( $cancelled, fn( $c ) => $c[0] === $w0['order_id'] ) ) );
	$r_sd = $ref( 'SD' );
	$x0   = Eru_MPO_Importer::import( $fake, $same( $r_sd, 'cancelled', 'active' ) );
	mpo_check( 'N5 dropped first line takes no stock (10 -> 9 for the active one)', 'created' === $x0['result'] && 9 === mpo_stock( $p1 ) );
	Eru_MPO_Importer::import( $fake, $same( $r_sd, 'cancelled', 'cancelled' ) );
	mpo_check( 'N5 the later cancel finds the active line, not the zero-priced dropped one (9 -> 10)', 10 === mpo_stock( $p1 ) );
	mpo_settings();
	$restock();

	// A listener that throws an Error after a complete save (notifications not muted): the order is kept.
	mpo_settings( array( 'mute_notifications' => 'no' ) );
	$r_ls = $ref( 'LSN' );
	$hook(
		'woocommerce_order_status_changed',
		function ( $id, $from, $to, $order ) use ( $r_ls ) {
			if ( $order instanceof WC_Order && $r_ls === $order->get_meta( '_eru_mpo_remote_ref' ) ) {
				throw new Error( 'demo listener error after save' );
			}
		},
		10,
		4
	);
	$l1 = Eru_MPO_Importer::import( $fake, $two( $r_ls ) );
	$l2 = Eru_MPO_Importer::import( $fake, $two( $r_ls ) );
	$unhook();
	$ids = mpo_order_ids( $r_ls );
	mpo_check( 'listener Error after a complete save: kept, two runs -> exactly one order with the same id, row done', 'created' === $l1['result'] && $l1['order_id'] > 0 && array( $l1['order_id'] ) === $ids && $l2['order_id'] === $l1['order_id'] && 'done' === mpo_map_row( $r_ls )->status && 2 === count( wc_get_order( $l1['order_id'] )->get_items() ) );
	mpo_check( 'the listener error is logged once and noted on the order', 1 === mpo_log_count( $r_ls, 'demo listener error after save' ) && false !== strpos( $notes( $l1['order_id'] ), 'Bir eklenti sipariş kaydı sonrasında hata verdi; sipariş korunuyor.' ) );
	mpo_settings();

	// I2 — "leave" mode holds when the shop changes the status by hand or completes the payment.
	mpo_settings();
	$restock();
	$r_lv = $ref( 'LV' );
	$lv   = Eru_MPO_Importer::import( $fake, $two( $r_lv ) );
	$o    = wc_get_order( $lv['order_id'] );
	$o->update_status( 'processing' );
	$o->update_status( 'on-hold' );
	wc_get_order( $lv['order_id'] )->payment_complete();
	wc_maybe_reduce_stock_levels( $lv['order_id'] );
	mpo_check( 'I2 leave mode: processing, on-hold, payment_complete and wc_maybe_reduce_stock_levels leave stock at 10 / 10', 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) );
	mpo_settings( array( 'status_mode' => 'core' ) );
	$lc = Eru_MPO_Importer::import( $fake, $two( $ref( 'LVC' ) ) );
	wc_get_order( $lc['order_id'] )->update_status( 'completed' );
	mpo_check( 'I2 leave mode, core statuses: completing by hand leaves stock at 10 / 10', 'processing' !== wc_get_order( $lc['order_id'] )->get_status() && 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) );
	$po = new WC_Order();
	$it = new WC_Order_Item_Product();
	$it->set_product( wc_get_product( $p1 ) );
	$it->set_quantity( 1 );
	$it->set_total( '149.90' );
	$po->add_item( $it );
	$po->set_billing_email( 'demo.web@example.com' );
	$po->update_meta_data( '_mpo_test_plain', 'yes' );
	$plain = (int) $po->save();
	$po->update_status( 'processing' );
	mpo_check( 'I2 control: a web order still reduces stock (10 -> 9)', 9 === mpo_stock( $p1 ) );
	wc_get_order( $plain )->delete( true );
	$plain = 0;
	$restock();

	// I4 — one failing package is logged, backed off, and the rest of the pull continues.
	mpo_settings();
	$r_a = $ref( 'PA' );
	$r_b = $ref( 'PB' );
	$r_c = $ref( 'PC' );
	Eru_MPO_Importer::import( $fake, $two( $r_a ) );
	$hook(
		'eru_mpo_order_status_advanced',
		function ( $order ) use ( $r_a ) {
			if ( $r_a === $order->get_meta( '_eru_mpo_remote_ref' ) ) {
				throw new Error( 'demo listener error' );
			}
		}
	);
	$fake->orders = array( $two( $r_a, array( 'status' => 'shipped' ) ), $two( $r_b ), $two( $r_c ) );
	$p            = Eru_MPO_Importer::pull( 'demo' );
	$row_a        = mpo_map_row( $r_a );
	mpo_check( 'I4 pull continues after a failing package: 1 error, 2 created', 'ok' === $p['result'] && 1 === (int) $p['errors'] && 2 === (int) $p['created'] && 1 === count( mpo_order_ids( $r_b ) ) && 1 === count( mpo_order_ids( $r_c ) ) );
	mpo_check( 'I4 the failing package keeps its order (row done) with the error and a backoff, and is logged', 'done' === $row_a->status && '' !== (string) $row_a->error && strtotime( $row_a->next_try_at . ' UTC' ) > time() && mpo_log_count( $r_a, 'demo listener error' ) >= 1 );
	$p2r = Eru_MPO_Importer::pull( 'demo' );
	mpo_check( 'I4 inside the backoff the package is skipped, the others are unchanged', 1 === (int) $p2r['skipped'] && 2 === (int) $p2r['unchanged'] && 0 === (int) $p2r['errors'] );
	$unhook();
	mpo_clear_backoff( $r_a );
	$p3 = Eru_MPO_Importer::pull( 'demo' );
	mpo_check( 'I4 after the backoff it is processed and its error cleared', 0 === (int) $p3['errors'] && null === mpo_map_row( $r_a )->error && 0 === (int) mpo_map_row( $r_a )->attempts );
	$fake->orders = array();

	// I5 — a line cancelled before import: zero-priced, no product link, no stock; total without it.
	mpo_settings( array( 'stock_mode' => 'reduce' ) );
	$restock();
	$r_cl = $ref( 'CL' );
	$cl   = Eru_MPO_Importer::import( $fake, $two( $r_cl, array( 'l2' => 'cancelled', 'total' => '299.80' ) ) );
	$oc   = wc_get_order( $cl['order_id'] );
	$its  = array_values( $oc->get_items() );
	mpo_check( 'I5 cancelled line kept at zero price, not linked to the product, marked cancelled', 'created' === $cl['result'] && 2 === count( $its ) && 0.0 === (float) $its[1]->get_total() && 0 === (int) $its[1]->get_product_id() && 'cancelled' === $its[1]->get_meta( '_eru_mpo_line_status' ) );
	mpo_check( 'I5 stock: active line reduced (8), cancelled line not (10); total 299.80 without review', 8 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) && 29980 === $minor( $oc->get_total() ) && false === $cl['review'] );
	$restock();
	$r_lc = $ref( 'LC' );
	$lc1  = Eru_MPO_Importer::import( $fake, $two( $r_lc ) );
	mpo_check( 'I5 set-up: both lines reduce stock (8 / 9)', 8 === mpo_stock( $p1 ) && 9 === mpo_stock( $p2 ) );
	$lc2 = Eru_MPO_Importer::import( $fake, $two( $r_lc, array( 'l2' => 'cancelled', 'total' => '299.80' ) ) );
	$lc3 = Eru_MPO_Importer::import( $fake, $two( $r_lc, array( 'l2' => 'cancelled', 'total' => '299.80' ) ) );
	$cx  = array_values( array_filter( $cancelled, fn( $e ) => $e[0] === $lc1['order_id'] ) );
	mpo_check( 'I5 a line cancelled later restores its unit once (9 -> 10), fires the action once, flags review', 'updated' === $lc2['result'] && 'unchanged' === $lc3['result'] && 10 === mpo_stock( $p2 ) && 8 === mpo_stock( $p1 ) && 1 === count( $cx ) && 'yes' === wc_get_order( $lc1['order_id'] )->get_meta( '_eru_mpo_review' ) );
	mpo_settings();

	// I5 — order-level discount and shipping VAT are applied; the total matches without review.
	$d   = Eru_MPO_Importer::import( $fake, $two( $ref( 'DS' ), array( 'discount' => '20.00', 'total' => '369.70' ) ) );
	$od  = wc_get_order( $d['order_id'] );
	$sum = 0;
	$tax = 0;
	foreach ( $od->get_items() as $i ) {
		$sum += $minor( $i->get_total() ) + $minor( $i->get_total_tax() );
		$tax += $minor( $i->get_total_tax() );
	}
	mpo_check( 'I5 discount 20.00: lines sum to 369.70, discount + discount tax = 20.00, total 369.70, no review', 'created' === $d['result'] && 36970 === $sum && 2000 === $minor( $od->get_discount_total() ) + $minor( $od->get_discount_tax() ) && 36970 === $minor( $od->get_total() ) && false === $d['review'] && $tax === $minor( $od->get_cart_tax() ) );
	$s  = Eru_MPO_Importer::import( $fake, $two( $ref( 'SV' ), array( 'ship' => '29.90', 'shipvat' => '20', 'total' => '419.60' ) ) );
	$os = wc_get_order( $s['order_id'] );
	$ti = array_values( $os->get_items( 'tax' ) );
	mpo_check( 'I5 shipping 29.90 with 20 % VAT: 24.92 + 4.98 on the shipping line and the tax line, total 419.60, no review', 'created' === $s['result'] && 2492 === $minor( $os->get_shipping_total() ) && 498 === $minor( $os->get_shipping_tax() ) && 1 === count( $ti ) && 498 === $minor( $ti[0]->get_shipping_tax_total() ) && 41960 === $minor( $os->get_total() ) && false === $s['review'] );

	// Minor — an 11-digit tax number is an identity number (P8).
	$tck = mpo_fake_tckn();
	$t1  = Eru_MPO_Importer::import( $fake, $two( $ref( 'TX1' ), array( 'taxno' => $tck ) ) );
	mpo_check( 'P8 11-digit tax number not stored while "TC kimlik no sakla" is off', '' === wc_get_order( $t1['order_id'] )->get_meta( '_eru_mpo_tax_number' ) );
	mpo_settings( array( 'store_identity' => 'yes' ) );
	$t2 = Eru_MPO_Importer::import( $fake, $two( $ref( 'TX2' ), array( 'taxno' => $tck ) ) );
	mpo_check( 'P8 stored when the setting is on', $tck === wc_get_order( $t2['order_id'] )->get_meta( '_eru_mpo_tax_number' ) );
	mpo_settings();

	// Minor — an order saved before a crash (map row left pending) is adopted with its post-import steps, once.
	mpo_settings( array( 'stock_mode' => 'reduce' ) );
	$restock();
	$r_cr  = $ref( 'CR' );
	$dcr   = $two( $r_cr );
	$dcr->marketplace = 'demo';
	Eru_MPO_Map::claim( 'demo', $r_cr );
	$built = Eru_MPO_Builder::build( $fake, $dcr );
	Eru_MPO_Isolated_Save::save( $built['order'] );
	$wpdb->update( $wpdb->prefix . 'eru_mpo_orders', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'marketplace' => 'demo', 'remote_ref' => $r_cr ) ); // phpcs:ignore
	mpo_check( 'crash set-up: order saved, stock untouched, row pending, no raw', 10 === mpo_stock( $p1 ) && 'pending' === mpo_map_row( $r_cr )->status && null === Eru_MPO_Raw::get( 'demo', $r_cr ) );
	Eru_MPO_Importer::import( $fake, $two( $r_cr ) );
	Eru_MPO_Importer::import( $fake, $two( $r_cr ) );
	mpo_check( 'crash adopted: one order, row done, stock reduced once (8 / 9), raw stored, imported action once', 1 === count( mpo_order_ids( $r_cr ) ) && 'done' === mpo_map_row( $r_cr )->status && 8 === mpo_stock( $p1 ) && 9 === mpo_stock( $p2 ) && is_array( Eru_MPO_Raw::get( 'demo', $r_cr ) ) && 1 === count( array_keys( $imported, $r_cr, true ) ) );
	mpo_settings();
	$restock();

	// Minor — a trashed order is not revived; logged once.
	$r_tr = $ref( 'TR' );
	$tr   = Eru_MPO_Importer::import( $fake, $two( $r_tr ) );
	wc_get_order( $tr['order_id'] )->delete( false );
	$tr2 = Eru_MPO_Importer::import( $fake, $two( $r_tr, array( 'status' => 'shipped' ) ) );
	$tr3 = Eru_MPO_Importer::import( $fake, $two( $r_tr, array( 'status' => 'shipped' ) ) );
	mpo_check( 'trashed order: skipped twice, still in the trash, no new order, logged once', 'skipped' === $tr2['result'] && 'skipped' === $tr3['result'] && 'trash' === Eru_MPO_Importer::stored_status( $tr['order_id'] ) && 1 === count( mpo_order_ids( $r_tr ) ) && 1 === mpo_log_count( $r_tr, 'çöp' ) );

	// Minor — il names in ASCII upper case and Turkish casing.
	$ic = Eru_MPO_Importer::import( $fake, $two( $ref( 'IL' ), array( 'bcity' => 'ISTANBUL', 'scity' => 'IZMIR' ) ) );
	$oi = wc_get_order( $ic['order_id'] );
	$st = fn( $c ) => Eru_MPO_Builder::state( Eru_MPO_Address_DTO::from_array( array( 'city' => $c ) ) );
	mpo_check( 'il names: ISTANBUL -> TR34, IZMIR -> TR35, SANLIURFA / şanlıurfa -> TR63, IÇEL -> TR33, ÇANKIRI -> TR18', 'TR34' === $oi->get_billing_state() && 'TR35' === $oi->get_shipping_state() && 'TR63' === $st( 'SANLIURFA' ) && 'TR63' === $st( 'şanlıurfa' ) && 'TR33' === $st( 'IÇEL' ) && 'TR18' === $st( 'ÇANKIRI' ) );

	// Minor — WooCommerce's sales counter keeps counting marketplace orders (allow-listed).
	mpo_settings( array( 'status_mode' => 'core' ) );
	$ts0 = mpo_total_sales( $p1 );
	Eru_MPO_Importer::import( $fake, $two( $ref( 'TS' ) ) );
	mpo_check( 'total_sales of the product rises by the quantity (2) in core mode', $ts0 + 2 === mpo_total_sales( $p1 ) );
	mpo_settings();

	// Minor — observe mode logs a package once a day, not on every pull.
	mpo_settings( array( 'observe' => 'yes' ) );
	$r_ob         = $ref( 'OB' );
	$fake->orders = array( $two( $r_ob ) );
	Eru_MPO_Importer::pull( 'demo' );
	Eru_MPO_Importer::pull( 'demo' );
	mpo_check( 'observe mode: two pulls, one "aktarılırdı" row', 1 === mpo_log_count( $r_ob, 'aktarılırdı' ) && array() === mpo_order_ids( $r_ob ) );
	$fake->orders = array();
	mpo_settings();

	// Minor — the purge keeps going in batches until done.
	$old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
	for ( $i = 0; $i < 3; $i++ ) {
		$vals = array();
		for ( $j = 0; $j < 500; $j++ ) {
			$vals[] = $wpdb->prepare( '(%s, %s, %s, %s, %s)', $old, 'info', 'demo', 'DEMO-PURGE', 'demo purge row' );
		}
		$wpdb->query( "INSERT INTO {$wpdb->prefix}eru_mpo_log (created_at, level, marketplace, remote_ref, message) VALUES " . implode( ',', $vals ) ); // phpcs:ignore
	}
	$one = fn() => 1;
	add_filter( 'eru_mpo_purge_batches', $one );
	Eru_MPO_Cron::unschedule_all();
	Eru_MPO_Cron::daily();
	$left = mpo_log_count( 'DEMO-PURGE', 'demo purge row' );
	$next = as_next_scheduled_action( 'eru_mpo_daily', array(), 'eru-mpo' );
	mpo_check( 'purge with a 1-batch cap: 1.000 removed, 500 left, next run within 2 minutes', 500 === $left && is_int( $next ) && $next - time() <= 120 );
	Eru_MPO_Cron::unschedule_all();
	Eru_MPO_Cron::daily();
	$next = as_next_scheduled_action( 'eru_mpo_daily', array(), 'eru-mpo' );
	remove_filter( 'eru_mpo_purge_batches', $one );
	mpo_check( 'second run removes the rest and re-arms a day later', 0 === mpo_log_count( 'DEMO-PURGE', 'demo purge row' ) && is_int( $next ) && $next - time() > HOUR_IN_SECONDS );

	// Final I1 — status moves made by the shop are tracked (never invisible to the rank model).
	mpo_settings( array( 'stock_mode' => 'reduce' ) );
	$restock();
	$hnotes = fn( $id, $frag ) => count( array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $id ) ), 'content' ), fn( $c ) => false !== strpos( $c, $frag ) ) );
	$fresh  = function ( $id ) {
		Eru_MPO_Importer::forget_order( $id );
		return wc_get_order( $id );
	};
	// (1) Ack mode "seçilen duruma geçince": the shop moves Yeni → Hazırlanıyor by hand; the ack success never moves it back.
	$ackf = new MPO_Ack_Fake( 'demo' );
	$swap = function ( $a ) use ( $ackf ) {
		$a['demo'] = $ackf;
		return $a;
	};
	add_filter( 'eru_mpo_adapters', $swap, 50 );
	Eru_MPO_Registry::reset();
	Eru_MPO_Settings::mp_update(
		'demo',
		array(
			'ack_mode'   => 'status',
			'ack_status' => 'eru-mpo-ready',
		)
	);
	$h1 = Eru_MPO_Importer::import( $ackf, $two( $ref( 'H1' ) ) );
	$fresh( $h1['order_id'] )->update_status( 'eru-mpo-ready' );
	$aq = as_get_scheduled_actions( array( 'hook' => Eru_MPO_Ack::HOOK, 'args' => array( (int) $h1['order_id'] ), 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
	mpo_check( 'H1 a hand move to "Pazaryeri: Hazırlanıyor" is recorded (logical status ready) and queues the acknowledgement', 'ready' === Eru_MPO_Statuses::current( $fresh( $h1['order_id'] ) ) && 1 === count( $aq ) );
	ActionScheduler::runner()->process_action( $aq[0], 'mpo-test' );
	$o = $fresh( $h1['order_id'] );
	mpo_check( 'H1 the acknowledgement is sent and does not move the order back to Onaylandı', 1 === $ackf->acks && 'eru-mpo-ready' === $o->get_status() && 'ready' === Eru_MPO_Statuses::current( $o ) );
	remove_filter( 'eru_mpo_adapters', $swap, 50 );
	Eru_MPO_Settings::mp_update( 'demo', array( 'ack_mode' => 'never' ) );
	Eru_MPO_Registry::reset();
	Eru_MPO_Cron::unschedule_all();
	// (2) The shop marks the order "Kargoda" by hand; a later "ready" from the marketplace does not move it back.
	$h2 = Eru_MPO_Importer::import( $fake, $two( $ref( 'H2' ) ) );
	$fresh( $h2['order_id'] )->update_status( 'eru-mpo-shipped' );
	Eru_MPO_Importer::import( $fake, $two( $ref( 'H2' ), array( 'status' => 'ready' ) ) );
	$o = $fresh( $h2['order_id'] );
	mpo_check( 'H2 hand move to Kargoda, then a marketplace "ready": the order stays Kargoda', 'eru-mpo-shipped' === $o->get_status() && 'shipped' === Eru_MPO_Statuses::current( $o ) );
	// (3) Reduce mode, hand "Pazaryeri: İptal": stock restored once; a later "shipped" does not reopen the order.
	$restock();
	$h3 = Eru_MPO_Importer::import( $fake, $two( $ref( 'H3' ) ) );
	mpo_check( 'H3 set-up: imported in reduce mode, stock 8 / 9', 8 === mpo_stock( $p1 ) && 9 === mpo_stock( $p2 ) );
	$adv0 = count( array_filter( $advanced, fn( $a ) => $a[0] === $h3['order_id'] && 'cancelled' === $a[2] ) );
	$fresh( $h3['order_id'] )->update_status( 'eru-mpo-cancelled' );
	$adv1 = count( array_filter( $advanced, fn( $a ) => $a[0] === $h3['order_id'] && 'cancelled' === $a[2] ) );
	mpo_check( 'H3 a hand "Pazaryeri: İptal" restores the stock once (10 / 10) and fires the cancel action once', 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) && 0 === $adv0 && 1 === $adv1 && 'cancelled' === Eru_MPO_Statuses::current( $fresh( $h3['order_id'] ) ) );
	Eru_MPO_Importer::import( $fake, $two( $ref( 'H3' ), array( 'status' => 'shipped' ) ) );
	Eru_MPO_Importer::import( $fake, $two( $ref( 'H3' ), array( 'status' => 'shipped' ) ) );
	$o = $fresh( $h3['order_id'] );
	mpo_check( 'H3 a later marketplace "shipped" keeps it cancelled, stock untouched, flagged for review with one note', 'eru-mpo-cancelled' === $o->get_status() && 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) && 'yes' === $o->get_meta( '_eru_mpo_review' ) && 1 === $hnotes( $h3['order_id'], 'elle iptal' ) );
	// (4) A hand move to core "İptal edildi": WooCommerce restores the stock; the marketplace never revives the order.
	$restock();
	$h4 = Eru_MPO_Importer::import( $fake, $two( $ref( 'H4' ) ) );
	$fresh( $h4['order_id'] )->update_status( 'cancelled' );
	Eru_MPO_Importer::import( $fake, $two( $ref( 'H4' ), array( 'status' => 'shipped' ) ) );
	$o = $fresh( $h4['order_id'] );
	mpo_check( 'H4 hand core cancel: stock 10 / 10 once, a later "shipped" does not revive it (no stock-less reopen)', 'cancelled' === $o->get_status() && 'cancelled' === Eru_MPO_Statuses::current( $o ) && 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) );
	// (5) The shop reopens a hand-cancelled order: in reduce mode the stock is reduced again, once.
	$fresh( $h3['order_id'] )->update_status( 'eru-mpo-ready' );
	$fresh( $h3['order_id'] )->update_status( 'eru-mpo-shipped' );
	mpo_check( 'H5 a hand reopen reduces the stock again, once (8 / 9 after two moves)', 8 === mpo_stock( $p1 ) && 9 === mpo_stock( $p2 ) && 'shipped' === Eru_MPO_Statuses::current( $fresh( $h3['order_id'] ) ) );
	// (6) The plugin's own moves are not hand moves (notifications not muted, so the status hooks run).
	$restock();
	mpo_settings(
		array(
			'stock_mode'         => 'reduce',
			'mute_notifications' => 'no',
		)
	);
	$h6 = Eru_MPO_Importer::import( $fake, $two( $ref( 'H6' ) ) );
	Eru_MPO_Importer::import( $fake, $two( $ref( 'H6' ), array( 'status' => 'cancelled' ) ) );
	$o = $fresh( $h6['order_id'] );
	mpo_check( 'H6 a marketplace cancel (hooks not muted) is not taken for a hand move; stock restored once', '' === (string) $o->get_meta( '_eru_mpo_hand_status' ) && 'eru-mpo-cancelled' === $o->get_status() && 10 === mpo_stock( $p1 ) && 10 === mpo_stock( $p2 ) );
	// (6b) Core mode, not muted: the plugin's own move to "delivered" (WooCommerce "completed") stays delivered; it
	// is not read back as a hand move to the lowest key behind "completed" (shipped).
	mpo_settings(
		array(
			'status_mode'        => 'core',
			'mute_notifications' => 'no',
		)
	);
	$h6b = Eru_MPO_Importer::import( $fake, $two( $ref( 'H6B' ) ) );
	Eru_MPO_Importer::import( $fake, $two( $ref( 'H6B' ), array( 'status' => 'delivered' ) ) );
	$o = $fresh( $h6b['order_id'] );
	mpo_check( 'H6 core mode, not muted: a marketplace move to delivered stays delivered (no hand marker)', 'completed' === $o->get_status() && 'delivered' === Eru_MPO_Statuses::current( $o ) && '' === (string) $o->get_meta( '_eru_mpo_hand_status' ) );
	// (7) Core status mode: the shop completes the order by hand; a later "ready" does not put it back to processing.
	mpo_settings( array( 'status_mode' => 'core' ) );
	$h7 = Eru_MPO_Importer::import( $fake, $two( $ref( 'H7' ) ) );
	$fresh( $h7['order_id'] )->update_status( 'completed' );
	Eru_MPO_Importer::import( $fake, $two( $ref( 'H7' ), array( 'status' => 'ready' ) ) );
	mpo_check( 'H7 core mode: hand "Tamamlandı", then a marketplace "ready": still completed', 'completed' === $fresh( $h7['order_id'] )->get_status() );
	mpo_settings();
	$restock();

	// M5 — woocommerce_after_order_object_save is never muted, even when a site adds it to the muted hooks.
	$addhook = fn( $h ) => array_merge( (array) $h, array( 'woocommerce_after_order_object_save' ) );
	add_filter( 'eru_mpo_isolated_save_hooks', $addhook );
	$m5 = Eru_MPO_Importer::import( $fake, $two( $ref( 'M5' ) ) );
	$m5h = Eru_MPO_Isolated_Save::hooks();
	remove_filter( 'eru_mpo_isolated_save_hooks', $addhook );
	mpo_check( 'M5 the completeness watch survives a filter that mutes woocommerce_after_order_object_save: the order is created', 'created' === $m5['result'] && ! in_array( 'woocommerce_after_order_object_save', $m5h, true ) && array( $m5['order_id'] ) === mpo_order_ids( $ref( 'M5' ) ) );

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
