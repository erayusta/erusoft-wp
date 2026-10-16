<?php
/**
 * VakıfBank Vinov Integration — process_payment: refuses without a bank-verified session for the posted TCKN
 * and the current total, ignores posted "verified" flags, clamps installments, adds the commission as a fee
 * line so the instruction amount equals the order total, looks an ambiguous create up before retrying,
 * stores only a TCKN mask, single-use verification, no TCKN or secret in logs or order notes.
 *
 *   wp plugin activate vakifbank-vinov-integration-for-woocommerce
 *   wp eval-file tests/php/vnv-payment.php
 */

require __DIR__ . '/vnv-helpers.php';
vnv_require_plugin();

$bank = vnv_install_bank();
$logs = vnv_capture_logs();
wc_load_cart();

$old_settings = get_option( Eru_VNV_Settings::OPTION, null );
$old_flag     = get_option( Eru_VNV_Settings::FLAG, null );
$failed       = false;
$gw           = null;

$codes = array();
add_action(
	'eru_vnv_payment_refused',
	function ( $id, $code ) use ( &$codes ) {
		$codes[] = $code;
	},
	10,
	2
);
$why = function () use ( &$codes ) {
	return (string) end( $codes );
};

$fee_lines = function ( WC_Order $o ) {
	return array_values( array_filter( $o->get_fees(), fn( $f ) => 0 === strpos( $f->get_name(), 'Vinov taksit farkı' ) ) );
};

