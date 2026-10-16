<?php
/**
 * Marketplace Orders for WooCommerce — migrator from the older public Trendyol / Hepsiburada plugins (g):
 * dry-run writes nothing; two Trendyol orders, one Hepsiburada order and a table-only Trendyol order get the new
 * meta, statuses and map rows; legacy meta and tables stay; a second run changes nothing; batches re-arm
 * pending-only; a legacy plugin that is still active blocks the run and the pull; an order the migrator has not
 * reached yet is adopted by the importer instead of being imported twice.
 *
 *   wp eval-file tests/php/mpo-migrator.php
 */

require __DIR__ . '/mpo-helpers.php';
mpo_require_plugin();
mpo_no_http();

global $wpdb;
$failed       = false;
$tag          = mpo_run_tag();
$old_settings = get_option( Eru_MPO_Settings::OPTION, null );
$old_mp       = get_option( 'eru_mpo_mp_trendyol', null );
$old_state    = get_option( Eru_MPO_Migrator::STATE, null );
$ty_table     = $wpdb->prefix . 'eru_trendyol_orders';
$hb_table     = $wpdb->prefix . 'eru_hepsiburada_orders';
$made_tables  = array();
$order_ids    = array();
$refs         = array();
$aid          = 0;

/** Legacy dedupe tables as the public plugins create them (only when missing; dropped again afterwards). */
$ensure_tables = function () use ( $wpdb, $ty_table, $hb_table, &$made_tables ) {
	$c = $wpdb->get_charset_collate();
	if ( $ty_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ty_table ) ) ) {
		$wpdb->query( "CREATE TABLE {$ty_table} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, shipment_package_id bigint(20) NOT NULL, order_number varchar(64) DEFAULT NULL, wc_order_id bigint(20) DEFAULT NULL, status varchar(32) DEFAULT 'imported', review tinyint(1) NOT NULL DEFAULT 0, error text DEFAULT NULL, created_at datetime DEFAULT CURRENT_TIMESTAMP, updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY shipment_package_id (shipment_package_id) ) {$c}" ); // phpcs:ignore
		$made_tables[] = $ty_table;
	}
	if ( $hb_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hb_table ) ) ) {
		$wpdb->query( "CREATE TABLE {$hb_table} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_number varchar(64) NOT NULL, hb_order_id varchar(64) DEFAULT NULL, wc_order_id bigint(20) DEFAULT NULL, status varchar(32) DEFAULT 'imported', review tinyint(1) NOT NULL DEFAULT 0, error text DEFAULT NULL, created_at datetime DEFAULT CURRENT_TIMESTAMP, updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY order_number (order_number) ) {$c}" ); // phpcs:ignore
		$made_tables[] = $hb_table;
	}
};

/** Leftovers of earlier runs: test legacy orders, their table rows and map rows. */
$cleanup = function () use ( $wpdb, $ty_table, $hb_table ) {
	$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	// Test legacy orders, and any order a failed run imported for a test reference (packages 90…, DEMO-HB-…).
	$ids  = $hpos
		? $wpdb->get_col( "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_mpo_test_legacy' OR ( meta_key = '_eru_mpo_remote_ref' AND ( meta_value LIKE '90%' OR meta_value LIKE 'DEMO-HB-%' ) AND order_id IN ( SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_eru_mpo_marketplace' AND meta_value IN ('trendyol','hepsiburada') ) )" ) // phpcs:ignore
		: $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mpo_test_legacy' OR ( meta_key = '_eru_mpo_remote_ref' AND ( meta_value LIKE '90%' OR meta_value LIKE 'DEMO-HB-%' ) AND post_id IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_eru_mpo_marketplace' AND meta_value IN ('trendyol','hepsiburada') ) )" ); // phpcs:ignore
	foreach ( $ids as $id ) {
		$o = wc_get_order( (int) $id );
		if ( ! $o ) {
			continue;
		}
		foreach ( array( '_eru_trendyol_package_id' => 'trendyol', '_eru_hepsiburada_order_number' => 'hepsiburada', '_eru_mpo_remote_ref' => '' ) as $k => $mp ) {
			$v = (string) $o->get_meta( $k );
			if ( '' !== $v ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eru_mpo_orders WHERE remote_ref = %s AND marketplace IN ('trendyol','hepsiburada')", $v ) ); // phpcs:ignore
			}
		}
		$o->delete( true );
	}
	if ( $ty_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ty_table ) ) ) {
		$wpdb->query( "DELETE FROM {$ty_table} WHERE order_number LIKE 'DEMO-TY-%'" ); // phpcs:ignore
	}
	if ( $hb_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hb_table ) ) ) {
		$wpdb->query( "DELETE FROM {$hb_table} WHERE order_number LIKE 'DEMO-HB-%'" ); // phpcs:ignore
	}
	foreach ( array( 'orders', 'raw', 'log' ) as $t ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}eru_mpo_{$t} WHERE marketplace IN ('trendyol','hepsiburada') AND ( remote_ref LIKE 'DEMO-HB-%' OR remote_ref LIKE '90%' )" ); // phpcs:ignore
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_mpo\\_lock\\_%'" ); // phpcs:ignore
	Eru_MPO_Cron::unschedule_all();
	wp_cache_flush();
};

