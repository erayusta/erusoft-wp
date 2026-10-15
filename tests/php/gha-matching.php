<?php
/**
 * Bank Transfer Auto-Approval for Garanti BBVA — matching, ledger and approval: reference boundaries,
 * prefix template, tolerance edges, ambiguity, ledger reuse after a rollback, split payments, debits,
 * dry-run, watched gateway, currency, status changed under the lock, masked approval note, e-mail.
 * Every HTTP call is answered from invented bodies; anything else exits 1.
 *
 *   wp plugin activate bank-transfer-auto-approval-for-garanti-bbva
 *   wp eval-file tests/php/gha-matching.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

foreach ( array( 'Eru_GHA_Settings', 'Eru_GHA_Matcher', 'Eru_GHA_Approver', 'Eru_GHA_Ledger', 'Eru_GHA_Log' ) as $eru_gha_class ) {
	if ( ! class_exists( $eru_gha_class ) ) {
		echo "FAIL: {$eru_gha_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}

/** Invented TR IBAN with a valid checksum (bank code and account are made up). */
$iban = function ( $bban ) {
	$num = '';
	foreach ( str_split( $bban . 'TR00' ) as $c ) {
		$num .= ctype_alpha( $c ) ? (string) ( ord( $c ) - 55 ) : $c;
	}
	$mod = 0;
	foreach ( str_split( $num ) as $d ) {
		$mod = ( $mod * 10 + (int) $d ) % 97;
	}
	return 'TR' . str_pad( (string) ( 98 - $mod ), 2, '0', STR_PAD_LEFT ) . $bban;
};
$payer_iban = $iban( '000990000000000000004321' );

// ---------------------------------------------------------------------------------------------
// Pure matcher.
// ---------------------------------------------------------------------------------------------
$rx  = Eru_GHA_Matcher::compile( Eru_GHA_Settings::DEFAULT_PATTERN, '' );
$has = fn( $text, $num, $c = null ) => in_array( $num, Eru_GHA_Matcher::references( $text, $c ?? $rx ), true );
$check( '(b) default pattern compiles with one capture group', '' === $rx['error'] );
$check( '(b) "SIPARIS 123 ODEME" references order 123', $has( 'SIPARIS 123 ODEME', '123' ) );
$check( '(b) "1234" does not reference 123', ! $has( 'SIPARIS 1234', '123' ) );
$check( '(b) "SIP-1235" does not reference 123', ! $has( 'SIP-1235 ODEME', '123' ) );
$check( '(b) "No:123." and "#123" reference 123', $has( 'No:123.', '123' ) && $has( 'ODEME #123', '123' ) );
$tpl = Eru_GHA_Matcher::compile( Eru_GHA_Settings::DEFAULT_PATTERN, 'SIP-{number}' );
$check( '(b) template SIP-{number} matches "sip-123" case-insensitively', '' === $tpl['error'] && $has( 'havale sip-123 demo', '123', $tpl ) );
$check( '(b) template SIP-{number}: bare "123" and "SIP-1235" do not match', ! $has( 'SIPARIS 123', '123', $tpl ) && ! $has( 'SIP-1235', '123', $tpl ) );
$check( '(b) a pattern without a capture group is rejected', '' !== Eru_GHA_Matcher::compile( '\d+', '' )['error'] );
$check( '(b) an invalid pattern is rejected', '' !== Eru_GHA_Matcher::compile( '(\d+', '' )['error'] );

$check( '(c) 1.000,00 vs credit 999,995 within 0.01 → match', Eru_GHA_Matcher::amount_ok( 1000.00, 999.995, 0.01 ) );
$check( '(c) 1.000,00 vs credit 999,99 within 0.01 → match (float edge)', Eru_GHA_Matcher::amount_ok( 1000.00, 999.99, 0.01 ) );
$check( '(c) 1.000,00 vs credit 999,98 within 0.01 → no match', ! Eru_GHA_Matcher::amount_ok( 1000.00, 999.98, 0.01 ) );
$check( '(c) tolerance 0 → exact only', Eru_GHA_Matcher::amount_ok( 1000.00, 1000.0, 0 ) && ! Eru_GHA_Matcher::amount_ok( 1000.00, 999.99, 0 ) );
$check( '(c) amount strings in Turkish and dotted format parse', 1000.0 === Eru_GHA_Matcher::parse_amount( '1.000,00' ) && 999.995 === Eru_GHA_Matcher::parse_amount( '999.995' ) && 1234.5 === Eru_GHA_Matcher::parse_amount( 1234.5 ) );

