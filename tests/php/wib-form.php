<?php
/**
 * Wholesale Inquiry Box — form handler sends one e-mail to the configured recipient.
 *
 *   wp eval-file tests/php/wib-form.php
 * Captures wp_mail through `pre_wp_mail`; restores options and removes its own product.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_WIB_Form' ) ) {
	echo "FAIL: class Eru_WIB_Form missing (plugin not active?)\n";
	exit( 1 );
}

$old_opt  = get_option( 'eru_wib_settings', null );
$captured = array();
$capture  = function ( $pre, $atts ) use ( &$captured ) {
	$captured[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capture, 10, 2 );
$no_wait = fn() => 0;
add_filter( 'eru_wib_rate_limit', $no_wait );
$pid = 0;

try {
	$p = new WC_Product_Simple();
	$p->set_name( 'WIB Demo Ürün' );
	$p->set_regular_price( '100' );
	$p->set_status( 'publish' );
	$pid = $p->save();

	$post = function ( array $over = array() ) use ( &$pid ) {
		return array_merge(
			array(
				'_eru_wib_nonce'  => wp_create_nonce( 'eru_wib_submit' ),
				'product_id'      => $pid,
				'name'            => 'Demo Kişi',
				'email'           => 'ornek@example.com',
				'phone'           => 'demo-telefon',
				'qty'             => 25,
				'message'         => 'Demo mesaj',
				'eru_wib_website' => '',
			),
			$over
		);
	};
	$count = function () use ( &$captured ) {
		return count( $captured );
	};

	// Disabled by default: no box, no output.
	delete_option( 'eru_wib_settings' );
	$check( 'disabled by default -> box does not apply', ! Eru_WIB_Form::applies_to( $pid ) );
	ob_start();
	$GLOBALS['product'] = wc_get_product( $pid );
	Eru_WIB_Form::render();
	$check( 'disabled by default -> no markup', '' === trim( ob_get_clean() ) );
	$check( 'disabled -> submission refused, no mail', 'invalid' === Eru_WIB_Form::process( $post() ) && 0 === $count() );

	update_option( 'eru_wib_settings', Eru_WIB_Settings::sanitize( array( 'enabled' => 1, 'min_qty' => 10, 'email' => 'alici@example.com' ) ) );
	ob_start();
	Eru_WIB_Form::render();
	$html = ob_get_clean();
	$check( 'enabled -> box with form and honeypot', false !== strpos( $html, 'Toplu alım için teklif isteyin' ) && false !== strpos( $html, 'eru_wib_website' ) && false !== strpos( $html, '_eru_wib_nonce' ) );

	$check( 'valid post -> ok', 'ok' === Eru_WIB_Form::process( $post() ) );
	$check( 'wp_mail called once', 1 === $count() );
	$to = $captured[0]['to'] ?? '';
	$check( 'recipient = setting', 'alici@example.com' === ( is_array( $to ) ? $to[0] : $to ) );
	$check( 'mail carries product and qty', false !== strpos( $captured[0]['message'], 'WIB Demo Ürün' ) && false !== strpos( $captured[0]['message'], '25' ) );

	$check( 'bad nonce rejected', 'nonce' === Eru_WIB_Form::process( $post( array( '_eru_wib_nonce' => 'x' ) ) ) && 1 === $count() );
	$check( 'honeypot filled -> silent ok, no mail', 'ok' === Eru_WIB_Form::process( $post( array( 'eru_wib_website' => 'spam' ) ) ) && 1 === $count() );
	$check( 'qty below minimum rejected', 'qty' === Eru_WIB_Form::process( $post( array( 'qty' => 3 ) ) ) && 1 === $count() );
	$check( 'invalid e-mail rejected', 'invalid' === Eru_WIB_Form::process( $post( array( 'email' => 'yanlis' ) ) ) && 1 === $count() );
	$check( 'header injection in name is flattened', 'ok' === Eru_WIB_Form::process( $post( array( 'name' => "Demo\r\nBcc: x@example.com" ) ) ) && false === stripos( implode( "\n", (array) $captured[1]['headers'] ), "\nBcc:" ) );

	// Recipient falls back to the admin e-mail.
	update_option( 'eru_wib_settings', Eru_WIB_Settings::sanitize( array( 'enabled' => 1, 'min_qty' => 10 ) ) );
	$captured = array();
	Eru_WIB_Form::process( $post() );
	$to = $captured[0]['to'] ?? '';
	$check( 'empty recipient -> admin e-mail', get_option( 'admin_email' ) === ( is_array( $to ) ? $to[0] : $to ) );

	// Category filter.
	$term = wp_insert_term( 'WIB Demo Kategori ' . wp_rand( 1000, 9999 ), 'product_cat' );
	update_option( 'eru_wib_settings', Eru_WIB_Settings::sanitize( array( 'enabled' => 1, 'category_ids' => array( $term['term_id'] ) ) ) );
	$check( 'category set, product outside -> no box', ! Eru_WIB_Form::applies_to( $pid ) );
	wp_set_object_terms( $pid, (int) $term['term_id'], 'product_cat' );
	$check( 'product inside the category -> box', Eru_WIB_Form::applies_to( $pid ) );
	wp_delete_term( (int) $term['term_id'], 'product_cat' );
} finally {
	remove_filter( 'pre_wp_mail', $capture, 10 );
	remove_filter( 'eru_wib_rate_limit', $no_wait );
	unset( $GLOBALS['product'] );
	if ( $pid ) {
		wp_delete_post( $pid, true );
	}
	null === $old_opt ? delete_option( 'eru_wib_settings' ) : update_option( 'eru_wib_settings', $old_opt, false );
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