/**
 * One order the way the legacy plugin left it.
 *
 * @param string $status Legacy status slug (without wc-).
 * @param array  $meta   Order meta.
 * @param array  $imeta  Line item meta.
 * @return int
 */
$legacy_order = function ( $status, array $meta, array $imeta ) use ( &$order_ids ) {
	$o = new WC_Order();
	$o->set_created_via( 'legacy-demo' );
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_last_name( 'Alıcı' );
	$o->set_billing_email( 'demo.alici@example.com' );
	$o->set_billing_city( 'Çankaya' );
	$o->set_billing_state( 'TR06' );
	$o->set_billing_country( 'TR' );
	$item = new WC_Order_Item_Product();
	$item->set_name( 'Demo Tişört' );
	$item->set_quantity( 1 );
	$item->set_subtotal( '89.90' );
	$item->set_total( '89.90' );
	foreach ( $imeta as $k => $v ) {
		$item->add_meta_data( $k, $v, true );
	}
	$o->add_item( $item );
	$o->set_total( '89.90' );
	foreach ( $meta as $k => $v ) {
		$o->update_meta_data( $k, $v );
	}
	$o->update_meta_data( '_mpo_test_legacy', 'yes' );
	$o->set_status( $status );
	$id          = (int) $o->save();
	$order_ids[] = $id;
	return $id;
};

/** Order meta whose key starts with a prefix. @return array<string,string> */
$meta_with = function ( $id, $prefix ) {
	$o   = wc_get_order( $id );
	$out = array();
	foreach ( $o ? $o->get_meta_data() : array() as $m ) {
		if ( 0 === strpos( $m->key, $prefix ) ) {
			$out[ $m->key ] = is_scalar( $m->value ) ? (string) $m->value : wp_json_encode( $m->value );
		}
	}
	ksort( $out );
	return $out;
};

Eru_MPO_Installer::maybe_install();
$log_start = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}eru_mpo_log" ); // phpcs:ignore