$now  = time();
$iso  = fn( $ts, $us ) => ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( 'Europe/Istanbul' ) )->format( 'Y-m-d\TH:i:s' ) . sprintf( '.%06d', $us );
$raw  = function ( $us, $amount, $text, $ci = 'A', $extra = array() ) use ( $iso, $now ) {
	return array_merge(
		array(
			'transactionInstanceId'   => $iso( $now - 600, $us ),
			'txnCreditDebitIndicator' => $ci,
			'amount'                  => $amount,
			'explanation'             => $text,
			'activityDate'            => gmdate( 'Y-m-d', $now ),
		),
		$extra
	);
};
$norm  = fn( array $list ) => array_map( array( 'Eru_GHA_Matcher', 'normalize' ), $list );
$snd   = fn( $name, $ib ) => array( 'enrichmentInformation' => array( array( 'enrichmentCode' => 'EFT', 'enrichmentValue' => array( 'remark' => '', 'senderName' => $name, 'senderIBANEFT' => $ib ) ) ) );
$sa    = $snd( 'Demo Müşteri', $payer_iban );
$sb    = $snd( 'Demo Alıcı', $iban( '000990000000000000008765' ) );
$o     = fn( $id, $num, $total, $created = null, $cur = 'TRY' ) => array( 'id' => $id, 'number' => $num, 'total' => $total, 'currency' => $cur, 'created' => $created ?? $now - 3600 );
$opts  = fn( $split = false, $tol = 0.01 ) => array( 'regex' => $rx, 'tolerance' => $tol, 'split' => $split, 'currency' => 'TRY' );

