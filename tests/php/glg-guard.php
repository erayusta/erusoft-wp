<?php
/**
 * Gift Line Guard (mu-plugin) — quantity lock and price check.
 *   wp eval-file tests/php/glg-guard.php   (mu-plugin linked; deactivate Gift Product Rules first, the guard is tested standalone)
 * A generic "gift" line is declared through the eru_glg_is_gift_line filter; a second case uses the
 * `_eru_gpr_gift` cart item key that Gift Product Rules sets.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_GLG_Guard' ) ) {
	echo "FAIL: class Eru_GLG_Guard missing (mu-plugin not linked?)\n";
	exit( 1 );
}

if ( class_exists( 'Eru_GPR_Cart' ) ) {
	echo "FAIL: deactivate gift-product-rules-for-woocommerce first (the guard is tested standalone)\n";
	exit( 1 );
}

wc_load_cart();
$ids = array();
$mk  = function ( $name, $price ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( (string) $price );
	$p->set_manage_stock( false );
	$p->set_status( 'publish' );
	return $ids[] = $p->save();
};
$near  = fn( $a, $b ) => abs( $a - $b ) < 0.001;
$total = fn() => (float) WC()->cart->get_total( 'edit' );

try {
	$main = $mk( 'GLG Demo Ürün', 200 );
	$gift = $mk( 'GLG Demo Hediye', 90 );

	// Case 1: a gift line identified through the filter, price zeroed by "another plugin".
	$mark = function ( $is, $item ) use ( $gift ) {
		return $is || ( isset( $item['product_id'] ) && (int) $item['product_id'] === $gift );
	};
	add_filter( 'eru_glg_is_gift_line', $mark, 10, 2 );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $main, 1 );
	$gk = WC()->cart->add_to_cart( $gift, 1 );
	WC()->cart->calculate_totals();
	$check( 'filter-marked gift line is priced 0 (total 200)', $near( $total(), 200 ) );

	WC()->cart->set_quantity( $gk, 5 );
	WC()->cart->calculate_totals();
	$line = WC()->cart->get_cart()[ $gk ] ?? null;
	$check( 'set_quantity(5) is reset to 1', $line && 1 === (int) $line['quantity'] );
	$check( 'price stays 0 after quantity edit (total 200)', $line && $near( (float) $line['data']->get_price(), 0 ) && $near( $total(), 200 ) );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$check( 'recalculating twice -> still 200', $near( $total(), 200 ) );

	$html = apply_filters( 'woocommerce_cart_item_quantity', '<input>', $gk, $line );
	$check( 'quantity input is replaced by a read-only value', false !== strpos( $html, 'eru-glg-qty' ) && false === strpos( $html, 'type="number"' ) );
	$main_line = array_values( array_filter( WC()->cart->get_cart(), fn( $i ) => (int) $i['product_id'] === $main ) )[0];
	$check( 'normal lines are untouched', '<input>' === apply_filters( 'woocommerce_cart_item_quantity', '<input>', 'x', $main_line ) );
	remove_filter( 'eru_glg_is_gift_line', $mark, 10 );

	// Case 2: the line meta set by Gift Product Rules.
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $main, 1 );
	$gk2 = WC()->cart->add_to_cart( $gift, 2, 0, array(), array( '_eru_gpr_gift' => 'demo' ) );
	WC()->cart->calculate_totals();
	$check( '_eru_gpr_gift line is priced 0 (total 200)', $near( $total(), 200 ) );
	WC()->cart->set_quantity( $gk2, 7 );
	WC()->cart->calculate_totals();
	$check( '_eru_gpr_gift line keeps its first quantity (2)', 2 === (int) WC()->cart->get_cart()[ $gk2 ]['quantity'] && $near( $total(), 200 ) );
	// A normal line of the same product is not a gift.
	WC()->cart->add_to_cart( $gift, 1 );
	WC()->cart->calculate_totals();
	$check( 'ordinary line of the same product is paid (200 + 90)', $near( $total(), 290 ) );
} finally {
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
}

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