try {
	vnv_purge( true );
	vnv_clear_log_files();
	vnv_reset();
	vnv_settings();
	$gw   = new Eru_VNV_Gateway();
	$p1   = vnv_product( 1000 );
	$p2   = vnv_product( 200, 'Demo Ek Ürün' );
	$tckn = vnv_fake_tckn();

	vnv_check( 'gateway id eru_vnv, products only', 'eru_vnv' === $gw->id && array( 'products' ) === $gw->supports );

	// --- (a) posted "verified" flags without a session verification ----------------------------------
	vnv_cart( array( $p1 => 1 ) );
	$order = vnv_checkout_order();
	$bank->reset();
	vnv_post( $tckn, 1, array( 'otp_ok' => '1', 'eru_vnv_otp_verified' => '1', 'eru_vnv_verified' => '1', 'otp_verified' => '1' ) );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(a) posted *_verified=1 without session verification → refused (not_verified)', 'failure' === ( $r['result'] ?? '' ) && true === ( $r['refresh'] ?? null ) && 'not_verified' === $why() && 0 === $bank->total() && count( vnv_errors() ) > 0 );
	vnv_check( '(a) order untouched (no instruction, still pending)', '' === wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) && 'pending' === wc_get_order( $order->get_id() )->get_status() );
	Eru_VNV_OTP_Controller::send( array( 'tckn' => $tckn, 'phone' => vnv_phone() ) );
	$bank->reset();
	vnv_post( $tckn, 1, array( 'eru_vnv_otp' => '123456', 'otp_verified' => '1' ) );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(a) code sent but never verified at the bank → refused (not_verified), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'not_verified' === $why() && 0 === $bank->total() );
	vnv_errors();
	$_POST = array();
	vnv_check( 'validate_fields refuses missing TCKN before an order exists', false === $gw->validate_fields() && count( vnv_errors() ) > 0 );

	// --- (b) cart changed after verification ----------------------------------------------------------
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	$v = vnv_verify( $tckn );
	vnv_check( '(b) verified for the invented 1.000', ! empty( $v['ok'] ) && 100000 === (int) WC()->session->get( Eru_VNV_Session::KEY )['total_minor'] );
	WC()->cart->add_to_cart( $p2, 1 );
	WC()->cart->calculate_totals();
	$order = vnv_checkout_order();
	vnv_check( '(b) order total now 1.200', 120000 === Eru_VNV_Installments::to_minor( $order->get_total() ) );
	$bank->reset();
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(b) total changed 1.000 → 1.200 → refused (total_changed), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'total_changed' === $why() && 0 === $bank->total() && count( vnv_errors() ) > 0 );

	// Same total, other cart contents (hash differs).
	vnv_reset();
	vnv_cart( array( $p2 => 5 ) );
	vnv_verify( $tckn );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $p1, 1 );
	WC()->cart->calculate_totals();
	$order = vnv_checkout_order();
	$bank->reset();
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(b) same total but another cart (hash mismatch) → refused (cart_changed), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'cart_changed' === $why() && 0 === $bank->total() );
	vnv_errors();

	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$other = vnv_fake_tckn();
	while ( $other === $tckn ) {
		$other = vnv_fake_tckn();
	}
	$bank->reset();
	vnv_post( $other );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(b) posted TCKN differs from the verified one → refused (tckn_mismatch), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'tckn_mismatch' === $why() && 0 === $bank->total() );
	vnv_errors();
	vnv_post( $tckn, 1, array( 'eru_vnv_phone' => '0' . '555 000 00 01' ) );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(b) posted phone differs from the one the code went to → refused (phone_mismatch), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'phone_mismatch' === $why() && 0 === $bank->total() );
	vnv_errors();

	$sess                = WC()->session->get( Eru_VNV_Session::KEY );
	$sess['verified_at'] = time() - 601;
	WC()->session->set( Eru_VNV_Session::KEY, $sess );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(b) verification older than 10 minutes → refused (expired), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'expired' === $why() && 0 === $bank->total() );
	vnv_errors();

	// --- (d) installments clamp -----------------------------------------------------------------------
	vnv_reset();
	vnv_cart( array( $p1 => 1 ), 12 );
	vnv_check( '(d) session asks 12 installments with max 6 → no fee (clamped to a single payment)', 100000 === Eru_VNV_Installments::to_minor( WC()->cart->get_total( 'edit' ) ) && 0 === count( WC()->cart->get_fees() ) );
	vnv_cart( array( $p1 => 1 ), 4 );
	vnv_check( '(d) disabled rate (4) → no fee', 0 === count( WC()->cart->get_fees() ) );
	vnv_check( '(d) allowed counts are 1 plus enabled rates up to the maximum', array( 1, 2, 3, 6 ) === Eru_VNV_Installments::allowed() );
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	vnv_post( $tckn, 12 );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(d) installments posted 12 with max 6 → refused (installments_invalid), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'installments_invalid' === $why() && 0 === $bank->total() );
	vnv_errors();
	vnv_post( $tckn, 4 );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(d) disabled installment count posted → refused (installments_invalid), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'installments_invalid' === $why() && 0 === $bank->total() );
	vnv_errors();
	vnv_post( $tckn, 3 );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(d) installments other than the verified count → refused (installments_changed), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'installments_changed' === $why() && 0 === $bank->total() );
	vnv_errors();

	// --- fee only with this gateway ------------------------------------------------------------------
	vnv_cart( array( $p1 => 1 ), 3 );
	WC()->session->set( 'chosen_payment_method', 'bacs' );
	WC()->cart->calculate_totals();
	vnv_check( 'fee only when Vinov is the chosen method', 0 === count( WC()->cart->get_fees() ) );

	// --- (c) happy path with 3 installments at 4 % ----------------------------------------------------
	vnv_reset();
	vnv_cart( array( $p1 => 1 ), 3 );
	$fees = array_values( WC()->cart->get_fees() );
	vnv_check( '(c) cart fee "Vinov taksit farkı (3 taksit, %4)" = 40, not taxable', 1 === count( $fees ) && 'Vinov taksit farkı (3 taksit, %4)' === $fees[0]->name && 4000 === Eru_VNV_Installments::to_minor( $fees[0]->amount ) && ! $fees[0]->taxable );
	$v = vnv_verify( $tckn, 3 );
	vnv_check( '(c) verified with 3 installments', ! empty( $v['ok'] ) );
	$order = vnv_checkout_order();
	vnv_check( '(c) order total 1.040 with one fee line of 40', 104000 === Eru_VNV_Installments::to_minor( $order->get_total() ) && 1 === count( $fee_lines( $order ) ) && 4000 === Eru_VNV_Installments::to_minor( $fee_lines( $order )[0]->get_total() ) );
	$bank->reset();
	vnv_post( $tckn, 3, array( 'eru_vnv_otp' => '123456' ) );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(c) success with a redirect to the thank-you page', 'success' === ( $r['result'] ?? '' ) && false !== strpos( (string) $r['redirect'], 'order-received' ) );
	$c = $bank->calls( '/vinov/createInstruction' );
	vnv_check( '(c) exactly one createInstruction, no other API call', 1 === count( $c ) && 1 === count( $bank->reqs ) );
	$b = $c[0]['body'];
	vnv_check( '(c) instruction amount 1.040 = order total, 3 installments', 1040.0 === (float) $b['InstructionAmount'] && 3 === (int) $b['NumberofInstallments'] && 1 === (int) $b['InstructionCreationType'] && $tckn === (string) $b['IdentityTaxNumber'] && 1 === (int) $b['WorkPlaceId'] && 3 === (int) $b['PaymentPurpose'] );
	$plan_minor = array_map( fn( $i ) => Eru_VNV_Installments::to_minor( $i['DebtAmount'] ), (array) ( $b['InstallmentPlan'] ?? array() ) );
	vnv_check( '(c) installment plan has 3 parts summing exactly to 1.040', 3 === count( $plan_minor ) && 104000 === array_sum( $plan_minor ) && max( $plan_minor ) - min( $plan_minor ) <= 1 && ! empty( $b['InstallmentPlan'][0]['MaturityDate'] ) && ! isset( $b['ExpiryDate'] ) );
	vnv_check( '(c) invoice number is the order number, description carries the attempt key', (string) $order->get_order_number() === (string) $b['InvoiceNumber'] && false !== strpos( (string) $b['InstructionDescription'], (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_attempt' ) ) );
	$o = wc_get_order( $order->get_id() );
	vnv_check( '(c) instruction id, installments and amount stored', (string) $bank->instruction === (string) $o->get_meta( '_eru_vnv_instruction_id' ) && 3 === (int) $o->get_meta( '_eru_vnv_installments' ) && 'test' === $o->get_meta( '_eru_vnv_env' ) );
	$mask = substr( $tckn, 0, 2 ) . str_repeat( '*', 7 ) . substr( $tckn, -2 );
	vnv_check( '(c) TCKN only as a mask (first 2 + last 2) in meta', $mask === $o->get_meta( '_eru_vnv_tckn_mask' ) && false === strpos( vnv_meta_dump( $o->get_id() ), $tckn ) );
	vnv_check( '(c) order status on-hold, cart emptied', 'on-hold' === $o->get_status() && WC()->cart->is_empty() );
	vnv_check( '(c) verification is single-use (cleared)', empty( WC()->session->get( Eru_VNV_Session::KEY ) ) );
	$notes = vnv_notes( $o->get_id() );
	vnv_check( '(h) order note names the instruction id, no TCKN, no secret', false !== strpos( $notes, (string) $bank->instruction ) && false === strpos( $notes, $tckn ) && false === strpos( $notes, vnv_secret( 'test' ) ) );

	// Replay: same session, new order → refused (single use).
	vnv_cart( array( $p1 => 1 ), 3 );
	$order2 = vnv_checkout_order();
	$bank->reset();
	vnv_post( $tckn, 3 );
	$r = $gw->process_payment( $order2->get_id() );
	vnv_check( 'single-use: a second payment with the same verification → refused (not_verified), no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'not_verified' === $why() && 0 === $bank->total() );
	vnv_errors();

	// Single payment: expiry date, no plan.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	$b = $bank->calls( '/vinov/createInstruction' )[0]['body'] ?? array();
	vnv_check( 'single payment: amount 1.000, ExpiryDate set, no InstallmentPlan, no fee line', 'success' === ( $r['result'] ?? '' ) && 1000.0 === (float) $b['InstructionAmount'] && 1 === (int) $b['NumberofInstallments'] && ! empty( $b['ExpiryDate'] ) && ! isset( $b['InstallmentPlan'] ) && 0 === count( $fee_lines( wc_get_order( $order->get_id() ) ) ) );

	// Lock held by a concurrent request → refused without HTTP.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	add_option( 'eru_vnv_lock_order_' . $order->get_id(), time() . '|other', '', 'no' );
	$bank->reset();
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'concurrent payment of the same order (lock held) → refused (busy), no HTTP, verification kept', 'failure' === ( $r['result'] ?? '' ) && 'busy' === $why() && 0 === $bank->total() && ! empty( WC()->session->get( Eru_VNV_Session::KEY )['verified_at'] ) );
	vnv_errors();
	delete_option( 'eru_vnv_lock_order_' . $order->get_id() );
	add_option( 'eru_vnv_lock_order_' . $order->get_id(), ( time() - 250 ) . '|other', '', 'no' );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'a 250-second-old lock is still held (TTL above the worst-case run) → busy, no HTTP', 'failure' === ( $r['result'] ?? '' ) && 'busy' === $why() && 0 === $bank->total() );
	vnv_errors();
	delete_option( 'eru_vnv_lock_order_' . $order->get_id() );

	// --- (e) ambiguous create: lookup before retry ----------------------------------------------------
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 45000 milliseconds' ) );
	$bank->push(
		'/vinov/listInstruction',
		function ( $body ) use ( $order ) {
			$o = wc_get_order( $order->get_id() );
			return VNV_Fake_Bank::resp( 200, vnv_fixture( 'list-instruction.json', array( '{{ID}}' => '700555', '{{INVOICE}}' => (string) $o->get_order_number(), '{{DESCRIPTION}}' => 'Sipariş ' . $o->get_order_number() . ' / ' . $o->get_meta( '_eru_vnv_attempt' ), '{{AMOUNT}}' => '1000.0' ) ) );
		}
	);
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(e) timeout → lookup finds the instruction → success without a second create', 'success' === ( $r['result'] ?? '' ) && 1 === $bank->count( '/vinov/createInstruction' ) && 1 === $bank->count( '/vinov/listInstruction' ) && '700555' === (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );
	vnv_check( '(e) the attempt key was saved before the call', '' !== (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_attempt' ) );

	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', array( 503, vnv_fixture( 'error-generic.json' ) ) );
	vnv_post( $tckn );
	$r  = $gw->process_payment( $order->get_id() );
	$cc = $bank->calls( '/vinov/createInstruction' );
	vnv_check( '(e) 5xx → lookup absent → exactly one retry → success', 'success' === ( $r['result'] ?? '' ) && 2 === count( $cc ) && 1 === $bank->count( '/vinov/listInstruction' ) && (string) $bank->instruction === (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );
	vnv_check( '(e) the retry reuses the same attempt key', $cc[0]['body']['InstructionDescription'] === $cc[1]['body']['InstructionDescription'] );

	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	$bank->push( '/vinov/listInstruction', array( 500, vnv_fixture( 'error-generic.json' ) ) );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(e) timeout and the lookup fails → refused (ambiguous), no blind retry', 'failure' === ( $r['result'] ?? '' ) && 'ambiguous' === $why() && 1 === $bank->count( '/vinov/createInstruction' ) && '' === wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) && 'ambiguous' === wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_attempt_state' ) );
	vnv_errors();
	// The customer verifies again and retries the same order: lookup first, found → no create.
	vnv_reset();
	vnv_verify( $tckn );
	WC()->session->set( 'order_awaiting_payment', $order->get_id() );
	$bank->reset();
	$bank->push(
		'/vinov/listInstruction',
		function () use ( $order ) {
			$o = wc_get_order( $order->get_id() );
			return VNV_Fake_Bank::resp( 200, vnv_fixture( 'list-instruction.json', array( '{{ID}}' => '700777', '{{INVOICE}}' => (string) $o->get_order_number(), '{{DESCRIPTION}}' => 'Sipariş ' . $o->get_order_number() . ' / ' . $o->get_meta( '_eru_vnv_attempt' ), '{{AMOUNT}}' => '1000.0' ) ) );
		}
	);
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( '(e) next attempt on an ambiguous order looks up first and adopts the instruction, no create', 'success' === ( $r['result'] ?? '' ) && 0 === $bank->count( '/vinov/createInstruction' ) && '700777' === (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );

	// Bank refuses the create outright → failure, state failed, verification spent.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', array( 200, vnv_fixture( 'error-generic.json' ) ) );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'bank refusal (non-success header) → failure (bank_refused), no lookup, no retry', 'failure' === ( $r['result'] ?? '' ) && 'bank_refused' === $why() && 1 === $bank->count( '/vinov/createInstruction' ) && 0 === $bank->count( '/vinov/listInstruction' ) && 'failed' === wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_attempt_state' ) );
	vnv_errors();

	// --- Fix round 1: one verification creates exactly one instruction ------------------------------
	$answer = function ( $fixture, $order, $id, $status_desc = null ) {
		return function () use ( $fixture, $order, $id, $status_desc ) {
			$o = wc_get_order( $order->get_id() );
			return VNV_Fake_Bank::resp( 200, vnv_fixture( $fixture, array( '{{ID}}' => (string) $id, '{{INVOICE}}' => (string) $o->get_order_number(), '{{DESCRIPTION}}' => null === $status_desc ? 'Sipariş ' . $o->get_order_number() . ' / ' . $o->get_meta( '_eru_vnv_attempt' ) : $status_desc, '{{AMOUNT}}' => '1000.0' ) ) );
		};
	};

	// P1: request B loaded the session before request A consumed the verification; B created its own order.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$snapshot = WC()->session->get( Eru_VNV_Session::KEY );
	vnv_check( 'every verification carries a random id', is_string( $snapshot['vid'] ?? null ) && strlen( $snapshot['vid'] ) >= 16 );
	$order_a = vnv_checkout_order();
	$bank->reset();
	vnv_post( $tckn );
	$ra = $gw->process_payment( $order_a->get_id() );
	vnv_check( 'P1: the claim row for the verification id exists after use', false !== get_option( 'eru_vnv_vused_' . $snapshot['vid'], false ) );
	WC()->session->set( Eru_VNV_Session::KEY, $snapshot );
	vnv_cart( array( $p1 => 1 ) );
	WC()->session->set( Eru_VNV_Session::KEY, $snapshot );
	$order_b = vnv_checkout_order();
	vnv_post( $tckn );
	$rb = $gw->process_payment( $order_b->get_id() );
	vnv_check( 'P1: two orders on one verification → exactly one createInstruction, B refused (not_verified)', 'success' === ( $ra['result'] ?? '' ) && 'failure' === ( $rb['result'] ?? '' ) && 'not_verified' === $why() && 1 === $bank->count( '/vinov/createInstruction' ) && '' === wc_get_order( $order_b->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );
	vnv_errors();

	// P2: the same order, a second request holding a stale order object and its own fresh verification.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$stale = wc_get_order( $order->get_id() );
	$bank->reset();
	vnv_post( $tckn );
	$r1 = $gw->process_payment( $order->get_id() );
	$id1 = (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' );
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$bank->reset();
	vnv_post( $tckn );
	$r2 = Eru_VNV_Payment::process( $stale, array( 'tckn' => $tckn, 'phone' => vnv_phone(), 'installments' => '1' ) );
	vnv_check( 'P2: same order with a stale object → refused (already_created) inside the lock, no second create, id kept', 'success' === ( $r1['result'] ?? '' ) && ! $r2['ok'] && 'already_created' === $r2['code'] && 0 === $bank->count( '/vinov/createInstruction' ) && $id1 === (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) && '' !== $id1 );
	vnv_check( 'P2: the fresh verification was not consumed by the refusal', ! empty( WC()->session->get( Eru_VNV_Session::KEY )['verified_at'] ) );

	// Unparsed reply (HTTP 200 with an HTML page) is ambiguous, never a refusal.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', array( 200, vnv_fixture( 'html-page.html' ) ) );
	$bank->push( '/vinov/listInstruction', array( 500, vnv_fixture( 'error-generic.json' ) ) );
	vnv_post( $tckn );
	$r   = $gw->process_payment( $order->get_id() );
	$key = (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_attempt' );
	vnv_check( 'HTML 200 on create → ambiguous: lookup runs, no blind retry, state ambiguous', 'failure' === ( $r['result'] ?? '' ) && 'ambiguous' === $why() && 1 === $bank->count( '/vinov/createInstruction' ) && 1 === $bank->count( '/vinov/listInstruction' ) && 'ambiguous' === wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_attempt_state' ) );
	vnv_errors();
	vnv_reset();
	vnv_verify( $tckn );
	$bank->reset();
	$bank->push( '/vinov/listInstruction', $answer( 'list-instruction.json', $order, 700888 ) );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'HTML 200: the next attempt keeps the attempt key, looks up first and adopts, no create', 'success' === ( $r['result'] ?? '' ) && 0 === $bank->count( '/vinov/createInstruction' ) && $key === (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_attempt' ) && '700888' === (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );

	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', array( 200, '' ) );
	$bank->push( '/vinov/listInstruction', $answer( 'list-instruction.json', $order, 700889 ) );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'empty 200 on create → lookup finds it → adopted, one create', 'success' === ( $r['result'] ?? '' ) && 1 === $bank->count( '/vinov/createInstruction' ) && '700889' === (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );

	// Lookup never adopts a cancelled instruction; a match without a description stays ambiguous.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	$bank->push( '/vinov/listInstruction', $answer( 'list-instruction-cancelled.json', $order, 700990 ) );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'lookup: cancelled instruction (status 3) is not adopted → absent → one retry creates', 'success' === ( $r['result'] ?? '' ) && 2 === $bank->count( '/vinov/createInstruction' ) && '700990' !== (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );

	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	$bank->push( '/vinov/listInstruction', $answer( 'list-instruction-nodesc.json', $order, 700991 ) );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'lookup: same invoice but no description → not adopted, no retry, state ambiguous', 'failure' === ( $r['result'] ?? '' ) && 'ambiguous' === $why() && 1 === $bank->count( '/vinov/createInstruction' ) && '' === wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) && 'ambiguous' === wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_attempt_state' ) );
	vnv_errors();

	// Month-end maturities clamp to the last day of the month.
	$from = new DateTimeImmutable( '2027-01-31 15:20:00', new DateTimeZone( 'Europe/Istanbul' ) );
	$plan = Eru_VNV_Installments::plan( 100000, 4, $from );
	vnv_check( 'plan from Jan 31: Feb 28, Mar 31, Apr 30, May 31 (midnight, Istanbul)', array( '2027-02-28T00:00:00+03:00', '2027-03-31T00:00:00+03:00', '2027-04-30T00:00:00+03:00', '2027-05-31T00:00:00+03:00' ) === array_column( $plan, 'maturity' ) && 100000 === array_sum( array_column( $plan, 'amount_minor' ) ) );

	// N1: the in-lock reload must not be served from the order cache.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$held  = wc_get_order( $order->get_id() );
	wc_get_order( $order->get_id() ); // Prime the cache.
	global $wpdb;
	if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$wpdb->insert( $wpdb->prefix . 'wc_orders_meta', array( 'order_id' => $order->get_id(), 'meta_key' => '_eru_vnv_instruction_id', 'meta_value' => '700444' ) );
	} else {
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $order->get_id(), 'meta_key' => '_eru_vnv_instruction_id', 'meta_value' => '700444' ) );
	}
	$bank->reset();
	$r = Eru_VNV_Payment::process( $held, array( 'tckn' => $tckn, 'phone' => vnv_phone(), 'installments' => '1' ) );
	vnv_check( 'N1: instruction written by another process (cache primed) → already_created, no bank call', ! $r['ok'] && 'already_created' === $r['code'] && 0 === $bank->total() );

	// N3: a success header without Result.IsSucceeded is ambiguous → lookup, key kept.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', array( 200, '{"Header":{"StatusCode":"APIGW000000"},"Data":{}}' ) );
	$bank->push( '/vinov/listInstruction', $answer( 'list-instruction.json', $order, 700556 ) );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'N3: success header without a result → lookup → adopted, one create', 'success' === ( $r['result'] ?? '' ) && 1 === $bank->count( '/vinov/createInstruction' ) && 1 === $bank->count( '/vinov/listInstruction' ) && '700556' === (string) wc_get_order( $order->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	$bank->reset();
	$bank->push( '/vinov/createInstruction', array( 200, '{"Header":{"StatusCode":"APIGW000000"},"Data":{"Result":{"IsSucceeded":false}}}' ) );
	vnv_post( $tckn );
	$r = $gw->process_payment( $order->get_id() );
	vnv_check( 'N3: explicit IsSucceeded false stays a definitive refusal (no lookup), with refresh', 'failure' === ( $r['result'] ?? '' ) && 'bank_refused' === $why() && 0 === $bank->count( '/vinov/listInstruction' ) && true === ( $r['refresh'] ?? null ) );
	vnv_errors();

	// N5: the busy message does not promise seconds.
	vnv_reset();
	vnv_cart( array( $p1 => 1 ) );
	vnv_verify( $tckn );
	$order = vnv_checkout_order();
	add_option( 'eru_vnv_lock_order_' . $order->get_id(), time() . '|other', '', 'no' );
	$r = Eru_VNV_Payment::process( wc_get_order( $order->get_id() ), array( 'tckn' => $tckn, 'phone' => vnv_phone(), 'installments' => '1' ) );
	vnv_check( 'N5: busy message asks to check the order in a few minutes', 'busy' === $r['code'] && 'Bu sipariş için ödeme işlemi sürüyor. Lütfen birkaç dakika sonra sipariş durumunu kontrol edin.' === $r['message'] );
	delete_option( 'eru_vnv_lock_order_' . $order->get_id() );

	// N4: the filter output is re-checked; an invalid admin entry is refused with an error.
	add_filter( 'eru_vnv_api_base', fn() => 'http://vinov-api.example.test' );
	vnv_check( 'N4: a filter returning http → not ready', '' === Eru_VNV_Settings::cred( 'base_url' ) && ! Eru_VNV_Settings::ready() );
	remove_all_filters( 'eru_vnv_api_base' );
	$g4 = new Eru_VNV_Gateway();
	$kept = $g4->validate_test_base_url_field( 'test_base_url', 'ftp://vinov-api.example.test/x' );
	vnv_check( 'N4: an invalid admin URL keeps the previous value and adds a settings error', VNV_Fake_Bank::BASE['test'] === $kept && count( $g4->get_errors() ) > 0 );
	vnv_check( 'N4: an empty admin URL clears it, a valid one is cleaned', '' === $g4->validate_test_base_url_field( 'test_base_url', '' ) && 'https://vinov-api.example.test/v1' === $g4->validate_test_base_url_field( 'test_base_url', ' https://VINOV-API.example.test/v1/ ' ) );

	// No default host: an empty base URL keeps the gateway unconfigured.
	vnv_settings( array( 'test_base_url' => '' ) );
	vnv_check( 'no default host: empty test base URL → not ready, flag 0', ! Eru_VNV_Settings::ready() && '0' === get_option( Eru_VNV_Settings::FLAG ) && '' === Eru_VNV_Settings::cred( 'base_url' ) );
	vnv_settings( array( 'test_base_url' => 'http://vinov-api.example.test' ) );
	vnv_check( 'a non-https base URL is not accepted', ! Eru_VNV_Settings::ready() );
	vnv_settings();
	vnv_check( 'the configured base URL is used', Eru_VNV_Settings::ready() && VNV_Fake_Bank::BASE['test'] === Eru_VNV_Settings::cred( 'base_url' ) && in_array( 'vinov-api.example.test', $bank->hosts, true ) );

	// --- settings: write-only secrets, autoload off, inert when switched off -------------------------
	global $wpdb;
	$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Eru_VNV_Settings::OPTION ) );
	vnv_check( 'settings option is not autoloaded', in_array( $autoload, array( 'no', 'off' ), true ) );
	$gw   = new Eru_VNV_Gateway();
	$html = $gw->generate_eru_vnv_secret_html( 'test_client_secret', array( 'title' => 'Secret' ) );
	vnv_check( 'secret field renders empty with a "Kayıtlı" hint', false === strpos( $html, vnv_secret( 'test' ) ) && false !== strpos( $html, 'Kayıtlı' ) && false !== strpos( $html, 'value=""' ) );
	$html = $gw->generate_eru_vnv_secret_html( 'test_client_id', array( 'title' => 'Client' ) );
	vnv_check( 'client id field renders empty too', false === strpos( $html, vnv_client( 'test' ) ) );
	$gw->set_post_data( array() );
	vnv_check( 'blank secret keeps the stored value', vnv_secret( 'test' ) === $gw->validate_eru_vnv_secret_field( 'test_client_secret', '' ) );
	vnv_check( 'a new secret replaces it', 'demo-new-value' === $gw->validate_eru_vnv_secret_field( 'test_client_secret', ' demo-new-value ' ) );
	$gw->set_post_data( array( $gw->get_field_key( 'test_client_secret' ) . '_clear' => '1' ) );
	vnv_check( '"Kayıtlı değeri sil" clears it', '' === $gw->validate_eru_vnv_secret_field( 'test_client_secret', '' ) );
	vnv_settings( array( 'enabled' => 'no' ) );
	vnv_cart( array( $p1 => 1 ), 3 );
	vnv_check( 'switched off: flag 0, no fee, gateway unavailable', '0' === get_option( Eru_VNV_Settings::FLAG ) && 0 === count( WC()->cart->get_fees() ) && ! ( new Eru_VNV_Gateway() )->is_available() );
	vnv_settings( array( 'consent_id' => '' ) );
	vnv_check( 'enabled but incomplete (no consent id): flag 0, unavailable', '0' === get_option( Eru_VNV_Settings::FLAG ) && ! ( new Eru_VNV_Gateway() )->is_available() );
	vnv_settings();
	add_filter( 'eru_vnv_checkout_uses_blocks', '__return_true' );
	vnv_check( 'block checkout: unavailable', ! ( new Eru_VNV_Gateway() )->is_available() );
	remove_filter( 'eru_vnv_checkout_uses_blocks', '__return_true' );
	add_filter( 'eru_vnv_checkout_uses_blocks', '__return_false' );
	vnv_check( 'enabled and configured, classic checkout: flag 1, available with a TRY cart', '1' === get_option( Eru_VNV_Settings::FLAG ) && 'TRY' === get_woocommerce_currency() && ( new Eru_VNV_Gateway() )->is_available() );
	vnv_settings( array( 'allowed_products' => (string) $p2 ) );
	vnv_check( 'allow-list: a cart item outside it hides the gateway', ! ( new Eru_VNV_Gateway() )->is_available() );
	vnv_settings( array( 'allowed_products' => (string) $p1 ) );
	vnv_check( 'allow-list: every item listed → available', ( new Eru_VNV_Gateway() )->is_available() );
	$term = wp_insert_term( 'Demo Vinov Kategori ' . VNV_RUN, 'product_cat' );
	$tid  = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
	wp_set_object_terms( $p1, array( $tid ), 'product_cat' );
	vnv_check( 'category field keeps only ids', array( (string) $tid ) === $gw->validate_eru_vnv_terms_field( 'allowed_categories', array( (string) $tid, 'x', '0' ) ) );
	vnv_settings( array( 'allowed_products' => '', 'allowed_categories' => array( (string) $tid ) ) );
	vnv_cart( array( $p1 => 1 ) );
	$in = ( new Eru_VNV_Gateway() )->is_available();
	WC()->cart->add_to_cart( $p2, 1 );
	$out = ( new Eru_VNV_Gateway() )->is_available();
	wp_delete_term( $tid, 'product_cat' );
	vnv_check( 'category allow-list: listed category available, an item outside hides it', $in && ! $out );
	remove_filter( 'eru_vnv_checkout_uses_blocks', '__return_false' );
	vnv_settings( array( 'allowed_categories' => array() ) );
	WC()->cart->empty_cart();

	// --- (h) logs and notes ---------------------------------------------------------------------------
	$all = implode( "\n", (array) $logs->getArrayCopy() ) . "\n" . vnv_log_files_text();
	vnv_check( '(h) the plugin logged the payment calls', false !== strpos( $all, '/vinov/createInstruction' ) );
	vnv_check( '(h) logs hold no TCKN of this run', false === strpos( $all, $tckn ) && false === strpos( $all, $other ) );
	vnv_check( '(h) logs hold no client secret or bearer token', false === strpos( $all, vnv_secret( 'test' ) ) && false === strpos( $all, 'demo-tok-' ) );
	vnv_check( '(h) list calls log status and count only, never the response body', false !== strpos( $all, '/vinov/listInstruction' ) && false === strpos( $all, 'Demo Borçlu' ) && false === strpos( $all, 'WorkPlaceInstruction' ) );
	$all_notes = '';
	foreach ( wc_get_orders( array( 'limit' => -1, 'meta_key' => '_vnv_test', 'meta_value' => VNV_RUN ) ) as $o ) {
		$all_notes .= vnv_notes( $o->get_id() ) . "\n" . vnv_meta_dump( $o->get_id() );
	}
	vnv_check( '(h) no order note or meta of this run holds the TCKN', false === strpos( $all_notes, $tckn ) && false === strpos( $all_notes, $other ) );
} catch ( Throwable $e ) {
	$failed = true;
	if ( ! $e instanceof RuntimeException ) {
		echo 'FAIL: ' . get_class( $e ) . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	$_POST = array();
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
