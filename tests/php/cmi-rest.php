<?php
/**
 * Consent Manager for İYS — who may read or change a consent: signed tokens, REST (eru-cmi/v1/consent),
 * the opt-out page, the Growth Automations consent filter and the public rate limit.
 *
 *   wp eval-file tests/php/cmi-rest.php
 */

require __DIR__ . '/cmi-helpers.php';
cmi_require_plugin();
foreach ( array( 'Eru_CMI_Tokens', 'Eru_CMI_Rest', 'Eru_CMI_Rate_Limit', 'Eru_CMI_Links', 'Eru_CMI_Gra_Bridge' ) as $eru_cmi_c ) {
	if ( ! class_exists( $eru_cmi_c ) ) {
		echo "FAIL: {$eru_cmi_c} missing\n";
		exit( 1 );
	}
}
$fake = cmi_fake_iys();

global $wpdb;
$old_settings = get_option( Eru_CMI_Settings::OPTION, null );
$emails       = array( 'cmi-a@example.com', 'cmi-b@example.com', 'cmi-unknown@example.com', 'cmi-bf1@example.com', 'cmi-bf2@example.com', 'cmi-bf3@example.com', 'cmi-bf-onay@example.com' );
$bf_marker    = get_option( 'eru_cmi_gra_backfill', null );
$a_mail       = $emails[0];
$b_mail       = $emails[1];
$failed       = false;
$mails        = cmi_mail_trap();

/** Status of a consent row or null. */
$status = function ( $r, $t ) {
	$row = Eru_CMI_Repository::get( $r, $t );
	return $row ? $row->status : null;
};

