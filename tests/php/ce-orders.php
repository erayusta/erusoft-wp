<?php
/**
 * Campaign Engine — orders: the `_eru_ce_applied` snapshot, order total = engine total (tax off, prices
 * excl. tax, prices incl. tax), usage reservations (reserve at checkout, release on failed/cancelled,
 * consume on paid, no double counting, refusal after consumption, two concurrent processes), the checkout
 * guard, the block-checkout validation hook, the reservation sweep and customer metrics.
 *
 *   wp eval-file tests/php/ce-orders.php   (plugin active; creates and removes its own data)
 */

use Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\Schema;
use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;
use Erusoft\CampaignEngine\Infrastructure\Jobs;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CartApplier;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\ContextFactory;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\OrderWriter;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\PaymentBinding;
use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;

require __DIR__ . '/ce-wc-helpers.php';
ce_wc_require();

$mails = array();
ce_wc_guards( $mails );
wc_load_cart();
$keep    = ce_wc_snapshot_options();
$gw_keep = array();
$fail    = false;
ce_wc_purge( true );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_prices_include_tax', 'no' );
delete_option( Options::OPTION );
Options::flush();
global $wpdb;
$usage_t = Schema::table( 'usage' );
$count   = fn( $cid, $status ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage_t} WHERE campaign_id = %d AND status = %s", $cid, $status ) );

