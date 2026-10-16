<?php
/**
 * Consent Manager for İYS — capture surfaces: checkout box (classic and Store API), registration box,
 * Hesabım tab, e-mail and phone changes, re-consent by e-mail for opted-out addresses, and the privacy
 * exporter/eraser.
 *
 *   wp eval-file tests/php/cmi-capture.php
 */

require __DIR__ . '/cmi-helpers.php';
cmi_require_plugin();
foreach ( array( 'Eru_CMI_Tokens', 'Eru_CMI_Links', 'Eru_CMI_Capture_Checkout', 'Eru_CMI_Capture_Register', 'Eru_CMI_Account', 'Eru_CMI_Privacy' ) as $eru_cmi_c ) {
	if ( ! class_exists( $eru_cmi_c ) ) {
		echo "FAIL: {$eru_cmi_c} missing\n";
		exit( 1 );
	}
}
$fake = cmi_fake_iys();

global $wpdb;
$old_settings = get_option( Eru_CMI_Settings::OPTION, null );
$emails       = array( 'cmi-f1@example.com', 'cmi-f2@example.com', 'cmi-f3@example.com', 'cmi-g@example.com', 'cmi-o@example.com', 'cmi-r@example.com', 'cmi-acc@example.com', 'cmi-acc2@example.com', 'cmi-p@example.com', 'cmi-ret@example.com', 'cmi-ph@example.com', 'cmi-retry@example.com', 'cmi-p-old@example.com' );
$failed       = false;
$mails        = cmi_mail_trap();
add_filter( 'send_email_change_email', '__return_false' );

$status = function ( $r, $t ) {
	$row = Eru_CMI_Repository::get( $r, $t );
	return $row ? $row->status : null;
};

/** A demo order (not paid), billing in Ankara/Çankaya. */
$order = function ( $email, $customer = 0 ) {
	$o = wc_create_order( array( 'customer_id' => $customer ) );
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_last_name( 'Müşteri' );
	$o->set_billing_email( $email );
	$o->set_billing_phone( cmi_phone() );
	$o->set_billing_city( 'Ankara' );
	$o->set_billing_state( 'TR06' );
	$o->set_billing_address_1( 'Demo Sokak 1, Çankaya' );
	$o->set_billing_country( 'TR' );
	$o->save();
	return $o;
};

/** Classic checkout: create-order and processed hooks, with or without the box ticked. */
$classic = function ( WC_Order $o, $ticked ) {
	if ( $ticked ) {
		$_POST['eru_cmi_optin'] = '1';
	} else {
		unset( $_POST['eru_cmi_optin'] );
	}
	do_action( 'woocommerce_checkout_create_order', $o, array() );
	$o->save();
	do_action( 'woocommerce_checkout_order_processed', $o->get_id(), array(), $o );
	unset( $_POST['eru_cmi_optin'] );
	return wc_get_order( $o->get_id() );
};

/** Store API checkout: update-from-request then processed. */
$blocks = function ( WC_Order $o, $ticked ) {
	$req = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$req->set_param( 'additional_fields', $ticked ? array( 'eru-cmi/optin' => true ) : array() );
	do_action( 'woocommerce_store_api_checkout_update_order_from_request', $o, $req );
	$o->save();
	do_action( 'woocommerce_store_api_checkout_order_processed', $o );
	return wc_get_order( $o->get_id() );
};

/** The plugin's box paragraph inside hook output (WooCommerce prints its own fields on the same hooks). */
$box = function ( $html ) {
	return preg_match( '#<p class="[^"]*eru-cmi-optin.*?</p>#s', $html, $m ) ? $m[0] : '';
};

$confirm_token = function ( $mail ) {
	return preg_match( '/eru_cmi_confirm=([A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)?)/', (string) $mail['message'], $m ) ? $m[1] : '';
};

