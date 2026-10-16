<?php
/**
 * Sales Team Commissions — payout-detail encryption, masks, CSV export and the privacy exporter/eraser.
 *   wp --path=$HOME/code/erusoft-wp-demo eval-file tests/php/stc-privacy.php
 *
 * (a) crypto: without ERU_STC_ENCRYPTION_KEY the TCKN/IBAN fields are hidden, a save is refused and stored
 *     ciphertexts read "anahtar yok"; with a key generated in this process (guarded define, never written
 *     anywhere) values round-trip, the stored text is not the plain text and holds no digit run of the IBAN,
 *     IBAN and TCKN checksums are enforced, blank fields keep the stored value
 * (b) masks never show more than the last 4 IBAN digits or the first 2 + last 2 TCKN digits; the payout
 *     CSV is masked by default; CSV cells starting with = + - @ tab CR are neutralised
 * (g) the eraser wipes the agent's payout fields, payout snapshots and display name; commissions and payouts
 *     stay with the agent id only; the exporter never returns a full IBAN or TCKN
 *
 * IBAN/TCKN values are generated at run time (stc_fake_iban / stc_fake_tckn); none is a literal here.
 */

require __DIR__ . '/stc-helpers.php';
stc_require_plugin();
foreach ( array( 'Eru_STC_Crypto', 'Eru_STC_Payout_Details', 'Eru_STC_Account', 'Eru_STC_Reports', 'Eru_STC_CSV', 'Eru_STC_Privacy' ) as $stc_c ) {
	if ( ! class_exists( $stc_c ) ) {
		echo "FAIL: {$stc_c} missing\n";
		exit( 1 );
	}
}

$mails = array();
stc_guards( $mails );
$keep = stc_snapshot();
stc_purge( true );

global $wpdb;
$at = $wpdb->prefix . 'eru_stc_agents';

/** The three payout columns of an agent. */
function stc_p_cols( $agent_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT payout_holder, payout_iban, payout_tckn FROM {$wpdb->prefix}eru_stc_agents WHERE id = %d", $agent_id ), ARRAY_A ); // phpcs:ignore
}

/** Whether any 6-digit run of $digits occurs in $text. */
function stc_p_has_run( $text, $digits, $len = 6 ) {
	$digits = preg_replace( '/\D/', '', $digits );
	for ( $i = 0; $i + $len <= strlen( $digits ); $i++ ) {
		if ( false !== strpos( (string) $text, substr( $digits, $i, $len ) ) ) {
			return true;
		}
	}
	return false;
}

/** Digits in a string. */
function stc_p_digits( $s ) {
	return preg_replace( '/\D/', '', (string) $s );
}