$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0 ), $o( 2, '456', 250.0 ) ), $norm( array( $raw( 1, 250.0, 'SIPARIS 123 456' ) ) ), array(), $opts() );
$check( '(d) pure: one transfer naming two orders with the same amount → no approval, both flagged', array() === $m['approve'] && isset( $m['review'][1], $m['review'][2] ) );
$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0 ) ), $norm( array( $raw( 1, 250.0, 'SIPARIS 123' ), $raw( 2, 250.0, 'SIP 123' ) ) ), array(), $opts() );
$check( '(d) pure: one order matched by two transfers → no approval, flagged', array() === $m['approve'] && isset( $m['review'][1] ) );
$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0 ) ), $norm( array( $raw( 1, 250.0, 'SIPARIS 123' ) ) ), array( $iso( $now - 600, 1 ) ), $opts() );
$check( '(d) pure: a used transaction is never offered again', array() === $m['approve'] );
$m = Eru_GHA_Matcher::match( array( $o( 1, '789', 1000.0 ) ), $norm( array( $raw( 1, 600.0, '789' ), $raw( 2, 400.0, '789' ) ) ), array(), $opts( false ) );
$check( '(e) pure: split off, 600 + 400 for 1.000 → no match', array() === $m['approve'] && isset( $m['mismatch'][1] ) );
$m = Eru_GHA_Matcher::match( array( $o( 1, '789', 1000.0 ) ), $norm( array( $raw( 1, 600.0, '789', 'A', $sa ), $raw( 2, 400.0, '789', 'A', $sa ) ) ), array(), $opts( true ) );
$check( '(e) pure: split on → match with both transactions', isset( $m['approve'][1] ) && 2 === count( $m['approve'][1] ) );
$m = Eru_GHA_Matcher::match( array( $o( 1, '789', 1000.0 ) ), $norm( array( $raw( 1, 600.0, '789' ), $raw( 2, 300.0, '789' ) ) ), array(), $opts( true ) );
$check( '(e) pure: split on, 600 + 300 for 1.000 → no match', array() === $m['approve'] );
$m = Eru_GHA_Matcher::match( array( $o( 1, '789', 1000.0 ) ), $norm( array( $raw( 1, 600.0, '789', 'A', $sa ), $raw( 2, 400.0, '789', 'A', $sb ) ) ), array(), $opts( true ) );
$check( 'fix1 (5) pure: split on, two different senders are not combined → review', array() === $m['approve'] && 'mixed_senders' === ( $m['review'][1]['reason'] ?? '' ) );
$m = Eru_GHA_Matcher::match( array( $o( 1, '789', 1000.0 ) ), $norm( array( $raw( 1, 600.0, '789' ), $raw( 2, 400.0, '789' ) ) ), array(), $opts( true ) );
$check( 'fix1 (5) pure: split on, unknown senders are not combined → review', array() === $m['approve'] && isset( $m['review'][1] ) );
$check( 'fix1 (5) split payments are off by default', 'no' === Eru_GHA_Settings::defaults()['allow_split'] );
$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0 ) ), $norm( array( $raw( 1, -250.0, 'SIPARIS 123' ) ) ), array(), $opts() );
$check( 'fix1 (4) pure: a credit with a negative amount is ignored', array() === $m['approve'] && array() === $m['review'] && array() === $m['mismatch'] );
$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0 ) ), $norm( array( $raw( 1, 0, 'SIPARIS 123' ) ) ), array(), $opts() );
$check( 'fix1 (4) pure: a zero credit is ignored', array() === $m['approve'] && array() === $m['mismatch'] );
$check( 'fix1 (4) normalize keeps the sign (no abs)', -250.0 === Eru_GHA_Matcher::normalize( $raw( 1, -250.0, 'x' ) )['amount'] );
$check( 'fix1 (6) payer name in the description is reduced to initials', 'EFT D. M. SIP 123' === Eru_GHA_Log::strip_name( 'EFT DEMO MUSTERI SIP 123', 'Demo Müşteri' ) && 'EFT D. M. SIP' === Eru_GHA_Log::strip_name( 'EFT Demo Müşteri SIP', 'Demo Müşteri' ) );
$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0 ) ), $norm( array( $raw( 1, 250.0, 'SIPARIS 123', 'B' ) ) ), array(), $opts() );
$check( '(f) pure: a debit with the reference and amount is ignored', array() === $m['approve'] && array() === $m['review'] && array() === $m['mismatch'] );
$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0, $now + 3 * HOUR_IN_SECONDS ) ), $norm( array( $raw( 1, 250.0, 'SIPARIS 123' ) ) ), array(), $opts() );
$check( 'pure: an order created more than 1 h after the transfer is not a candidate', array() === $m['approve'] );
$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0, null, 'USD' ) ), $norm( array( $raw( 1, 250.0, 'SIPARIS 123' ) ) ), array(), $opts() );
$check( 'pure: currency must match (USD order vs TRY account)', array() === $m['approve'] );
$m = Eru_GHA_Matcher::match( array( $o( 1, '123', 250.0 ) ), $norm( array( $raw( 1, 250.0, 'SIPARIS 123', 'A', array( 'currencyCode' => 'EUR' ) ) ) ), array(), $opts() );
$check( 'pure: a transaction in another currency is ignored', array() === $m['approve'] );
$m = Eru_GHA_Matcher::match(
	array( $o( 1, '123', 250.0 ) ),
	$norm( array( $raw( 1, 250.0, 'HAVALE', 'A', array( 'enrichmentInformation' => array( array( 'enrichmentCode' => 'EFT', 'enrichmentValue' => array( 'remark' => 'SIPARIS 123', 'senderName' => 'Demo Müşteri' ) ) ) ) ) ) ),
	array(),
	$opts()
);
$check( 'pure: reference found in the EFT remark', isset( $m['approve'][1] ) );

// ---------------------------------------------------------------------------------------------
// Integration: real orders, mocked bank, ledger, notes, mail.
// ---------------------------------------------------------------------------------------------
$bank = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$bank ) {
		if ( wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) && false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_gha_test', 'loopback cron blocked in tests' ); // Not an outbound call.
		}
		$resp = fn( $code, $body ) => array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 'apis.garantibbva.com.tr' === wp_parse_url( $url, PHP_URL_HOST ) ) {
			if ( '/auth/oauth/v2/token' === $path ) {
				$tk = 'demo-' . 'tok-' . wp_rand( 1000, 9999 );
				return $resp( 200, array( 'access_token' => $tk, 'expires_in' => 3600 ) );
			}
			if ( '/balancesandmovements/accountinformation/transaction/v1/gettransactions' === $path ) {
				$pi = (int) ( json_decode( (string) $args['body'], true )['pageIndex'] ?? 1 );
				return $resp( 200, array( 'transactions' => 1 === $pi ? $bank : array() ) );
			}
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

$mails = array();
add_filter(
	'pre_wp_mail',
	function ( $r, $atts ) use ( &$mails ) {
		// Only this plugin's mails (WooCommerce's own order e-mails are ignored).
		if ( false !== strpos( (string) $atts['subject'], 'Havale' ) ) {
			$mails[] = $atts;
		}
		return true;
	},
	10,
	2
);

global $wpdb;
$log_t    = Eru_GHA_Log::table();
$first_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$log_t}" ); // phpcs:ignore
$old      = get_option( Eru_GHA_Settings::OPTION, null );
$orders   = array();
$numbers  = array();
add_filter(
	'woocommerce_order_number',
	function ( $n, $order ) use ( &$numbers ) {
		return $numbers[ (int) $order->get_id() ] ?? $n;
	},
	99,
	2
);

