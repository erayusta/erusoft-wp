<?php
/**
 * USD Pricing — TRY price calculated from a manual rate / TCMB XML.
 *
 *   wp eval-file tests/php/usd-pricing.php
 * Creates and removes its own products; restores options. No real HTTP request is made.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$near = function ( $a, $b ) {
	return abs( (float) $a - (float) $b ) < 0.005;
};

if ( ! class_exists( 'Eru_USD_Rate' ) ) {
	echo "FAIL: class Eru_USD_Rate missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$names = array( 'eru_usd_source', 'eru_usd_manual_rate', 'eru_usd_markup', 'eru_usd_show_usd' );
$old   = array();
foreach ( $names as $n ) {
	$old[ $n ] = get_option( $n, null );
}
$old_transient = get_transient( 'eru_usd_tcmb_rate' );
$old_last      = get_option( 'eru_usd_tcmb_last', null );
$ids           = array();
$http          = 0;
$block         = function ( $pre ) use ( &$http ) {
	$http++;
	return new WP_Error( 'eru_test_blocked', 'blocked' );
};
add_filter( 'pre_http_request', $block, 1 ); // Only this test's filter is removed afterwards.

try {
	// Inert by default: no rate configured -> prices untouched.
	foreach ( $names as $n ) {
		delete_option( $n );
	}
	delete_transient( 'eru_usd_tcmb_rate' );

	$simple = new WC_Product_Simple();
	$simple->set_name( 'USD Demo Ürün' );
	$simple->set_regular_price( '123' );
	$simple->set_status( 'publish' );
	$ids[] = $simple->save();
	update_post_meta( $ids[0], '_eru_usd_price', '10' );

	$check( 'no rate configured -> price untouched (123)', $near( wc_get_product( $ids[0] )->get_price(), 123 ) );

	update_option( 'eru_usd_source', 'manual' );
	update_option( 'eru_usd_manual_rate', '40' );
	update_option( 'eru_usd_markup', '0' );
	$check( 'rate 40, USD 10 -> 400', $near( wc_get_product( $ids[0] )->get_price(), 400 ) );
	$check( 'regular price follows too', $near( wc_get_product( $ids[0] )->get_regular_price(), 400 ) );

	update_option( 'eru_usd_markup', '5' );
	$check( 'markup 5% -> 420', $near( wc_get_product( $ids[0] )->get_price(), 420 ) );
	update_option( 'eru_usd_markup', '0' );

	// Stored-price sync (sorting/filtering) and restore when the USD price is cleared.
	Eru_USD_Pricing::sync( $ids[0] );
	$check( 'sync writes the calculated price into _regular_price/_price (400)', $near( get_post_meta( $ids[0], '_regular_price', true ), 400 ) && $near( get_post_meta( $ids[0], '_price', true ), 400 ) );
	delete_post_meta( $ids[0], '_eru_usd_price' );
	Eru_USD_Pricing::sync( $ids[0] );
	$check( 'clearing the USD price restores the original TRY price (123)', $near( get_post_meta( $ids[0], '_regular_price', true ), 123 ) && $near( get_post_meta( $ids[0], '_price', true ), 123 ) && $near( wc_get_product( $ids[0] )->get_price(), 123 ) );
	update_post_meta( $ids[0], '_eru_usd_price', '10' );
	Eru_USD_Pricing::sync( $ids[0] );
	update_post_meta( $ids[0], '_regular_price', '150' ); // Merchant edits the TRY price by hand…
	delete_post_meta( $ids[0], '_eru_usd_price' );        // …and clears USD in the same save.
	Eru_USD_Pricing::sync( $ids[0] );
	$check( 'a hand-edited TRY price is kept when USD is cleared (150)', $near( get_post_meta( $ids[0], '_regular_price', true ), 150 ) );
	update_post_meta( $ids[0], '_regular_price', '123' );
	update_post_meta( $ids[0], '_price', '123' );
	update_post_meta( $ids[0], '_eru_usd_price', '10' );

	// Variation price cache key follows the rate.
	$h40 = apply_filters( 'woocommerce_get_variation_prices_hash', array(), wc_get_product( $ids[0] ), false );
	update_option( 'eru_usd_manual_rate', '41' );
	$h41 = apply_filters( 'woocommerce_get_variation_prices_hash', array(), wc_get_product( $ids[0] ), false );
	update_option( 'eru_usd_manual_rate', '40' );
	$check( 'variation prices hash carries the rate and changes with it', in_array( 'eru_usd:40', $h40, true ) && $h40 !== $h41 );

	update_post_meta( $ids[0], '_eru_usd_sale_price', '8' );
	$p = wc_get_product( $ids[0] );
	$check( 'USD sale 8 -> price 320, regular 400, on sale', $near( $p->get_price(), 320 ) && $near( $p->get_regular_price(), 400 ) && $p->is_on_sale() );
	delete_post_meta( $ids[0], '_eru_usd_sale_price' );

	// Variable product.
	$var = new WC_Product_Variable();
	$var->set_name( 'USD Demo Varyasyonlu' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Boyut' );
	$attr->set_options( array( 'S' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$var->set_attributes( array( $attr ) );
	$var->set_status( 'publish' );
	$ids[]     = $var->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $var->get_id() );
	$variation->set_attributes( array( 'boyut' => 'S' ) );
	$variation->set_regular_price( '99' );
	$ids[] = $variation->save();
	WC_Product_Variable::sync( $var->get_id() );
	update_post_meta( $ids[2], '_eru_usd_price', '5' );

	$check( 'variation USD 5 -> 200', $near( wc_get_product( $ids[2] )->get_price(), 200 ) );
	WC_Product_Variable::sync( $ids[1] );
	$prices = wc_get_product( $ids[1] )->get_variation_prices( true );
	$check( 'variable parent min price -> 200', $near( min( $prices['price'] ), 200 ) );

	// Cart total, recalculated twice.
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[1], 1, $ids[2], array( 'attribute_boyut' => 'S' ) );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$check( 'cart with the variation -> 200 (twice)', $near( WC()->cart->get_total( 'edit' ), 200 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[0], 2 );
	WC()->cart->calculate_totals();
	$check( 'cart 2 x USD 10 at 40 -> 800', $near( WC()->cart->get_total( 'edit' ), 800 ) );
	WC()->cart->empty_cart();

	// Manual source never touches the network.
	delete_transient( 'eru_usd_tcmb_rate' );
	Eru_USD_Rate::effective();
	$check( 'manual source -> zero HTTP requests', 0 === $http );

	// TCMB parsing (fixture, no network).
	$xml = '<?xml version="1.0" encoding="UTF-8"?><Tarih_Date Tarih="10.10.2026"><Currency CurrencyCode="EUR"><ForexSelling>45.5000</ForexSelling></Currency><Currency CurrencyCode="USD"><ForexBuying>39.9000</ForexBuying><ForexSelling>40.1234</ForexSelling></Currency></Tarih_Date>';
	$check( 'TCMB fixture parsed (USD ForexSelling 40.1234)', $near( Eru_USD_Rate::parse_tcmb( $xml ), 40.1234 ) );
	$check( 'garbage XML -> 0', 0.0 === Eru_USD_Rate::parse_tcmb( '<a><b>' ) );
	$check( 'XML without USD -> 0', 0.0 === Eru_USD_Rate::parse_tcmb( '<Tarih_Date><Currency CurrencyCode="EUR"><ForexSelling>1</ForexSelling></Currency></Tarih_Date>' ) );

	// TCMB source: cached transient used, no HTTP.
	update_option( 'eru_usd_source', 'tcmb' );
	set_transient( 'eru_usd_tcmb_rate', 41.0, HOUR_IN_SECONDS );
	$before = $http;
	$check( 'tcmb source with fresh cache -> rate 41, no HTTP', $near( Eru_USD_Rate::effective(), 41 ) && $http === $before );
	delete_transient( 'eru_usd_tcmb_rate' );
	delete_transient( 'eru_usd_tcmb_backoff' );
	update_option( 'eru_usd_tcmb_last', array( 'rate' => 39.5, 'time' => time() - DAY_IN_SECONDS ), false );
	wp_clear_scheduled_hook( 'eru_usd_refresh_rate' );
	$before = $http;
	$rate   = Eru_USD_Rate::effective();
	$check( 'tcmb source with empty cache: price filter makes no HTTP, uses the last rate (39.5)', $http === $before && $near( $rate, 39.5 ) );
	$check( 'tcmb source with empty cache: refresh scheduled in cron', false !== wp_next_scheduled( 'eru_usd_refresh_rate' ) );
	do_action( 'eru_usd_refresh_rate' );
	$check( 'cron refresh attempts the HTTP request', $http > $before );
	wp_clear_scheduled_hook( 'eru_usd_refresh_rate' );
} finally {
	remove_filter( 'pre_http_request', $block, 1 );
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $names as $n ) {
		null === $old[ $n ] ? delete_option( $n ) : update_option( $n, $old[ $n ] );
	}
	null === $old_last ? delete_option( 'eru_usd_tcmb_last' ) : update_option( 'eru_usd_tcmb_last', $old_last, false );
	false === $old_transient ? delete_transient( 'eru_usd_tcmb_rate' ) : set_transient( 'eru_usd_tcmb_rate', $old_transient, HOUR_IN_SECONDS );
	delete_transient( 'eru_usd_tcmb_backoff' );
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