try {
	stc_settings( array( 'payout_details_form' => 'yes' ) );
	$agent = stc_agent( 'privacy' );
	$row   = Eru_STC_Agents::get( $agent );
	wp_set_current_user( (int) $row->user_id );
	$iban = stc_fake_iban();
	$tckn = stc_fake_tckn();

	// ---------------------------------------------------------------- (a) without a key
	if ( defined( 'ERU_STC_ENCRYPTION_KEY' ) ) {
		throw new RuntimeException( 'ERU_STC_ENCRYPTION_KEY is already defined on this site; the no-key checks need a site without it' );
	}
	stc_check( '(a) helpers: generated IBAN and TCKN pass the plugin validators', Eru_STC_Crypto::valid_iban( $iban ) && Eru_STC_Crypto::valid_tckn( $tckn ) );
	stc_check( '(a) no key: crypto unavailable, state "missing"', ! Eru_STC_Crypto::available() && 'missing' === Eru_STC_Crypto::state() );
	stc_check( '(a) no key: encrypt refuses', is_wp_error( Eru_STC_Crypto::encrypt( 'x' ) ) );
	$r = Eru_STC_Payout_Details::save(
		$agent,
		array(
			'holder' => 'Demo Temsilci',
			'iban'   => $iban,
			'tckn'   => $tckn,
		)
	);
	stc_check( '(a) no key: save refused (eru_stc_no_key)', is_wp_error( $r ) && 'eru_stc_no_key' === $r->get_error_code() );
	stc_check( '(a) no key: nothing stored', array( null, null, null ) === array_values( stc_p_cols( $agent ) ) );
	$html = Eru_STC_Account::render( 'details' );
	stc_check( '(a) no key: the details form shows no IBAN/TCKN inputs and explains why', false === strpos( $html, 'name="eru_stc_iban"' ) && false === strpos( $html, 'name="eru_stc_tckn"' ) && false !== strpos( $html, 'eru-stc-nokey' ) );
	$res = Eru_STC_Account::process(
		'details_save',
		array(
			'_wpnonce'       => wp_create_nonce( Eru_STC_Account::nonce_action( 'details_save' ) ),
			'eru_stc_holder' => 'Demo Temsilci',
			'eru_stc_iban'   => $iban,
			'eru_stc_tckn'   => $tckn,
		)
	);
	stc_check( '(a) no key: the My Account controller refuses the save too', false === $res['ok'] && array( null, null, null ) === array_values( stc_p_cols( $agent ) ) );
	$wpdb->update( $at, array( 'payout_iban' => 'v1:' . base64_encode( random_bytes( 60 ) ) ), array( 'id' => $agent ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	$m = Eru_STC_Payout_Details::masked( $agent );
	stc_check( '(a) no key: a stored ciphertext reads "anahtar yok"', 'nokey' === $m['state'] && 'anahtar yok' === $m['iban'] );
	$wpdb->update( $at, array( 'payout_iban' => null ), array( 'id' => $agent ) );

	// Key parsing (pure): only base64 of exactly 32 bytes is a key.
	stc_check( '(a) parse_key: 32 bytes ok; 31 bytes, empty and non-base64 refused', 32 === strlen( (string) Eru_STC_Crypto::parse_key( base64_encode( random_bytes( 32 ) ) ) ) && null === Eru_STC_Crypto::parse_key( base64_encode( random_bytes( 31 ) ) ) && null === Eru_STC_Crypto::parse_key( '' ) && null === Eru_STC_Crypto::parse_key( '%%not base64%%' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

	// ---------------------------------------------------------------- (a) with a key (this process only)
	stc_define_test_key();
	stc_check( '(a) key: crypto available', Eru_STC_Crypto::available() && 'ok' === Eru_STC_Crypto::state() );
	$c1 = Eru_STC_Crypto::encrypt( $iban );
	$c2 = Eru_STC_Crypto::encrypt( $iban );
	stc_check( '(a) key: v1: prefix, round-trip, fresh nonce per value', 0 === strpos( $c1, 'v1:' ) && $iban === Eru_STC_Crypto::decrypt( $c1 ) && $c1 !== $c2 );
	stc_check( '(a) key: a tampered ciphertext does not decrypt', null === Eru_STC_Crypto::decrypt( substr( $c1, 0, -4 ) . 'AAAA' ) && null === Eru_STC_Crypto::decrypt( 'plain' ) );
	$r = Eru_STC_Payout_Details::save(
		$agent,
		array(
			'holder' => 'Demo Temsilci',
			'iban'   => strtolower( chunk_split( $iban, 4, ' ' ) ),
			'tckn'   => $tckn,
		)
	);
	stc_check( '(a) key: save accepted (IBAN with spaces and lower case is normalised)', true === $r );
	$cols = stc_p_cols( $agent );
	stc_check( '(a) stored values are v1: ciphertexts, not the plain text', 0 === strpos( $cols['payout_iban'], 'v1:' ) && 0 === strpos( $cols['payout_tckn'], 'v1:' ) && 0 === strpos( $cols['payout_holder'], 'v1:' ) && false === strpos( implode( '|', $cols ), $iban ) && false === strpos( implode( '|', $cols ), $tckn ) && false === strpos( implode( '|', $cols ), 'Demo Temsilci' ) );
	stc_check( '(a) no 6-digit run of the IBAN or TCKN in any stored column', ! stc_p_has_run( implode( '|', $cols ), substr( $iban, 2 ) ) && ! stc_p_has_run( implode( '|', $cols ), $tckn ) );
	$note = $mails ? end( $mails ) : null;
	$body = $note ? (string) $note['subject'] . ' ' . (string) $note['message'] : '';
	stc_check( '(a) M4: the agent is mailed "Ödeme bilgileriniz güncellendi" with masked values only', $note && get_userdata( $row->user_id )->user_email === ( is_array( $note['to'] ) ? $note['to'][0] : $note['to'] ) && false !== strpos( $body, 'Ödeme bilgileriniz güncellendi' ) && false !== strpos( $body, Eru_STC_Crypto::mask_iban( $iban ) ) && false === strpos( $body, $iban ) && false === strpos( $body, $tckn ) && ! stc_p_has_run( $body, substr( $iban, 2 ) ) && ! stc_p_has_run( $body, $tckn ) );
	$mail_count = count( $mails );
	stc_check( '(a) decrypts back to the normalised values', $iban === Eru_STC_Crypto::decrypt( $cols['payout_iban'] ) && $tckn === Eru_STC_Crypto::decrypt( $cols['payout_tckn'] ) && 'Demo Temsilci' === Eru_STC_Crypto::decrypt( $cols['payout_holder'] ) );

	$bad_iban = substr( $iban, 0, 25 ) . ( ( (int) substr( $iban, -1 ) + 1 ) % 10 );
	$bad_tckn = substr( $tckn, 0, 10 ) . ( ( (int) substr( $tckn, -1 ) + 1 ) % 10 );
	$r1       = Eru_STC_Payout_Details::save( $agent, array( 'iban' => $bad_iban ) );
	$r2       = Eru_STC_Payout_Details::save( $agent, array( 'tckn' => $bad_tckn ) );
	$r3       = Eru_STC_Payout_Details::save( $agent, array( 'iban' => 'DE' . substr( $iban, 2 ) ) );
	stc_check( '(a) IBAN checksum, TR-only and TCKN checksum enforced; refused saves change nothing', is_wp_error( $r1 ) && 'eru_stc_iban' === $r1->get_error_code() && is_wp_error( $r2 ) && 'eru_stc_tckn' === $r2->get_error_code() && is_wp_error( $r3 ) && $cols === stc_p_cols( $agent ) );
	stc_check( '(a) TCKN rules: 11 digits, first not 0', ! Eru_STC_Crypto::valid_tckn( '0' . substr( $tckn, 1 ) ) && ! Eru_STC_Crypto::valid_tckn( substr( $tckn, 0, 10 ) ) && ! Eru_STC_Crypto::valid_tckn( 'abcdefghijk' ) );
	$r = Eru_STC_Payout_Details::save(
		$agent,
		array(
			'holder' => '',
			'iban'   => '',
			'tckn'   => '',
		)
	);
	stc_check( '(a) blank fields keep the stored values (and send no change mail)', true === $r && $cols === stc_p_cols( $agent ) && count( $mails ) === $mail_count );

	$html = Eru_STC_Account::render( 'details' );
	$m    = Eru_STC_Payout_Details::masked( $agent );
	stc_check( '(a) details form (key set): inputs present, empty, masked values shown, full values never in the HTML', false !== strpos( $html, 'name="eru_stc_iban"' ) && false !== strpos( $html, esc_html( $m['iban'] ) ) && false === strpos( $html, $iban ) && false === strpos( $html, $tckn ) && ! stc_p_has_run( $html, substr( $iban, 2 ) ) && ! stc_p_has_run( $html, $tckn ) );
	stc_check( '(a) masked(): state ok, complete', 'ok' === $m['state'] && $m['complete'] );

	// ---------------------------------------------------------------- (b) masks
	$ok = true;
	for ( $i = 0; $i < 60; $i++ ) {
		$v  = stc_fake_iban();
		$mi = Eru_STC_Crypto::mask_iban( $v );
		$t  = stc_fake_tckn();
		$mt = Eru_STC_Crypto::mask_tckn( $t );
		$ok = $ok && 1 === preg_match( '/^TR\*\* \*{4} \*{4} \*{4} \*{4} \*\*\d\d \d\d$/', $mi ) && substr( $v, -4 ) === stc_p_digits( $mi ) && 1 === preg_match( '/^\d\d\*{7}\d\d$/', $mt ) && substr( $t, 0, 2 ) . substr( $t, -2 ) === stc_p_digits( $mt );
	}
	stc_check( '(b) 60 random values: IBAN mask shows only the last 4 digits, TCKN mask only first 2 + last 2', $ok );
	stc_check( '(b) masks of short or empty values reveal nothing', '' === stc_p_digits( Eru_STC_Crypto::mask_iban( 'TR12' ) ) && '' === stc_p_digits( Eru_STC_Crypto::mask_tckn( '123' ) ) && '' === Eru_STC_Crypto::mask_iban( '' ) );
	stc_check( '(b) holder name masked', 'D*** T*******' === Eru_STC_Crypto::mask_name( 'Demo Temsilci' ) );

	// Payout CSV: masked by default, full IBAN only on request; TCKN always masked.
	Eru_STC_Commissions::insert(
		array(
			'order_id'     => 990000001,
			'item_id'      => 1,
			'agent_id'     => $agent,
			'level'        => 1,
			'amount_minor' => 4250,
			'basis_minor'  => 42500,
			'currency'     => 'TRY',
			'status'       => 'approved',
			'earned_at'    => current_time( 'mysql', true ),
			'approved_at'  => current_time( 'mysql', true ),
			'note'         => 'stc-test ' . STC_RUN,
		)
	);
	$payout = Eru_STC_Payouts::create_request( $agent );
	stc_check( '(b) payout request snapshots the encrypted details', is_int( $payout ) && $payout > 0 && 0 === strpos( (string) Eru_STC_Payouts::get( $payout )->details, '{' ) && false === strpos( (string) Eru_STC_Payouts::get( $payout )->details, $iban ) );
	$csv = Eru_STC_Reports::payouts_csv( array( 'agent_id' => $agent ), false );
	stc_check( '(b) payout CSV masked by default (no full IBAN, no TCKN, no 6-digit run)', false !== strpos( $csv, Eru_STC_Crypto::mask_iban( $iban ) ) && false === strpos( $csv, $iban ) && ! stc_p_has_run( $csv, substr( $iban, 2 ) ) && ! stc_p_has_run( $csv, $tckn ) && false !== strpos( $csv, '42.50' ) );
	$full = Eru_STC_Reports::payouts_csv( array( 'agent_id' => $agent ), true );
	stc_check( '(b) payout CSV with "tam IBAN": full IBAN and holder, TCKN still masked', false !== strpos( $full, $iban ) && false !== strpos( $full, 'Demo Temsilci' ) && false === strpos( $full, $tckn ) );
	stc_check(
		'(b) CSV cells starting with = + - @ tab CR are neutralised; plain numbers stay numbers',
		"'=HYPERLINK(\"x\")" === Eru_STC_CSV::cell( '=HYPERLINK("x")' ) && "'+1+2" === Eru_STC_CSV::cell( '+1+2' ) && "'-2+3" === Eru_STC_CSV::cell( '-2+3' ) && "'@SUM(A1)" === Eru_STC_CSV::cell( '@SUM(A1)' ) && "'\tx" === Eru_STC_CSV::cell( "\tx" ) && "'\rx" === Eru_STC_CSV::cell( "\rx" ) && '-4.00' === Eru_STC_CSV::cell( '-4.00' ) && 'Demo' === Eru_STC_CSV::cell( 'Demo' )
	);
	$evil = Eru_STC_Agents::update( $agent, array( 'settings' => array( 'display_name' => '=cmd|calc' ) ) );
	$csv  = Eru_STC_Reports::payouts_csv( array( 'agent_id' => $agent ), false );
	stc_check( '(b) a formula-like display name is neutralised in the payout CSV', true === $evil && false !== strpos( $csv, "'=cmd|calc" ) && false === strpos( $csv, ',=cmd' ) && false === strpos( $csv, '"=cmd' ) );
	Eru_STC_Agents::update( $agent, array( 'settings' => array( 'display_name' => 'Demo Temsilci Gizlilik' ) ) );

	// M6: the payout CSV is not capped (520 more payouts → 521 data rows).
	$vals = array();
	for ( $i = 0; $i < 520; $i++ ) {
		$vals[] = $wpdb->prepare( "(%d, '1.0000', 'TRY', 'paid', 'other', %s, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())", $agent, 'stc-bulk ' . STC_RUN );
	}
	$wpdb->query( "INSERT INTO {$wpdb->prefix}eru_stc_payouts (agent_id, amount, currency, status, method, note, requested_at, paid_at, updated_at) VALUES " . implode( ',', $vals ) ); // phpcs:ignore
	// Final wave M2: a payout created while the file is being read never duplicates a boundary row (keyset).
	$selects = 0;
	$grow    = function ( $q ) use ( &$selects, $agent ) {
		global $wpdb;
		if ( 0 === strpos( ltrim( $q ), 'SELECT * FROM ' . $wpdb->prefix . 'eru_stc_payouts' ) && 2 === ++$selects ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}eru_stc_payouts (agent_id, amount, currency, status, method, note, requested_at, paid_at, updated_at) VALUES (%d, '1.0000', 'TRY', 'paid', 'other', %s, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())", $agent, 'stc-bulk ' . STC_RUN ) ); // phpcs:ignore
		}
		return $q;
	};
	add_filter( 'query', $grow );
	$csv = Eru_STC_Reports::payouts_csv( array( 'agent_id' => $agent ), false );
	remove_filter( 'query', $grow );
	$data = array_filter( explode( "\n", trim( $csv ) ) );
	$ids  = array_map( fn( $l ) => (int) str_getcsv( $l )[0], array_slice( array_values( $data ), 1 ) );
	stc_check( '(b) M6: payout CSV streams every row in batches (1 header + 521 rows)', 522 === count( $data ) );
	stc_check( '(b) final wave M2: a payout inserted between batches duplicates no row', $selects >= 2 && count( $ids ) === count( array_unique( $ids ) ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eru_stc_payouts WHERE note = %s", 'stc-bulk ' . STC_RUN ) ); // phpcs:ignore

	// ---------------------------------------------------------------- (g) exporter and eraser
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	stc_check( '(g) exporter and eraser registered', isset( $exporters['eru-stc'], $erasers['eru-stc'] ) );
	$email = get_userdata( $row->user_id )->user_email;
	$exp   = call_user_func( $exporters['eru-stc']['callback'], $email, 1 );
	$flat  = wp_json_encode( $exp );
	stc_check( '(g) exporter: agent data with masked payout details only', ! empty( $exp['data'] ) && false !== strpos( $flat, Eru_STC_Agents::get( $agent )->referral_code ) && false === strpos( $flat, $iban ) && false === strpos( $flat, $tckn ) && false !== strpos( $flat, 'Demo Temsilci Gizlilik' ) );

	// A customer of the agent with a commission row: erasing the customer drops the link and the customer id.
	$cust = stc_user( 'privcust' );
	Eru_STC_Links::link( $cust, $agent, 'manual' );
	Eru_STC_Commissions::insert(
		array(
			'order_id'     => 990000002,
			'item_id'      => 1,
			'agent_id'     => $agent,
			'customer_id'  => $cust,
			'level'        => 1,
			'amount_minor' => 1000,
			'basis_minor'  => 10000,
			'currency'     => 'TRY',
			'status'       => 'pending',
			'note'         => 'stc-test ' . STC_RUN,
		)
	);
	$before_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_stc_commissions WHERE agent_id = %d", $agent ) ); // phpcs:ignore
	// M7: with an open payout request the payout details (and that request's snapshot) are retained.
	$res      = call_user_func( $erasers['eru-stc']['callback'], $email, 1 );
	$after    = Eru_STC_Agents::get( $agent );
	$settings = json_decode( (string) $after->settings, true );
	stc_check( '(g) M7: open request → payout details and its snapshot retained, message says why', null !== $after->payout_iban && null !== Eru_STC_Payouts::get( $payout )->details && true === $res['items_retained'] && false !== strpos( implode( ' ', $res['messages'] ), 'Açık ödeme talebi' ) );
	stc_check( '(g) M7: the rest is erased (display name)', true === $res['items_removed'] && empty( $settings['display_name'] ) );
	Eru_STC_Payouts::cancel( $payout );
	$res      = call_user_func( $erasers['eru-stc']['callback'], $email, 1 );
	$after    = Eru_STC_Agents::get( $agent );
	$settings = json_decode( (string) $after->settings, true );
	stc_check( '(g) eraser after the request is settled: removed items and done', true === $res['items_removed'] && true === $res['done'] );
	stc_check( '(g) payout fields wiped', null === $after->payout_holder && null === $after->payout_iban && null === $after->payout_tckn );
	stc_check( '(g) display name removed', empty( $settings['display_name'] ) );
	stc_check( '(g) payout snapshots wiped, payout kept as an accounting record', null === Eru_STC_Payouts::get( $payout )->details && '42.5000' === Eru_STC_Payouts::get( $payout )->amount );
	stc_check( '(g) commissions kept with the agent id', $before_rows === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_stc_commissions WHERE agent_id = %d", $agent ) ) ); // phpcs:ignore
	$m = Eru_STC_Payout_Details::masked( $agent );
	stc_check( '(g) masked() after erasure: empty', 'empty' === $m['state'] );
	$exp2 = call_user_func( $exporters['eru-stc']['callback'], $email, 1 );
	stc_check( '(g) exporter after erasure: no name, no payout details', false === strpos( wp_json_encode( $exp2 ), 'Demo Temsilci Gizlilik' ) && false === strpos( wp_json_encode( $exp2 ), '****' ) );

	$cmail = get_userdata( $cust )->user_email;
	$res   = call_user_func( $erasers['eru-stc']['callback'], $cmail, 1 );
	stc_check( '(g) customer erasure: link removed, commission row kept with customer id 0', true === $res['items_removed'] && null === Eru_STC_Links::get( $cust ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_stc_commissions WHERE order_id = %d AND agent_id = %d AND customer_id = 0", 990000002, $agent ) ) ); // phpcs:ignore
	$unknown = call_user_func( $erasers['eru-stc']['callback'], 'stc-demo-nobody-' . STC_RUN . '@example.com', 1 );
	stc_check( '(g) unknown e-mail: nothing removed, done', false === $unknown['items_removed'] && true === $unknown['done'] );

	echo "ALL PASS: stc-privacy\n";
	$status = 0;
} catch ( Throwable $e ) {
	echo 'FAIL: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	$status = 1;
} finally {
	wp_set_current_user( 0 );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eru_stc_commissions WHERE note = %s", 'stc-test ' . STC_RUN ) ); // phpcs:ignore
	stc_purge( false );
	stc_restore( $keep );
}
exit( $status );
