<?php
/**
 * Device Trade-In — value, cap, one per cart, stale device, discount modes (fee vs line discount tax).
 *
 *   wp eval-file tests/php/dti-pricing.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_DTI_Pricing' ) ) {
	echo "FAIL: Eru_DTI_Pricing missing (plugin not active?)\n";
	exit( 1 );
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_dti_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

$old_settings = get_option( Eru_DTI_Settings::OPTION, null );
$old_tax      = get_option( 'woocommerce_calc_taxes' );
$old_incl     = get_option( 'woocommerce_prices_include_tax' );
$pids         = array();
$dids         = array();
$order_ids    = array();
$rate_id      = 0;
$code         = 0;

try {
	wc_load_cart();
	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	$rate_id = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'Demo KDV', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );

	$mk_product = function ( $price ) use ( &$pids ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'Demo Ürün' );
		$p->set_regular_price( (string) $price );
		$p->set_status( 'publish' );
		$p->save();
		$pids[] = $p->get_id();
		return $p->get_id();
	};
	$mk_device = function ( $base ) use ( &$dids ) {
		$id     = Eru_DTI_DB::device_insert( array( 'level1' => 'Demo Marka', 'level2' => 'Model A', 'level3' => '64 GB', 'base_value' => $base, 'active' => 1 ) );
		$dids[] = $id;
		return $id;
	};
	$post = function ( $did, $cond = 'iyi' ) {
		$_POST['eru_dti'] = array( 'use' => '1', 'device_id' => (string) $did, 'condition' => $cond, 'serial' => 'DEMO-0001', 'terms' => '1' );
	};
	// Mirrors WC_Form_Handler: validation filter first, then add_to_cart.
	$add = function ( $pid ) {
		if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1 ) ) {
			return false;
		}
		return WC()->cart->add_to_cart( $pid, 1 );
	};
	$fresh_cart = function () {
		WC()->cart->empty_cart();
		wc_clear_notices();
	};

	Eru_DTI_Settings::save_for_test( array( 'bonus_enabled' => 'no', 'bonus_amount' => '0', 'discount_mode' => 'fee', 'serial_required' => 'yes', 'terms_text' => '' ) );

	// (a) value = base x percent (+ bonus).
	$d1 = $mk_device( 10000 );
	$r  = Eru_DTI_Pricing::resolve( $d1, 'iyi' );
	$check( '(a) 10.000 x Iyi 80% = 8.000', ! is_wp_error( $r ) && abs( $r['value'] - 8000 ) < 0.001 );
	Eru_DTI_Settings::save_for_test( array( 'bonus_enabled' => 'yes', 'bonus_amount' => '500' ) );
	$r = Eru_DTI_Pricing::resolve( $d1, 'iyi' );
	$check( '(a) campaign bonus 500 on = 8.500', abs( $r['value'] - 8500 ) < 0.001 && abs( $r['bonus'] - 500 ) < 0.001 );
	Eru_DTI_Settings::save_for_test( array( 'bonus_enabled' => 'no' ) );
	$check( 'unknown condition is an error', is_wp_error( Eru_DTI_Pricing::resolve( $d1, 'yok' ) ) );

	// (b) capped at the per-unit ex-tax price.
	$p6 = $mk_product( 6000 );
	Eru_DTI_Settings::save_for_test( array( 'products' => array( $p6 ) ) );
	$fresh_cart();
	$post( $d1 );
	$key = $add( $p6 );
	$check( 'trade-in product added', (bool) $key );
	WC()->cart->calculate_totals();
	$check( '(b) credit capped at 6.000 (fee total -6.000)', abs( WC()->cart->get_fee_total() + 6000 ) < 0.001 );

	// (c) second trade-in rejected.
	wc_clear_notices();
	$post( $d1 );
	$key2 = $add( $p6 );
	$notices = wc_get_notices( 'error' );
	$check( '(c) second trade-in rejected with a notice', ! $key2 && ! empty( $notices ) );

	// (d) device deactivated after add -> removed on recalc with a notice.
	wc_clear_notices();
	Eru_DTI_DB::device_update( $d1, array( 'active' => 0 ) );
	WC()->cart->calculate_totals();
	$items = WC()->cart->get_cart();
	$item  = reset( $items );
	$check( '(d) trade-in data removed from the line', empty( $item['eru_dti'] ) );
	$check( '(d) product line kept, fee gone', 0.0 === (float) WC()->cart->get_fee_total() );
	$check( '(d) customer notice', ! empty( wc_get_notices( 'notice' ) ) );
	Eru_DTI_DB::device_update( $d1, array( 'active' => 1 ) );

	// Not enabled product: selector data refused.
	$pother = $mk_product( 500 );
	$fresh_cart();
	$post( $d1 );
	$check( 'product outside enabled list refuses a trade-in', ! $add( $pother ) );
	// Serial required.
	$fresh_cart();
	$post( $d1 );
	$_POST['eru_dti']['serial'] = '';
	$check( 'missing serial refused when required', ! $add( $p6 ) );

	// (e) fee vs line discount.
	$pe = $mk_product( 10000 );
	$de = $mk_device( 10000 );
	Eru_DTI_Settings::save_for_test( array( 'products' => array( $pe ), 'discount_mode' => 'fee' ) );
	$fresh_cart();
	$post( $de );
	$add( $pe );
	WC()->cart->calculate_totals();
	$fee_total = (float) WC()->cart->get_total( 'edit' );
	$fee_tax   = (float) WC()->cart->get_total_tax();
	$check( '(e) fee mode: total reduced (12.000 - 8.000)', abs( $fee_total - 4000 ) < 0.01 );
	$check( '(e) fee mode: tax unchanged (2.000)', abs( $fee_tax - 2000 ) < 0.01 );

	Eru_DTI_Settings::save_for_test( array( 'discount_mode' => 'line_discount' ) );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals(); // Idempotent across recalcs.
	$ld_total = (float) WC()->cart->get_total( 'edit' );
	$ld_tax   = (float) WC()->cart->get_total_tax();
	$check( '(e) line_discount: tax base reduced, tax lower (400)', $ld_tax < $fee_tax && abs( $ld_tax - 400 ) < 0.01 );
	$check( '(e) line_discount: total 2.400 and no fee', abs( $ld_total - 2400 ) < 0.01 && 0.0 === (float) WC()->cart->get_fee_total() );

	// Order creation writes the trade-in row, order meta and visible line meta (once).
	$order_data = array( 'billing_email' => 'ornek@example.com', 'billing_first_name' => 'Demo', 'billing_last_name' => 'Kişi', 'payment_method' => 'bacs', 'customer_id' => 0 );
	$oid        = WC()->checkout()->create_order( $order_data );
	$order      = wc_get_order( $oid );
	$order_ids[] = $oid;
	$tid        = (int) $order->get_meta( '_eru_dti_tradein_id' );
	$row        = $tid ? Eru_DTI_DB::tradein_get( $tid ) : null;
	$check( 'order creation writes the trade-in row and order meta', $row && (int) $row['order_id'] === $oid && 'beklemede' === $row['status'] && abs( (float) $row['value'] - 8000 ) < 0.001 && 'ornek@example.com' === $row['customer_email'] );
	$meta_keys = array();
	foreach ( $order->get_items() as $it ) {
		foreach ( $it->get_formatted_meta_data() as $m ) {
			$meta_keys[] = $m->display_key;
		}
	}
	$check( 'order line shows the trade-in meta (hidden payload not shown)', in_array( 'Takas cihazı', $meta_keys, true ) && 2 === count( $meta_keys ) );
	$payload_has_serial = false;
	foreach ( wc_get_order( $oid )->get_items() as $it ) {
		$pl = $it->get_meta( '_eru_dti' );
		$payload_has_serial = $payload_has_serial || isset( $pl['serial'] );
	}
	$check( 'serial is not kept in the order line meta (table only)', ! $payload_has_serial && 'DEMO-0001' === $row['serial'] );
	Eru_DTI_Cart::create_tradein_for_order( $order );
	$check( 'creating twice does not duplicate the row', 1 === (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Eru_DTI_DB::table( 'tradeins' ) . ' WHERE order_id = %d', $oid ) ) );

	// Remove link clears the credit.
	$items = WC()->cart->get_cart();
	$k     = array_key_first( $items );
	$check( 'remove link carries a nonce', false !== strpos( Eru_DTI_Cart::remove_url( $k ), '_wpnonce=' ) );
	Eru_DTI_Cart::remove_tradein( $k );
	WC()->cart->calculate_totals();
	$check( 'after removal the full price is charged (12.000)', abs( (float) WC()->cart->get_total( 'edit' ) - 12000 ) < 0.01 );
} catch ( Throwable $e ) {
	echo 'ERROR: ' . $e->getMessage() . "\n";
	$code = 1;
} finally {
	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
	wc_clear_notices();
	unset( $_POST['eru_dti'] );
	foreach ( $order_ids as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}eru_dti_tradeins WHERE customer_email = 'ornek@example.com'" ); // phpcs:ignore
	foreach ( $pids as $pid ) {
		wp_delete_post( $pid, true );
	}
	foreach ( $dids as $did ) {
		Eru_DTI_DB::device_delete( $did );
	}
	if ( $rate_id ) {
		WC_Tax::_delete_tax_rate( $rate_id );
	}
	update_option( 'woocommerce_calc_taxes', $old_tax );
	update_option( 'woocommerce_prices_include_tax', $old_incl );
	if ( null === $old_settings ) {
		delete_option( Eru_DTI_Settings::OPTION );
	} else {
		update_option( Eru_DTI_Settings::OPTION, $old_settings );
	}
}
if ( $code ) {
	exit( 1 );
}
echo "OK dti-pricing\n";