$make = function ( $num, $total, $method = 'bacs', $status = 'on-hold' ) use ( &$orders, &$numbers, $now ) {
	$order = wc_create_order();
	$order->set_payment_method( $method );
	$order->set_currency( 'TRY' );
	$order->set_billing_first_name( 'Demo' );
	$order->set_billing_last_name( 'Müşteri' );
	$order->set_billing_email( 'ornek@example.com' );
	$order->set_total( $total );
	$order->set_date_created( $now - 2 * HOUR_IN_SECONDS );
	$order->set_status( $status );
	$order->update_meta_data( '_eru_gha_test', 1 );
	$order->save();
	$orders[]                       = $order->get_id();
	$numbers[ $order->get_id() ]    = (string) $num;
	return $order->get_id();
};
$status = function ( $id ) {
	return wc_get_order( $id )->get_status();
};
$log_rows = function () use ( $wpdb, $log_t, &$first_id ) {
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$log_t} WHERE id > %d ORDER BY id", $first_id ), ARRAY_A ); // phpcs:ignore
};
$reset = function () use ( &$orders, &$numbers, &$mails, &$bank, $wpdb ) {
	foreach ( $orders as $id ) {
		Eru_GHA_Ledger::delete_for_test( $id );
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	$orders  = array();
	$numbers = array();
	$mails   = array();
	$bank    = array();
	delete_transient( Eru_GHA_Client::CACHE );
	$wpdb->delete( $wpdb->options, array( 'option_name' => Eru_GHA_Lock::option( 'run' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_delete( Eru_GHA_Lock::option( 'run' ), 'options' );
};
$settings = function ( array $extra = array() ) use ( $iban ) {
	update_option( Eru_GHA_Settings::OPTION, array(), false );
	Eru_GHA_Settings::save_for_test(
		array_merge(
			array(
				'enabled'        => 'yes',
				'dry_run'        => 'no',
				'client_id'      => 'demo-client-id',
				'client_secret'  => 'demo-' . 'client-secret-0000',
				'consent_id'     => 'DEMO-CONSENT',
				'account_number' => '0000000',
				'unit_number'    => '000',
				'iban'           => $iban( '000620000000000000000001' ),
				'tolerance'      => '0.01',
				'allow_split'    => 'no',
				'notify_emails'  => 'ornek@example.com',
			),
			$extra
		)
	);
};

// Runs on success, on failure (exit inside $check skips finally) and only once.
$finished = false;
$finish   = function () use ( &$finished, &$cleanup, &$reset, &$old, $wpdb, $log_t, &$first_id ) {
	if ( $finished ) {
		return;
	}
	$finished = true;
	$reset();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_t} WHERE id > %d", $first_id ) ); // phpcs:ignore
	if ( null === $old ) {
		delete_option( Eru_GHA_Settings::OPTION );
	} else {
		update_option( Eru_GHA_Settings::OPTION, $old, false );
	}
	delete_option( 'eru_gha_last_run' );
	as_unschedule_all_actions( 'eru_gha_daily', array(), 'eru-gha' );
	as_unschedule_all_actions( 'eru_gha_check', array(), 'eru-gha' );
	if ( null !== $old ) {
		Eru_GHA_Cron::sync();
	}
};
register_shutdown_function( $finish );
// Leftovers of an aborted earlier run.
foreach ( wc_get_orders( array( 'limit' => 100, 'status' => array_keys( wc_get_order_statuses() ), 'meta_query' => array( array( 'key' => '_eru_gha_test', 'value' => '1' ) ) ) ) as $eru_gha_left ) { // phpcs:ignore WordPress.DB.SlowDBQuery
	Eru_GHA_Ledger::delete_for_test( $eru_gha_left->get_id() );
	$eru_gha_left->delete( true );
}

try {
	$reset();
	$settings();

	// (d) one transfer names 123 and 456, same amount for both → none approved.
	$a    = $make( '123', 250 );
	$b    = $make( '456', 250 );
	$bank = array( $raw( 11, 250.0, 'SIPARIS 123 456' ) );
	$sum  = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( '(d) ambiguous transfer → neither order approved', 'on-hold' === $status( $a ) && 'on-hold' === $status( $b ) && array() === $sum['approved'] && array() === Eru_GHA_Ledger::used_ids( array( $raw( 11, 0, '' )['transactionInstanceId'] ) ) );
	$check( '(d) both orders flagged for review (meta + log row)', 'ambiguous_txn' === wc_get_order( $a )->get_meta( '_eru_gha_review' ) && 'ambiguous_txn' === wc_get_order( $b )->get_meta( '_eru_gha_review' ) && 2 <= count( array_filter( $log_rows(), fn( $r ) => 'review' === $r['type'] ) ) );
	$check( '(d) review sends one admin e-mail', 1 === count( $mails ) );
	$mails = array();
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( '(d) a second run on the same day adds no duplicate review rows and no e-mail', 2 === count( array_filter( $log_rows(), fn( $r ) => 'review' === $r['type'] ) ) && array() === $mails );

	// (d) a transfer for 123 only → approved; rollback → not re-approved with the same transaction.
	$tid  = $raw( 12, 0, '' )['transactionInstanceId'];
	$bank = array( $raw( 12, 250.0, 'SIPARIS 123 ODEME', 'A', array( 'enrichmentInformation' => array( array( 'enrichmentCode' => 'EFT', 'enrichmentValue' => array( 'remark' => 'SIPARIS 123 ' . $payer_iban, 'senderName' => 'Demo Müşteri', 'senderIBANEFT' => $payer_iban ) ) ) ) ) );
	$sum  = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( '(d) the single transfer approves order 123 → processing', 'processing' === $status( $a ) && isset( $sum['approved'][ $a ] ) && 'on-hold' === $status( $b ) );
	$check( '(d) ledger row written for the transaction with the order id', array( $tid ) === Eru_GHA_Ledger::used_ids( array( $tid ) ) && $a === Eru_GHA_Ledger::order_for( $tid ) );
	$check( '(d) order meta lists the transaction id and the review flag is cleared', array( $tid ) === wc_get_order( $a )->get_meta( '_eru_gha_txn_ids' ) && '' === wc_get_order( $a )->get_meta( '_eru_gha_review' ) );
	$check( 'approval e-mail sent to the notify list', 1 === count( $mails ) && false !== strpos( wp_json_encode( $mails[0]['to'] ), 'ornek@example.com' ) && false === strpos( (string) $mails[0]['message'], $payer_iban ) );

	// (j) approval note: no full IBAN, last 4 shown, payer initials only.
	$notes = implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $a ) ), 'content' ) );
	$check( '(j) approval note contains no full IBAN, keeps the last 4 digits, payer as initials', false === strpos( $notes, $payer_iban ) && false !== strpos( $notes, '4321' ) && false === strpos( $notes, 'Demo Müşteri' ) && false !== strpos( $notes, 'D. M.' ) );
	$all_log = wp_json_encode( $log_rows() );
	$check( '(j) the log holds no full IBAN either', false === strpos( $all_log, $payer_iban ) );

	$o = wc_get_order( $a );
	$o->set_status( 'on-hold' );
	$o->save();
	$sum = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( '(d) after a rollback to on-hold the used transaction does not approve it again', 'on-hold' === $status( $a ) && array() === $sum['approved'] );
	$check( '(d) the ledger row survives the rollback', $a === Eru_GHA_Ledger::order_for( $tid ) );
	$check( 'ledger refuses a second claim of the same transaction', false === Eru_GHA_Ledger::claim( array( $tid => 250.0 ), $b ) );

	// Release with a note → the transaction may be matched again.
	$rel = Eru_GHA_Ledger::release( $tid, 'Demo inceleme notu', 1 );
	$check( 'manual release requires a note and frees the transaction', ! is_wp_error( $rel ) && array() === Eru_GHA_Ledger::used_ids( array( $tid ) ) && is_wp_error( Eru_GHA_Ledger::release( $tid, '', 1 ) ) );
	$sum = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( 'after a release the transaction approves the order again (one active ledger row)', 'processing' === $status( $a ) && array( $tid ) === Eru_GHA_Ledger::used_ids( array( $tid ) ) );
	$reset();

	// (e) split payments.
	$c    = $make( '789', 1000 );
	$bank = array( $raw( 21, 600.0, 'SIPARIS 789', 'A', $sa ), $raw( 22, 400.0, 'SIPARIS 789 KALAN', 'A', $sa ) );
	$t21  = $raw( 21, 0, '' )['transactionInstanceId'];
	$t22  = $raw( 22, 0, '' )['transactionInstanceId'];
	$sum  = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( '(e) split off: 600 + 400 for a 1.000 order → not approved, no ledger row', 'on-hold' === $status( $c ) && array() === Eru_GHA_Ledger::used_ids( array( $t21, $t22 ) ) );
	$check( '(e) split off: mismatch logged once', 1 === count( array_filter( $log_rows(), fn( $r ) => 'mismatch' === $r['type'] && (int) $r['order_id'] === $c ) ) );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( 'mismatch logging is de-duplicated per (order, transaction) per day', 1 === count( array_filter( $log_rows(), fn( $r ) => 'mismatch' === $r['type'] && (int) $r['order_id'] === $c ) ) );
	$settings( array( 'allow_split' => 'yes' ) );
	$sum = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$used = Eru_GHA_Ledger::used_ids( array( $t21, $t22 ) );
	sort( $used );
	$exp = array( $t21, $t22 );
	sort( $exp );
	$check( '(e) split on: approved, both transaction ids in the ledger', 'processing' === $status( $c ) && $exp === $used );
	$reset();
	$settings();

	// Fix round 1 (5): split on, different senders → review, no ledger row.
	$settings( array( 'allow_split' => 'yes' ) );
	$c2   = $make( '790', 1000 );
	$bank = array( $raw( 23, 600.0, 'SIPARIS 790', 'A', $sa ), $raw( 24, 400.0, 'SIPARIS 790', 'A', $sb ) );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( 'fix1 (5) split on, two senders → not approved, needs review, no ledger row', 'on-hold' === $status( $c2 ) && 'mixed_senders' === wc_get_order( $c2 )->get_meta( '_eru_gha_review' ) && array() === Eru_GHA_Ledger::used_ids( array( $raw( 23, 0, '' )['transactionInstanceId'], $raw( 24, 0, '' )['transactionInstanceId'] ) ) );
	$reset();
	$settings();

	// Fix round 1 (1): master switch off → forced dry-run for run(), CLI and manual.
	$settings( array( 'enabled' => 'no', 'dry_run' => 'no' ) );
	$fo     = $make( '555', 120 );
	$bank   = array( $raw( 91, 120.0, 'SIPARIS 555' ) );
	$before = count( $log_rows() );
	$sum    = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( 'fix1 (1) switch off: run() is forced to dry-run (no status, no ledger, one would_approve row)', 'on-hold' === $status( $fo ) && ! empty( $sum['dry_run'] ) && ! empty( $sum['forced_dry'] ) && array() === Eru_GHA_Ledger::used_ids( array( $raw( 91, 0, '' )['transactionInstanceId'] ) ) && 1 === count( $log_rows() ) - $before && 'would_approve' === array_slice( $log_rows(), -1 )[0]['type'] );
	$out = WP_CLI::runcommand( 'eru-gha check', array( 'return' => 'all', 'launch' => false, 'exit_error' => false ) );
	$check( 'fix1 (1) switch off: wp eru-gha check reports a forced dry-run and changes nothing', false !== strpos( (string) $out->stdout, 'dry_run=yes' ) && 'on-hold' === $status( $fo ) && array() === Eru_GHA_Ledger::used_ids( array( $raw( 91, 0, '' )['transactionInstanceId'] ) ) );
	Eru_GHA_Approver::fetch_only();
	$mr = Eru_GHA_Approver::manual( $raw( 91, 0, '' )['transactionInstanceId'], $fo, false );
	$check( 'fix1 (1) switch off: manual approval is refused', is_wp_error( $mr ) && 'eru_gha_off' === $mr->get_error_code() && 'on-hold' === $status( $fo ) );
	$reset();
	$settings();

	// Fix round 1 (2): one order, at most one active transaction set.
	$oo   = $make( '556', 200 );
	$bank = array( $raw( 92, 200.0, 'SIPARIS 556' ) );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$x = wc_get_order( $oo );
	$x->set_status( 'on-hold' );
	$x->save();
	$bank = array( $raw( 93, 200.0, 'SIPARIS 556 TEKRAR' ) );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( 'fix1 (2) an order with an active ledger row is not approved again by another transfer', 'on-hold' === $status( $oo ) && array() === Eru_GHA_Ledger::used_ids( array( $raw( 93, 0, '' )['transactionInstanceId'] ) ) );
	$check( 'fix1 (2) ledger claim refuses a second transaction for the same order', false === Eru_GHA_Ledger::claim( array( 'demo-other-txn' => 200.0 ), $oo ) );
	$skip_rows = array_filter( $log_rows(), fn( $r ) => 'skipped' === $r['type'] && (int) $r['order_id'] === $oo );
	$check( 'fix2 (2) the refusal is logged with the "already approved, release first" message', 1 === count( $skip_rows ) && false !== strpos( array_values( $skip_rows )[0]['message'], 'Bu sipariş daha önce bir havale işlemiyle onaylanmış' ) );
	$reset();

	// Fix round 2 (1): a per-order MySQL lock held by another connection makes the approval fail closed.
	$lo   = $make( '558', 140 );
	$bank = array( $raw( 95, 140.0, 'SIPARIS 558' ) );
	list( $h, $p, $sock ) = $wpdb->parse_db_host( DB_HOST );
	$other = mysqli_connect( $h, DB_USER, DB_PASSWORD, DB_NAME, $p ? (int) $p : 3306, $sock ? $sock : null );
	$lname = 'eru_gha_order_' . get_current_blog_id() . '_' . $lo;
	$got   = $other ? mysqli_query( $other, "SELECT GET_LOCK('" . mysqli_real_escape_string( $other, $lname ) . "', 1)" )->fetch_row()[0] : 0;
	$check( 'fix2 (1) test setup: a second connection holds the per-order lock', '1' === (string) $got );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( 'fix2 (1) held per-order lock → not approved, no ledger row (fails closed)', 'on-hold' === $status( $lo ) && array() === Eru_GHA_Ledger::used_ids( array( $raw( 95, 0, '' )['transactionInstanceId'] ) ) );
	mysqli_query( $other, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string( $other, $lname ) . "')" );
	mysqli_close( $other );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( 'fix2 (1) once the lock is free the order is approved', 'processing' === $status( $lo ) );
	$check( 'fix2 (1) the per-order lock is released after the approval', '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lname ) ) );
	$reset();
	$check( 'fix2 (5) no test-only lock helper in production code', ! method_exists( 'Eru_GHA_Lock', 'force_release_for_test' ) );
	$k = Eru_GHA_Matcher::sender_key( $payer_iban, '' );
	$check( 'fix2 (4) sender key is a salted HMAC, not md5 of the IBAN', '' !== $k && false === strpos( $k, md5( $payer_iban ) ) && false === strpos( $k, md5( strtoupper( $payer_iban ) ) ) );

	// Fix round 1 (6): the log keeps payer initials, not the name in the description.
	$po   = $make( '557', 310 );
	$bank = array( $raw( 94, 310.0, 'EFT DEMO MUSTERI SIPARIS 557', 'A', $sa ) );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$lj = wp_json_encode( $log_rows(), JSON_UNESCAPED_UNICODE );
	$check( 'fix1 (6) approved: log context has no sender name, only initials', 'processing' === $status( $po ) && false === stripos( $lj, 'MUSTERI' ) && false === strpos( $lj, 'Müşteri' ) && false !== strpos( $lj, 'D. M.' ) );
	$reset();

	// (f) debit ignored.
	$d    = $make( '321', 300 );
	$bank = array( $raw( 31, 300.0, 'SIPARIS 321', 'B' ) );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( '(f) a debit transaction never approves', 'on-hold' === $status( $d ) );
	$reset();

	// (g) dry-run: no status change, no ledger row, one log row.
	$settings( array( 'dry_run' => 'yes' ) );
	$g      = $make( '654', 500 );
	$bank   = array( $raw( 41, 500.0, 'SIPARIS 654' ) );
	$before = count( $log_rows() );
	$notes0 = count( wc_get_order_notes( array( 'order_id' => $g ) ) );
	$sum    = Eru_GHA_Approver::run();
	$after  = $log_rows();
	$check( '(g) dry-run: status unchanged, no ledger row, no note, no meta', 'on-hold' === $status( $g ) && array() === Eru_GHA_Ledger::used_ids( array( $raw( 41, 0, '' )['transactionInstanceId'] ) ) && $notes0 === count( wc_get_order_notes( array( 'order_id' => $g ) ) ) && '' === wc_get_order( $g )->get_meta( '_eru_gha_txn_ids' ) );
	$check( '(g) dry-run: exactly one log row ("eşleşirdi")', 1 === count( $after ) - $before && 'would_approve' === end( $after )['type'] && isset( $sum['would'][ $g ] ) );
	$check( '(g) dry-run: no e-mail', array() === $mails );
	$reset();
	$settings();

	// (i) watched gateway bacs only: a cod order with matching amount is ignored.
	$i    = $make( '852', 300, 'cod' );
	$bank = array( $raw( 51, 300.0, 'SIPARIS 852' ) );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( '(i) a cod order with a matching transfer is ignored', 'on-hold' === $status( $i ) && array() === Eru_GHA_Ledger::used_ids( array( $raw( 51, 0, '' )['transactionInstanceId'] ) ) );
	$reset();

	// Pending is watched by default (P1); status changed under the lock → no approval.
	$p    = $make( '963', 150, 'bacs', 'pending' );
	$bank = array( $raw( 61, 150.0, 'SIPARIS 963' ) );
	$hook = function ( $order_id ) use ( $p ) {
		if ( (int) $order_id === $p ) {
			$x = wc_get_order( $p );
			$x->set_status( 'cancelled' );
			$x->save();
		}
	};
	add_action( 'eru_gha_before_approve', $hook );
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	remove_action( 'eru_gha_before_approve', $hook );
	$check( 'status changed meanwhile (re-read under the lock) → not approved, no ledger row', 'cancelled' === $status( $p ) && array() === Eru_GHA_Ledger::used_ids( array( $raw( 61, 0, '' )['transactionInstanceId'] ) ) );
	$x = wc_get_order( $p );
	$x->set_status( 'pending' );
	$x->save();
	Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$check( 'a pending bacs order is watched by default and approved', 'processing' === $status( $p ) );
	$reset();

	// Lock held → run skipped.
	$l    = $make( '741', 90 );
	$bank = array( $raw( 71, 90.0, 'SIPARIS 741' ) );
	Eru_GHA_Lock::acquire( 'run' );
	$sum = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$wpdb->delete( $wpdb->options, array( 'option_name' => Eru_GHA_Lock::option( 'run' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_delete( Eru_GHA_Lock::option( 'run' ), 'options' );
	$check( 'a held run lock skips the run', 'locked' === $sum['status'] && 'on-hold' === $status( $l ) );
	$reset();

	// Manual approval: server-side transaction by id only.
	$mo   = $make( '258', 75 );
	$bank = array( $raw( 81, 75.0, 'ISIMSIZ HAVALE' ) );
	$mt   = $raw( 81, 0, '' )['transactionInstanceId'];
	$check( 'manual approval fails when the transaction is not in the fetched list', is_wp_error( Eru_GHA_Approver::manual( $mt, $mo, false ) ) );
	Eru_GHA_Approver::fetch_only();
	$r = Eru_GHA_Approver::manual( $mt, $mo, false );
	$check( 'manual approval with a fetched transaction id approves and writes the ledger', ! is_wp_error( $r ) && 'processing' === $status( $mo ) && $mo === Eru_GHA_Ledger::order_for( $mt ) );
	$mo2 = $make( '259', 75 );
	$check( 'manual approval refuses an already used transaction', is_wp_error( Eru_GHA_Approver::manual( $mt, $mo2, false ) ) && 'on-hold' === $status( $mo2 ) );
	$reset();
} finally {
	$finish();
}
echo "OK gha-matching\n";