// Clean-up runs once: in finally, and from a shutdown function after an exit() or a fatal error.
$final_cleanup = function () use ( &$aid, $cleanup, $log_start, &$made_tables, $old_settings, $old_mp, $old_state ) {
	global $wpdb;
	static $ran = false;
	if ( $ran ) {
		return;
	}
	$ran = true;
	if ( $aid ) {
		try {
			ActionScheduler::store()->mark_complete( $aid ); // The simulated running action, after a failed check.
		} catch ( Throwable $ignored ) {
			unset( $ignored );
		}
	}
	$cleanup();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eru_mpo_log WHERE id > %d AND marketplace IN ('trendyol','hepsiburada','migrator')", $log_start ) ); // phpcs:ignore
	foreach ( $made_tables as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore
	}
	Eru_MPO_Migrator::detect_legacy();
	foreach ( array( Eru_MPO_Settings::OPTION => $old_settings, 'eru_mpo_mp_trendyol' => $old_mp, Eru_MPO_Migrator::STATE => $old_state ) as $k => $v ) {
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
	$ensure_tables();
	$cleanup();
	Eru_MPO_Statuses::register_legacy_statuses();
	Eru_MPO_Settings::update( array_merge( Eru_MPO_Settings::defaults(), array( 'store_identity' => 'no' ) ) );
	delete_option( Eru_MPO_Migrator::STATE );

	$base = random_int( 900000, 909999 ) * 10000;
	$pkg  = array( 1 => (string) ( $base + 1 ), 2 => (string) ( $base + 2 ), 3 => (string) ( $base + 3 ), 4 => (string) ( $base + 4 ), 5 => (string) ( $base + 5 ) );
	$hbno = 'DEMO-HB-' . $tag;
	$tckn = mpo_fake_tckn();
	$refs = array( $pkg[1], $pkg[2], $pkg[4], $pkg[5], $hbno );

	$ty1 = $legacy_order(
		'trendyol-yeni',
		array(
			'_eru_trendyol_source'          => 'trendyol',
			'_eru_trendyol_package_id'      => $pkg[1],
			'_eru_trendyol_order_number'    => 'DEMO-TY-' . $tag . '-1',
			'_eru_trendyol_cargo_provider'  => 'Demo Kargo',
			'_eru_trendyol_cargo_tracking'  => 'DEMO-TRK-1',
			'_eru_trendyol_identity_number' => $tckn,
			'_eru_trendyol_review'          => 'yes',
			'_eru_trendyol_picking_pushed'  => 'yes',
			'_eru_trendyol_raw'             => '{"marker":"legacy-raw"}',
			'_eru_trendyol_imported_at'     => '2026-10-01 15:00:00',
		),
		array(
			'trendyol_stock_code'           => 'DEMO-SKU-1',
			'trendyol_barcode'              => 'DEMO-BC-1',
			'_eru_trendyol_commission_rate' => '10',
		)
	);
	$ty2 = $legacy_order(
		'trendyol-kargo',
		array(
			'_eru_trendyol_source'       => 'trendyol',
			'_eru_trendyol_package_id'   => $pkg[2],
			'_eru_trendyol_order_number' => 'DEMO-TY-' . $tag . '-2',
			'_eru_trendyol_tax_number'   => $tckn,
		),
		array( 'trendyol_stock_code' => 'DEMO-SKU-2' )
	);
	$hb1 = $legacy_order(
		'hepsiburada-hazir',
		array(
			'_eru_hepsiburada_source'          => 'hepsiburada',
			'_eru_hepsiburada_order_number'    => $hbno,
			'_eru_hepsiburada_order_id'        => 'DEMO-HBID-' . $tag,
			'_eru_hepsiburada_cargo_company'   => 'Demo Kargo',
			'_eru_hepsiburada_identity_number' => $tckn,
			'_eru_hepsiburada_cancel_noted'    => '["DEMO-LI-3"]',
			'_eru_hepsiburada_noted_lines'     => '["DEMO-LI-9"]',
		),
		array(
			'hepsiburada_merchant_sku'      => 'DEMO-SKU-3',
			'_eru_hepsiburada_line_item_id' => 'DEMO-LI-3',
		)
	);
	$ty3 = $legacy_order( 'trendyol-teslim', array(), array() ); // In the legacy table only (meta lost).
	$hb2 = $legacy_order( 'hepsiburada-yeni', array(), array() ); // In the legacy table only (meta lost).
	$wpdb->insert( $ty_table, array( 'shipment_package_id' => $pkg[1], 'order_number' => 'DEMO-TY-' . $tag . '-1', 'wc_order_id' => $ty1, 'status' => 'imported', 'review' => 1 ) ); // phpcs:ignore
	$wpdb->insert( $ty_table, array( 'shipment_package_id' => $pkg[2], 'order_number' => 'DEMO-TY-' . $tag . '-2', 'wc_order_id' => $ty2, 'status' => 'imported' ) ); // phpcs:ignore
	$wpdb->insert( $ty_table, array( 'shipment_package_id' => $pkg[3], 'order_number' => 'DEMO-TY-' . $tag . '-3', 'wc_order_id' => 2147483000, 'status' => 'imported' ) ); // phpcs:ignore
	$wpdb->insert( $ty_table, array( 'shipment_package_id' => $pkg[4], 'order_number' => 'DEMO-TY-' . $tag . '-4', 'wc_order_id' => $ty3, 'status' => 'imported' ) ); // phpcs:ignore
	$wpdb->insert( $hb_table, array( 'order_number' => $hbno, 'hb_order_id' => 'DEMO-HBID-' . $tag, 'wc_order_id' => $hb1, 'status' => 'imported' ) ); // phpcs:ignore
	$wpdb->insert( $hb_table, array( 'order_number' => $hbno . '-2', 'hb_order_id' => 'DEMO-HBID2-' . $tag, 'wc_order_id' => $hb2, 'status' => 'imported' ) ); // phpcs:ignore
	$ty_rows_before = $wpdb->get_results( "SELECT * FROM {$ty_table} WHERE order_number LIKE 'DEMO-TY-%' ORDER BY id", ARRAY_A ); // phpcs:ignore
	$hb_rows_before = $wpdb->get_results( "SELECT * FROM {$hb_table} WHERE order_number LIKE 'DEMO-HB-%' ORDER BY id", ARRAY_A ); // phpcs:ignore
	$legacy_before  = array(
		$ty1 => $meta_with( $ty1, '_eru_trendyol_' ),
		$ty2 => $meta_with( $ty2, '_eru_trendyol_' ),
		$hb1 => $meta_with( $hb1, '_eru_hepsiburada_' ),
	);
	mpo_check( '(g) legacy orders created with their legacy statuses', 'trendyol-yeni' === wc_get_order( $ty1 )->get_status() && 'trendyol-kargo' === wc_get_order( $ty2 )->get_status() && 'hepsiburada-hazir' === wc_get_order( $hb1 )->get_status() && 'trendyol-teslim' === wc_get_order( $ty3 )->get_status() );

	// Dry run: a report, nothing written.
	$rep = Eru_MPO_Migrator::scan();
	mpo_check( '(g) dry run: Trendyol 3 to migrate (2 by meta + 1 table-only), 1 orphan row; Hepsiburada 2 (1 + 1 table-only)', 3 === (int) $rep['trendyol']['to_migrate'] && 1 === (int) $rep['trendyol']['orphans'] && 2 === (int) $rep['hepsiburada']['to_migrate'] && 0 === (int) $rep['trendyol']['migrated'] );
	mpo_check( '(g) dry run lists legacy statuses', 1 === (int) ( $rep['trendyol']['statuses']['trendyol-yeni'] ?? 0 ) && 1 === (int) ( $rep['trendyol']['statuses']['trendyol-kargo'] ?? 0 ) && 1 === (int) ( $rep['hepsiburada']['statuses']['hepsiburada-hazir'] ?? 0 ) );
	mpo_check( '(g) dry run writes nothing (no new meta, statuses, map rows, job or state)', array() === $meta_with( $ty1, '_eru_mpo_' ) && array() === $meta_with( $hb1, '_eru_mpo_' ) && 'trendyol-yeni' === wc_get_order( $ty1 )->get_status() && null === mpo_map_row( $pkg[1], 'trendyol' ) && array() === mpo_pending( 'eru_mpo_migrate' ) && null === get_option( Eru_MPO_Migrator::STATE, null ) );

	// A legacy plugin that is still active blocks the run (and the pull for that marketplace).
	$legacy_on = fn() => array( 'trendyol' );
	add_filter( 'eru_mpo_legacy_active', $legacy_on );
	$blocked = Eru_MPO_Migrator::start();
	if ( ! class_exists( 'Eru_MPO_Admin' ) ) {
		require_once ERU_MPO_PATH . 'includes/class-eru-mpo-admin.php';
	}
	ob_start();
	wp_set_current_user( 1 );
	Eru_MPO_Admin::legacy_notice();
	$notice = (string) ob_get_clean();
	$fake   = mpo_fake_adapter( 'trendyol' );
	mpo_settings( array(), 'trendyol' );
	$pull = Eru_MPO_Importer::pull( 'trendyol' );
	remove_filter( 'eru_mpo_legacy_active', $legacy_on );
	mpo_check( '(g) legacy plugin active: start refused, nothing scheduled', is_wp_error( $blocked ) && 'eru_mpo_legacy_active' === $blocked->get_error_code() && array() === mpo_pending( 'eru_mpo_migrate' ) );
	mpo_check( '(g) legacy plugin active: admin notice "önce eski eklentiyi devre dışı bırakın"', false !== strpos( $notice, 'önce eski eklentiyi devre dışı bırakın' ) );
	mpo_check( '(g) legacy plugin active: pull for that marketplace refused before any fetch', 'legacy_active' === $pull['result'] && 0 === $fake->fetches );
	Eru_MPO_Settings::update( array_merge( Eru_MPO_Settings::defaults(), array( 'store_identity' => 'no' ) ) );

	// Run in batches of 2: the running action re-arms a pending one; the last batch stops.
	$small = fn() => 2;
	add_filter( 'eru_mpo_migrate_batch', $small );
	mpo_check( '(g) start schedules one migrate action', true === Eru_MPO_Migrator::start() && 1 === count( mpo_pending( 'eru_mpo_migrate' ) ) );
	$aid = mpo_pending( 'eru_mpo_migrate' )[0];
	ActionScheduler::store()->log_execution( $aid );
	$b1 = Eru_MPO_Migrator::run_batch();
	$re = mpo_pending( 'eru_mpo_migrate' );
	mpo_check( '(g) first batch migrates 2 and re-arms although its own action is running', 2 === (int) $b1['migrated'] && 1 === count( $re ) && (int) $re[0] !== (int) $aid );
	ActionScheduler::store()->mark_complete( $aid );
	$aid = 0;
	for ( $i = 0; $i < 5 && 'done' !== ( get_option( Eru_MPO_Migrator::STATE )['status'] ?? '' ); $i++ ) {
		Eru_MPO_Migrator::run_batch();
	}
	remove_filter( 'eru_mpo_migrate_batch', $small );
	$state = get_option( Eru_MPO_Migrator::STATE );
	mpo_check( '(g) batches finish: state done, 5 migrated, 1 orphan row', 'done' === $state['status'] && 5 === (int) $state['migrated'] && 1 === (int) $state['orphans'] );
	foreach ( mpo_pending( 'eru_mpo_migrate' ) as $leftover ) {
		as_unschedule_action( 'eru_mpo_migrate', array(), 'eru-mpo' );
	}

	$o1 = wc_get_order( $ty1 );
	mpo_check( '(g) Trendyol order 1: new meta and eru-mpo-new', 'trendyol' === $o1->get_meta( '_eru_mpo_marketplace' ) && $pkg[1] === (string) $o1->get_meta( '_eru_mpo_remote_ref' ) && $pkg[1] === (string) $o1->get_meta( '_eru_mpo_package_id' ) && ( 'DEMO-TY-' . $tag . '-1' ) === $o1->get_meta( '_eru_mpo_order_number' ) && 'DEMO-TRK-1' === $o1->get_meta( '_eru_mpo_cargo_tracking' ) && 'eru-mpo-new' === $o1->get_status() && 'new' === $o1->get_meta( '_eru_mpo_status' ) && 'yes' === $o1->get_meta( '_eru_mpo_review' ) && 'done' === $o1->get_meta( '_eru_mpo_ack_state' ) );
	$i1 = array_values( $o1->get_items() )[0];
	mpo_check( '(g) Trendyol line meta mapped (SKU, barcode, commission)', 'DEMO-SKU-1' === $i1->get_meta( '_eru_mpo_sku' ) && 'DEMO-BC-1' === $i1->get_meta( '_eru_mpo_barcode' ) && '10' === (string) $i1->get_meta( '_eru_mpo_commission_rate' ) );
	mpo_check( '(g) identity number not copied (setting off), raw not copied to new meta', '' === $o1->get_meta( '_eru_mpo_identity_number' ) && '' === $o1->get_meta( '_eru_mpo_raw' ) );
	mpo_check( '(g) an 11-digit legacy tax number is treated as an identity number (not copied)', '' === wc_get_order( $ty2 )->get_meta( '_eru_mpo_tax_number' ) );
	mpo_check( '(g) legacy site-local time converted to UTC', get_gmt_from_date( '2026-10-01 15:00:00' ) === $o1->get_meta( '_eru_mpo_imported_at' ) );
	mpo_check( '(g) Trendyol order 2 -> eru-mpo-shipped', 'eru-mpo-shipped' === wc_get_order( $ty2 )->get_status() && 'shipped' === wc_get_order( $ty2 )->get_meta( '_eru_mpo_status' ) );
	$h1 = wc_get_order( $hb1 );
	mpo_check( '(g) Hepsiburada order -> eru-mpo-ready, order number as reference, remote order id kept', 'hepsiburada' === $h1->get_meta( '_eru_mpo_marketplace' ) && $hbno === $h1->get_meta( '_eru_mpo_remote_ref' ) && ( 'DEMO-HBID-' . $tag ) === $h1->get_meta( '_eru_mpo_remote_order_id' ) && 'eru-mpo-ready' === $h1->get_status() && 'DEMO-SKU-3' === array_values( $h1->get_items() )[0]->get_meta( '_eru_mpo_sku' ) );
	$hev = $h1->get_meta( '_eru_mpo_events' );
	mpo_check( '(g) Hepsiburada noted cancels become applied event ids; noted lines kept', is_array( $hev ) && in_array( 'cancel-line-DEMO-LI-3', $hev, true ) && '["DEMO-LI-9"]' === $h1->get_meta( '_eru_mpo_noted_lines' ) );
	$h2 = wc_get_order( $hb2 );
	mpo_check( '(g) table-only Hepsiburada order: order number and hb_order_id from the legacy table', ( $hbno . '-2' ) === $h2->get_meta( '_eru_mpo_remote_ref' ) && ( 'DEMO-HBID2-' . $tag ) === $h2->get_meta( '_eru_mpo_remote_order_id' ) && 'eru-mpo-new' === $h2->get_status() );
	$o3 = wc_get_order( $ty3 );
	mpo_check( '(g) table-only Trendyol order: reference from the legacy table, eru-mpo-delivered', $pkg[4] === (string) $o3->get_meta( '_eru_mpo_remote_ref' ) && 'eru-mpo-delivered' === $o3->get_status() );
	$maps = array(
		mpo_map_row( $pkg[1], 'trendyol' ),
		mpo_map_row( $pkg[2], 'trendyol' ),
		mpo_map_row( $pkg[4], 'trendyol' ),
		mpo_map_row( $hbno, 'hepsiburada' ),
	);
	mpo_check( '(g) four map rows done with the right order ids; the orphan row is not mapped', $maps[0] && (int) $maps[0]->wc_order_id === $ty1 && (int) $maps[1]->wc_order_id === $ty2 && (int) $maps[2]->wc_order_id === $ty3 && (int) $maps[3]->wc_order_id === $hb1 && 'done' === $maps[0]->status && 1 === (int) $maps[0]->review && null === mpo_map_row( $pkg[3], 'trendyol' ) );
	mpo_check( '(g) legacy meta untouched (all keys and values)', $legacy_before[ $ty1 ] === $meta_with( $ty1, '_eru_trendyol_' ) && $legacy_before[ $ty2 ] === $meta_with( $ty2, '_eru_trendyol_' ) && $legacy_before[ $hb1 ] === $meta_with( $hb1, '_eru_hepsiburada_' ) );
	mpo_check( '(g) legacy item meta untouched', 'DEMO-SKU-1' === $i1->get_meta( 'trendyol_stock_code' ) );
	mpo_check( '(g) legacy tables untouched', $ty_rows_before === $wpdb->get_results( "SELECT * FROM {$ty_table} WHERE order_number LIKE 'DEMO-TY-%' ORDER BY id", ARRAY_A ) && $hb_rows_before === $wpdb->get_results( "SELECT * FROM {$hb_table} WHERE order_number LIKE 'DEMO-HB-%' ORDER BY id", ARRAY_A ) ); // phpcs:ignore

	// Second run: nothing new.
	$snap = array();
	foreach ( array( $ty1, $ty2, $hb1, $ty3, $hb2 ) as $id ) {
		$snap[ $id ] = array( $meta_with( $id, '_eru_mpo_' ), wc_get_order( $id )->get_status(), count( wc_get_order( $id )->get_customer_order_notes() ), count( wc_get_order_notes( array( 'order_id' => $id ) ) ) );
	}
	$map_snap = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}eru_mpo_orders WHERE marketplace IN ('trendyol','hepsiburada') ORDER BY id", ARRAY_A ); // phpcs:ignore
	Eru_MPO_Migrator::start();
	$b2 = Eru_MPO_Migrator::run_batch();
	foreach ( mpo_pending( 'eru_mpo_migrate' ) as $leftover ) {
		as_unschedule_action( 'eru_mpo_migrate', array(), 'eru-mpo' );
	}
	$same = true;
	foreach ( $snap as $id => $s ) {
		$same = $same && $s === array( $meta_with( $id, '_eru_mpo_' ), wc_get_order( $id )->get_status(), count( wc_get_order( $id )->get_customer_order_notes() ), count( wc_get_order_notes( array( 'order_id' => $id ) ) ) );
	}
	mpo_check( '(g) second run migrates nothing and changes no meta, status, note or map row', 0 === (int) $b2['migrated'] && $same && $map_snap === $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}eru_mpo_orders WHERE marketplace IN ('trendyol','hepsiburada') ORDER BY id", ARRAY_A ) ); // phpcs:ignore
	$rep2 = Eru_MPO_Migrator::scan();
	mpo_check( '(g) dry run after migration: nothing left, 3 + 2 migrated', 0 === (int) $rep2['trendyol']['to_migrate'] && 0 === (int) $rep2['hepsiburada']['to_migrate'] && 3 === (int) $rep2['trendyol']['migrated'] && 2 === (int) $rep2['hepsiburada']['migrated'] );
	mpo_check( '(g) migration done and no order left in a legacy status: legacy statuses retire', ! Eru_MPO_Migrator::legacy_present() );

	// A reactivated legacy plugin moved two migrated orders back to legacy statuses: a re-run repairs them.
	$hb_adv = array();
	add_action(
		'eru_mpo_order_status_advanced',
		function ( $o, $f, $t ) use ( &$hb_adv, $hb1 ) {
			if ( $o->get_id() === $hb1 ) {
				$hb_adv[] = $t;
			}
		},
		10,
		3
	);
	wc_get_order( $hb1 )->get_data_store()->set_stock_reduced( $hb1, true );
	foreach ( array( $ty2 => 'trendyol-kargo', $ty1 => 'trendyol-teslim', $hb1 => 'hepsiburada-iptal' ) as $id => $legacy ) {
		$lo = wc_get_order( $id );
		$lo->set_status( $legacy );
		$lo->save();
	}
	$rep3 = Eru_MPO_Migrator::scan();
	mpo_check( '(g) dry run sees 2 + 1 migrated orders back in legacy statuses', 2 === (int) $rep3['trendyol']['to_repair'] && 1 === (int) $rep3['hepsiburada']['to_repair'] && 0 === (int) $rep3['trendyol']['to_migrate'] );
	Eru_MPO_Migrator::start();
	for ( $i = 0; $i < 5 && 'done' !== ( get_option( Eru_MPO_Migrator::STATE )['status'] ?? '' ); $i++ ) {
		Eru_MPO_Migrator::run_batch();
	}
	mpo_check( '(g) repair: kargo -> eru-mpo-shipped; teslim (ahead of new) -> eru-mpo-delivered with the logical status moved forward', 'eru-mpo-shipped' === wc_get_order( $ty2 )->get_status() && 'eru-mpo-delivered' === wc_get_order( $ty1 )->get_status() && 'delivered' === wc_get_order( $ty1 )->get_meta( '_eru_mpo_status' ) );
	mpo_check( '(g) repair to cancelled (hepsiburada-iptal): eru-mpo-cancelled, reduced stock restored (flag cleared), one status action', 'eru-mpo-cancelled' === wc_get_order( $hb1 )->get_status() && ! Eru_MPO_Stock::is_reduced( wc_get_order( $hb1 ) ) && array( 'cancelled' ) === $hb_adv );
	$rsnap = array( $meta_with( $ty1, '_eru_mpo_' ), $meta_with( $ty2, '_eru_mpo_' ), count( wc_get_order_notes( array( 'order_id' => $ty1 ) ) ), $hb_adv );
	Eru_MPO_Migrator::start();
	$b3 = Eru_MPO_Migrator::run_batch();
	mpo_check( '(g) repair is idempotent: a further run changes nothing', 0 === (int) $b3['repaired'] && $rsnap === array( $meta_with( $ty1, '_eru_mpo_' ), $meta_with( $ty2, '_eru_mpo_' ), count( wc_get_order_notes( array( 'order_id' => $ty1 ) ) ), $hb_adv ) );
	$hl = wc_get_order( $hb1 );
	$hl->set_status( 'hepsiburada-iptal' );
	$hl->save();
	Eru_MPO_Migrator::start();
	for ( $i = 0; $i < 5 && 'done' !== ( get_option( Eru_MPO_Migrator::STATE )['status'] ?? '' ); $i++ ) {
		Eru_MPO_Migrator::run_batch();
	}
	mpo_check( '(g) moved back to iptal again and repaired again: still cancelled, the status action does not fire twice', 'eru-mpo-cancelled' === wc_get_order( $hb1 )->get_status() && array( 'cancelled' ) === $hb_adv );
	foreach ( mpo_pending( 'eru_mpo_migrate' ) as $leftover ) {
		as_unschedule_action( 'eru_mpo_migrate', array(), 'eru-mpo' );
	}

	// An order the migrator has not reached: the importer adopts it instead of importing it again.
	$ty5 = $legacy_order(
		'trendyol-hazir',
		array(
			'_eru_trendyol_source'       => 'trendyol',
			'_eru_trendyol_package_id'   => $pkg[5],
			'_eru_trendyol_order_number'    => 'DEMO-TY-' . $tag . '-5',
			'_eru_trendyol_identity_number' => $tckn,
		),
		array()
	);
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( Eru_MPO_Importer::hpos() ? 'woocommerce_page_wc-orders' : 'shop_order' );
	$_GET['action'] = 'edit';
	$_GET['id']    = $ty2;
	$_GET['post']  = $ty2;
	$list_plain    = wc_get_order_statuses();
	$_GET['id']    = $ty5;
	$_GET['post']  = $ty5;
	$list_legacy   = wc_get_order_statuses();
	unset( $_GET['id'], $_GET['post'], $_GET['action'] );
	$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$list_elsewhere = wc_get_order_statuses();
	mpo_check( '(g) legacy statuses are not offered when editing a non-legacy order, but are for a legacy order and in lists', ! isset( $list_plain['wc-trendyol-yeni'] ) && isset( $list_plain['wc-eru-mpo-new'] ) && isset( $list_legacy['wc-trendyol-hazir'] ) && isset( $list_elsewhere['wc-trendyol-hazir'] ) );
	mpo_settings( array( 'store_identity' => 'yes' ), 'trendyol' );
	$adopt = Eru_MPO_Importer::import( $fake, mpo_dto( 'order-basic.json', array( '{{REF}}' => $pkg[5], '{{SKU}}' => 'MPO-TEST-NONE', '{{STATUS}}' => 'shipped', '{{TCKN}}' => '' ) ) );
	mpo_check( '(g) importer adopts the unmigrated legacy order: no second order, map row points to it', 'created' !== $adopt['result'] && $ty5 === (int) $adopt['order_id'] && array( $ty5 ) === mpo_order_ids( $pkg[5], 'trendyol' ) && (int) mpo_map_row( $pkg[5], 'trendyol' )->wc_order_id === $ty5 );
	mpo_check( '(g) adopted order got the new meta and advanced ready -> shipped', 'eru-mpo-shipped' === wc_get_order( $ty5 )->get_status() && $pkg[5] === (string) wc_get_order( $ty5 )->get_meta( '_eru_trendyol_package_id' ) );
	mpo_check( '(g) with "e-Arşiv için TC kimlik no sakla" on, the legacy identity number is copied (P8)', $tckn === wc_get_order( $ty5 )->get_meta( '_eru_mpo_identity_number' ) );

	// M3 — a listener throws after a complete migration save: the order counts as migrated and gets its map row.
	$pm3  = '90' . random_int( 10000000, 99999999 );
	$ty9  = $legacy_order( 'processing', array( '_eru_trendyol_package_id' => $pm3, '_eru_trendyol_order_number' => 'DEMO-ON-M3' ), array() );
	$boom = function ( $o ) use ( $ty9 ) {
		if ( $o instanceof WC_Order && (int) $o->get_id() === $ty9 && '' !== (string) $o->get_meta( '_eru_mpo_migrated' ) ) {
			throw new Error( 'Demo: listener after save' );
		}
	};
	add_action( 'woocommerce_after_order_object_save', $boom, 50 );
	try {
		$m3 = Eru_MPO_Migrator::migrate_order( $ty9, 'trendyol' );
	} catch ( Throwable $t ) {
		$m3 = 'threw: ' . get_class( $t );
	} finally {
		remove_action( 'woocommerce_after_order_object_save', $boom, 50 );
	}
	$m3row = mpo_map_row( $pm3, 'trendyol' );
	mpo_check( '(M3) a listener error after a complete migration save: migrated, map row written', 'migrated' === $m3 && $m3row && (int) $m3row->wc_order_id === $ty9 && 'done' === $m3row->status );
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
