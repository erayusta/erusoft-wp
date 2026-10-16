<?php
/**
 * Marketplace Orders for WooCommerce — admin: full stock sync handler (d), migrator screen (e), order list
 * column and filter under HPOS (f), privacy exporter/eraser (g), plus paid statuses, write-only credentials and
 * enqueued actions on the marketplace tabs, the log viewer (redaction, filters, raw link, purge), the order box
 * and the stock bridge rules form.
 *
 *   wp eval-file tests/php/mpo-admin.php
 */

require __DIR__ . '/mpo-adapter-helpers.php';
mpo_require_plugin();
Eru_MPO_Plugin::load_admin();
foreach ( array( 'Eru_MPO_Admin_Actions', 'Eru_MPO_Admin_Pages', 'Eru_MPO_Orders_List', 'Eru_MPO_Metabox', 'Eru_MPO_Privacy', 'Eru_MPO_Stock_Bridge', 'Eru_MPO_Rules' ) as $mpo_c ) {
	if ( ! class_exists( $mpo_c ) ) {
		echo "FAIL: {$mpo_c} missing\n";
		exit( 1 );
	}
}

global $wpdb;
$router  = new MPO_Router();
$ty_host = 'https://api.demo-pazaryeri.invalid/integration';
add_filter( 'eru_mpo_trendyol_base_url', fn() => $ty_host );
$fake  = mpo_fake_adapter( 'demo' );
$fake2 = mpo_fake_adapter( 'demo2' );
$old   = array();
foreach ( array( Eru_MPO_Settings::OPTION, 'eru_mpo_mp_demo', 'eru_mpo_mp_demo2', 'eru_mpo_mp_trendyol', 'eru_mpo_migration', 'eru_mpo_legacy_present' ) as $n ) {
	$old[ $n ] = get_option( $n, null );
}
$failed  = false;
$tag     = mpo_run_tag();
$sku     = 'MPO-TEST-' . $tag;
$t       = $wpdb->prefix . 'eru_mpo_';
$email   = 'demo.alici@example.com';
$sub_id  = 0;
$created = array();
$admin   = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
$creds   = array(
	'seller_id'  => '1000',
	'api_key'    => 'demo-' . 'key-' . strtolower( $tag ),
	'api_secret' => 'demo-' . 'secret-' . strtolower( $tag ),
);

$dto = function ( $ref, $status = 'new', $mp_sku = null, $tckn = '' ) use ( $sku ) {
	return mpo_dto(
		'order-basic.json',
		array(
			'{{REF}}'    => $ref,
			'{{SKU}}'    => $mp_sku ?? $sku,
			'{{STATUS}}' => $status,
			'{{TCKN}}'   => '' !== $tckn ? $tckn : mpo_fake_tckn(),
		)
	);
};
$html = function ( callable $fn ) {
	ob_start();
	$fn();
	return (string) ob_get_clean();
};
$wp_err = fn( $r, $code ) => is_wp_error( $r ) && $code === $r->get_error_code();

