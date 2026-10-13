<?php
/**
 * Coming-Soon Products — a flagged product is not purchasable, shows the message and no add-to-cart button.
 *   wp eval-file tests/php/csp-coming-soon.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_CSP' ) ) {
	echo "FAIL: class Eru_CSP missing (plugin not active?)\n";
	exit( 1 );
}

$saved = get_option( 'eru_csp_settings', null );
$ids   = array();
$mk    = function ( $title ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	$p->save();
	return $ids[] = $p->get_id();
};
$single = function ( $id ) {
	global $product, $post;
	$post    = get_post( $id );
	$product = wc_get_product( $id );
	setup_postdata( $post );
	ob_start();
	do_action( 'woocommerce_single_product_summary' );
	return ob_get_clean();
};

try {
	delete_option( 'eru_csp_settings' );
	$p   = $mk( 'CSP Demo Ürün' );
	$q   = $mk( 'CSP Demo Normal' );
	$out = $single( $p );
	$check( 'default: not flagged -> purchasable, add-to-cart present', wc_get_product( $p )->is_purchasable() && false !== strpos( $out, 'single_add_to_cart_button' ) );
	$check( 'default: no coming-soon markup', false === strpos( $out, 'eru-csp' ) );

	update_post_meta( $p, Eru_CSP::ENABLED, 'yes' );
	update_post_meta( $p, Eru_CSP::MESSAGE, 'Demo mesaj: kısa süre içinde' );
	$prod = wc_get_product( $p );
	$check( 'flagged product is not purchasable', false === $prod->is_purchasable() );
	$out = $single( $p );
	$check( 'single page contains the per-product message', false !== strpos( $out, 'Demo mesaj: kısa süre içinde' ) );
	$check( 'single page has no add-to-cart button', false === strpos( $out, 'single_add_to_cart_button' ) && false === strpos( $out, 'name="add-to-cart"' ) );
	$check( 'price is replaced by the badge', false === strpos( $prod->get_price_html(), 'amount' ) );
	$check( 'other product unaffected', wc_get_product( $q )->is_purchasable() );
	if ( null === WC()->cart ) {
		wc_load_cart();
	}
	WC()->cart->empty_cart();
	$check( 'cart refuses the flagged product', false === WC()->cart->add_to_cart( $p ) && 0 === WC()->cart->get_cart_contents_count() );
	wc_clear_notices();

	delete_post_meta( $p, Eru_CSP::MESSAGE );
	update_option( 'eru_csp_settings', array( 'message' => 'Demo varsayılan mesaj', 'badge' => 'Yakında', 'hide_price' => 'yes', 'show_date' => 'yes' ) );
	$out = $single( $p );
	$check( 'global default message used when product has none', false !== strpos( $out, 'Demo varsayılan mesaj' ) );

	update_post_meta( $p, Eru_CSP::DATE, wp_date( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) );
	$check( 'future release date keeps it coming soon', Eru_CSP::is_active( wc_get_product( $p ) ) );
	update_post_meta( $p, Eru_CSP::DATE, wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) );
	$check( 'past release date ends coming-soon mode', ! Eru_CSP::is_active( wc_get_product( $p ) ) && wc_get_product( $p )->is_purchasable() );

	// Variation of a flagged variable product.
	$v = new WC_Product_Variable();
	$v->set_name( 'CSP Demo Varyasyonlu' );
	$v->set_status( 'publish' );
	$v->save();
	$ids[] = $vid = $v->get_id();
	$var = new WC_Product_Variation();
	$var->set_parent_id( $vid );
	$var->set_regular_price( '10' );
	$var->save();
	$ids[] = $var->get_id();
	$check( 'variation purchasable before flagging', wc_get_product( $var->get_id() )->is_purchasable() );
	update_post_meta( $vid, Eru_CSP::ENABLED, 'yes' );
	$check( 'variation of a flagged parent is not purchasable', ! wc_get_product( $var->get_id() )->is_purchasable() );
} finally {
	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( null === $saved ) {
		delete_option( 'eru_csp_settings' );
	} else {
		update_option( 'eru_csp_settings', $saved );
	}
}

exit( $fail ? 1 : 0 );
