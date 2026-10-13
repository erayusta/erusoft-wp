<?php
/**
 * Purchase Limits — product / category / order / per-customer limits.
 *
 *   wp eval-file tests/php/pl-limits.php
 * Creates and removes its own products, category, user and order; restores options.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_PL_Limits' ) ) {
	echo "FAIL: class Eru_PL_Limits missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old_settings = get_option( 'eru_pl_settings', null );
$ids          = array();
$cat          = 0;
$user_id      = 0;
$order_id     = 0;

$cart_qty = function () {
	$n = 0;
	foreach ( WC()->cart->get_cart() as $item ) {
		$n += $item['quantity'];
	}
	return $n;
};
$errors = function () {
	$n = wc_notice_count( 'error' );
	wc_clear_notices();
	return $n;
};
$mk = function ( $name, $price = '10', $cats = array() ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_category_ids( $cats );
	$p->set_status( 'publish' );
	$ids[] = $p->save();
	return end( $ids );
};

try {
	delete_option( 'eru_pl_settings' );
	$term = wp_insert_term( 'PL Demo Kategori', 'product_cat' );
	$cat  = is_wp_error( $term ) ? (int) $term->get_error_data() : (int) $term['term_id'];

	// --- per product max ---------------------------------------------------
	$a = $mk( 'PL Demo A' );
	update_post_meta( $a, '_eru_pl_max', 2 );
	WC()->cart->empty_cart();
	wc_clear_notices();
	$r = WC()->cart->add_to_cart( $a, 3 );
	$check( 'qty 3 with max 2 -> rejected with error notice', $errors() > 0 && 0 === $cart_qty() );
	$check( 'form/AJAX validation filter rejects qty 3', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $a, 3 ) && $errors() > 0 );
	$r = WC()->cart->add_to_cart( $a, 2 );
	$check( 'qty 2 with max 2 -> accepted', false !== $r && 2 === $cart_qty() );
	$errors();
	$r = WC()->cart->add_to_cart( $a, 1 );
	$check( 'adding one more beyond max -> rejected, qty stays <= 2', $errors() > 0 && 2 === $cart_qty() );

	// --- per product min (reported by cart check) ---------------------------
	$b = $mk( 'PL Demo B' );
	update_post_meta( $b, '_eru_pl_min', 3 );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $b, 1 );
	wc_clear_notices();
	do_action( 'woocommerce_check_cart_items' );
	$check( 'qty 1 with min 3 -> cart check error', $errors() > 0 );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $b, 3 );
	do_action( 'woocommerce_check_cart_items' );
	$check( 'qty 3 with min 3 -> no error', 0 === $errors() );

	// --- category max across products --------------------------------------
	$c1 = $mk( 'PL Demo C1', '10', array( $cat ) );
	$c2 = $mk( 'PL Demo C2', '10', array( $cat ) );
	update_option( 'eru_pl_settings', array( 'category_rules' => array( array( 'category' => $cat, 'min' => 0, 'max' => 3, 'customer_max' => 0, 'period_days' => 0 ) ) ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $c1, 2 );
	$errors();
	$r = WC()->cart->add_to_cart( $c2, 2 );
	$check( 'category max 3 across two products -> 2+2 rejected, qty stays 2', $errors() > 0 && 2 === $cart_qty() );
	$r = WC()->cart->add_to_cart( $c2, 1 );
	$check( 'category max 3 -> 2+1 accepted', false !== $r );
	$errors();

	// --- order totals -------------------------------------------------------
	update_option( 'eru_pl_settings', array( 'order_max' => 4 ) );
	$d = $mk( 'PL Demo D' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $d, 3 );
	$errors();
	$r = WC()->cart->add_to_cart( $a, 2 );
	$check( 'order max 4 -> 3+2 rejected, qty stays 3', $errors() > 0 && 3 === $cart_qty() );
	update_option( 'eru_pl_settings', array( 'order_min' => 2 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $d, 1 );
	do_action( 'woocommerce_check_cart_items' );
	$check( 'order min 2 with 1 item -> cart check error', $errors() > 0 );

	// --- per customer per period -------------------------------------------
	delete_option( 'eru_pl_settings' );
	$e = $mk( 'PL Demo E' );
	update_post_meta( $e, '_eru_pl_customer_max', 3 );
	update_post_meta( $e, '_eru_pl_period_days', 30 );
	$user_id = wp_create_user( 'pl_demo_user_' . wp_rand( 1000, 9999 ), wp_generate_password(), 'pl-demo-' . wp_rand( 1000, 9999 ) . '@example.com' );
	$order   = wc_create_order( array( 'customer_id' => $user_id, 'status' => 'completed' ) );
	$order->add_product( wc_get_product( $e ), 2 );
	$order->set_billing_email( get_userdata( $user_id )->user_email );
	$order->calculate_totals();
	$order->save();
	$order_id = $order->get_id();

	wp_set_current_user( $user_id );
	WC()->cart->empty_cart();
	$errors();
	$r = WC()->cart->add_to_cart( $e, 2 );
	$check( 'bought 2 earlier, customer max 3 -> adding 2 rejected', $errors() > 0 && 0 === $cart_qty() );
	$r = WC()->cart->add_to_cart( $e, 1 );
	$check( 'bought 2 earlier, customer max 3 -> adding 1 accepted', false !== $r );
	$errors();

	$order->set_date_created( time() - 60 * DAY_IN_SECONDS );
	$order->save();
	Eru_PL_Limits::flush_cache();
	WC()->cart->empty_cart();
	$r = WC()->cart->add_to_cart( $e, 3 );
	$check( 'order older than the period is not counted -> 3 accepted', false !== $r );
	$errors();
	wp_set_current_user( 0 );

	// --- custom message ------------------------------------------------------
	update_option( 'eru_pl_settings', array( 'msg_max' => 'Sınır: {limit} adet ({name})' ) );
	WC()->cart->empty_cart();
	wc_clear_notices();
	WC()->cart->add_to_cart( $a, 3 );
	$notices = wc_get_notices( 'error' );
	wc_clear_notices();
	$check( 'custom message placeholders are filled', ! empty( $notices ) && false !== strpos( wp_strip_all_tags( $notices[0]['notice'] ), 'Sınır: 2 adet (PL Demo A)' ) );
} finally {
	WC()->cart->empty_cart();
	wc_clear_notices();
	if ( $order_id && ( $o = wc_get_order( $order_id ) ) ) {
		$o->delete( true );
	}
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $cat ) {
		wp_delete_term( $cat, 'product_cat' );
	}
	if ( $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
	null === $old_settings ? delete_option( 'eru_pl_settings' ) : update_option( 'eru_pl_settings', $old_settings );
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
