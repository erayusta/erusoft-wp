<?php
/**
 * VakıfBank Vinov Integration — server-side OTP: send/verify through the bank (mocked), session holds only
 * an HMAC of the TCKN, checkDebtor gets the server-computed total, test mode still calls verifyOTP, 401
 * refreshes once, success only on the documented header code, token cache per environment and client,
 * rate limits per fingerprint and per TCKN hash, honeypot, no TCKN or secret in logs.
 *
 *   wp plugin activate vakifbank-vinov-integration-for-woocommerce
 *   wp eval-file tests/php/vnv-otp.php
 */

require __DIR__ . '/vnv-helpers.php';
vnv_require_plugin();

$bank = vnv_install_bank();
$logs = vnv_capture_logs();
wc_load_cart();

$old_settings = get_option( Eru_VNV_Settings::OPTION, null );
$old_flag     = get_option( Eru_VNV_Settings::FLAG, null );
$failed       = false;

try {
	vnv_purge( true );
	vnv_clear_log_files();
	vnv_reset();
	vnv_settings();
	$p1 = vnv_product( 1000 );
	vnv_cart( array( $p1 => 1 ) );
	vnv_check( 'cart total is the invented 1.000', 100000 === Eru_VNV_Installments::to_minor( WC()->cart->get_total( 'edit' ) ) );

	$tckn   = vnv_fake_tckn();
	$secret = vnv_secret( 'test' );

	// --- send: validation before any HTTP -------------------------------------------------------------
	$bad = $tckn;
	$bad[10] = (string) ( ( (int) $bad[10] + 1 ) % 10 );
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $bad, 'phone' => vnv_phone() ) );
	vnv_check( 'send: TCKN with a wrong checksum refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => '12345' ) );
	vnv_check( 'send: invalid phone refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone(), 'hp' => 'http://bot.example.com' ) );
	vnv_check( 'send: honeypot filled refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );

	// --- send: success --------------------------------------------------------------------------------
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	$sent_ok = $r;
	vnv_check( 'send: ok', ! empty( $r['ok'] ) );
	vnv_check( 'send: one oob client_credentials grant', 1 === count( $bank->grants ) && 'oob' === $bank->grants[0]['scope'] && 'client_credentials' === $bank->grants[0]['grant_type'] && 'sandbox' === ( $bank->grants[0]['resource'] ?? '' ) );
	vnv_check( 'send: grant carries the test credentials in the POST body', vnv_client( 'test' ) === $bank->grants[0]['client_id'] && $secret === $bank->grants[0]['client_secret'] );
	$c = $bank->calls( '/sendOTP' );
	vnv_check( 'send: one sendOTP with IdentityNumber and the configured TransactionCode', 1 === count( $c ) && (string) $c[0]['body']['IdentityNumber'] === $tckn && 'DEMOOTP' === $c[0]['body']['TransactionCode'] && 0 === strpos( $c[0]['auth'], 'Bearer demo-tok-' ) );
	$sess = WC()->session->get( Eru_VNV_Session::KEY );
	vnv_check( 'send: session holds tckn_hmac, phone_hash, otp_txn_id, sent_at', is_array( $sess ) && Eru_VNV_Identity::hmac( $tckn ) === $sess['tckn_hmac'] && '' !== (string) $sess['phone_hash'] && (string) $bank->otp_txn === (string) $sess['otp_txn_id'] && abs( time() - (int) $sess['sent_at'] ) < 5 && empty( $sess['verified_at'] ) );
	vnv_check( 'send: session never holds the TCKN or the phone in clear', false === strpos( maybe_serialize( $sess ), $tckn ) && false === strpos( maybe_serialize( $sess ), '5550000000' ) );
	vnv_check( 'send: browser response has no OTP transaction id or TCKN', false === strpos( wp_json_encode( $r ), (string) $bank->otp_txn ) && false === strpos( wp_json_encode( $r ), $tckn ) );

	// --- verify: refusals before any HTTP -------------------------------------------------------------
	$bank->reset();
	$other = vnv_fake_tckn();
	while ( $other === $tckn ) {
		$other = vnv_fake_tckn();
	}
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $other, 'otp' => '123456', 'installments' => '1' ) );
	vnv_check( 'verify: TCKN other than the one the code went to refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '', 'installments' => '1' ) );
	vnv_check( 'verify: empty code refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '12' ) );
	vnv_check( 'verify: installments above the maximum refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '4' ) );
	vnv_check( 'verify: disabled installment count refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '1', 'hp' => 'x' ) );
	vnv_check( 'verify: honeypot filled refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );

	// --- (i) test mode still verifies at the bank; checkDebtor gets the server total ------------------
	$txn = (string) $bank->otp_txn;
	$r   = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '1', 'amount' => '1', 'verified' => '1' ) );
	vnv_check( '(i) test mode: verify ok', ! empty( $r['ok'] ) && true === $r['verified'] );
	$v = $bank->calls( '/verifyOTP' );
	vnv_check( '(i) test mode: verifyOTP was called with the stored transaction id (no local bypass)', 1 === count( $v ) && '123456' === (string) $v[0]['body']['OTPCode'] && $txn === (string) $v[0]['body']['OTPTransactionId'] );
	$d = $bank->calls( '/vinov/checkDebtor' );
	vnv_check( 'verify: checkDebtor with the server cart total, not the posted amount', 1 === count( $d ) && 1000.0 === (float) $d[0]['body']['Amount'] && $tckn === (string) $d[0]['body']['IdentityNumber'] && 1 === (int) $d[0]['body']['WorkPlaceId'] && '123456' === (string) $d[0]['body']['Otp'] && $txn === (string) $d[0]['body']['OtpId'] );
	vnv_check( 'verify: payment scope uses b2b_credentials with the consent id', 1 === count( array_filter( $bank->grants, fn( $g ) => 'payment' === $g['scope'] && 'b2b_credentials' === $g['grant_type'] && 'demo-consent-0001' === ( $g['consentId'] ?? '' ) ) ) );
	$keys = array_keys( $r );
	sort( $keys );
	vnv_check( 'verify: browser gets only a boolean and a message', array( 'message', 'ok', 'verified' ) === $keys );
	$sess = WC()->session->get( Eru_VNV_Session::KEY );
	vnv_check( 'verify: session stores verified_at, total_minor, installments, cart_hash; code id spent', abs( time() - (int) $sess['verified_at'] ) < 5 && 100000 === (int) $sess['total_minor'] && 1 === (int) $sess['installments'] && WC()->cart->get_cart_hash() === $sess['cart_hash'] && empty( $sess['otp_txn_id'] ) );
	vnv_check( 'verify: session still holds no TCKN', false === strpos( maybe_serialize( $sess ), $tckn ) );
	$bank->reset();
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '1' ) );
	vnv_check( 'verify: a spent code cannot be verified again, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );

	// --- verify: installment fee is part of the checked amount ----------------------------------------
	vnv_reset();
	$bank->reset();
	$r = vnv_verify( $tckn, 3 );
	$d = $bank->calls( '/vinov/checkDebtor' );
	vnv_check( 'verify: 3 installments at 4 % → checkDebtor Amount 1.040', ! empty( $r['ok'] ) && 1 === count( $d ) && 1040.0 === (float) $d[0]['body']['Amount'] && 104000 === (int) WC()->session->get( Eru_VNV_Session::KEY )['total_minor'] );

	// --- verify: bank refusals ------------------------------------------------------------------------
	vnv_reset();
	$bank->reset();
	$bank->push( '/verifyOTP', array( 200, vnv_fixture( 'verify-otp-wrong.json' ) ) );
	$r    = vnv_verify( $tckn );
	$sess = WC()->session->get( Eru_VNV_Session::KEY );
	vnv_check( 'verify: wrong code (ACBH000301) refused with the generic message, no checkDebtor', empty( $r['ok'] ) && 'Kod doğrulanamadı. Kodu kontrol edin ya da yeni kod isteyin.' === $r['message'] && 0 === $bank->count( '/vinov/checkDebtor' ) && empty( $sess['verified_at'] ) );
	$wrong_code = $r;

	vnv_reset();
	$bank->reset();
	$bank->push( '/verifyOTP', array( 200, vnv_fixture( 'other-code.json' ) ) );
	$r = vnv_verify( $tckn );
	vnv_check( 'verify: HTTP 200 with another header code is not a success', empty( $r['ok'] ) && 0 === $bank->count( '/vinov/checkDebtor' ) && empty( WC()->session->get( Eru_VNV_Session::KEY )['verified_at'] ) );

	vnv_reset();
	$bank->reset();
	$bank->push( '/verifyOTP', array( 200, '{"Header":{"StatusCode":"APIGW000000"},"Data":{"Result":{"Success":false}}}' ) );
	$r = vnv_verify( $tckn );
	vnv_check( 'verify: Result.Success false refused', empty( $r['ok'] ) && 0 === $bank->count( '/vinov/checkDebtor' ) );

	vnv_reset();
	$bank->reset();
	$bank->push( '/vinov/checkDebtor', array( 200, vnv_fixture( 'check-debtor-refused.json' ) ) );
	$r = vnv_verify( $tckn );
	vnv_check( 'verify: checkDebtor IsSucceeded false refused, not verified', empty( $r['ok'] ) && empty( WC()->session->get( Eru_VNV_Session::KEY )['verified_at'] ) );

	vnv_reset();
	$bank->reset();
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '1' ) );
	vnv_check( 'verify: without a sent code refused, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );

	// --- 401 refreshes once ---------------------------------------------------------------------------
	vnv_reset();
	$bank->reset();
	$bank->push( '/sendOTP', array( 401, vnv_fixture( 'unauthorized.json' ) ) );
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	vnv_check( '401: token refreshed once and the call retried', ! empty( $r['ok'] ) && 2 === count( $bank->grants ) && 2 === $bank->count( '/sendOTP' ) && $bank->calls( '/sendOTP' )[0]['auth'] !== $bank->calls( '/sendOTP' )[1]['auth'] );
	vnv_reset();
	$bank->reset();
	$bank->push( '/sendOTP', array( 401, vnv_fixture( 'unauthorized.json' ) ) );
	$bank->push( '/sendOTP', array( 401, vnv_fixture( 'unauthorized.json' ) ) );
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	vnv_check( '401 twice: no third call, no code pending, same customer-facing answer as a success', $r === $sent_ok && 2 === $bank->count( '/sendOTP' ) && 2 === count( $bank->grants ) && ! Eru_VNV_Session::pending() );

	// --- send answers the same whether or not the bank accepted the number ----------------------------
	vnv_reset();
	$bank->reset();
	$bank->push( '/sendOTP', array( 200, vnv_fixture( 'error-generic.json' ) ) );
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	vnv_check( 'send: bank refusal gives the same answer as a success, nothing pending', $r === $sent_ok && 1 === $bank->count( '/sendOTP' ) && ! Eru_VNV_Session::pending() );
	vnv_check( 'send: the uniform message says a code arrives only if a phone is registered', false !== mb_strpos( $sent_ok['message'], 'kayıtlı telefonunuza' ) );
	$bank->reset();
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '1' ) );
	vnv_check( 'verify after a refused send fails there, no HTTP', empty( $r['ok'] ) && 0 === $bank->total() );
	vnv_check( 'no oracle: verify after a refused send is byte-identical to a wrong code after an accepted send', wp_json_encode( $r ) === wp_json_encode( $wrong_code ) );
	vnv_reset();
	$bank->reset();
	$r = Eru_VNV_OTP_Controller::verify( array( 'tckn' => vnv_fake_tckn(), 'otp' => '123456', 'installments' => '1' ) );
	vnv_check( 'no oracle: verify with no send at all is byte-identical too', wp_json_encode( $r ) === wp_json_encode( $wrong_code ) && 0 === $bank->total() );
	$shape_refused = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '', 'installments' => '1' ) );
	Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	$bank->reset();
	$shape_accepted = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '', 'installments' => '1' ) );
	vnv_check( 'no oracle: an empty code gets the same shape error with or without an accepted send, no HTTP', wp_json_encode( $shape_refused ) === wp_json_encode( $shape_accepted ) && 0 === $bank->total() );

	// --- N2: verify counts per TCKN only for the number the session holds ------------------------------
	global $wpdb;
	$rows = fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_eru\\_vnv\\_rlt\\_%'" );
	vnv_reset();
	$before = $rows();
	for ( $i = 0; $i < 3; $i++ ) {
		Eru_VNV_OTP_Controller::verify( array( 'tckn' => vnv_fake_tckn(), 'otp' => '123456', 'installments' => '1' ) );
	}
	vnv_check( 'N2: verify for numbers with no send in the session writes no per-TCKN counter rows', $before === $rows() );
	Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	$after_send = $rows();
	$bank->push( '/verifyOTP', array( 200, vnv_fixture( 'verify-otp-wrong.json' ) ) );
	Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '1' ) );
	vnv_check( 'N2: verify for the number the session holds counts per TCKN', $after_send > $before && $rows() > $after_send );

	// --- (g) token cache per environment and client ---------------------------------------------------
	vnv_reset();
	$bank->reset();
	$test_client = new Eru_VNV_API_Client( 'test' );
	$live_client = new Eru_VNV_API_Client( 'live' );
	vnv_check( '(g) token keys differ between test and live', $test_client->token_key( 'oob' ) !== $live_client->token_key( 'oob' ) && $test_client->token_key( 'oob' ) !== $test_client->token_key( 'payment' ) );
	Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	vnv_fingerprint( 'fp-g2-' . VNV_RUN );
	Eru_VNV_OTP_Controller::send( array( 'tckn' => vnv_fake_tckn(), 'phone' => vnv_phone() ) );
	vnv_check( '(g) second test-mode call reuses the cached token', 1 === count( $bank->grants ) && 2 === $bank->count( '/sendOTP' ) );
	vnv_settings( array( 'testmode' => 'no' ) );
	vnv_fingerprint( 'fp-g3-' . VNV_RUN );
	Eru_VNV_OTP_Controller::send( array( 'tckn' => vnv_fake_tckn(), 'phone' => vnv_phone() ) );
	$last = $bank->calls( '/sendOTP' )[2];
	vnv_check( '(g) switching test → live mints a second token with the live client', 2 === count( $bank->grants ) && vnv_client( 'live' ) === $bank->grants[1]['client_id'] && 'production' === ( $bank->grants[1]['resource'] ?? '' ) && $last['auth'] !== $bank->calls( '/sendOTP' )[0]['auth'] );
	vnv_check( '(g) each environment calls its own configured host', 'vinov-api-live.example.test' === wp_parse_url( $last['url'], PHP_URL_HOST ) && 'vinov-api.example.test' === wp_parse_url( $bank->calls( '/sendOTP' )[0]['url'], PHP_URL_HOST ) );
	vnv_settings();
	$key_before = ( new Eru_VNV_API_Client( 'test' ) )->token_key( 'oob' );
	vnv_settings( array( 'test_client_id' => vnv_client( 'test' ) . 'b' ) );
	vnv_check( '(g) another client id uses another token key', ( new Eru_VNV_API_Client( 'test' ) )->token_key( 'oob' ) !== $key_before );
	vnv_settings();

	// --- (f) rate limits ------------------------------------------------------------------------------
	vnv_reset();
	$bank->reset();
	vnv_fingerprint( 'fp-f1-' . VNV_RUN );
	$ok = 0;
	for ( $i = 0; $i < 5; $i++ ) {
		$r   = Eru_VNV_OTP_Controller::send( array( 'tckn' => vnv_fake_tckn(), 'phone' => vnv_phone() ) );
		$ok += empty( $r['ok'] ) ? 0 : 1;
	}
	$before = $bank->count( '/sendOTP' );
	$r      = Eru_VNV_OTP_Controller::send( array( 'tckn' => vnv_fake_tckn(), 'phone' => vnv_phone() ) );
	vnv_check( '(f) 5 sends per fingerprint pass, the 6th is refused without HTTP', 5 === $ok && empty( $r['ok'] ) && $before === $bank->count( '/sendOTP' ) && 5 === $before );

	vnv_reset();
	$bank->reset();
	$same = vnv_fake_tckn();
	$ok   = 0;
	for ( $i = 0; $i < 5; $i++ ) {
		vnv_fingerprint( 'fp-f2-' . $i . '-' . VNV_RUN );
		$r   = Eru_VNV_OTP_Controller::send( array( 'tckn' => $same, 'phone' => vnv_phone() ) );
		$ok += empty( $r['ok'] ) ? 0 : 1;
	}
	vnv_fingerprint( 'fp-f2-x-' . VNV_RUN );
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $same, 'phone' => vnv_phone() ) );
	vnv_check( '(f) 5 sends per TCKN hash across fingerprints, the 6th is refused without HTTP', 5 === $ok && empty( $r['ok'] ) && 5 === $bank->count( '/sendOTP' ) );
	vnv_check( '(f) the counter store holds no TCKN', false === strpos( maybe_serialize( get_option( 'eru_vnv_rl' ) ), $same ) );
	for ( $i = 0; $i < 600; $i++ ) {
		Eru_VNV_Rate_Limit::hit( 'send', 'fp-evict-' . $i . '-' . VNV_RUN, 5 );
	}
	vnv_fingerprint( 'fp-f2-y-' . VNV_RUN );
	$r = Eru_VNV_OTP_Controller::send( array( 'tckn' => $same, 'phone' => vnv_phone() ) );
	vnv_check( '(f) 600 other fingerprints cannot evict the per-TCKN counter: 7th send still refused, map capped', empty( $r['ok'] ) && 5 === $bank->count( '/sendOTP' ) && count( (array) get_option( 'eru_vnv_rl' ) ) <= 500 );

	vnv_reset();
	$bank->reset();
	vnv_fingerprint( 'fp-f3-' . VNV_RUN );
	Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	for ( $i = 0; $i < 10; $i++ ) {
		$bank->push( '/verifyOTP', array( 200, vnv_fixture( 'verify-otp-wrong.json' ) ) );
		Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '00000' . ( $i % 10 ), 'installments' => '1' ) );
	}
	$before = $bank->count( '/verifyOTP' );
	$r      = Eru_VNV_OTP_Controller::verify( array( 'tckn' => $tckn, 'otp' => '123456', 'installments' => '1' ) );
	vnv_check( '(f) 10 verifies per window, the 11th is refused without HTTP', 10 === $before && empty( $r['ok'] ) && 10 === $bank->count( '/verifyOTP' ) );

	// --- (h) logs: no TCKN, no secret, no token ---------------------------------------------------------
	$all = implode( "\n", (array) $logs->getArrayCopy() ) . "\n" . vnv_log_files_text();
	vnv_check( '(h) the plugin logged the calls (debug level)', count( $logs ) > 5 && false !== strpos( $all, '/sendOTP' ) );
	vnv_check( '(h) logs hold no TCKN of this run', false === strpos( $all, $tckn ) && false === strpos( $all, $same ) );
	vnv_check( '(h) logs hold no client secret, bearer token or OTP code', false === strpos( $all, vnv_secret( 'test' ) ) && false === strpos( $all, vnv_secret( 'live' ) ) && false === strpos( $all, 'demo-tok-' ) && false === strpos( $all, '"123456"' ) );
} catch ( Throwable $e ) {
	$failed = true;
	if ( ! $e instanceof RuntimeException ) {
		echo 'FAIL: ' . get_class( $e ) . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	vnv_fingerprint( null );
	vnv_reset();
	vnv_purge();
	null === $old_settings ? delete_option( Eru_VNV_Settings::OPTION ) : update_option( Eru_VNV_Settings::OPTION, $old_settings, false );
	null === $old_flag ? delete_option( Eru_VNV_Settings::FLAG ) : update_option( Eru_VNV_Settings::FLAG, $old_flag );
}
if ( $failed || ! empty( $GLOBALS['vnv_soft_failed'] ) ) {
	exit( 1 );
}
echo "ALL PASS\n";