try {
	cmi_reset();
	cmi_cleanup_people( $emails );
	delete_option( Eru_CMI_Settings::OPTION );
	cmi_collect();
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
	wp_set_current_user( 0 );

	$a = cmi_user( $a_mail, 'Demo A' );
	$b = cmi_user( $b_mail, 'Demo B' );
	Eru_CMI_Repository::set( $a_mail, 'EPOSTA', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin', 'note' => 'Demo: test kaydı', 'user_id' => $a ) );
	Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin', 'note' => 'Demo: test kaydı', 'user_id' => $b ) );
	$before = $wpdb->get_results( 'SELECT id, status, updated_at FROM ' . $wpdb->prefix . 'eru_cmi_consents ORDER BY id' ); // phpcs:ignore
	$hist0  = cmi_count( 'history' );

	// Tokens.
	$tok = Eru_CMI_Tokens::mint( $b_mail, 'EPOSTA' );
	cmi_check( 'token: format is base64url(payload).base64url(hmac), no e-mail inside', is_string( $tok ) && 1 === substr_count( $tok, '.' ) && false === stripos( $tok, 'example' ) && false === stripos( base64_decode( strtr( explode( '.', $tok )[0], '-_', '+/' ) ), 'example' ) );
	$v = Eru_CMI_Tokens::verify( $tok );
	cmi_check( 'token: verifies to the recipient hash and channel', is_array( $v ) && Eru_CMI_Recipient::hash( $b_mail ) === $v['hash'] && 'EPOSTA' === $v['channel'] && $v['exp'] > time() + 29 * DAY_IN_SECONDS );
	cmi_check( 'token: secret option is not autoloaded', in_array( $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'eru_cmi_token_secret'" ), array( 'no', 'off', 'auto-off' ), true ) );
	cmi_check( 'token: a plain e-mail is not a token', is_wp_error( Eru_CMI_Tokens::verify( $b_mail ) ) );
	cmi_check( 'token: an opt-out token is not a confirm token', is_wp_error( Eru_CMI_Tokens::verify( $tok, 'confirm' ) ) );
	cmi_check( 'eru_cmi_optout_url(): signed link on the home URL', 0 === strpos( eru_cmi_optout_url( $b_mail ), home_url( '/' ) ) && false !== strpos( eru_cmi_optout_url( $b_mail ), 'eru_cmi_optout=' ) && '' === eru_cmi_optout_url( 'not-an-address', 'EPOSTA' ) );

	// (a) Anonymous POST with a plain e-mail as token → 401, nothing changed.
	cmi_fingerprint( 'a' );
	list( $code, $data, $body ) = cmi_rest( 'POST', array( 'token' => $b_mail, 'type' => 'EPOSTA', 'status' => 'RET', 'recipient' => $b_mail ) );
	cmi_check( '(a) anonymous POST token=<e-mail> → 401', 401 === $code );
	list( $code ) = cmi_rest( 'DELETE', array( 'token' => $b_mail, 'recipient' => $b_mail, 'type' => 'EPOSTA' ) );
	list( $code2 ) = cmi_rest( 'POST', array( 'recipient' => $b_mail, 'type' => 'EPOSTA', 'status' => 'RET' ) );
	$after = $wpdb->get_results( 'SELECT id, status, updated_at FROM ' . $wpdb->prefix . 'eru_cmi_consents ORDER BY id' ); // phpcs:ignore
	cmi_check( '(a) anonymous DELETE with a plain e-mail and POST without a token → 401; no row or history changed', 401 === $code && 401 === $code2 && $before == $after && $hist0 === cmi_count( 'history' ) ); // phpcs:ignore Universal.Operators.StrictComparisons

	// (b) Forged and expired tokens → 401; a valid token writes RET for its own recipient only.
	list( $p, $sig ) = explode( '.', $tok );
	$forged_sig      = $p . '.' . ( 'A' === $sig[0] ? 'B' : 'A' ) . substr( $sig, 1 );
	$a_payload       = rtrim( strtr( base64_encode( Eru_CMI_Recipient::hash( $a_mail ) . '.EPOSTA.' . ( time() + 3600 ) ), '+/', '-_' ), '=' );
	list( $c1 ) = cmi_rest( 'POST', array( 'token' => $forged_sig, 'status' => 'RET' ) );
	list( $c2 ) = cmi_rest( 'POST', array( 'token' => $a_payload . '.' . $sig, 'status' => 'RET' ) );
	$expired    = Eru_CMI_Tokens::mint( $b_mail, 'EPOSTA', 'optout', -60 );
	list( $c3 ) = cmi_rest( 'POST', array( 'token' => $expired, 'status' => 'RET' ) );
	cmi_check( '(b) forged signature → 401; payload swapped to another recipient → 401; expired token → 401', 401 === $c1 && 401 === $c2 && 401 === $c3 && 'ONAY' === $status( $b_mail, 'EPOSTA' ) && 'ONAY' === $status( $a_mail, 'EPOSTA' ) );
	list( $code, $data, $body ) = cmi_rest( 'POST', array( 'token' => $tok, 'status' => 'ONAY', 'type' => 'EPOSTA' ) );
	cmi_check( '(b) a token never grants consent (status ONAY with a token → 403)', 403 === $code && 'ONAY' === $status( $b_mail, 'EPOSTA' ) );
	list( $code, $data, $body ) = cmi_rest( 'POST', array( 'token' => $tok, 'status' => 'RET', 'recipient' => $a_mail, 'type' => 'MESAJ' ) );
	cmi_check( '(b) valid token → 200, RET written for the token\'s recipient and channel', 200 === $code && 'RET' === $status( $b_mail, 'EPOSTA' ) );
	cmi_check( '(b) ... the recipient named in the body is ignored (A unchanged)', 'ONAY' === $status( $a_mail, 'EPOSTA' ) && null === $status( cmi_phone(), 'MESAJ' ) );
	cmi_check( '(b) ... response is masked and carries no address', false === strpos( $body, $b_mail ) && false === strpos( $body, $a_mail ) && false !== strpos( $body, 'cm***@example.com' ) );
	$h = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( $b_mail, 'EPOSTA' )->id );
	cmi_check( '(b) ... history row: actor user, source api', 'user' === end( $h )->actor && 'api' === end( $h )->source && 'RET' === end( $h )->new_status );
	Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin', 'note' => 'Demo: test kaydı' ) );
	list( $code ) = cmi_rest( 'DELETE', array( 'token' => $tok ) );
	cmi_check( '(b) DELETE with a valid token → 200 and RET', 200 === $code && 'RET' === $status( $b_mail, 'EPOSTA' ) );

	// (c) Logged-in owner checks.
	cmi_fingerprint( 'c' );
	wp_set_current_user( $a );
	delete_user_meta( $a, 'billing_phone' );
	list( $code ) = cmi_rest( 'POST', array( 'type' => 'MESAJ', 'recipient' => cmi_phone(), 'status' => 'ONAY' ) );
	cmi_check( '(c) user A writes a phone that is not on A\'s account → 403, no row', 403 === $code && null === $status( cmi_phone(), 'MESAJ' ) );
	list( $code ) = cmi_rest( 'POST', array( 'type' => 'EPOSTA', 'recipient' => $b_mail, 'status' => 'ONAY' ) );
	cmi_check( '(c) user A writes B\'s e-mail → 403, B unchanged', 403 === $code && 'RET' === $status( $b_mail, 'EPOSTA' ) );
	update_user_meta( $a, 'billing_phone', cmi_phone() );
	list( $code, $data, $body ) = cmi_rest( 'POST', array( 'type' => 'MESAJ', 'recipient' => cmi_phone_e164(), 'status' => 'ONAY' ) );
	cmi_check( '(c) A\'s own billing phone (any format) → 200, ONAY', 200 === $code && 'ONAY' === $status( cmi_phone(), 'MESAJ' ) );
	$hh = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( cmi_phone(), 'MESAJ' )->id );
	cmi_check( '(c) phone writes are never marked verified', 0 === (int) end( $hh )->verified );
	list( $code ) = cmi_rest( 'POST', array( 'type' => 'EPOSTA', 'status' => 'RET' ) );
	cmi_check( '(c) no recipient given → the account\'s own address; RET → 200', 200 === $code && 'RET' === $status( $a_mail, 'EPOSTA' ) );
	// Lifting a RET needs proof: the account e-mail is not verified by WooCommerce, the phone is free text.
	$mails->exchangeArray( array() );
	list( $code, $data, $body ) = cmi_rest( 'POST', array( 'type' => 'EPOSTA', 'status' => 'ONAY' ) );
	cmi_check( '(c) ONAY over the account\'s own e-mail RET → 202, still RET, one confirmation mail to that address', 202 === $code && 'RET' === $status( $a_mail, 'EPOSTA' ) && 'sent' === ( $data['confirmation'] ?? '' ) && 1 === count( $mails ) && $a_mail === ( is_array( $mails[0]['to'] ) ? $mails[0]['to'][0] : $mails[0]['to'] ) );
	list( $code ) = cmi_rest( 'POST', array( 'type' => 'MESAJ', 'status' => 'RET' ) );
	list( $code2, $data2 ) = cmi_rest( 'POST', array( 'type' => 'MESAJ', 'status' => 'ONAY' ) );
	cmi_check( '(c) a phone RET cannot be lifted by the account owner (403 eru_cmi_reconsent_required, still RET)', 200 === $code && 403 === $code2 && 'eru_cmi_reconsent_required' === ( $data2['code'] ?? '' ) && 'RET' === $status( cmi_phone(), 'MESAJ' ) );
	list( $code ) = cmi_rest( 'POST', array( 'type' => 'FAKS', 'status' => 'RET' ) );
	cmi_check( '(c) invalid channel → 400', 400 === $code );

	// (d) GET never discloses another person's data.
	cmi_fingerprint( 'd' );
	list( $code, $data, $body ) = cmi_rest( 'GET', array( 'recipient' => $b_mail ) );
	cmi_check( '(d) user A GET ?recipient=<B> → own records only', 200 === $code && is_array( $data['consents'] ?? null ) && false === strpos( $body, $b_mail ) && 'RET' === ( wp_list_pluck( $data['consents'], 'status', 'type' )['MESAJ'] ?? '' ) );
	$types = wp_list_pluck( $data['consents'], 'type' );
	cmi_check( '(d) ... A\'s e-mail and phone channels, masked; no raw e-mail, phone, user id or display name', in_array( 'EPOSTA', $types, true ) && in_array( 'MESAJ', $types, true ) && false === strpos( $body, $a_mail ) && false === strpos( $body, '5550000000' ) && false === strpos( $body, 'Demo A' ) && false === strpos( $body, '"user_id"' ) && false === strpos( $body, '"id"' ) );
	wp_set_current_user( 0 );
	list( $code, $data, $body ) = cmi_rest( 'GET', array( 'recipient' => $b_mail ) );
	cmi_check( '(d) anonymous GET ?recipient=<B> → 401, no address in the body', 401 === $code && false === strpos( $body, $b_mail ) && false === strpos( $body, 'cm***' ) );
	list( $code, $data, $body ) = cmi_rest( 'GET', array( 'token' => $tok, 'recipient' => $a_mail ) );
	cmi_check( '(d) GET with a token → only the token\'s channel and status, masked', 200 === $code && 'EPOSTA' === $data['type'] && 'RET' === $data['status'] && 'cm***@example.com' === $data['recipient'] && false === strpos( $body, $a_mail ) && false === strpos( $body, $b_mail ) );
	list( $code ) = cmi_rest( 'GET', array( 'token' => $b_mail ) );
	cmi_check( '(d) GET with a plain e-mail as token → 401', 401 === $code );

	// (e) Opt-out page: GET renders a button and changes nothing; POST withdraws.
	cmi_fingerprint( 'e' );
	Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin', 'note' => 'Demo: test kaydı' ) );
	$h0   = cmi_count( 'history' );
	$page = Eru_CMI_Links::handle( 'GET', array( 'eru_cmi_optout' => $tok ) );
	$html = Eru_CMI_Links::kses( $page['html'] );
	cmi_check( '(e) GET: 200 page with <form method=post>, the hidden token input and a button after kses', 200 === $page['status'] && false !== strpos( $html, '<form method="post"' ) && false !== strpos( $html, 'type="hidden" name="eru_cmi_optout" value="' . $tok . '"' ) && false !== strpos( $html, '<button type="submit"' ) );
	cmi_check( '(e) GET: masked recipient shown, address not shown', false !== strpos( $html, 'cm***@example.com' ) && false === strpos( $html, $b_mail ) );
	cmi_check( '(e) GET changed nothing', 'ONAY' === $status( $b_mail, 'EPOSTA' ) && $h0 === cmi_count( 'history' ) );
	$bad = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $b_mail ) );
	cmi_check( '(e) POST with a plain e-mail as token → 400, nothing changed', 400 === $bad['status'] && 'ONAY' === $status( $b_mail, 'EPOSTA' ) );
	$_SERVER['HTTP_ORIGIN'] = 'https://example.com';
	$x = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $tok ) );
	unset( $_SERVER['HTTP_ORIGIN'] );
	cmi_check( '(e) POST naming another site in Origin → 403, nothing changed', 403 === $x['status'] && 'ONAY' === $status( $b_mail, 'EPOSTA' ) );
	$ok = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $tok ) );
	$h  = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( $b_mail, 'EPOSTA' )->id );
	cmi_check( '(e) POST without Origin/Referer → RET (actor user, source link)', 200 === $ok['status'] && 'RET' === $status( $b_mail, 'EPOSTA' ) && 'user' === end( $h )->actor && 'link' === end( $h )->source );
	Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin', 'note' => 'Demo: test kaydı' ) );
	$_SERVER['HTTP_ORIGIN'] = 'null';
	$ok = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $tok ) );
	unset( $_SERVER['HTTP_ORIGIN'] );
	cmi_check( '(e) POST with Origin: null → RET', 200 === $ok['status'] && 'RET' === $status( $b_mail, 'EPOSTA' ) );
	$exp_page = Eru_CMI_Links::handle( 'GET', array( 'eru_cmi_optout' => $expired ) );
	$exp_html = Eru_CMI_Links::kses( $exp_page['html'] );
	cmi_check( '(e) expired link: "süresi doldu" and a resend button (POST), nothing changed', 410 === $exp_page['status'] && false !== strpos( $exp_html, 'süresi doldu' ) && false !== strpos( $exp_html, 'name="eru_cmi_resend"' ) );
	$mails->exchangeArray( array() );
	$rs    = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $expired, 'eru_cmi_resend' => '1' ) );
	$rs2   = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $expired, 'eru_cmi_resend' => '1' ) );
	cmi_check( '(e) resend: one fresh link mailed to the address on record (second request within a day sends nothing)', 200 === $rs['status'] && 200 === $rs2['status'] && 1 === count( $mails ) && $b_mail === ( is_array( $mails[0]['to'] ) ? $mails[0]['to'][0] : $mails[0]['to'] ) && false !== strpos( (string) $mails[0]['message'], 'eru_cmi_optout=' ) );
	// A recipient without any record: the withdrawal still takes effect (hash-only row, nothing sent to İYS).
	$unk = Eru_CMI_Tokens::mint( $emails[2], 'EPOSTA' );
	$u   = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $unk ) );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'eru_cmi_consents WHERE recipient_hash = %s', Eru_CMI_Recipient::hash( $emails[2] ) ) ); // phpcs:ignore
	cmi_check( '(e) unknown recipient: RET stored by hash only (no address), not queued; eru_cmi_is_consented() → false', 200 === $u['status'] && $row && 'RET' === $row->status && false === strpos( $row->recipient, '@' ) && 0 === cmi_count( 'queue', 'consent_id = ' . (int) $row->id ) && false === eru_cmi_is_consented( $emails[2], 'EPOSTA' ) );
	$r0 = Eru_CMI_Repository::set( $emails[2], 'EPOSTA', 'ONAY', array( 'actor' => 'user', 'source' => 'account' ) );
	cmi_check( '(e) ... an unproven ONAY cannot lift that RET', is_wp_error( $r0 ) && 'eru_cmi_reconsent_required' === $r0->get_error_code() && false === eru_cmi_is_consented( $emails[2], 'EPOSTA' ) );
	$r = Eru_CMI_Repository::set( $emails[2], 'EPOSTA', 'ONAY', array( 'actor' => 'user', 'source' => 'link', 'verified' => true ) );
	cmi_check( '(e) ... the owner\'s confirmation reuses the row and restores the address', ! is_wp_error( $r ) && (int) $row->id === (int) $r['id'] && $emails[2] === Eru_CMI_Repository::get( $emails[2], 'EPOSTA' )->recipient );

	// (h) Growth Automations filter (P2).
	Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
	cmi_check( '(h) RET → false even when $has is true', false === Eru_CMI_Gra_Bridge::filter( true, $b_mail, 'send' ) );
	Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin', 'note' => 'Demo: test kaydı' ) );
	cmi_check( '(h) ONAY → true even when $has is false', true === Eru_CMI_Gra_Bridge::filter( false, $b_mail, 'send' ) );
	cmi_check( '(h) unknown → $has unchanged', true === Eru_CMI_Gra_Bridge::filter( true, 'cmi-none@example.com', 'send' ) && false === Eru_CMI_Gra_Bridge::filter( false, 'cmi-none@example.com', 'capture' ) );
	if ( defined( 'ERU_GRA_VERSION' ) ) {
		cmi_check( '(h) hooked at priority 20 with 3 args while Growth Automations is active', 20 === has_filter( 'eru_gra_has_marketing_consent', array( 'Eru_CMI_Gra_Bridge', 'filter' ) ) );
		cmi_check( '(h) through the real filter chain: ONAY → true', true === (bool) apply_filters( 'eru_gra_has_marketing_consent', false, $b_mail, 'send' ) );
		Eru_GRA_Consent::remove_suppression( $b_mail );
		Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
		cmi_check( '(h) an EPOSTA RET is mirrored to Growth Automations\' suppression list', Eru_GRA_Consent::is_suppressed( $b_mail ) && false === (bool) apply_filters( 'eru_gra_has_marketing_consent', true, $b_mail, 'send' ) );
		Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin', 'note' => 'Demo: test kaydı' ) );
		cmi_check( '(h) an ONAY without owner proof does not lift that suppression', Eru_GRA_Consent::is_suppressed( $b_mail ) );
		Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
		Eru_CMI_Repository::set( $b_mail, 'EPOSTA', 'ONAY', array( 'actor' => 'user', 'source' => 'link', 'verified' => true ) );
		cmi_check( '(h) only a confirmation-link ONAY (verified) lifts it', ! Eru_GRA_Consent::is_suppressed( $b_mail ) );

		// Final review M-4 — opt-outs recorded before Growth Automations was active are copied once, in batches.
		$bf = array( 'cmi-bf1@example.com', 'cmi-bf2@example.com', 'cmi-bf3@example.com' );
		foreach ( $bf as $e ) {
			Eru_CMI_Repository::set( $e, 'EPOSTA', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
			Eru_GRA_Consent::remove_suppression( $e ); // As if recorded while Growth Automations was inactive.
		}
		Eru_CMI_Repository::set( 'cmi-bf-onay@example.com', 'EPOSTA', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin' ) );
		Eru_CMI_Repository::set( cmi_phone(), 'MESAJ', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
		update_option( 'eru_cmi_gra_backfill', 'done', true );
		do_action( 'activated_plugin', 'growth-automations-for-woocommerce/growth-automations-for-woocommerce.php', false );
		cmi_check( '(h) [M-4] activating Growth Automations re-opens the one-off copy', false === get_option( 'eru_cmi_gra_backfill' ) );
		Eru_CMI_Gra_Bridge::maybe_backfill();
		Eru_CMI_Gra_Bridge::maybe_backfill();
		cmi_check( '(h) [M-4] first run with its API present: exactly one pending copy job', 1 === count( cmi_pending( 'eru_cmi_gra_backfill' ) ) && 0 === count( array_filter( $bf, array( 'Eru_GRA_Consent', 'is_suppressed' ) ) ) );
		// Every e-mail opt-out with an address on record, in id order (earlier steps of this test made some too).
		$eligible = $wpdb->get_col( 'SELECT recipient FROM ' . $wpdb->prefix . "eru_cmi_consents WHERE type = 'EPOSTA' AND status = 'RET' AND recipient NOT LIKE '#%' ORDER BY id ASC" ); // phpcs:ignore
		$emails   = array_values( array_unique( array_merge( $emails, $eligible ) ) ); // Clean-up covers them all.
		foreach ( $eligible as $e ) {
			Eru_GRA_Consent::remove_suppression( $e );
		}
		$running = cmi_pending( 'eru_cmi_gra_backfill' )[0];
		ActionScheduler::store()->log_execution( $running );
		Eru_CMI_Gra_Bridge::run_backfill( 2 );
		cmi_check( '(h) [M-4] one batch copied through suppress(); the running job re-armed exactly one pending successor', count( $eligible ) > 2 && array_slice( $eligible, 0, 2 ) === array_values( array_filter( $eligible, array( 'Eru_GRA_Consent', 'is_suppressed' ) ) ) && 1 === count( cmi_pending( 'eru_cmi_gra_backfill' ) ) );
		ActionScheduler::store()->mark_complete( $running );
		for ( $i = 0; $i < 10 && 'done' !== get_option( 'eru_cmi_gra_backfill' ); $i++ ) {
			Eru_CMI_Gra_Bridge::run_backfill( 2 );
		}
		cmi_check( '(h) [M-4] every e-mail opt-out copied (an ONAY and a phone are not); marker done, nothing pending', $eligible === array_values( array_filter( $eligible, array( 'Eru_GRA_Consent', 'is_suppressed' ) ) ) && 3 === count( array_intersect( $bf, $eligible ) ) && ! Eru_GRA_Consent::is_suppressed( 'cmi-bf-onay@example.com' ) && 'done' === get_option( 'eru_cmi_gra_backfill' ) && array() === cmi_pending( 'eru_cmi_gra_backfill' ) );
		Eru_CMI_Gra_Bridge::maybe_backfill();
		cmi_check( '(h) [M-4] once done it never runs again on its own', array() === cmi_pending( 'eru_cmi_gra_backfill' ) );
	} else {
		cmi_check( '(h) not hooked while Growth Automations is inactive', false === has_filter( 'eru_gra_has_marketing_consent', array( 'Eru_CMI_Gra_Bridge', 'filter' ) ) );
	}

	// (i) Rate limit: 20 requests per 10 minutes per fingerprint on the public routes.
	cmi_fingerprint( 'i' );
	$codes = array();
	for ( $n = 1; $n <= 21; $n++ ) {
		list( $codes[ $n ] ) = cmi_rest( 'GET', array( 'token' => $tok ) );
	}
	cmi_check( '(i) requests 1–20 answered, the 21st → 429', 200 === $codes[20] && 429 === $codes[21] && 20 === count( array_filter( $codes, fn( $c ) => 200 === $c ) ) );
	list( $code ) = cmi_rest( 'POST', array( 'token' => $tok, 'status' => 'RET' ) );
	cmi_check( '(i) ... POST from the same fingerprint is limited too, nothing written', 429 === $code && 'ONAY' === $status( $b_mail, 'EPOSTA' ) );
	cmi_fingerprint( 'i2' );
	list( $code ) = cmi_rest( 'GET', array( 'token' => $tok ) );
	cmi_check( '(i) another fingerprint is not affected', 200 === $code );
	cmi_fingerprint( 'i3' );
	for ( $n = 1; $n <= 20; $n++ ) {
		Eru_CMI_Rate_Limit::hit();
	}
	$p = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $tok ) );
	cmi_check( '(i) the opt-out page POST shares the limit (429, nothing written)', 429 === $p['status'] && 'ONAY' === $status( $b_mail, 'EPOSTA' ) );
	$map = get_option( 'eru_cmi_rl', array() );
	cmi_check( '(i) counters: object cache or a bounded, non-autoloaded option map', wp_using_ext_object_cache() || ( is_array( $map ) && count( $map ) <= Eru_CMI_Rate_Limit::MAX_KEYS && in_array( $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'eru_cmi_rl'" ), array( 'no', 'off', 'auto-off' ), true ) ) );
	for ( $n = 0; $n < Eru_CMI_Rate_Limit::MAX_KEYS + 30; $n++ ) {
		$GLOBALS['cmi_fp'] = 'cmi-flood-' . $n;
		Eru_CMI_Rate_Limit::hit();
	}
	$map = get_option( 'eru_cmi_rl', array() );
	cmi_check( '(i) many fingerprints never grow the map past its bound', wp_using_ext_object_cache() || count( $map ) <= Eru_CMI_Rate_Limit::MAX_KEYS );

	echo "ALL PASS\n";
} catch ( Throwable $e ) {
	$failed = true;
	if ( ! $e instanceof RuntimeException ) {
		echo 'FAIL: ' . get_class( $e ) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	wp_set_current_user( 0 );
	unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
	if ( class_exists( 'Eru_CMI_Rate_Limit' ) ) {
		Eru_CMI_Rate_Limit::reset();
	}
	if ( class_exists( 'Eru_GRA_Consent' ) ) {
		foreach ( $emails as $eru_cmi_e ) {
			Eru_GRA_Consent::remove_suppression( $eru_cmi_e ); // RETs written here were mirrored to Growth Automations.
		}
	}
	cmi_cleanup_people( $emails );
	cmi_reset();
	delete_option( 'eru_cmi_rl' );
	if ( null === $bf_marker ) {
		delete_option( 'eru_cmi_gra_backfill' );
	} else {
		update_option( 'eru_cmi_gra_backfill', $bf_marker, true );
	}
	if ( null === $old_settings ) {
		delete_option( Eru_CMI_Settings::OPTION );
	} else {
		Eru_CMI_Settings::update( $old_settings );
	}
	remove_filter( 'pre_http_request', array( $fake, 'route' ), 10 );
}
exit( $failed ? 1 : 0 );