$cleanup = function () use ( &$sub_id, &$created, $old, $wpdb, $t, $email, $tag ) {
	static $ran = false;
	if ( $ran ) {
		return;
	}
	$ran = true;
	wp_set_current_user( 0 );
	unset( $_GET['eru_mpo_mp'] );
	mpo_reset();
	mpo_delete_orders( $created );
	foreach ( array( 'demo2', 'trendyol' ) as $mp ) {
		mpo_adapter_cleanup( $mp, 'mpo.test.admin@example.com' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}orders WHERE marketplace = %s AND remote_ref LIKE %s", $mp, 'DEMO-PKG-' . $tag . '-%' ) ); // phpcs:ignore
	}
	foreach ( array( 'raw', 'log' ) as $tb ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}{$tb} WHERE remote_ref LIKE %s", 'DEMO-PKG-' . $tag . '-%' ) ); // phpcs:ignore -- this run's rows only.
	}
	$wpdb->query( "DELETE FROM {$t}sync_queue WHERE marketplace IN ('demo','demo2','trendyol')" ); // phpcs:ignore
	foreach ( array( 'demo', 'demo2', 'trendyol' ) as $mp ) {
		foreach ( array( 'bridge', 'full', 'conn', 'listings', 'orders', 'lifecycle' ) as $k ) {
			delete_option( 'eru_mpo_sync_' . $k . '_' . $mp );
		}
	}
	mpo_delete_products();
	if ( $sub_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $sub_id );
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
	foreach ( get_users( array( 'search' => 'mpo-test-sub-*', 'search_columns' => array( 'user_login' ), 'fields' => 'ID' ) ) as $u ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $u );
	}
	$sub_id = (int) wp_insert_user(
		array(
			'user_login' => 'mpo-test-sub-' . strtolower( $tag ),
			'user_email' => 'mpo-test-sub-' . strtolower( $tag ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	mpo_check( 'setup: an administrator and a subscriber', $admin > 0 && $sub_id > 0 );
	mpo_settings();
	Eru_MPO_Settings::mp_update( 'demo2', array( 'enabled' => 'yes' ) );
	$pid = mpo_product( $sku, 10 );

	// (d) Full stock sync: refused without the capability or the nonce; with both, Action Scheduler batches only.
	Eru_MPO_Settings::mp_update( 'demo', array( 'push_stock' => 'yes' ) );
	as_unschedule_all_actions( 'eru_mpo_full_sync', array(), 'eru-mpo' );
	$n0 = count( $router->calls );
	wp_set_current_user( $sub_id );
	$r = Eru_MPO_Admin_Actions::full_sync_request(
		array(
			'mp'       => 'demo',
			'_wpnonce' => wp_create_nonce( 'eru_mpo_full_sync' ),
		)
	);
	mpo_check( '(d) full sync without manage_woocommerce -> refused, nothing queued', $wp_err( $r, 'eru_mpo_forbidden' ) && array() === mpo_pending( 'eru_mpo_full_sync' ) );
	wp_set_current_user( $admin );
	$r = Eru_MPO_Admin_Actions::full_sync_request(
		array(
			'mp'       => 'demo',
			'_wpnonce' => 'nope',
		)
	);
	mpo_check( '(d) full sync with a bad nonce -> refused, nothing queued', $wp_err( $r, 'eru_mpo_bad_nonce' ) && array() === mpo_pending( 'eru_mpo_full_sync' ) );
	$r = Eru_MPO_Admin_Actions::full_sync_request(
		array(
			'mp'       => 'demo',
			'_wpnonce' => wp_create_nonce( 'eru_mpo_full_sync' ),
		)
	);
	$fs = mpo_pending_args( 'eru_mpo_full_sync', array( 'demo', 0 ) );
	mpo_check( '(d) with cap + nonce: one queued batch from the start, no HTTP, nothing pushed', true === $r && 1 === count( $fs ) && count( $router->calls ) === $n0 );
	$wpdb->query( "DELETE FROM {$t}sync_queue WHERE marketplace IN ('demo','demo2')" ); // phpcs:ignore
	Eru_MPO_Settings::mp_update( 'demo2', array( 'push_stock' => 'yes' ) );
	add_filter( 'eru_mpo_full_sync_batch', fn() => 2 );
	$runs = 0;
	while ( ( $p = mpo_pending( 'eru_mpo_full_sync' ) ) && $runs < 500 ) {
		mpo_run_action( $p[0] );
		++$runs;
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t}sync_queue WHERE product_id = %d AND marketplace = 'demo' AND kind = 'stock'", $pid ) ); // phpcs:ignore
	mpo_check( '(d) the batches re-arm themselves (2 products each) until done, queue the product and arm one flush', $runs >= 2 && null !== $row && 1 === count( mpo_pending_args( 'eru_mpo_stock_flush', array( 'demo' ) ) ) && 'done' === ( get_option( 'eru_mpo_sync_full_demo' )['status'] ?? '' ) );
	mpo_check( '(d) still no HTTP request during the batches', count( $router->calls ) === $n0 );
	mpo_check( '(d) "send all" for demo queues only demo, not the other active marketplace', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}sync_queue WHERE marketplace = 'demo2'" ) ); // phpcs:ignore
	Eru_MPO_Settings::mp_update( 'demo2', array( 'push_stock' => 'no' ) );
	Eru_MPO_Settings::mp_update( 'demo', array( 'push_stock' => 'no' ) );
	$wpdb->query( "DELETE FROM {$t}sync_queue WHERE marketplace = 'demo'" ); // phpcs:ignore
	Eru_MPO_Cron::unschedule_all();

	// Stock bridge form: capability, nonce, sanitised rules.
	wp_set_current_user( $sub_id );
	$post = array(
		'mp'       => 'demo',
		'_wpnonce' => wp_create_nonce( 'eru_mpo_save_bridge_demo' ),
		'bridge'   => array(
			'push_stock'       => 'yes',
			'price_multiplier' => '1,15',
			'rounding'         => '99',
			'excluded_skus'    => "A-1\nB-2",
			'stock_floor'      => '3',
		),
	);
	mpo_check( 'bridge form without the capability -> refused', $wp_err( Eru_MPO_Admin_Actions::save_bridge_request( $post ), 'eru_mpo_forbidden' ) );
	wp_set_current_user( $admin );
	$post['_wpnonce'] = wp_create_nonce( 'eru_mpo_save_bridge_demo' );
	$sv               = Eru_MPO_Admin_Actions::save_bridge_request( $post );
	Eru_MPO_Settings::reset_cache();
	$rules = Eru_MPO_Rules::get( 'demo' );
	mpo_check( 'bridge form saved: push stock on, price off, rules sanitised', true === $sv && 'yes' === Eru_MPO_Settings::mp_get( 'demo', 'push_stock' ) && 'no' === Eru_MPO_Settings::mp_get( 'demo', 'push_price' ) && '1.15' === (string) $rules['price_multiplier'] && '99' === $rules['rounding'] && array( 'A-1', 'B-2' ) === $rules['excluded_skus'] && 3 === $rules['stock_floor'] );
	Eru_MPO_Settings::mp_update( 'demo', array( 'push_stock' => 'no' ) );
	Eru_MPO_Cron::unschedule_all();
	$page = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'stok' ) );
	mpo_check( 'after saving rules the tab offers "Kuralları uygula (tam senkron)" once', false !== strpos( $page, 'Kuralları uygula (tam senkron)' ) && false === strpos( $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'stok' ) ), 'Kuralları uygula (tam senkron)' ) );
	mpo_check( 'stock bridge tab renders the rules and the full sync form', false !== strpos( $page, 'eru_mpo_full_sync' ) && false !== strpos( $page, 'price_multiplier' ) && false !== strpos( $page, 'excluded_cats' ) );

	// (e) Migrator screen: dry-run counts rendered, the run enqueues Action Scheduler, the handler refuses without the cap.
	$leg = wc_create_order();
	$leg->update_meta_data( '_eru_trendyol_package_id', 'DEMO-PKG-' . $tag . '-LEG' );
	$leg->update_meta_data( '_eru_trendyol_order_number', 'DEMO-ON-' . $tag );
	$leg->set_billing_email( 'mpo.test.admin@example.com' );
	$leg->save();
	$created[] = $leg->get_id();
	delete_option( 'eru_mpo_migration' );
	as_unschedule_all_actions( 'eru_mpo_migrate', array(), 'eru-mpo' );
	$scan = Eru_MPO_Migrator::scan();
	$page = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'gecis' ) );
	mpo_check( '(e) dry-run report renders the Trendyol row with its counts, nothing queued by rendering', $scan['trendyol']['to_migrate'] >= 1 && false !== strpos( $page, 'Kuru çalıştırma' ) && 1 === preg_match( '#<td>Trendyol</td><td>' . (int) $scan['trendyol']['orders'] . '</td><td>' . (int) $scan['trendyol']['to_migrate'] . '</td>#', $page ) && array() === mpo_pending( 'eru_mpo_migrate' ) );
	wp_set_current_user( $sub_id );
	$r = Eru_MPO_Admin_Actions::migrate_request( array( '_wpnonce' => wp_create_nonce( 'eru_mpo_migrate' ) ) );
	mpo_check( '(e) run without the capability -> refused, nothing queued', $wp_err( $r, 'eru_mpo_forbidden' ) && array() === mpo_pending( 'eru_mpo_migrate' ) && false === get_option( 'eru_mpo_migration' ) );
	wp_set_current_user( $admin );
	mpo_check( '(e) run with a bad nonce -> refused', $wp_err( Eru_MPO_Admin_Actions::migrate_request( array( '_wpnonce' => 'nope' ) ), 'eru_mpo_bad_nonce' ) && array() === mpo_pending( 'eru_mpo_migrate' ) );
	$r = Eru_MPO_Admin_Actions::migrate_request( array( '_wpnonce' => wp_create_nonce( 'eru_mpo_migrate' ) ) );
	mpo_check( '(e) run with cap + nonce -> one batch queued in Action Scheduler, state running, no order touched yet', true === $r && 1 === count( mpo_pending( 'eru_mpo_migrate' ) ) && 'running' === ( get_option( 'eru_mpo_migration' )['status'] ?? '' ) && '' === (string) wc_get_order( $leg->get_id() )->get_meta( '_eru_mpo_migrated' ) );
	$page = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'gecis' ) );
	mpo_check( '(e) the screen shows the running state', false !== strpos( $page, 'Sürüyor' ) );
	as_unschedule_all_actions( 'eru_mpo_migrate', array(), 'eru-mpo' );
	delete_option( 'eru_mpo_migration' );

	// (f) Order list column and filter under HPOS.
	mpo_check( '(f) the demo site runs HPOS', Eru_MPO_Importer::hpos() );
	$a1 = Eru_MPO_Importer::import( $fake, $dto( 'DEMO-PKG-' . $tag . '-F1' ) );
	$a2 = Eru_MPO_Importer::import( $fake, $dto( 'DEMO-PKG-' . $tag . '-F2' ) );
	$b1 = Eru_MPO_Importer::import( $fake2, $dto( 'DEMO-PKG-' . $tag . '-F3' ) );
	$plain = wc_create_order();
	$plain->save();
	$created = array_merge( $created, array( $a1['order_id'], $a2['order_id'], $b1['order_id'], $plain->get_id() ) );
	Eru_MPO_Orders_List::init();
	mpo_check( '(f) hooks registered for HPOS and the classic list', false !== has_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( 'Eru_MPO_Orders_List', 'hpos_query_args' ) ) && false !== has_filter( 'request', array( 'Eru_MPO_Orders_List', 'classic_request' ) ) );
	$base = array(
		'limit'  => -1,
		'return' => 'ids',
		'type'   => 'shop_order',
	);
	$_GET['eru_mpo_mp'] = 'demo';
	$ids                = array_map( 'intval', wc_get_orders( apply_filters( 'woocommerce_order_list_table_prepare_items_query_args', $base ) ) );
	mpo_check( '(f) filter "demo" -> only the two demo orders (not demo2, not a web order)', in_array( $a1['order_id'], $ids, true ) && in_array( $a2['order_id'], $ids, true ) && ! in_array( $b1['order_id'], $ids, true ) && ! in_array( $plain->get_id(), $ids, true ) );
	$_GET['eru_mpo_mp'] = '_any';
	$ids                = array_map( 'intval', wc_get_orders( Eru_MPO_Orders_List::hpos_query_args( $base ) ) );
	mpo_check( '(f) filter "all marketplaces" -> demo and demo2 orders, never the web order', in_array( $b1['order_id'], $ids, true ) && in_array( $a1['order_id'], $ids, true ) && ! in_array( $plain->get_id(), $ids, true ) );
	$_GET['eru_mpo_mp'] = 'x\' OR 1=1';
	$ids                = array_map( 'intval', wc_get_orders( Eru_MPO_Orders_List::hpos_query_args( $base ) ) );
	mpo_check( '(f) an unknown marketplace value is ignored (no filter added)', in_array( $plain->get_id(), $ids, true ) );
	$_GET['eru_mpo_mp'] = 'demo';
	$req                = Eru_MPO_Orders_List::classic_request( array( 'post_type' => 'shop_order' ) );
	mpo_check( '(f) classic list: the request gets a meta query on _eru_mpo_marketplace', '_eru_mpo_marketplace' === ( $req['meta_query'][0]['key'] ?? '' ) && 'demo' === ( $req['meta_query'][0]['value'] ?? '' ) );
	unset( $_GET['eru_mpo_mp'] );
	$cols = Eru_MPO_Orders_List::columns( array( 'order_number' => 'Sipariş', 'order_date' => 'Tarih' ) );
	$cell = $html( fn() => Eru_MPO_Orders_List::render_cell( 'eru_mpo', wc_get_order( $a1['order_id'] ) ) );
	mpo_check( '(f) column after the order number; cell shows the badge and the remote number', array( 'order_number', 'eru_mpo', 'order_date' ) === array_keys( $cols ) && false !== strpos( $cell, 'Demo Pazaryeri' ) && false !== strpos( $cell, 'DEMO-ON-DEMO-PKG-' . $tag . '-F1' ) );
	$filter = $html( fn() => Eru_MPO_Orders_List::filter_dropdown( 'shop_order', 'top' ) );
	mpo_check( '(f) the filter dropdown lists the marketplaces', false !== strpos( $filter, 'name="eru_mpo_mp"' ) && false !== strpos( $filter, 'value="demo2"' ) );

	// Order box: references, ack state, "yeniden çek" (enqueued), no identity number.
	$o1  = wc_get_order( $a1['order_id'] );
	$box = $html( fn() => Eru_MPO_Metabox::render( $o1 ) );
	mpo_check( 'order box: package, order number, cargo, refetch button, raw link for the admin', false !== strpos( $box, 'DEMO-PKG-' . $tag . '-F1' ) && false !== strpos( $box, 'Demo Kargo' ) && false !== strpos( $box, 'eru_mpo_refetch' ) && false !== strpos( $box, 'raw_ref=' ) );
	mpo_check( 'order box: no 11-digit number anywhere', 1 !== preg_match( '/(?<!\d)\d{11}(?!\d)/', $box ) );
	mpo_check( 'order box: a marketplace without a single-order lookup offers "Pazaryerini şimdi çek" and says it pulls the whole marketplace', false !== strpos( $box, 'Pazaryerini şimdi çek' ) && false === strpos( $box, '>Yeniden çek<' ) );
	$lm = (object) array(
		'key'   => '_eru_mpo_commission_rate',
		'value' => '12.5',
	);
	mpo_check( 'order screen: the plugin\'s line meta gets Turkish labels, other keys untouched', 'Komisyon oranı (%)' === apply_filters( 'woocommerce_order_item_display_meta_key', '_eru_mpo_commission_rate', $lm, null ) && '_other' === apply_filters( 'woocommerce_order_item_display_meta_key', '_other', (object) array( 'key' => '_other' ), null ) );
	wp_set_current_user( $sub_id );
	$rr = Eru_MPO_Admin_Actions::refetch_request(
		array(
			'order_id'             => $a1['order_id'],
			'eru_mpo_order_nonce'  => wp_create_nonce( 'eru_mpo_order_' . $a1['order_id'] ),
		)
	);
	mpo_check( 'refetch without the capability -> refused', $wp_err( $rr, 'eru_mpo_forbidden' ) && array() === mpo_pending( 'eru_mpo_refetch' ) );
	wp_set_current_user( $admin );
	$rr = Eru_MPO_Admin_Actions::refetch_request(
		array(
			'order_id'            => $a1['order_id'],
			'eru_mpo_order_nonce' => wp_create_nonce( 'eru_mpo_order_' . $a1['order_id'] ),
		)
	);
	mpo_check( 'refetch with cap + nonce -> one queued action for the order, no fetch inside the request', true === $rr && 1 === count( mpo_pending_args( 'eru_mpo_refetch', array( $a1['order_id'] ) ) ) && 0 === $fake->fetches );
	$fake->lifecycle = array( $dto( 'DEMO-PKG-' . $tag . '-F1', 'shipped' ) );
	mpo_run_action( mpo_pending_args( 'eru_mpo_refetch', array( $a1['order_id'] ) )[0] );
	mpo_check( 'running the refetch applies the marketplace update to the order', 'shipped' === Eru_MPO_Statuses::current( wc_get_order( $a1['order_id'] ) ) );
	$fake->lifecycle = array();

	// Paid statuses: own marketplace statuses count as paid; cancelled/returned are left out of analytics.
	$paid = wc_get_is_paid_statuses();
	$excl = apply_filters( 'woocommerce_analytics_excluded_order_statuses', array() );
	mpo_check( 'paid statuses include eru-mpo-new … eru-mpo-delivered, not cancelled/returned', in_array( 'eru-mpo-new', $paid, true ) && in_array( 'eru-mpo-delivered', $paid, true ) && ! in_array( 'eru-mpo-cancelled', $paid, true ) && ! in_array( 'eru-mpo-returned', $paid, true ) );
	mpo_check( 'analytics excludes eru-mpo-cancelled and eru-mpo-returned', in_array( 'eru-mpo-cancelled', $excl, true ) && in_array( 'eru-mpo-returned', $excl, true ) );
	$rep_paid = apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) );
	$rep_ref  = apply_filters( 'woocommerce_reports_order_statuses', array( 'refunded' ) );
	mpo_check( 'legacy reports: marketplace statuses join lists of paid statuses only (a refunded-only report is unchanged)', in_array( 'eru-mpo-shipped', $rep_paid, true ) && array( 'refunded' ) === $rep_ref );

	// Marketplace tab: secrets never rendered; connection test and "Şimdi senkronla" are enqueued only.
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
	$tab = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'trendyol' ) );
	mpo_check( 'trendyol tab: stored key and secret never in the HTML, "Kayıtlı" shown, test and sync forms present', false === strpos( $tab, $creds['api_key'] ) && false === strpos( $tab, $creds['api_secret'] ) && false !== strpos( $tab, 'Kayıtlı' ) && false !== strpos( $tab, 'eru_mpo_test_connection' ) && false !== strpos( $tab, 'eru_mpo_sync_now' ) );
	$n0 = count( $router->calls );
	wp_set_current_user( $sub_id );
	mpo_check( 'connection test without the capability -> refused', $wp_err( Eru_MPO_Admin_Actions::test_connection_request( array( 'mp' => 'trendyol', '_wpnonce' => wp_create_nonce( 'eru_mpo_test_trendyol' ) ) ), 'eru_mpo_forbidden' ) );
	wp_set_current_user( $admin );
	$tc = Eru_MPO_Admin_Actions::test_connection_request(
		array(
			'mp'       => 'trendyol',
			'_wpnonce' => wp_create_nonce( 'eru_mpo_test_trendyol' ),
		)
	);
	mpo_check( 'connection test: queued, no request inside the page request', true === $tc && 1 === count( mpo_pending_args( 'eru_mpo_test_connection', array( 'trendyol' ) ) ) && count( $router->calls ) === $n0 );
	$router->on( 'GET', '#^' . preg_quote( $ty_host, '#' ) . '/order/sellers/1000/orders\?#', fn() => mpo_resp( 200, array( 'page' => 0, 'totalPages' => 0, 'content' => array() ) ) );
	mpo_run_action( mpo_pending_args( 'eru_mpo_test_connection', array( 'trendyol' ) )[0] );
	$conn = get_option( 'eru_mpo_sync_conn_trendyol' );
	mpo_check( 'connection test action: one GET, result stored as ok', count( $router->calls ) === $n0 + 1 && is_array( $conn ) && ! empty( $conn['ok'] ) );
	$tab = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'trendyol' ) );
	mpo_check( 'trendyol tab shows the connection result', false !== strpos( $tab, 'Bağlantı başarılı' ) );
	$fake->fetches = 0;
	$sn            =Eru_MPO_Admin_Actions::sync_now_request(
		array(
			'mp'       => 'demo',
			'_wpnonce' => wp_create_nonce( 'eru_mpo_sync_now_demo' ),
		)
	);
	$po = mpo_pending_args( 'eru_mpo_sync_orders', array( 'demo' ) );
	mpo_check( '"Şimdi senkronla": the order pull is queued to run now (one pending action), nothing fetched inline', true === $sn && 1 === count( $po ) && mpo_action_time( $po[0] ) <= time() + 10 && 0 === $fake->fetches );
	$sn = Eru_MPO_Admin_Actions::sync_now_request(
		array(
			'mp'       => 'trendyol',
			'_wpnonce' => wp_create_nonce( 'eru_mpo_sync_now_trendyol' ),
		)
	);
	mpo_check( '"Şimdi senkronla" for a switched-off marketplace -> refused with a reason', $wp_err( $sn, 'eru_mpo_not_ready' ) );
	Eru_MPO_Cron::unschedule_all();

	// Log viewer: redacted on display, filters, raw link only with the capability, purge enqueued.
	$tck = mpo_fake_tckn();
	$wpdb->insert( "{$t}log", array( 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'level' => 'error', 'marketplace' => 'demo', 'remote_ref' => 'DEMO-PKG-' . $tag . '-F1', 'message' => 'Demo ham ' . $tck . ' Authorization: Basic ZGVtbzpkZW1v' ) ); // phpcs:ignore
	$wpdb->insert( "{$t}log", array( 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'level' => 'info', 'marketplace' => 'demo2', 'remote_ref' => 'DEMO-PKG-' . $tag . '-F3', 'message' => 'Demo ikinci pazaryeri satırı' ) ); // phpcs:ignore
	$_GET['mp'] = 'demo';
	$log        = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'gunluk' ) );
	unset( $_GET['mp'] );
	mpo_check( 'log viewer: the identity number and the Basic token are hidden on display', false !== strpos( $log, 'Demo ham' ) && false === strpos( $log, $tck ) && false === strpos( $log, 'ZGVtbzpkZW1v' ) );
	mpo_check( 'log viewer: the marketplace filter leaves out the other marketplace', false === strpos( $log, 'Demo ikinci pazaryeri' ) );
	mpo_check( 'log viewer: a package with a retained payload links to its raw view', false !== strpos( $log, 'raw_ref=DEMO-PKG-' . $tag . '-F1' ) );
	$raw_url = Eru_MPO_Admin_Pages::raw_url( 'demo', 'DEMO-PKG-' . $tag . '-F1' );
	parse_str( (string) wp_parse_url( $raw_url, PHP_URL_QUERY ), $rq );
	$_GET = $rq;
	$raw  = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'gunluk' ) );
	mpo_check( 'raw view for the admin: the payload marker, the identity number masked', false !== strpos( $raw, 'demo-raw-marker' ) && 1 !== preg_match( '/(?<!\d)\d{11}(?!\d)/', $raw ) );
	wp_set_current_user( $sub_id );
	$raw = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'gunluk' ) );
	mpo_check( 'raw view without the capability -> nothing shown', false === strpos( $raw, 'demo-raw-marker' ) );
	wp_set_current_user( $admin );
	$_GET['_wpnonce'] = 'nope';
	$raw              = $html( fn() => Eru_MPO_Admin_Pages::render_tab( 'gunluk' ) );
	mpo_check( 'raw view with a bad nonce -> nothing shown', false === strpos( $raw, 'demo-raw-marker' ) );
	$_GET = array();
	$wpdb->update( "{$t}raw", array( 'stored_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'marketplace' => 'demo', 'remote_ref' => 'DEMO-PKG-' . $tag . '-F2' ) ); // phpcs:ignore
	Eru_MPO_Raw::purge();
	$box2 = $html( fn() => Eru_MPO_Metabox::render( wc_get_order( $a2['order_id'] ) ) );
	mpo_check( 'raw link only while the payload is retained (purged after 30 days -> no link)', false === strpos( $box2, 'raw_ref=' ) );
	wp_set_current_user( $sub_id );
	mpo_check( 'log purge without the capability -> refused', $wp_err( Eru_MPO_Admin_Actions::purge_log_request( array( 'scope' => 'all', '_wpnonce' => wp_create_nonce( 'eru_mpo_purge_log' ) ) ), 'eru_mpo_forbidden' ) );
	wp_set_current_user( $admin );
	$nc = Eru_MPO_Admin_Actions::purge_log_request(
		array(
			'scope'    => 'all',
			'mp'       => 'demo2',
			'_wpnonce' => wp_create_nonce( 'eru_mpo_purge_log' ),
		)
	);
	mpo_check( 'log purge "all" without the confirmation field -> refused, nothing queued', $wp_err( $nc, 'eru_mpo_confirm' ) && array() === mpo_pending( 'eru_mpo_purge_log' ) );
	$pg = Eru_MPO_Admin_Actions::purge_log_request(
		array(
			'scope'    => 'all',
			'mp'       => 'demo2',
			'confirm'  => 'yes',
			'_wpnonce' => wp_create_nonce( 'eru_mpo_purge_log' ),
		)
	);
	$pq = mpo_pending( 'eru_mpo_purge_log' );
	mpo_check( 'log purge: queued, rows still there inside the request', true === $pg && 1 === count( $pq ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}log WHERE marketplace = 'demo2'" ) > 0 ); // phpcs:ignore
	mpo_run_action( $pq[0] );
	mpo_check( 'log purge action: the chosen marketplace\'s rows deleted, others kept', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}log WHERE marketplace = 'demo2'" ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}log WHERE marketplace = 'demo'" ) > 0 ); // phpcs:ignore

	// (g) Privacy exporter/eraser: raw rows and identity meta for the billing e-mail.
	mpo_settings( array( 'store_identity' => 'yes' ) );
	$tg  = mpo_fake_tckn();
	$gid = 'DEMO-PKG-' . $tag . '-G1';
	$g   = Eru_MPO_Importer::import( $fake, $dto( $gid, 'new', null, $tg ) );
	$created[] = $g['order_id'];
	$go  = wc_get_order( $g['order_id'] );
	$go->update_meta_data( '_eru_mpo_tax_number', mpo_fake_tckn() );
	$go->save();
	Eru_MPO_Raw::store( 'demo', 'DEMO-PKG-' . $tag . '-ORPHAN', array( 'customerEmail' => $email, 'fullName' => 'Demo Alıcı' ) );
	Eru_MPO_Raw::store( 'demo', 'DEMO-PKG-' . $tag . '-OTHER', array( 'customerEmail' => 'baska.alici@example.com' ) );
	mpo_settings();
	$ex = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$er = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	mpo_check( '(g) exporter and eraser registered', isset( $ex['marketplace-orders-for-woocommerce'] ) && isset( $er['marketplace-orders-for-woocommerce'] ) );
	$exp  = Eru_MPO_Privacy::export( $email, 1 );
	$dump = wp_json_encode( $exp, JSON_UNESCAPED_UNICODE );
	mpo_check( '(g) export covers the order (identity masked), its raw payload and the orphan raw row', $exp['done'] && false !== strpos( $dump, $gid ) && false !== strpos( $dump, 'demo-raw-marker' ) && false !== strpos( $dump, 'DEMO-PKG-' . $tag . '-ORPHAN' ) && false === strpos( $dump, $tg ) && false !== strpos( $dump, substr( $tg, 0, 2 ) . '*******' . substr( $tg, -2 ) ) && false === strpos( $dump, 'baska.alici' ) );
	$era = Eru_MPO_Privacy::erase( $email, 1 );
	$go  = wc_get_order( $g['order_id'] );
	mpo_check( '(g) erase: raw rows of the e-mail gone (order and orphan), identity meta and the 11-digit tax number removed', $era['items_removed'] && null === Eru_MPO_Raw::get( 'demo', $gid ) && null === Eru_MPO_Raw::get( 'demo', 'DEMO-PKG-' . $tag . '-ORPHAN' ) && '' === (string) $go->get_meta( '_eru_mpo_identity_number' ) && '' === (string) $go->get_meta( '_eru_mpo_tax_number' ) );
	mpo_check( '(g) erase: the order\'s log rows removed; another buyer\'s raw row kept; the order itself kept', 0 === mpo_log_count( $gid, '' ) && is_array( Eru_MPO_Raw::get( 'demo', 'DEMO-PKG-' . $tag . '-OTHER' ) ) && $go && $gid === $go->get_meta( '_eru_mpo_remote_ref' ) );
	$exp2 = Eru_MPO_Privacy::export( $email, 1 );
	mpo_check( '(g) a second export finds no identity or raw data', false === strpos( wp_json_encode( $exp2 ), 'demo-raw-marker' ) && false === strpos( wp_json_encode( $exp2 ), '*******' ) );

	// I1: with WooCommerce's order eraser on, identity data goes too (our eraser runs first; WooCommerce's per-order
	// erase hook covers orders it erases on its own). Unmigrated legacy orders are covered as well.
	$old_wc_erase = get_option( 'woocommerce_erasure_request_removes_order_data', null );
	update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
	try {
		$imail = 'mpo.erase.' . strtolower( $tag ) . '@example.com';
		mpo_settings( array( 'store_identity' => 'yes' ) );
		$i1  = 'DEMO-PKG-' . $tag . '-I1';
		$ri1 = Eru_MPO_Importer::import( $fake, $dto( $i1, 'new', null, mpo_fake_tckn() ) );
		$oi1 = wc_get_order( $ri1['order_id'] );
		$oi1->set_billing_email( $imail );
		$oi1->update_meta_data( '_eru_mpo_tax_number', mpo_fake_tckn() );
		$oi1->save();
		$leg2 = wc_create_order();
		$leg2->set_billing_email( $imail );
		$leg2->update_meta_data( '_eru_trendyol_package_id', 'DEMO-PKG-' . $tag . '-I1L' );
		$leg2->update_meta_data( '_eru_trendyol_identity_number', mpo_fake_tckn() );
		$leg2->update_meta_data( '_eru_trendyol_tax_number', mpo_fake_tckn() );
		$leg2->update_meta_data( '_eru_trendyol_raw', wp_json_encode( array( 'fullName' => 'Demo Alıcı' ) ) );
		$leg2->save();
		$created = array_merge( $created, array( $ri1['order_id'], $leg2->get_id() ) );
		mpo_settings();
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$order   = array_keys( $erasers );
		mpo_check( 'I1: our eraser is registered ahead of WooCommerce\'s order eraser', false !== array_search( 'woocommerce-customer-orders', $order, true ) && array_search( 'marketplace-orders-for-woocommerce', $order, true ) < array_search( 'woocommerce-customer-orders', $order, true ) );
		foreach ( $erasers as $e ) { // As wp_privacy_process_personal_data_erasure_page() does: each eraser, page by page.
			$pg_no = 1;
			do {
				$res = call_user_func( $e['callback'], $imail, $pg_no );
				++$pg_no;
			} while ( empty( $res['done'] ) && $pg_no < 20 );
		}
		Eru_MPO_Importer::forget_order( $ri1['order_id'] );
		$oi1 = wc_get_order( $ri1['order_id'] );
		$lg2 = wc_get_order( $leg2->get_id() );
		mpo_check( 'I1: through the real eraser order WooCommerce anonymised the order, and our identity meta, 11-digit tax number and raw row are gone', $imail !== $oi1->get_billing_email() && '' === (string) $oi1->get_meta( '_eru_mpo_identity_number' ) && '' === (string) $oi1->get_meta( '_eru_mpo_tax_number' ) && null === Eru_MPO_Raw::get( 'demo', $i1 ) );
		mpo_check( 'I1: an unmigrated legacy order loses the legacy identity number, 11-digit tax number and raw payload', '' === (string) $lg2->get_meta( '_eru_trendyol_identity_number' ) && '' === (string) $lg2->get_meta( '_eru_trendyol_tax_number' ) && '' === (string) $lg2->get_meta( '_eru_trendyol_raw' ) );
		// WooCommerce erasing an order on its own (our eraser not involved): the per-order hook removes the data.
		mpo_settings( array( 'store_identity' => 'yes' ) );
		$i2  = 'DEMO-PKG-' . $tag . '-I2';
		$ri2 = Eru_MPO_Importer::import( $fake, $dto( $i2, 'new', null, mpo_fake_tckn() ) );
		mpo_settings();
		$created[] = $ri2['order_id'];
		WC_Privacy_Erasers::remove_order_personal_data( wc_get_order( $ri2['order_id'] ) );
		Eru_MPO_Importer::forget_order( $ri2['order_id'] );
		mpo_check( 'I1: WooCommerce\'s per-order erase alone removes the identity meta and the raw row', '' === (string) wc_get_order( $ri2['order_id'] )->get_meta( '_eru_mpo_identity_number' ) && null === Eru_MPO_Raw::get( 'demo', $i2 ) );
	} finally {
		if ( null === $old_wc_erase ) {
			delete_option( 'woocommerce_erasure_request_removes_order_data' );
		} else {
			update_option( 'woocommerce_erasure_request_removes_order_data', $old_wc_erase );
		}
	}

	// "Yeniden çek" on Trendyol fetches that order number only (no whole-marketplace pull).
	$ty     = Eru_MPO_Registry::get( 'trendyol' );
	$typkg  = mpo_id();
	$tynum  = 'DEMO-ON-' . $tag . '-Y';
	$typ    = function ( $status ) use ( $typkg, $tynum, $tag ) {
		return mpo_fixture(
			'ty-package.json',
			array(
				'{{PKG}}'           => $typkg,
				'{{NUM}}'           => $tynum,
				'{{STATUS}}'        => $status,
				'{{DATE_MS}}'       => ( time() ) * 1000,
				'{{TCKN}}'          => mpo_fake_tckn(),
				'{{TAXNO}}'         => mpo_tax_number(),
				'{{TRACK}}'         => 'Shipped' === $status ? 'DEMO-TRK-1' : '',
				'{{LINE_A}}'        => $typkg . '1',
				'{{LINE_B}}'        => $typkg . '2',
				'{{LINE_A_STATUS}}' => $status,
				'{{SKU_A}}'         => 'MPO-TEST-' . $tag . '-YA',
				'{{SKU_B}}'         => 'MPO-TEST-' . $tag . '-YB',
			)
		);
	};
	$ry        = Eru_MPO_Importer::import( $ty, $ty->to_dto( $typ( 'Created' ) ) );
	$created[] = $ry['order_id'];
	$ybox      = $html( fn() => Eru_MPO_Metabox::render( wc_get_order( $ry['order_id'] ) ) );
	mpo_check( 'order box: Trendyol supports a single-order lookup -> "Yeniden çek"', 'created' === $ry['result'] && false !== strpos( $ybox, '>Yeniden çek<' ) );
	$router->on( 'GET', '#/order/sellers/1000/orders\?#', fn( $req ) => isset( $req['query']['orderNumber'] ) && $tynum === $req['query']['orderNumber'] ? mpo_resp( 200, array( 'page' => 0, 'totalPages' => 1, 'content' => array( $typ( 'Shipped' ) ) ) ) : mpo_resp( 500, 'unexpected' ) );
	$ny = count( $router->calls );
	$rr = Eru_MPO_Admin_Actions::refetch_request(
		array(
			'eru_mpo_order_id'    => $ry['order_id'],
			'eru_mpo_order_nonce' => wp_create_nonce( 'eru_mpo_order_' . $ry['order_id'] ),
		)
	);
	mpo_run_action( mpo_pending_args( 'eru_mpo_refetch', array( $ry['order_id'] ) )[0] );
	$calls = array_slice( $router->calls, $ny );
	mpo_check( 'Trendyol "Yeniden çek": one lookup by order number, no status-list pull, the order moves to shipped', true === $rr && 1 === count( $calls ) && $tynum === ( $calls[0]['query']['orderNumber'] ?? '' ) && 'shipped' === Eru_MPO_Statuses::current( wc_get_order( $ry['order_id'] ) ) );

	// Genel tab and the pages render without notices for an admin.
	foreach ( array( 'genel', 'hepsiburada', 'stok', 'gecis', 'gunluk' ) as $tb ) {
		$out = $html( fn() => Eru_MPO_Admin_Pages::render_tab( $tb ) );
		mpo_check( 'tab ' . $tb . ' renders', strlen( $out ) > 100 );
	}
	$tabs = Eru_MPO_Admin::tabs();
	mpo_check( 'tabs in order: Genel, Trendyol, Hepsiburada, Stok köprüsü, Geçiş, Günlük', array( 'genel', 'trendyol', 'hepsiburada', 'stok', 'gecis', 'gunluk' ) === array_values( array_intersect( array_keys( $tabs ), array( 'genel', 'trendyol', 'hepsiburada', 'stok', 'gecis', 'gunluk' ) ) ) && 'Stok köprüsü' === $tabs['stok'] );
} catch ( Throwable $e ) {
	$failed = true;
	if ( 'mpo-helpers.php' !== basename( $e->getFile() ) ) {
		echo 'FAIL: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	$cleanup();
}
if ( $failed ) {
	exit( 1 );
}
echo "ALL PASS\n";
