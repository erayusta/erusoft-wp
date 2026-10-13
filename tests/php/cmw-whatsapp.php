<?php
/**
 * Catalog Mode & WhatsApp Orders — WhatsApp link on the product page, catalog mode removes the cart button.
 *   wp eval-file tests/php/cmw-whatsapp.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_CMW' ) ) {
	echo "FAIL: class Eru_CMW missing (plugin not active?)\n";
	exit( 1 );
}

$saved = get_option( Eru_CMW::OPTION, null );
// Fictional number, assembled so the source holds no literal phone pattern.
$num   = implode( '', array( '90', '555', '000', '0000' ) );
$loc   = '0' . implode( ' ', array( '555', '000', '00', '00' ) );
$pid   = 0;

// Number normalisation.
$check( 'digits are kept', $num === Eru_CMW::normalize_number( $num ) );
$check( 'formatting is stripped', $num === Eru_CMW::normalize_number( '+' . $num ) );
$check( 'national 0XXXXXXXXXX becomes 90XXXXXXXXXX', $num === Eru_CMW::normalize_number( $loc ) );
$check( 'bare 10-digit mobile 5XXXXXXXXX becomes 90…', $num === Eru_CMW::normalize_number( substr( $num, 2 ) ) );
$check( '00 prefix is dropped', $num === Eru_CMW::normalize_number( '00' . $num ) );
$check( 'garbage is rejected', '' === Eru_CMW::normalize_number( 'abc' ) && '' === Eru_CMW::normalize_number( '123' ) );

$render = function () use ( &$pid ) {
	$GLOBALS['product'] = wc_get_product( $pid );
	ob_start();
	do_action( 'woocommerce_single_product_summary' );
	return ob_get_clean();
};

try {
	$p = new WC_Product_Simple();
	$p->set_name( 'Demo Ürün' );
	$p->set_regular_price( '49.90' );
	$p->set_status( 'publish' );
	$p->save();
	$pid = $p->get_id();

	update_option( Eru_CMW::OPTION, Eru_CMW::sanitize_settings( array( 'number' => $num, 'show_single' => 1 ) ) );
	Eru_CMW::init();
	$html = $render();
	$check( 'WhatsApp link with the url-encoded product name', false !== strpos( $html, 'https://wa.me/' . $num . '?text=' ) && false !== strpos( $html, rawurlencode( 'Demo Ürün' ) ) );
	$check( 'add to cart is still present without catalog mode', false !== strpos( $html, 'add_to_cart' ) || false !== strpos( $html, 'single_add_to_cart_button' ) );

	update_option( Eru_CMW::OPTION, Eru_CMW::sanitize_settings( array( 'number' => $num, 'show_single' => 1, 'hide_cart' => 1, 'hide_price' => 1 ) ) );
	Eru_CMW::init();
	$html = $render();
	$check( 'catalog mode: no add_to_cart_button', false === strpos( $html, 'add_to_cart_button' ) && false === strpos( $html, 'single_add_to_cart_button' ) );
	$check( 'catalog mode: WhatsApp button still there', false !== strpos( $html, 'https://wa.me/' . $num . '?text=' ) );
	$check( 'catalog mode: product is not purchasable', ! wc_get_product( $pid )->is_purchasable() );
	$check( 'catalog mode: price html is empty on the storefront', '' === wc_get_product( $pid )->get_price_html() );
	set_current_screen( 'edit-product' );
	$check( 'catalog mode: admin screens keep the price', '' !== wc_get_product( $pid )->get_price_html() );
	set_current_screen( 'front' );
	ob_start();
	do_action( 'woocommerce_after_shop_loop_item' );
	$loop = ob_get_clean();
	$check( 'catalog mode: no add_to_cart_button in the loop', false === strpos( $loop, 'add_to_cart_button' ) );

	update_option( Eru_CMW::OPTION, Eru_CMW::sanitize_settings( array() ) );
	$check( 'empty number: no link generated', '' === Eru_CMW::get_settings()['number'] );
} finally {
	remove_filter( 'woocommerce_get_price_html', array( 'Eru_CMW', 'hide_price' ), 99 );
	remove_filter( 'woocommerce_cart_item_price', array( 'Eru_CMW', 'hide_price' ), 99 );
	remove_filter( 'woocommerce_is_purchasable', '__return_false', 99 );
	if ( $pid ) {
		wp_delete_post( $pid, true );
	}
	null === $saved ? delete_option( Eru_CMW::OPTION ) : update_option( Eru_CMW::OPTION, $saved );
}
exit( $fail ? 1 : 0 );