try {
	cmi_reset();
	cmi_cleanup_people( $emails );
	delete_option( Eru_CMI_Settings::OPTION );
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
	wp_set_current_user( 0 );
	cmi_fingerprint( 'capture' );

	// Inert default: no box, no tab, nothing captured.
	ob_start();
	do_action( 'woocommerce_review_order_before_submit' );
	do_action( 'woocommerce_register_form' );
	$out = ob_get_clean();
	$o   = $classic( $order( $emails[0] ), true );
	cmi_check( '[inert] defaults: no checkout or registration box, a ticked POST captures nothing, no account tab', false === strpos( $out, 'eru_cmi_optin' ) && 0 === cmi_count( 'consents' ) && '' === $o->get_meta( '_eru_cmi_optin' ) && ! isset( wc_get_account_menu_items()['iletisim-tercihleri'] ) );
	$o->delete( true );

	cmi_collect();
	ob_start();
	do_action( 'woocommerce_review_order_before_submit' );
	$out = $box( ob_get_clean() );
	cmi_check( '(f) checkout box rendered, unticked by default, with the label', false !== strpos( $out, 'name="eru_cmi_optin"' ) && false === strpos( $out, 'checked' ) && false !== strpos( $out, 'Demo kampanya iletileri almak istiyorum.' ) );

	// (f) Checkout capture.
	$o = $classic( $order( $emails[0] ), false );
	cmi_check( '(f) unticked → no consent row, no queue row, no history, no order meta', 0 === cmi_count( 'consents' ) && 0 === cmi_count( 'queue' ) && 0 === cmi_count( 'history' ) && '' === $o->get_meta( '_eru_cmi_optin' ) );
	$o = $classic( $order( $emails[1] ), true );
	$e = Eru_CMI_Repository::get( $emails[1], 'EPOSTA' );
	$s = Eru_CMI_Repository::get( cmi_phone(), 'MESAJ' );
	cmi_check( '(f) ticked → EPOSTA and MESAJ ONAY, source checkout', $e && $s && 'ONAY' === $e->status && 'ONAY' === $s->status && 'checkout' === $e->source && 'checkout' === $s->source && null === $status( cmi_phone(), 'ARAMA' ) );
	cmi_check( '(f) ... one queue row each, order meta _eru_cmi_optin = yes', 1 === cmi_count( 'queue', 'consent_id = ' . (int) $e->id ) && 1 === cmi_count( 'queue', 'consent_id = ' . (int) $s->id ) && 'yes' === $o->get_meta( '_eru_cmi_optin' ) );
	$h = Eru_CMI_History::for_consent( (int) $e->id );
	cmi_check( '(f) ... history: actor user, IP stored only as a hash', 1 === count( $h ) && 'user' === $h[0]->actor && 64 === strlen( $h[0]->ip_hash ) && false === strpos( $h[0]->ip_hash, '127.0.0.1' ) );
	$texts = get_option( 'eru_cmi_texts', array() );
	cmi_check( '(f) evidence: not verified, hash of the consent text shown (text kept once, with the time), IP hash, source', 0 === (int) $h[0]->verified && 64 === strlen( (string) $h[0]->text_hash ) && isset( $texts[ $h[0]->text_hash ] ) && false !== strpos( $texts[ $h[0]->text_hash ]['t'], 'Demo kampanya iletileri almak istiyorum.' ) && ! empty( $texts[ $h[0]->text_hash ]['d'] ) && 'checkout' === $h[0]->source && '' !== $h[0]->created_at );
	cmi_check( '(f) ... and the phone row carries the same evidence, unverified', 0 === (int) Eru_CMI_History::for_consent( (int) $s->id )[0]->verified && $h[0]->text_hash === Eru_CMI_History::for_consent( (int) $s->id )[0]->text_hash );
	$h0 = cmi_count( 'history' );
	do_action( 'woocommerce_checkout_order_processed', $o->get_id(), array(), $o );
	cmi_check( '(f) processing the same order again writes nothing', $h0 === cmi_count( 'history' ) );
	// A draft whose first attempt was ticked and failed, then retried unticked: the stale tick must not count.
	$d   = $order( $emails[2] );
	$req = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$req->set_param( 'additional_fields', array( 'eru-cmi/optin' => true ) );
	do_action( 'woocommerce_store_api_checkout_update_order_from_request', $d, $req );
	$d->save();
	$o = $blocks( wc_get_order( $d->get_id() ), false );
	cmi_check( '(f) Store API checkout unticked (after a failed ticked attempt on the same draft) → nothing', null === $status( $emails[2], 'EPOSTA' ) && '' === $o->get_meta( '_eru_cmi_optin' ) );
	$c = $order( $emails[2] );
	$_POST['eru_cmi_optin'] = '1';
	do_action( 'woocommerce_checkout_create_order', $c, array() );
	$c->save();
	$o = $classic( wc_get_order( $c->get_id() ), false );
	cmi_check( '(f) classic order resumed unticked after a ticked attempt → nothing', null === $status( $emails[2], 'EPOSTA' ) && '' === $o->get_meta( '_eru_cmi_optin' ) );
	$o = $blocks( $order( $emails[2] ), true );
	cmi_check( '(f) Store API checkout ticked → EPOSTA ONAY, source checkout, order meta', 'ONAY' === $status( $emails[2], 'EPOSTA' ) && 'checkout' === Eru_CMI_Repository::get( $emails[2], 'EPOSTA' )->source && 'yes' === $o->get_meta( '_eru_cmi_optin' ) );
	cmi_collect( array( 'channels' => 'EPOSTA' ) );
	Eru_CMI_Repository::set( cmi_phone(), 'MESAJ', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
	$classic( $order( $emails[5] ), true );
	cmi_check( '(f) only offered channels are captured (SMS off → phone untouched)', 'ONAY' === $status( $emails[5], 'EPOSTA' ) && 'RET' === $status( cmi_phone(), 'MESAJ' ) );
	cmi_collect();

	// (g) Opted-out address: a guest order never lifts the RET; a confirmation mail does it.
	Eru_CMI_Repository::set( $emails[3], 'EPOSTA', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
	$mails->exchangeArray( array() );
	$classic( $order( $emails[3] ), true );
	cmi_check( '(g) guest order, ticked, for an address with RET → still RET', 'RET' === $status( $emails[3], 'EPOSTA' ) );
	cmi_check( '(g) ... SMS RET is not lifted by a checkout either', 'RET' === $status( cmi_phone(), 'MESAJ' ) );
	cmi_check( '(g) ... exactly one re-consent mail to that address, with a signed confirm link', 1 === count( $mails ) && $emails[3] === ( is_array( $mails[0]['to'] ) ? $mails[0]['to'][0] : $mails[0]['to'] ) && '' !== $confirm_token( $mails[0] ) );
	$classic( $order( $emails[3] ), true );
	cmi_check( '(g) a second order the same day sends no second mail', 1 === count( $mails ) && 'RET' === $status( $emails[3], 'EPOSTA' ) );
	$ct   = $confirm_token( $mails[0] );
	$page = Eru_CMI_Links::handle( 'GET', array( 'eru_cmi_confirm' => $ct ) );
	$html = Eru_CMI_Links::kses( $page['html'] );
	cmi_check( '(g) confirm link GET: a form with the hidden token and a button; nothing changes', 200 === $page['status'] && false !== strpos( $html, '<form method="post"' ) && false !== strpos( $html, 'name="eru_cmi_confirm" value="' . $ct . '"' ) && false !== strpos( $html, '<button type="submit"' ) && 'RET' === $status( $emails[3], 'EPOSTA' ) );
	cmi_check( '(g) an opt-out token is not accepted as a confirm token', 400 === Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_confirm' => Eru_CMI_Tokens::mint( $emails[3], 'EPOSTA' ) ) )['status'] && 'RET' === $status( $emails[3], 'EPOSTA' ) );
	$ok = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_confirm' => $ct ) );
	$h  = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( $emails[3], 'EPOSTA' )->id );
	cmi_check( '(g) confirm POST → ONAY (actor user, source link)', 200 === $ok['status'] && 'ONAY' === $status( $emails[3], 'EPOSTA' ) && 'user' === end( $h )->actor && 'link' === end( $h )->source );
	Eru_CMI_Repository::set( $emails[3], 'EPOSTA', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'eru_cmi_history SET created_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s', time() + 5 ), (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . $wpdb->prefix . 'eru_cmi_history' ) ) ); // phpcs:ignore
	$replay = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_confirm' => $ct ) );
	cmi_check( '(g) a confirm link is void once the status changed after it was sent', 400 === $replay['status'] && 'RET' === $status( $emails[3], 'EPOSTA' ) );
	// Being logged in with that account e-mail is no proof (WooCommerce does not verify it): a mail again.
	$owner = cmi_user( $emails[4], 'Demo Sahip' );
	Eru_CMI_Repository::set( $emails[4], 'EPOSTA', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
	$mails->exchangeArray( array() );
	wp_set_current_user( $owner );
	$classic( $order( $emails[4], $owner ), true );
	wp_set_current_user( 0 );
	cmi_check( '(g) logged-in account with that e-mail ticks → still RET, one confirmation mail', 'RET' === $status( $emails[4], 'EPOSTA' ) && 1 === count( $mails ) );

	// Re-consent for an opt-out known only by hash (opt-out link for an address without a record).
	$ph = $emails[10];
	Eru_CMI_Repository::withdraw_hash( Eru_CMI_Recipient::hash( $ph ), 'EPOSTA', array( 'actor' => 'user', 'source' => 'link' ) );
	$prow = Eru_CMI_Repository::get( $ph, 'EPOSTA' );
	cmi_check( '[placeholder] precondition: hash-only RET row', $prow && Eru_CMI_Repository::is_placeholder( $prow ) && 'RET' === $prow->status );
	$mails->exchangeArray( array() );
	$classic( $order( $ph ), true );
	cmi_check( '[placeholder] ticked guest order → still RET, one confirmation mail', 'RET' === $status( $ph, 'EPOSTA' ) && 1 === count( $mails ) && '' !== $confirm_token( $mails[0] ) );
	$plink   = $confirm_token( $mails[0] );
	$page = Eru_CMI_Links::handle( 'GET', array( 'eru_cmi_confirm' => $plink ) );
	cmi_check( '[placeholder] confirm GET: masked address and a button, nothing changed', 200 === $page['status'] && false !== strpos( $page['html'], 'cm***@example.com' ) && false !== strpos( Eru_CMI_Links::kses( $page['html'] ), '<button type="submit"' ) && 'RET' === $status( $ph, 'EPOSTA' ) );
	cmi_check( '[placeholder] the link does not show the address in clear', false === strpos( $plink, 'example' ) && false === strpos( (string) base64_decode( strtr( (string) explode( '.', $plink )[2], '-_', '+/' ) ), 'example' ) );
	$forged = Eru_CMI_Tokens::mint_hash( Eru_CMI_Recipient::hash( $ph ), 'EPOSTA', time() + 3600, 'confirm', $emails[1] );
	cmi_check( '[placeholder] a signed link whose carried address does not match the hash is refused', 400 === Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_confirm' => $forged ) )['status'] && 'RET' === $status( $ph, 'EPOSTA' ) && Eru_CMI_Repository::is_placeholder( Eru_CMI_Repository::get( $ph, 'EPOSTA' ) ) );
	$other = explode( '.', Eru_CMI_Tokens::mint( $emails[1], 'EPOSTA', 'confirm' ) );
	$mine  = explode( '.', $plink );
	cmi_check( '[placeholder] a link whose address part was swapped from another link is refused', 400 === Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_confirm' => $mine[0] . '.' . $mine[1] . '.' . $other[2] ) )['status'] && 'RET' === $status( $ph, 'EPOSTA' ) );
	$ok   = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_confirm' => $plink ) );
	$prow2 = Eru_CMI_Repository::get( $ph, 'EPOSTA' );
	cmi_check( '[placeholder] confirm POST → ONAY on the same row, address restored, verified', 200 === $ok['status'] && 'ONAY' === $prow2->status && (int) $prow->id === (int) $prow2->id && $ph === $prow2->recipient && 1 === (int) current( array_slice( Eru_CMI_History::for_consent( (int) $prow2->id ), -1 ) )->verified );

	// The ticked consent is marked done only once it is written; a failed write is retried.
	$fail = function ( $q ) {
		return ( 0 === stripos( ltrim( $q ), 'INSERT' ) && false !== strpos( $q, 'eru_cmi_consents' ) ) ? 'SELECT * FROM eru_cmi_no_such_table' : $q;
	};
	$prev = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail );
	$o = $classic( $order( $emails[11] ), true );
	remove_filter( 'query', $fail );
	$wpdb->suppress_errors( $prev );
	cmi_check( '[retry] a failed write leaves the order un-captured and schedules one retry', null === $status( $emails[11], 'EPOSTA' ) && '' === $o->get_meta( '_eru_cmi_captured' ) && 1 === count( cmi_pending( 'eru_cmi_capture_order' ) ) );
	do_action( 'eru_cmi_capture_order', $o->get_id() );
	$o = wc_get_order( $o->get_id() );
	cmi_check( '[retry] the retry writes the consent and marks the order', 'ONAY' === $status( $emails[11], 'EPOSTA' ) && '' !== $o->get_meta( '_eru_cmi_captured' ) );

	// Registration box.
	ob_start();
	do_action( 'woocommerce_register_form' );
	$out = $box( ob_get_clean() );
	cmi_check( '[register] box rendered, unticked', false !== strpos( $out, 'name="eru_cmi_optin"' ) && false === strpos( $out, 'checked' ) );
	$uid = cmi_user( 'cmi-reg@example.com', 'Demo Kayıt' );
	unset( $_POST['eru_cmi_optin'] );
	do_action( 'woocommerce_created_customer', $uid, array( 'user_email' => 'cmi-reg@example.com' ), false );
	cmi_check( '[register] unticked → nothing', null === $status( 'cmi-reg@example.com', 'EPOSTA' ) );
	$_POST['eru_cmi_optin'] = '1';
	do_action( 'woocommerce_created_customer', $uid, array( 'user_email' => 'cmi-reg@example.com' ), false );
	unset( $_POST['eru_cmi_optin'] );
	$row = Eru_CMI_Repository::get( 'cmi-reg@example.com', 'EPOSTA' );
	cmi_check( '[register] ticked → EPOSTA ONAY, source register, owner set', $row && 'ONAY' === $row->status && 'register' === $row->source && (int) $uid === (int) $row->user_id );
	wp_delete_user( $uid );

	// Hesabım tab.
	$acc = cmi_user( $emails[6], 'Demo Hesap' );
	update_user_meta( $acc, 'billing_phone', cmi_phone() );
	wp_set_current_user( $acc );
	cmi_check( '[account] endpoint registered as a WooCommerce query var and menu item', in_array( 'iletisim-tercihleri', WC()->query->get_query_vars(), true ) && isset( wc_get_account_menu_items()['iletisim-tercihleri'] ) );
	ob_start();
	do_action( 'woocommerce_account_iletisim-tercihleri_endpoint' );
	$out = ob_get_clean();
	cmi_check( '[account] tab renders a POST form with a nonce and one box per offered channel', false !== strpos( $out, '<form' ) && false !== strpos( $out, 'method="post"' ) && false !== strpos( $out, 'name="_eru_cmi_nonce"' ) && false !== strpos( $out, 'value="EPOSTA"' ) && false !== strpos( $out, 'value="MESAJ"' ) && false === strpos( $out, 'value="ARAMA"' ) );
	$bad = Eru_CMI_Account::process( $acc, array( '_eru_cmi_nonce' => 'nope', 'eru_cmi_channels' => array( 'EPOSTA' ) ) );
	cmi_check( '[account] wrong nonce → error, nothing written', is_wp_error( $bad ) && null === $status( $emails[6], 'EPOSTA' ) );
	$res = Eru_CMI_Account::process( $acc, array( '_eru_cmi_nonce' => wp_create_nonce( 'eru_cmi_account' ), 'eru_cmi_channels' => array( 'EPOSTA' ) ) );
	$sms = Eru_CMI_Repository::get( cmi_phone(), 'MESAJ' );
	cmi_check( '[account] save: EPOSTA ticked → ONAY (source account); SMS unticked → RET', ! is_wp_error( $res ) && 'ONAY' === $status( $emails[6], 'EPOSTA' ) && 'account' === Eru_CMI_Repository::get( $emails[6], 'EPOSTA' )->source && 'RET' === $sms->status );
	$res = Eru_CMI_Account::process( $acc, array( '_eru_cmi_nonce' => wp_create_nonce( 'eru_cmi_account' ), 'eru_cmi_channels' => array( 'EPOSTA', 'MESAJ', 'ARAMA' ) ) );
	cmi_check( '[account] a phone RET cannot be lifted from the tab; channels not offered are ignored (ARAMA)', 'RET' === $status( cmi_phone(), 'MESAJ' ) && 'blocked' === ( $res['MESAJ'] ?? '' ) && null === $status( cmi_phone(), 'ARAMA' ) );
	ob_start();
	do_action( 'woocommerce_account_iletisim-tercihleri_endpoint' );
	$out = ob_get_clean();
	cmi_check( '[account] the tab shows the SMS box disabled with the İYS notice', 1 === preg_match( '#value="MESAJ"[^>]*disabled#', $out ) && false !== strpos( $out, 'İYS üzerinden' ) );
	cmi_reset();
	update_user_meta( $acc, 'billing_phone', cmi_phone() );
	$owner_ctx = null;
	add_action( 'eru_cmi_consent_changed', $grab = function ( $r, $p, $c ) use ( &$owner_ctx ) { $owner_ctx = $c; }, 10, 3 ); // phpcs:ignore
	Eru_CMI_Account::process( $acc, array( '_eru_cmi_nonce' => wp_create_nonce( 'eru_cmi_account' ), 'eru_cmi_channels' => array( 'EPOSTA' ) ) );
	remove_action( 'eru_cmi_consent_changed', $grab, 10 );
	$q = cmi_queue_rows( 'consent_id = ' . (int) Eru_CMI_Repository::get( cmi_phone(), 'MESAJ' )->id );
	cmi_check( '[account] unticking without any earlier ONAY: local RET, queue row skipped (H175)', 'RET' === $status( cmi_phone(), 'MESAJ' ) && 1 === count( $q ) && 'skipped' === $q[0]->status && false !== strpos( (string) $q[0]->last_error, 'H175' ) );
	cmi_check( '[account] account changes carry actor user, no company key (type kept) and are not marked verified', is_array( $owner_ctx ) && 'user' === $owner_ctx['actor'] && ! array_key_exists( 'company', $owner_ctx ) && empty( $owner_ctx['verified'] ) );
	// Only İYS or an admin with a written note may lift a phone RET.
	$e1 = Eru_CMI_Repository::set( cmi_phone(), 'MESAJ', 'ONAY', array( 'actor' => 'user', 'source' => 'account' ) );
	$e2 = Eru_CMI_Repository::set( cmi_phone(), 'MESAJ', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin' ) );
	$e3 = Eru_CMI_Repository::set( cmi_phone(), 'MESAJ', 'ONAY', array( 'actor' => 'api', 'source' => 'api', 'note' => 'Demo' ) );
	cmi_check( '[lift] user, admin without a note and api cannot lift a RET (eru_cmi_reconsent_required)', is_wp_error( $e1 ) && is_wp_error( $e2 ) && is_wp_error( $e3 ) && 'eru_cmi_reconsent_required' === $e1->get_error_code() && 'eru_cmi_reconsent_required' === $e2->get_error_code() && 'RET' === $status( cmi_phone(), 'MESAJ' ) );
	$ok = Eru_CMI_Repository::set( cmi_phone(), 'MESAJ', 'ONAY', array( 'actor' => 'admin', 'source' => 'admin', 'note' => 'Demo: müşteri yazılı izin formu verdi' ) );
	$hh = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( cmi_phone(), 'MESAJ' )->id );
	cmi_check( '[lift] an admin with a note lifts it; the note is kept in the history', ! is_wp_error( $ok ) && 'ONAY' === $status( cmi_phone(), 'MESAJ' ) && 'Demo: müşteri yazılı izin formu verdi' === end( $hh )->note && 'admin' === end( $hh )->actor );

	// E-mail change: consents move to the new address, the old one gets RET.
	wp_update_user( array( 'ID' => $acc, 'user_email' => $emails[7] ) );
	cmi_check( '[email] change: new address ONAY, old address RET', 'ONAY' === $status( $emails[7], 'EPOSTA' ) && 'RET' === $status( $emails[6], 'EPOSTA' ) );
	Eru_CMI_Repository::set( $emails[9], 'EPOSTA', 'RET', array( 'actor' => 'admin', 'source' => 'admin' ) );
	$mails->exchangeArray( array() );
	wp_update_user( array( 'ID' => $acc, 'user_email' => $emails[9] ) );
	cmi_check( '[email] change to an address that has RET: stays RET, a confirmation mail goes to it', 'RET' === $status( $emails[9], 'EPOSTA' ) && 'RET' === $status( $emails[7], 'EPOSTA' ) && 1 === count( $mails ) );
	// Phone change: the old number gets RET only if it had ONAY.
	cmi_check( '[phone] precondition: SMS ONAY', 'ONAY' === $status( cmi_phone(), 'MESAJ' ) );
	update_user_meta( $acc, 'billing_phone', '' );
	cmi_check( '[phone] number removed → old number RET', 'RET' === $status( cmi_phone(), 'MESAJ' ) );
	$h0 = cmi_count( 'history' );
	update_user_meta( $acc, 'billing_phone', cmi_phone() );
	update_user_meta( $acc, 'billing_phone', '' );
	cmi_check( '[phone] a number without ONAY gets no new RET', $h0 === cmi_count( 'history' ) );
	// The account owner ticks e-mail over a RET: a confirmation mail, not a direct ONAY.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_cmi\\_lock\\_rc\\_%'" ); // phpcs:ignore
	$mails->exchangeArray( array() );
	$res = Eru_CMI_Account::process( $acc, array( '_eru_cmi_nonce' => wp_create_nonce( 'eru_cmi_account' ), 'eru_cmi_channels' => array( 'EPOSTA' ) ) );
	cmi_check( '[account] e-mail ticked over a RET → still RET, confirmation mail to the account address', 'RET' === $status( $emails[9], 'EPOSTA' ) && 'confirm_sent' === ( $res['EPOSTA'] ?? '' ) && 1 === count( $mails ) && $emails[9] === ( is_array( $mails[0]['to'] ) ? $mails[0]['to'][0] : $mails[0]['to'] ) );
	wp_set_current_user( 0 );

	// (j) Privacy exporter and eraser.
	cmi_reset();
	$p = cmi_user( $emails[8], 'Demo Gizlilik' );
	update_user_meta( $p, 'billing_phone', cmi_phone() );
	$text = Eru_CMI_Capture_Checkout::consent_text();
	Eru_CMI_Repository::set( $emails[8], 'EPOSTA', 'ONAY', array( 'actor' => 'user', 'source' => 'checkout', 'user_id' => $p, 'text_hash' => $text['hash'], 'ip' => '127.0.0.1' ) );
	Eru_CMI_Repository::set( $emails[12], 'EPOSTA', 'ONAY', array( 'actor' => 'user', 'source' => 'account', 'user_id' => $p ) ); // An earlier account address.
	Eru_CMI_Repository::set( cmi_phone(), 'MESAJ', 'ONAY', array( 'actor' => 'user', 'source' => 'checkout', 'user_id' => $p ) );
	Eru_CMI_Repository::set( cmi_phone(), 'ARAMA', 'ONAY', array( 'actor' => 'user', 'source' => 'account', 'user_id' => $p ) );
	// ARAMA: the ONAY left for İYS; the later RET waits in the queue (a withdrawal İYS must still receive).
	$wpdb->query( 'UPDATE ' . $wpdb->prefix . "eru_cmi_queue SET status = 'sent', request_id = 'demo-req-1', sub_index = 0 WHERE consent_id = " . (int) Eru_CMI_Repository::get( cmi_phone(), 'ARAMA' )->id ); // phpcs:ignore
	Eru_CMI_Repository::set( cmi_phone(), 'ARAMA', 'RET', array( 'actor' => 'user', 'source' => 'account', 'user_id' => $p ) );
	delete_user_meta( $p, 'billing_phone' ); // The number is no longer on the account; the rows still belong to it.
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	cmi_check( '(j) exporter and eraser registered', isset( $exporters['consent-manager-for-iys'] ) && isset( $erasers['consent-manager-for-iys'] ) );
	$ex   = Eru_CMI_Privacy::export( $emails[8], 1 );
	$flat = wp_json_encode( $ex['data'] );
	cmi_check( '(j) exporter: both e-mail rows (current and earlier) and both phone rows (found by owner), with history', true === $ex['done'] && 4 === count( $ex['data'] ) && false !== strpos( $flat, 'MESAJ' ) && false !== strpos( $flat, 'ARAMA' ) && false !== strpos( $flat, $emails[8] ) && false !== strpos( $flat, $emails[12] ) && false !== strpos( $flat, 'ONAY' ) && false !== strpos( $flat, 'RET' ) );
	$uflat = (string) wp_json_encode( $ex['data'], JSON_UNESCAPED_UNICODE );
	cmi_check( '(j) exporter: evidence (consent text, its hash, verified flag, IP hash) is exported', false !== strpos( $uflat, $text['hash'] ) && false !== strpos( $uflat, 'Demo kampanya iletileri almak istiyorum.' ) && false !== strpos( $uflat, 'doğrulanmadı' ) && false !== strpos( $uflat, 'IP özeti' ) );
	$ids = $wpdb->get_col( 'SELECT id FROM ' . $wpdb->prefix . 'eru_cmi_consents' ); // phpcs:ignore
	$er  = Eru_CMI_Privacy::erase( $emails[8], 1 );
	cmi_check( '(j) eraser: items removed, legal record retained with a message', true === $er['items_removed'] && true === $er['items_retained'] && ! empty( $er['messages'] ) && true === $er['done'] );
	$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'eru_cmi_consents ORDER BY id' ); // phpcs:ignore
	$flat = wp_json_encode( $rows );
	cmi_check( '(j) consent rows kept (same ids; active consents withdrawn as RET first) without the e-mail or phone; owner cleared', $ids == wp_list_pluck( $rows, 'id' ) && false === strpos( $flat, $emails[8] ) && false === strpos( $flat, '5550000000' ) && array( 'RET', 'RET', 'RET', 'RET' ) === wp_list_pluck( $rows, 'status' ) && false === strpos( $flat, $emails[12] ) && array( 0 ) === array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'user_id' ) ) ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$hist = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'eru_cmi_history' ); // phpcs:ignore
	cmi_check( '(j) history keeps only hashes (no actor user id, no address)', count( $hist ) >= 4 && array( 0 ) === array_values( array_unique( array_map( 'intval', wp_list_pluck( $hist, 'actor_id' ) ) ) ) && false === strpos( wp_json_encode( $hist ), $emails[8] ) );
	$open = cmi_queue_rows( "status IN ('queued','sent')" );
	$done = cmi_queue_rows( "status NOT IN ('queued','sent')" );
	cmi_check( '(j) queue: rows no longer needed are anonymised (unsent ONAY superseded), the pending RET to İYS and the sent row awaiting its result are kept', false === strpos( wp_json_encode( $done ), $emails[8] ) && false === strpos( wp_json_encode( $done ), '5550000000' ) && 2 === count( $open ) && 'RET' === json_decode( end( $open )->payload, true )['status'] );
	cmi_check( '(j) eru_cmi_is_consented() by address still answers from the kept record (now RET)', false === eru_cmi_is_consented( $emails[8], 'EPOSTA' ) && false === eru_cmi_is_consented( cmi_phone(), 'ARAMA' ) );
	$after = wp_json_encode( Eru_CMI_Privacy::export( $emails[8], 1 )['data'] );
	cmi_check( '(j) exporter after erasure: the kept record without any address', false === strpos( $after, $emails[8] ) && false === strpos( $after, '5550000000' ) && false !== strpos( $after, 'anonimle' ) );

	echo "ALL PASS\n";
} catch ( Throwable $e ) {
	$failed = true;
	if ( ! $e instanceof RuntimeException ) {
		echo 'FAIL: ' . get_class( $e ) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	wp_set_current_user( 0 );
	unset( $_POST['eru_cmi_optin'] );
	if ( class_exists( 'Eru_GRA_Consent' ) ) {
		foreach ( $emails as $eru_cmi_e ) {
			Eru_GRA_Consent::remove_suppression( $eru_cmi_e ); // RETs written here were mirrored to Growth Automations.
		}
	}
	cmi_cleanup_people( array_merge( $emails, array( 'cmi-reg@example.com' ) ) );
	cmi_reset();
	delete_option( 'eru_cmi_rl' );
	delete_option( 'eru_cmi_texts' );
	if ( null === $old_settings ) {
		delete_option( Eru_CMI_Settings::OPTION );
	} else {
		Eru_CMI_Settings::update( $old_settings );
	}
	remove_filter( 'pre_http_request', array( $fake, 'route' ), 10 );
}
exit( $failed ? 1 : 0 );
