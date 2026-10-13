<?php
/**
 * Product Status Notifications — stock-out mail, defaults inert, recipient rules.
 *   wp eval-file tests/php/psn-notify.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_PSN' ) ) {
	echo "FAIL: class Eru_PSN missing (plugin not active?)\n";
	exit( 1 );
}

$saved = get_option( Eru_PSN::OPTION, null );
$mails = array();
add_filter(
	'pre_wp_mail',
	function ( $pre, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
$pid = 0;
$tid = 0;

try {
	$term = wp_insert_term( 'PSN Demo Kategori', 'product_cat', array( 'slug' => 'psn-demo-kategori' ) );
	$tid  = (int) $term['term_id'];
	$p    = new WC_Product_Simple();
	$p->set_name( 'Demo Ürün' );
	$p->set_sku( 'PSN-DEMO-1' );
	$p->set_regular_price( '20' );
	$p->set_manage_stock( false );
	$p->set_stock_status( 'instock' );
	$p->set_status( 'publish' );
	$p->save();
	$pid = $p->get_id();

	// Default: no events -> no mail.
	delete_option( Eru_PSN::OPTION );
	$p = wc_get_product( $pid );
	$p->set_stock_status( 'outofstock' );
	$p->save();
	$check( 'defaults: stock-out sends nothing', 0 === count( $mails ) );
	wc_get_product( $pid )->set_stock_status( 'instock' );
	$p = wc_get_product( $pid );
	$p->set_stock_status( 'instock' );
	$p->save();

	// Stock-out event with a configured recipient.
	update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'stock_out' ), 'recipients' => 'ornek@example.com' ) ) );
	$mails = array();
	$p     = wc_get_product( $pid );
	$p->set_stock_status( 'outofstock' );
	$p->save();
	Eru_PSN::flush();
	$check( 'stock out: exactly one mail', 1 === count( $mails ) );
	$check( 'stock out: sent to the configured address', isset( $mails[0] ) && array( 'ornek@example.com' ) === (array) $mails[0]['to'] );
	$check( 'stock out: subject and body name the product', isset( $mails[0] ) && false !== strpos( $mails[0]['subject'], 'Demo Ürün' ) && false !== strpos( $mails[0]['message'], 'PSN-DEMO-1' ) );

	// A second save without change sends nothing.
	$mails = array();
	$p     = wc_get_product( $pid );
	$p->set_name( 'Demo Ürün 2' );
	$p->save();
	Eru_PSN::flush();
	$check( 'unrelated save: no mail', 0 === count( $mails ) );

	// Price event + default recipient = admin e-mail.
	update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'price' ) ) ) );
	$mails = array();
	$p     = wc_get_product( $pid );
	$p->set_regular_price( '25' );
	$p->save();
	Eru_PSN::flush();
	$check( 'price change: one mail to the admin address by default', 1 === count( $mails ) && array( get_option( 'admin_email' ) ) === (array) $mails[0]['to'] );

	// Recipient rule routes by category and exclusion works.
	wp_set_object_terms( $pid, array( $tid ), 'product_cat' );
	update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'price' ), 'rules' => 'product_cat:psn-demo-kategori = kural@example.com' ) ) );
	$check( 'rule: matching product goes to the rule recipients', array( 'kural@example.com' ) === Eru_PSN::recipients_for( $pid ) );
	update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'price' ), 'excluded' => array( $tid ) ) ) );
	$mails = array();
	$p     = wc_get_product( $pid );
	$p->set_regular_price( '30' );
	$p->save();
	Eru_PSN::flush();
	$check( 'excluded category: no mail', 0 === count( $mails ) );

	// Status transition event publish -> draft.
	update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'status' ), 'status_from' => 'publish', 'status_to' => 'draft', 'recipients' => 'ornek@example.com' ) ) );
	$mails = array();
	wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
	Eru_PSN::flush();
	$check( 'status publish -> draft: one mail', 1 === count( $mails ) );
	$check( 'new product (auto-draft -> publish) is not a status event even for any -> any', ( function () use ( &$mails ) {
		update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'status' ), 'status_from' => 'any', 'status_to' => 'any', 'recipients' => 'ornek@example.com' ) ) );
		$mails = array();
		$p     = new WC_Product_Simple();
		$p->set_name( 'Demo Yeni' );
		$p->set_status( 'publish' );
		$p->save();
		Eru_PSN::flush();
		wp_delete_post( $p->get_id(), true );
		return 0 === count( $mails );
	} )() );

	// Digest: five price changes in one request -> exactly one mail listing all five.
	update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'price' ), 'recipients' => 'ornek@example.com' ) ) );
	$many = array();
	for ( $i = 1; $i <= 5; $i++ ) {
		$q = new WC_Product_Simple();
		$q->set_name( 'Demo Toplu ' . $i );
		$q->set_regular_price( '10' );
		$q->set_status( 'publish' );
		$q->save();
		$many[] = $q->get_id();
	}
	Eru_PSN::flush();
	$mails = array();
	foreach ( $many as $id ) {
		$q = wc_get_product( $id );
		$q->set_regular_price( '12' );
		$q->save();
	}
	Eru_PSN::flush();
	$all = 1 === count( $mails );
	for ( $i = 1; $i <= 5 && $all; $i++ ) {
		$all = false !== strpos( $mails[0]['message'], 'Demo Toplu ' . $i );
	}
	$check( 'five price changes in one request: exactly one digest mail with all five', $all );
	$mails = array();
	add_filter( 'eru_psn_max_items', fn() => 2 );
	foreach ( $many as $id ) {
		$q = wc_get_product( $id );
		$q->set_regular_price( '14' );
		$q->save();
	}
	Eru_PSN::flush();
	$check( 'item cap lists 2 and says +3 more', 1 === count( $mails ) && false !== strpos( $mails[0]['message'], '+3' ) );
	remove_all_filters( 'eru_psn_max_items' );

	// Order-driven stock drop through wc_update_product_stock().
	update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'stock_out' ), 'recipients' => 'ornek@example.com' ) ) );
	$s = wc_get_product( $many[0] );
	$s->set_manage_stock( true );
	$s->set_stock_quantity( 1 );
	$s->save();
	Eru_PSN::flush();
	$mails = array();
	wc_update_product_stock( wc_get_product( $many[0] ), 0 );
	Eru_PSN::flush();
	$check( 'stock dropping to 0 via wc_update_product_stock() sends a stock-out mail', 'outofstock' === wc_get_product( $many[0] )->get_stock_status() && 1 === count( $mails ) );

	// Creating a product that is already out of stock is not a stock-out event.
	$mails = array();
	$fresh = new WC_Product_Simple();
	$fresh->set_name( 'PSN Demo Yeni' );
	$fresh->set_regular_price( '10' );
	$fresh->set_stock_status( 'outofstock' );
	$fresh->set_status( 'publish' );
	$many[] = $fresh->save();
	Eru_PSN::flush();
	$check( 'new product created out of stock: no mail', 0 === count( $mails ) );

	// The "before" label is the real previous status (on backorder → out of stock).
	$b = wc_get_product( $many[1] );
	$b->set_stock_status( 'onbackorder' );
	$b->save();
	Eru_PSN::flush();
	$mails = array();
	$b = wc_get_product( $many[1] );
	$b->set_stock_status( 'outofstock' );
	$b->save();
	Eru_PSN::flush();
	$check( 'previous status shown as "Ön sipariş", not "Stokta"', 1 === count( $mails ) && str_contains( $mails[0]['message'], 'Ön sipariş' ) && ! str_contains( $mails[0]['message'], '>Stokta<' ) );

	// After a manual flush the next change is still delivered at shutdown (the hook is re-armed).
	$c = wc_get_product( $many[2] );
	$c->set_stock_status( 'outofstock' );
	$c->save();
	$check( 'queue re-arms the shutdown hook after a flush', false !== has_action( 'shutdown', array( 'Eru_PSN', 'flush' ) ) );
	Eru_PSN::flush();
	foreach ( $many as $id ) {
		wp_delete_post( $id, true );
	}

	// Imports are skipped by default.
	define( 'WP_IMPORTING', true );
	update_option( Eru_PSN::OPTION, Eru_PSN::sanitize_settings( array( 'events' => array( 'price' ), 'recipients' => 'ornek@example.com' ) ) );
	$mails = array();
	$p     = wc_get_product( $pid );
	$p->set_regular_price( '99' );
	$p->save();
	Eru_PSN::flush();
	$check( 'WP_IMPORTING: no mail', 0 === count( $mails ) );
	$check( 'invalid addresses are dropped', array( 'ornek@example.com' ) === Eru_PSN::parse_emails( 'ornek@example.com, nope, <x>' ) );
} finally {
	if ( $pid ) {
		wp_delete_post( $pid, true );
	}
	if ( $tid ) {
		wp_delete_term( $tid, 'product_cat' );
	}
	null === $saved ? delete_option( Eru_PSN::OPTION ) : update_option( Eru_PSN::OPTION, $saved );
}
exit( $fail ? 1 : 0 );