try {
	$cat = ce_wc_term( 'CE Demo Koşu' );
	$p1  = ce_wc_product( 'CE Demo Tişört', '33.33', array( $cat ) );
	$p2  = ce_wc_product( 'CE Demo Şort', '249.90', array( $cat ) );
	$p3  = ce_wc_product( 'CE Demo Çanta', '89.90', array() );
	list( $zone, $rate_id ) = ce_wc_zone( '49.90' );
	WC()->session->set( 'chosen_shipping_methods', array( $rate_id ) );

	// ------------------------------------------------------------------ (d) snapshot + order total = engine total
	$pct   = ce_wc_campaign(
		array(
			'actions'  => array( array( 'type' => 'product_percent', 'percent' => '12.5' ) ),
			'targets'  => array( 'include' => array( 'term' => array( $cat ) ) ),
			'priority' => 20,
		),
		'CE Demo Yüzde'
	);
	$fixed = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'cart_fixed', 'amount' => 1001 ) ) ), 'CE Demo Sabit', 'publish' );
	update_option( Options::OPTION, array( 'stacking' => 'stack' ), false );
	Options::flush();

	$modes = array(
		'tax off'            => array( 'no', 'no' ),
		'prices excl. tax'   => array( 'yes', 'no' ),
		'prices incl. tax'   => array( 'yes', 'yes' ),
	);
	$rid = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'CE Demo KDV ' . CE_RUN, 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
	WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	foreach ( $modes as $label => $m ) {
		update_option( 'woocommerce_calc_taxes', $m[0] );
		update_option( 'woocommerce_prices_include_tax', $m[1] );
		$cart = ce_wc_fill( array( array( $p1, 3 ), array( $p2, 7 ), array( $p3, 1 ) ) );
		$cart->calculate_totals();
		$r        = CartApplier::resultFor( $cart );
		$engine   = ce_wc_base_total( $cart ) - $r->adjustments->totalDiscount();
		$gross    = 'yes' === $m[1];
		$wc_lines = 0;
		foreach ( $cart->get_cart() as $it ) {
			$wc_lines += ce_minor( $it['line_subtotal'] ) + ( $gross ? ce_minor( $it['line_subtotal_tax'] ) : 0 );
		}
		ce_ok( "(d) $label: cart lines = engine total (" . $engine . ')', abs( $wc_lines - $engine ) <= ( $gross ? 1 : 0 ) && ( ! $gross || ce_minor( (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() ) === $engine ) );
		$order = ce_wc_checkout( ce_mail( 'a' ) );
		ce_ok( "(d) $label: order created", $order instanceof WC_Order );
		$snap  = $order->get_meta( OrderWriter::META );
		$ids   = wp_list_pluck( (array) ( $snap['campaigns'] ?? array() ), 'id' );
		$sum   = array_sum( wp_list_pluck( (array) ( $snap['campaigns'] ?? array() ), 'discount_minor' ) );
		$items_base = 0;
		$items_net  = 0;
		$line_sum   = 0;
		foreach ( $order->get_items() as $oi ) {
			$lm = $oi->get_meta( OrderWriter::META );
			$items_base += (int) $lm['base_unit_minor'] * (int) $lm['qty'];
			$line_sum   += (int) $lm['discount_minor'];
			$items_net  += ce_minor( $oi->get_subtotal() ) + ( $gross ? ce_minor( $oi->get_subtotal_tax() ) : 0 );
		}
		ce_ok( "(d) $label: snapshot lists both campaign ids", array( $fixed, $pct ) == array_values( array_unique( array_map( 'intval', $ids ) ) ) || array( $pct, $fixed ) == array_values( array_unique( array_map( 'intval', $ids ) ) ) );
		ce_ok( "(d) $label: snapshot amounts sum to the order discount (Σ base − Σ items)", $sum === $snap['total_discount_minor'] && $sum === $line_sum && abs( ( $items_base - $items_net ) - $sum ) <= ( $gross ? 1 : 0 ) );
		$shipping = ce_minor( $order->get_shipping_total() ) + ce_minor( $order->get_shipping_tax() );
		$tax      = $gross ? 0 : ce_minor( $order->get_total_tax() ) - ce_minor( $order->get_shipping_tax() );
		ce_ok( "(d) $label: order total = engine total + item tax + shipping", ce_minor( $order->get_total() ) === $engine + $tax + $shipping );
		$order->delete( true );
	}
	WC_Tax::_delete_tax_rate( $rid );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	update_option( Options::OPTION, array(), false );
	Options::flush();
	CampaignWriter::update( $pct, null, null, 'draft' );
	CampaignWriter::update( $fixed, null, null, 'draft' );

	// Loser of best stacking: no reward, not in the snapshot.
	$win  = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '20' ) ) ), 'CE Demo Kazanan' );
	$lose = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '5' ), array( 'type' => 'future_reward', 'reward' => 'fixed', 'value' => 5000 ) ) ), 'CE Demo Kaybeden' );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	$order = ce_wc_checkout( ce_mail( 'a' ) );
	$snap  = $order->get_meta( OrderWriter::META );
	ce_ok( '(d) best stacking: the losing campaign is neither in the snapshot nor in its rewards', array( $win ) === array_map( 'intval', wp_list_pluck( $snap['campaigns'], 'id' ) ) && array() === $snap['rewards'] );
	$order->delete( true );
	CampaignWriter::update( $win, null, null, 'draft' );
	CampaignWriter::update( $lose, null, null, 'draft' );

	// ------------------------------------------------------------------ (c) usage limit 1
	$lim = ce_wc_campaign(
		array(
			'actions'     => array( array( 'type' => 'product_percent', 'percent' => '10' ) ),
			'usage_limit' => 1,
		),
		'CE Demo Tek Kullanım'
	);
	ce_wc_fill( array( array( $p3, 1 ) ) );
	$a1 = ce_wc_checkout( ce_mail( 'a' ) );
	ce_ok( '(c) checkout reserves one use', 1 === $count( $lim, 'reserved' ) && 8091 === ce_minor( $a1->get_subtotal() ) );
	ce_wc_customer( ce_mail( 'b' ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( '(c) while reserved, a second customer does not get the campaign', 8990 === ce_minor( $cart->get_subtotal() ) );
	$a1->update_status( 'failed' );
	ce_ok( '(c) failed order releases the reservation', 0 === $count( $lim, 'reserved' ) && 1 === $count( $lim, 'released' ) );
	$a1->update_status( 'failed' );
	$a1->update_status( 'cancelled' );
	ce_ok( '(c) repeated failed/cancelled: still one released row', 1 === $count( $lim, 'released' ) && 0 === $count( $lim, 'reserved' ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( '(c) after the release the campaign is available again', 8091 === ce_minor( $cart->get_subtotal() ) );

	// Resume of the same pending order does not reserve twice.
	$a2 = ce_wc_checkout( ce_mail( 'a' ) );
	$a2_resumed = ce_wc_checkout( ce_mail( 'a' ), true );
	ce_ok( '(c) resumed checkout of the same order: one reservation', $a2->get_id() === $a2_resumed->get_id() && 1 === $count( $lim, 'reserved' ) );
	$a2 = wc_get_order( $a2->get_id() );
	$a2->payment_complete();
	ce_ok( '(c) paid consumes the reservation', 1 === $count( $lim, 'consumed' ) && 0 === $count( $lim, 'reserved' ) );
	$a2 = wc_get_order( $a2->get_id() );
	$a2->update_status( 'completed' );
	do_action( 'woocommerce_payment_complete', $a2->get_id() );
	ce_ok( '(c) processing → completed and a repeated payment hook: still one consumed row', 1 === $count( $lim, 'consumed' ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage_t} WHERE campaign_id = %d AND status <> 'released'", $lim ) ) );

	// Second customer: not offered; with a stale cart the checkout guard and the reservation refuse it.
	ce_wc_customer( ce_mail( 'b' ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( '(c) after consumption the second customer is not offered the campaign', 8990 === ce_minor( $cart->get_subtotal() ) );

	// Classic checkout guard: B saw the campaign; it is used up before B posts the form.
	$wpdb->update( $usage_t, array( 'status' => 'released' ), array( 'campaign_id' => $lim ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( '(c) guard set-up: B sees the campaign', 8091 === ce_minor( $cart->get_subtotal() ) );
	$wpdb->update( $usage_t, array( 'status' => 'consumed' ), array( 'campaign_id' => $lim, 'order_id' => $a2->get_id() ) );
	do_action( 'woocommerce_checkout_process' );
	$errors = new WP_Error();
	do_action( 'woocommerce_after_checkout_validation', array(), $errors );
	ce_ok( '(c) checkout guard: campaigns changed since the customer saw them -> checkout error', in_array( 'eru_ce_campaigns_changed', $errors->get_error_codes(), true ) && 8990 === ce_minor( $cart->get_subtotal() ) );
	do_action( 'woocommerce_checkout_process' );
	$errors = new WP_Error();
	do_action( 'woocommerce_after_checkout_validation', array(), $errors );
	ce_ok( '(c) checkout guard: posting again with the updated total passes', ! $errors->has_errors() );

	// Race: B's cart was evaluated while a use was left; it is consumed before B's order is written.
	$wpdb->update( $usage_t, array( 'status' => 'released' ), array( 'campaign_id' => $lim ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( '(c) race set-up: B sees the campaign', 8091 === ce_minor( $cart->get_subtotal() ) );
	$wpdb->update( $usage_t, array( 'status' => 'consumed' ), array( 'campaign_id' => $lim, 'order_id' => $a2->get_id() ) );
	$res = WC()->checkout()->create_order(
		array(
			'billing_email'      => ce_mail( 'b' ),
			'billing_first_name' => 'Demo',
			'billing_last_name'  => 'Alıcı',
			'billing_country'    => 'TR',
			'payment_method'     => 'bacs',
		)
	);
	ce_ok( '(c) reservation refused at order creation -> checkout error, no reservation left behind', is_wp_error( $res ) && 1 === $count( $lim, 'consumed' ) && 0 === $count( $lim, 'reserved' ) );
	ce_ok( '(c) the cart is re-evaluated after the refusal (campaign gone)', 8990 === ce_minor( WC()->cart->get_subtotal() ) );

	// Block checkout path: woocommerce_checkout_validate_order_before_payment refuses too.
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	$wpdb->update( $usage_t, array( 'status' => 'released' ), array( 'campaign_id' => $lim ) );
	CartApplier::forget( $cart ); // usage changed behind the repository's back (another request in real life)
	$cart->calculate_totals();
	$draft = wc_create_order( array( 'status' => 'checkout-draft' ) );
	$draft->set_billing_email( ce_mail( 'b' ) );
	$draft->update_meta_data( '_ce_test', CE_RUN );
	WC()->checkout()->create_order_line_items( $draft, $cart );
	do_action( 'woocommerce_store_api_checkout_update_order_from_request', $draft, new WP_REST_Request() );
	$draft->calculate_totals( false );
	$draft->save();
	$wpdb->update( $usage_t, array( 'status' => 'consumed' ), array( 'campaign_id' => $lim, 'order_id' => $a2->get_id() ) );
	$errors = new WP_Error();
	do_action( 'woocommerce_checkout_validate_order_before_payment', $draft, $errors );
	ce_ok( '(c) block checkout: validation error when the last use is gone', $errors->has_errors() && 0 === $count( $lim, 'reserved' ) );
	$wpdb->update( $usage_t, array( 'status' => 'released' ), array( 'campaign_id' => $lim, 'order_id' => $a2->get_id() ) );
	$errors = new WP_Error();
	do_action( 'woocommerce_checkout_validate_order_before_payment', $draft, $errors );
	ce_ok( '(c) block checkout: with a use left the draft reserves it', ! $errors->has_errors() && 1 === $count( $lim, 'reserved' ) );
	$draft->delete( true );
	ce_ok( '(c) deleted order releases its reservation', 0 === $count( $lim, 'reserved' ) );
	$wpdb->update( $usage_t, array( 'status' => 'consumed' ), array( 'campaign_id' => $lim, 'order_id' => $a2->get_id() ) );

	// Stale pending reservations are swept; a reservation never written to an order (crash) too.
	$wpdb->query( $wpdb->prepare( "UPDATE {$usage_t} SET status = 'released' WHERE campaign_id = %d", $lim ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	$pending = ce_wc_checkout( ce_mail( 'c' ) );
	ce_ok( '(c) pending order holds a reservation', 1 === $count( $lim, 'reserved' ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$usage_t} SET created_at = %s WHERE campaign_id = %d AND status = 'reserved'", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), $lim ) );
	$orphan = ce_token( 'orphan' );
	UsageRepository::reserve( array( $lim => array( 'total' => null, 'per_customer' => null ) ), $orphan, 0, '', 0 );
	$wpdb->query( $wpdb->prepare( "UPDATE {$usage_t} SET created_at = %s WHERE token = %s", gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ), $orphan ) );
	Jobs::runDailySweep();
	ce_ok( '(c) sweep: stale pending reservation and orphan reservation released', 0 === $count( $lim, 'reserved' ) );
	$pending = wc_get_order( $pending->get_id() );
	ce_ok( '(c) sweep leaves an order note on the stale order', (bool) array_filter( wc_get_order_notes( array( 'order_id' => $pending->get_id() ) ), fn( $n ) => false !== stripos( $n->content, 'kampanya' ) ) );
	$pending->delete( true );

	// Two processes racing for the last use: exactly one wins (GET_LOCK around the count and the insert).
	$wpdb->query( $wpdb->prepare( "UPDATE {$usage_t} SET status = 'released' WHERE campaign_id = %d", $lim ) );
	$worker = __DIR__ . '/ce-fixtures/reserve-worker.php';
	$start  = microtime( true ) + 2.0;
	$procs  = array();
	foreach ( array( ce_token( 'racea' ), ce_token( 'raceb' ) ) as $tok ) {
		$cmd     = sprintf( 'CE_CAMPAIGN=%d CE_TOKEN=%s CE_START=%s WP_CLI_PHP_ARGS=%s wp --path=%s eval-file %s 2>/dev/null', $lim, $tok, $start, escapeshellarg( '-d error_reporting=24575' ), escapeshellarg( ABSPATH ), escapeshellarg( $worker ) );
		$procs[] = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ) ), $pipes[ $tok ] );
	}
	$out = '';
	foreach ( $pipes as $tok => $p ) {
		$out .= stream_get_contents( $p[1] );
	}
	foreach ( $procs as $pr ) {
		proc_close( $pr );
	}
	ce_ok( '(c) two concurrent reservations for the last use: exactly one OK (' . trim( str_replace( "\n", ' ', $out ) ) . ')', 1 === substr_count( $out, 'RESERVED' ) && 1 === substr_count( $out, 'REFUSED' ) && 1 === $count( $lim, 'reserved' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$usage_t} WHERE campaign_id = %d AND token IN (%s, %s)", $lim, ce_token( 'racea' ), ce_token( 'raceb' ) ) );

	// Per-customer limit: guest and account count together by e-mail.
	CampaignWriter::update( $lim, null, null, 'draft' );
	$per = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ), 'usage_limit_per_customer' => 1 ), 'CE Demo Kişi Başı' );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	$d1 = ce_wc_checkout( ce_mail( 'd' ) );
	$d1->payment_complete();
	ce_wc_customer( ce_mail( 'd' ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( '(c) per-customer limit: same e-mail is not offered the campaign again', 8990 === ce_minor( $cart->get_subtotal() ) );
	ce_wc_customer( ce_mail( 'c' ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( '(c) per-customer limit: another e-mail still gets it', 8091 === ce_minor( $cart->get_subtotal() ) );

	// ------------------------------------------------------------------ customer metrics on paid / refund
	$metrics = MetricsRepository::forEmail( ce_mail( 'd' ) );
	ce_ok( 'metrics: paid order counted once (1 order, 80.91 + 49.90 shipping)', $metrics && 1 === (int) $metrics['order_count'] && 13081 === (int) $metrics['total_spent_minor'] );
	$d1 = wc_get_order( $d1->get_id() );
	$d1->update_status( 'completed' );
	ce_ok( 'metrics: status change to completed does not count twice', 1 === (int) MetricsRepository::forEmail( ce_mail( 'd' ) )['order_count'] );
	wc_create_refund( array( 'order_id' => $d1->get_id(), 'amount' => '30.00', 'reason' => 'CE Demo iade' ) );
	ce_ok( 'metrics: partial refund lowers the spend (130.81 − 30.00)', 10081 === (int) MetricsRepository::forEmail( ce_mail( 'd' ) )['total_spent_minor'] );
	do_action( 'woocommerce_order_refunded', $d1->get_id(), 0 );
	ce_ok( 'metrics: a repeated refund hook changes nothing', 10081 === (int) MetricsRepository::forEmail( ce_mail( 'd' ) )['total_spent_minor'] );
	$d1 = wc_get_order( $d1->get_id() );
	$d1->update_status( 'cancelled' );
	ce_ok( 'metrics: cancelled after paid -> no longer counted; usage released', 0 === (int) MetricsRepository::forEmail( ce_mail( 'd' ) )['order_count'] && 0 === $count( $per, 'consumed' ) );
	$ctx = ContextFactory::customer( 0, ce_mail( 'd' ) );
	ce_ok( 'metrics feed the engine customer (order count, spend)', 0 === $ctx->order_count && 0 === $ctx->total_spent_minor );
	CampaignWriter::update( $per, null, null, 'draft' );

	// ------------------------------------------------------------------ block checkout through the real Store API route
	// The cart was priced for the session's payment method (bacs); the order is posted with cheque. WooCommerce
	// prices the draft before it applies the posted method, so the plugin must re-check and refuse.
	foreach ( array( 'bacs', 'cheque' ) as $g ) {
		$opt              = 'woocommerce_' . $g . '_settings';
		$gw_keep[ $opt ]  = get_option( $opt, null );
		$val              = (array) get_option( $opt, array() );
		$val['enabled']   = 'yes';
		update_option( $opt, $val );
	}
	WC()->payment_gateways()->init();
	add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
	$pm   = ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ),
			'rules'   => array(
				'op'       => 'and',
				'children' => array( array( 'condition' => 'cart.payment_method', 'cmp' => '==', 'value' => 'bacs' ) ),
			),
		),
		'CE Demo Havale'
	);
	$addr = array(
		'first_name' => 'Demo',
		'last_name'  => 'Alıcı',
		'address_1'  => 'Demo Sokak 1',
		'city'       => 'Çankaya',
		'state'      => 'TR06',
		'postcode'   => '06420',
		'country'    => 'TR',
		'email'      => ce_mail( 'sapi' ),
		'phone'      => '',
	);
	$sapi = function ( $method, array $body ) {
		$r = new WP_REST_Request( $method, '/wc/store/v1/checkout' );
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
		$res = rest_do_request( $r );
		return array( $res->get_status(), (array) $res->get_data() );
	};
	$tag_order = function ( $data ) {
		$o = wc_get_order( (int) ( $data['order_id'] ?? 0 ) );
		if ( $o ) {
			$o->update_meta_data( '_ce_test', CE_RUN );
			$o->save();
		}
		return $o;
	};
	WC()->session->set( 'order_awaiting_payment', 0 );
	WC()->session->set( 'store_api_draft_order', 0 );
	ce_wc_customer( ce_mail( 'sapi' ) );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	list( $st ) = $sapi( 'PUT', array( 'payment_method' => 'bacs' ) );
	WC()->cart->calculate_totals();
	ce_ok( 'Store API: PUT bacs -> session bacs, cart priced 80.91', 200 === $st && 'bacs' === WC()->session->get( 'chosen_payment_method' ) && 8091 === ce_minor( WC()->cart->get_subtotal() ) );
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'cheque' ) );
	$tag_order( $d );
	ce_ok( 'Store API: POST with cheque while priced for bacs -> refused (' . $st . ' ' . ( $d['code'] ?? '' ) . ')', 400 === $st && false !== mb_stripos( (string) ( $d['message'] ?? '' ), 'kampanyalar değişti' ) );
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'cheque' ) );
	$o = $tag_order( $d );
	ce_ok( 'Store API: re-submitted with cheque -> order at full price 89.90, no campaign claimed', 200 === $st && $o && 8990 === ce_minor( $o->get_subtotal() ) && array() === OrderWriter::snapshot( $o ) && 'cheque' === $o->get_payment_method() );
	WC()->session->set( 'store_api_draft_order', 0 );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	list( $st ) = $sapi( 'PUT', array( 'payment_method' => 'bacs' ) );
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'bacs' ) );
	$o = $tag_order( $d );
	ce_ok( 'Store API: posted with bacs -> order keeps the campaign (80.91, snapshot lists it)', 200 === $st && $o && 8091 === ce_minor( $o->get_subtotal() ) && array( $pm ) === OrderWriter::campaignIds( OrderWriter::snapshot( $o ) ) );
	$bound = $o;
	ce_ok( 'snapshot binds the order to the payment method its campaign depends on (bacs)', 'bacs' === ( OrderWriter::snapshot( $bound )['payment_bound'] ?? '' ) );

	// Pay-for-order route POST /checkout/{id}: never refused by the cart's state, snapshot never rewritten.
	$pay = function ( WC_Order $order, $method ) use ( $addr ) {
		$r = new WP_REST_Request( 'POST', '/wc/store/v1/checkout/' . $order->get_id() );
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( array( 'key' => $order->get_order_key(), 'billing_email' => $order->get_billing_email(), 'billing_address' => array_merge( $addr, array( 'email' => $order->get_billing_email() ) ), 'shipping_address' => $addr, 'payment_method' => $method ) ) );
		$res = rest_do_request( $r );
		return array( $res->get_status(), (array) $res->get_data() );
	};
	CampaignWriter::update( $pm, null, null, 'draft' );
	$plain = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ) ), 'CE Demo Düz' );
	WC()->session->set( 'store_api_draft_order', 0 );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	$with = ce_wc_checkout( ce_mail( 'sapi' ) );
	WC()->session->set( 'order_awaiting_payment', 0 );
	WC()->cart->empty_cart();
	list( $st, $d ) = $pay( $with, 'cheque' );
	$with = wc_get_order( $with->get_id() );
	ce_ok( 'pay-for-order (empty cart): paid (' . $st . ' ' . ( $d['code'] ?? '' ) . ') and its snapshot kept', 200 === $st && array( $plain ) === OrderWriter::campaignIds( OrderWriter::snapshot( $with ) ) );
	$without = wc_create_order( array( 'status' => 'pending' ) );
	$without->add_product( wc_get_product( $p3 ), 1 );
	$without->set_billing_email( ce_mail( 'sapi' ) );
	$without->update_meta_data( '_ce_test', CE_RUN );
	$without->calculate_totals();
	$without->save();
	ce_wc_fill( array( array( $p3, 1 ) ) ); // the cart qualifies for the campaign
	list( $st, $d ) = $pay( $without, 'cheque' );
	$without = wc_get_order( $without->get_id() );
	ce_ok( 'pay-for-order (qualifying cart): order without campaign is paid (' . $st . ' ' . ( $d['code'] ?? '' ) . ') and gains no snapshot', 200 === $st && array() === OrderWriter::snapshot( $without ) );
	CampaignWriter::update( $plain, null, null, 'draft' );

	// A payment-method-bound order cannot be paid later with another gateway (Store API pay route, classic order-pay).
	$bound->update_status( 'failed' );
	WC()->cart->empty_cart();
	list( $st, $d ) = $pay( wc_get_order( $bound->get_id() ), 'cheque' );
	ce_ok( 'pay route: bacs-bound order paid with cheque -> refused (' . $st . ')', 400 === $st );
	global $wp;
	$wp->query_vars['order-pay'] = $bound->get_id();
	$gws = array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
	ce_ok( 'order-pay page: only the bound gateway is offered (' . implode( ',', $gws ) . ')', array( 'bacs' ) === $gws );
	wc_clear_notices();
	$_POST['payment_method'] = 'cheque';
	do_action( 'woocommerce_before_pay_action', wc_get_order( $bound->get_id() ) );
	ce_ok( 'order-pay action: another gateway posted -> error notice stops the payment', wc_notice_count( 'error' ) > 0 );
	wc_clear_notices();
	$_POST['payment_method'] = 'bacs';
	do_action( 'woocommerce_before_pay_action', wc_get_order( $bound->get_id() ) );
	ce_ok( 'order-pay action: the bound gateway passes', 0 === wc_notice_count( 'error' ) );
	unset( $wp->query_vars['order-pay'], $_POST['payment_method'] );
	list( $st, $d ) = $pay( wc_get_order( $bound->get_id() ), 'bacs' );
	ce_ok( 'pay route: bound order paid with bacs -> accepted (' . $st . ' ' . ( $d['code'] ?? '' ) . ')', 200 === $st );

	// ------------------------------------------------------------------ block checkout retry: WooCommerce reuses a pending/failed order
	// POST /checkout reuses the session's pending or failed order when the cart hash is unchanged. That order is
	// the cart's own checkout order (decided by route): snapshot, re-check, binding and reservation run again.
	// store_api_draft_order is deliberately kept between the attempts.
	$reused = function ( array $d, $expect_id ) {
		return (int) ( $d['order_id'] ?? 0 ) === (int) $expect_id;
	};
	$keep_session = function ( $order_id ) {
		WC()->session->set( 'store_api_draft_order', (int) $order_id );
	};
	// (a) failed order, campaign starts between the attempts -> snapshot lists it and the use is reserved.
	WC()->session->set( 'store_api_draft_order', 0 );
	ce_wc_customer( ce_mail( 'sapi' ) );
	ce_wc_fill( array( array( $p3, 2 ) ) );
	list( $st ) = $sapi( 'PUT', array( 'payment_method' => 'bacs' ) );
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'bacs' ) );
	$ra = $tag_order( $d );
	ce_ok( 'retry (a): first attempt placed without a campaign (' . $st . ' ' . ( $d['code'] ?? '' ) . ')', 200 === $st && $ra && array() === OrderWriter::snapshot( $ra ) );
	$ra->update_status( 'failed' );
	$keep_session( $ra->get_id() );
	$late = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ), 'usage_limit' => 4 ), 'CE Demo Sonradan' );
	ce_wc_fill( array( array( $p3, 2 ) ) );
	// Same cart contents as the failed order: WooCommerce compares the cart hash it stored on the order.
	$ra = wc_get_order( $ra->get_id() );
	$ra->set_cart_hash( WC()->cart->get_cart_hash() );
	$ra->save();
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'bacs' ) );
	$ra2 = $tag_order( $d );
	ce_ok( 'retry (a): WooCommerce reused the failed order (' . $st . ' ' . ( $d['code'] ?? '' ) . ')', 200 === $st && $reused( $d, $ra->get_id() ) );
	ce_ok( 'retry (a): the reused order\'s snapshot lists the campaign that started', $ra2 && array( $late ) === OrderWriter::campaignIds( OrderWriter::snapshot( $ra2 ) ) );
	ce_ok( 'retry (a): the use is held for the reused order', 1 === $count( $late, 'reserved' ) );
	CampaignWriter::update( $late, null, null, 'draft' );

	// (b) order priced with a cheque-bound campaign, left pending; retried with bacs -> one "kampanyalar değişti"
	// refusal (never the binding lockout), then priced for bacs.
	$chq = ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ),
			'rules'   => array( 'op' => 'and', 'children' => array( array( 'condition' => 'cart.payment_method', 'cmp' => '==', 'value' => 'cheque' ) ) ),
		),
		'CE Demo Çek'
	);
	WC()->session->set( 'store_api_draft_order', 0 );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	list( $st ) = $sapi( 'PUT', array( 'payment_method' => 'cheque' ) );
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'cheque' ) );
	$rb = $tag_order( $d );
	ce_ok( 'retry (b): first attempt priced with the cheque campaign and bound to cheque', 200 === $st && $rb && 'cheque' === ( OrderWriter::snapshot( $rb )['payment_bound'] ?? '' ) );
	$rb->update_status( 'pending' );
	$keep_session( $rb->get_id() );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	$rb = wc_get_order( $rb->get_id() );
	$rb->set_cart_hash( WC()->cart->get_cart_hash() );
	$rb->save();
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'bacs' ) );
	ce_ok( 'retry (b): re-posted with bacs -> one "kampanyalar değişti" refusal, not the binding lockout (' . $st . ' ' . ( $d['code'] ?? '' ) . ')', 400 === $st && false !== mb_stripos( (string) ( $d['message'] ?? '' ), 'kampanyalar değişti' ) && false === mb_stripos( (string) ( $d['message'] ?? '' ), 'aynı ödeme yöntemiyle' ) );
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'bacs' ) );
	$rb2 = $tag_order( $d );
	ce_ok( 'retry (b): re-submitted -> placed with bacs at full price, no campaign, no binding (' . $st . ' ' . ( $d['code'] ?? '' ) . ')', 200 === $st && $rb2 && 'bacs' === $rb2->get_payment_method() && 8990 === ce_minor( $rb2->get_subtotal() ) && array() === OrderWriter::campaignIds( OrderWriter::snapshot( $rb2 ) ) );

	// (c) the pay-for-order route still refuses another gateway for a cheque-bound order.
	WC()->session->set( 'store_api_draft_order', 0 );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	list( $st ) = $sapi( 'PUT', array( 'payment_method' => 'cheque' ) );
	list( $st, $d ) = $sapi( 'POST', array( 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'cheque' ) );
	$rc = $tag_order( $d );
	$rc->update_status( 'pending' );
	WC()->cart->empty_cart();
	list( $st, $d ) = $pay( wc_get_order( $rc->get_id() ), 'bacs' );
	ce_ok( 'retry (c): pay route for a cheque-bound order with bacs -> still refused (' . $st . ')', 400 === $st && 'cheque' === ( OrderWriter::snapshot( wc_get_order( $rc->get_id() ) )['payment_bound'] ?? '' ) );
	CampaignWriter::update( $chq, null, null, 'draft' );
	WC()->session->set( 'store_api_draft_order', 0 );

	// The session still names the order (Store API never clears store_api_draft_order): binding still enforced.
	$b = wc_get_order( $bound->get_id() );
	$b->update_status( 'failed' );
	WC()->session->set( 'store_api_draft_order', $b->get_id() );
	$b->set_payment_method( 'cheque' );
	$errors = new WP_Error();
	do_action( 'woocommerce_checkout_validate_order_before_payment', $b, $errors );
	ce_ok( 'failed bound order still named in the session: binding enforced, no cart re-check', array( 'eru_ce_payment_bound' ) === $errors->get_error_codes() );
	WC()->session->set( 'store_api_draft_order', 0 );

	// Bound gateway unavailable: a clear message naming it, never a silent lockout.
	$bacs_title = WC()->payment_gateways()->payment_gateways()['bacs']->get_title();
	$val        = (array) get_option( 'woocommerce_bacs_settings', array() );
	$val['enabled'] = 'no';
	update_option( 'woocommerce_bacs_settings', $val );
	WC()->payment_gateways()->init();
	$wp->query_vars['order-pay'] = $bound->get_id();
	$gws = array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
	$msg = (string) apply_filters( 'woocommerce_no_available_payment_methods_message', 'genel' );
	ce_ok( 'order-pay, bound gateway disabled: no gateway offered and the message names it (' . $msg . ')', array() === $gws && false !== strpos( $msg, $bacs_title ) && false !== mb_stripos( $msg, 'kullanılamıyor' ) );
	unset( $wp->query_vars['order-pay'] );
	list( $st, $d ) = $pay( wc_get_order( $bound->get_id() ), 'cheque' );
	ce_ok( 'pay route, bound gateway disabled: refused with the same message', 400 === $st && false !== mb_stripos( (string) ( $d['message'] ?? '' ), 'kullanılamıyor' ) );
	$lift = fn( $bound_to, $order ) => false;
	add_filter( 'eru_ce_payment_bound', $lift, 10, 2 );
	$wp->query_vars['order-pay'] = $bound->get_id();
	ce_ok( 'filter eru_ce_payment_bound returning false lifts the binding', in_array( 'cheque', array_keys( WC()->payment_gateways()->get_available_payment_gateways() ), true ) );
	unset( $wp->query_vars['order-pay'] );
	remove_filter( 'eru_ce_payment_bound', $lift, 10 );

	// Admin: "Ödeme bağını kaldır" on the order screen (capability edit_shop_orders, nonce, order note).
	ob_start();
	PaymentBinding::renderMetabox( wc_get_order( $bound->get_id() ) );
	$box = ob_get_clean();
	ce_ok( 'order metabox shows the binding and the lift button with a nonce', false !== strpos( $box, $bacs_title ) && false !== strpos( $box, 'Ödeme bağını kaldır' ) && false !== strpos( $box, 'eru_ce_unbind_nonce' ) );
	$total_before = $bound->get_total();
	$_POST['eru_ce_unbind'] = '1';
	wp_set_current_user( 0 );
	$_POST['eru_ce_unbind_nonce'] = wp_create_nonce( 'eru_ce_unbind_' . $bound->get_id() );
	do_action( 'woocommerce_process_shop_order_meta', $bound->get_id(), wc_get_order( $bound->get_id() ) );
	ce_ok( 'lift without the capability: nothing changes', 'bacs' === PaymentBinding::boundTo( wc_get_order( $bound->get_id() ) ) );
	wp_set_current_user( 1 );
	$_POST['eru_ce_unbind_nonce'] = 'gecersiz';
	do_action( 'woocommerce_process_shop_order_meta', $bound->get_id(), wc_get_order( $bound->get_id() ) );
	ce_ok( 'lift with a bad nonce: nothing changes', 'bacs' === PaymentBinding::boundTo( wc_get_order( $bound->get_id() ) ) );
	$_POST['eru_ce_unbind_nonce'] = wp_create_nonce( 'eru_ce_unbind_' . $bound->get_id() );
	do_action( 'woocommerce_process_shop_order_meta', $bound->get_id(), wc_get_order( $bound->get_id() ) );
	wp_set_current_user( 0 );
	unset( $_POST['eru_ce_unbind'], $_POST['eru_ce_unbind_nonce'] );
	$b = wc_get_order( $bound->get_id() );
	ce_ok( 'lift by a shop manager: binding removed, total kept, order note written', '' === PaymentBinding::boundTo( $b ) && $total_before === $b->get_total() && (bool) array_filter( wc_get_order_notes( array( 'order_id' => $b->get_id() ) ), fn( $n ) => false !== mb_stripos( $n->content, 'ödeme bağı' ) ) );
	list( $st, $d ) = $pay( $b, 'cheque' );
	ce_ok( 'after the lift the order can be paid with another gateway (' . $st . ')', 200 === $st );
	remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

	// The customer's own block-checkout draft reservation does not count against them.
	$own = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ), 'usage_limit' => 1 ), 'CE Demo Taslak' );
	WC()->session->set( 'store_api_draft_order', 0 );
	ce_wc_customer( ce_mail( 'c' ) );
	$cart  = ce_wc_fill( array( array( $p3, 1 ) ) );
	$draft = wc_create_order( array( 'status' => 'checkout-draft' ) );
	$draft->set_billing_email( ce_mail( 'c' ) );
	$draft->update_meta_data( '_ce_test', CE_RUN );
	WC()->checkout()->create_order_line_items( $draft, $cart );
	do_action( 'woocommerce_store_api_checkout_update_order_from_request', $draft, new WP_REST_Request() );
	$draft->save();
	$errors = new WP_Error();
	do_action( 'woocommerce_checkout_validate_order_before_payment', $draft, $errors );
	ce_ok( 'draft order reserved the last use', ! $errors->has_errors() && 1 === $count( $own, 'reserved' ) );
	WC()->session->set( 'store_api_draft_order', $draft->get_id() );
	CartApplier::forget( $cart );
	$cart->calculate_totals();
	ce_ok( 'own draft reservation does not take the campaign away from its customer', 8091 === ce_minor( $cart->get_subtotal() ) );
	WC()->session->set( 'store_api_draft_order', 0 );
	$draft->delete( true );
	CampaignWriter::update( $own, null, null, 'draft' );

	// ------------------------------------------------------------------ paid-only mode: nothing held at checkout, counted when paid
	$po = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ), 'usage_limit' => 5 ), 'CE Demo Ödemede Say' );
	Options::update( array( 'reservation_mode' => 'paid_only' ) );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	$e1 = ce_wc_checkout( ce_mail( 'c' ) );
	ce_ok( 'paid_only: checkout holds nothing', 0 === $count( $po, 'reserved' ) && 0 === $count( $po, 'consumed' ) && 8091 === ce_minor( $e1->get_subtotal() ) );
	$e1->payment_complete();
	ce_ok( 'paid_only: paid order consumes one use', 1 === $count( $po, 'consumed' ) );
	Options::update( array( 'reservation_mode' => 'reserve' ) );
	CampaignWriter::update( $po, null, null, 'draft' );

	// ------------------------------------------------------------------ inert: before any campaign was ever saved, no metrics are written
	$since = get_option( MetricsRepository::SINCE_OPTION );
	delete_option( MetricsRepository::SINCE_OPTION );
	ce_wc_fill( array( array( $p3, 1 ) ) );
	$i1 = ce_wc_checkout( ce_mail( 'b' ) );
	$i1->payment_complete();
	ce_ok( 'inert: no metrics row and no order meta while no campaign was ever saved', null === MetricsRepository::forEmail( ce_mail( 'b' ) ) && '' === wc_get_order( $i1->get_id() )->get_meta( '_eru_ce_metrics' ) );
	update_option( MetricsRepository::SINCE_OPTION, $since, false );
} catch ( RuntimeException $e ) {
	$fail = true;
	echo 'STOPPED at first failure: ' . $e->getMessage() . "\n";
} finally {
	foreach ( $gw_keep as $opt => $val ) {
		null === $val ? delete_option( $opt ) : update_option( $opt, $val );
	}
	ce_wc_purge();
	ce_wc_restore_options( $keep );
	WC()->session->set( 'chosen_shipping_methods', array() );
	WC()->session->set( 'order_awaiting_payment', 0 );
}

echo $fail ? "FAILED\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
