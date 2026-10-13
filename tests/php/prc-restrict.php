<?php
/**
 * Payment Restrictions by Category — gateway availability check.
 *
 *   wp eval-file tests/php/prc-restrict.php
 * Enables cod + bacs for the test and restores every option it touches.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_PRC_Rules' ) ) {
	echo "FAIL: class Eru_PRC_Rules missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old = array(
	'rules' => get_option( 'eru_prc_rules', null ),
	'cod'   => get_option( 'woocommerce_cod_settings', null ),
	'bacs'  => get_option( 'woocommerce_bacs_settings', null ),
);
$ids = array();
$cat = 0;

try {
	$term = wp_insert_term( 'Demo Kategori', 'product_cat' );
	$cat  = is_wp_error( $term ) ? (int) $term->get_error_data() : (int) $term['term_id'];
	$child = wp_insert_term( 'Demo Alt Kategori', 'product_cat', array( 'parent' => $cat ) );
	$child = is_wp_error( $child ) ? (int) $child->get_error_data() : (int) $child['term_id'];

	foreach ( array( 'cod', 'bacs' ) as $gw ) {
		$settings            = (array) get_option( "woocommerce_{$gw}_settings", array() );
		$settings['enabled'] = 'yes';
		update_option( "woocommerce_{$gw}_settings", $settings );
	}
	WC()->payment_gateways()->init();

	$restricted = new WC_Product_Simple();
	$restricted->set_name( 'PRC Demo Kısıtlı' );
	$restricted->set_regular_price( '100' );
	$restricted->set_category_ids( array( $child ) );
	$restricted->set_status( 'publish' );
	$ids[] = $restricted->save();

	$normal = new WC_Product_Simple();
	$normal->set_name( 'PRC Demo Normal' );
	$normal->set_regular_price( '100' );
	$normal->set_status( 'publish' );
	$ids[] = $normal->save();

	$available = function () {
		WC()->payment_gateways()->init();
		return array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
	};

	delete_option( 'eru_prc_rules' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[0] );
	$check( 'no rules -> cod available', in_array( 'cod', $available(), true ) );

	update_option( 'eru_prc_rules', array( array( 'terms' => array( 'product_cat:' . $cat ), 'gateways' => array( 'cod' ), 'notice' => 'Demo uyarı' ) ) );
	$check( 'restricted (child of picked category) cart -> cod hidden', ! in_array( 'cod', $available(), true ) );
	$check( 'restricted cart -> bacs still available', in_array( 'bacs', $available(), true ) );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[1] );
	$check( 'other cart -> cod available', in_array( 'cod', $available(), true ) );

	WC()->cart->add_to_cart( $ids[0] );
	$check( 'mixed cart -> cod hidden', ! in_array( 'cod', $available(), true ) );

	$rows = Eru_PRC_Rules::sanitize( array( array( 'terms' => array( 'product_cat:5', 'bogus', 'product_tag:x' ), 'gateways' => array( 'cod', '' ), 'notice' => '<script>x</script>Merhaba' ), array( 'terms' => array(), 'gateways' => array( 'cod' ) ) ) );
	$check( 'sanitiser drops rows without terms and bad terms', 1 === count( $rows ) && array( 'product_cat:5' ) === $rows[0]['terms'] && false === strpos( $rows[0]['notice'], '<script' ) );
} finally {
	WC()->cart->empty_cart();
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $cat ) {
		wp_delete_term( $child, 'product_cat' );
		wp_delete_term( $cat, 'product_cat' );
	}
	foreach ( array( 'rules' => 'eru_prc_rules', 'cod' => 'woocommerce_cod_settings', 'bacs' => 'woocommerce_bacs_settings' ) as $k => $opt ) {
		null === $old[ $k ] ? delete_option( $opt ) : update_option( $opt, $old[ $k ] );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
