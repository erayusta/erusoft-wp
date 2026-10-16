<?php
/**
 * Sales Team Commissions — payouts, invitations and handler permissions.
 *   wp --path=$HOME/code/erusoft-wp-demo eval-file tests/php/stc-payouts.php
 *
 * (c) a balance below the minimum is refused (repository and My Account controller); two concurrent
 *     requests (the second while another connection holds the agent's lock) → one payout row; a double
 *     submit from My Account → one payout row
 * (d) marking a payout paid → its rows paid, payout amount = sum of its rows, method and reference
 *     recorded; a negative adjustment (refund after an earlier payout) is netted into the next payout
 * (e) an agent "adding" an existing customer only queues a signed, single-use confirmation link (the same
 *     job for every address): no link until the
 *     customer POSTs the confirmation (GET shows a button and changes nothing); the agent sees the same
 *     text for existing, unknown, already-linked and own e-mails; forged, expired or plain-e-mail tokens are
 *     refused; invitations are rate-limited per agent
 * (f) an agent cannot read another agent's commissions via REST or admin AJAX (403); there are no REST
 *     write routes; admin handlers refuse a user without the capability and a request without a nonce;
 *     the My Account controller refuses another user's nonce
 */

require __DIR__ . '/stc-helpers.php';
stc_require_plugin();
foreach ( array( 'Eru_STC_Crypto', 'Eru_STC_Payout_Details', 'Eru_STC_Account', 'Eru_STC_Invitations', 'Eru_STC_Admin', 'Eru_STC_Rest' ) as $stc_c ) {
	if ( ! class_exists( $stc_c ) ) {
		echo "FAIL: {$stc_c} missing\n";
		exit( 1 );
	}
}

$mails = array();
stc_guards( $mails );
stc_trap_exits();
$keep = stc_snapshot();
stc_purge( true );
stc_define_test_key();

global $wpdb;
$ct = $wpdb->prefix . 'eru_stc_commissions';

/** An approved commission row for an agent (minor units). @return int Row id. */
function stc_y_row( $agent_id, $minor, $order_id = 0, array $extra = array() ) {
	static $n = 0;
	++$n;
	return Eru_STC_Commissions::insert(
		array_merge(
			array(
				'order_id'     => $order_id ? $order_id : 980000000 + $n,
				'item_id'      => $n,
				'agent_id'     => $agent_id,
				'level'        => 1,
				'amount_minor' => $minor,
				'basis_minor'  => $minor * 10,
				'currency'     => 'TRY',
				'status'       => 'approved',
				'earned_at'    => current_time( 'mysql', true ),
				'approved_at'  => current_time( 'mysql', true ),
				'note'         => 'stc-test ' . STC_RUN,
			),
			$extra
		)
	);
}

/** Payout rows of an agent. @return object[] */
function stc_y_payouts( $agent_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}eru_stc_payouts WHERE agent_id = %d ORDER BY id", $agent_id ) ); // phpcs:ignore
}

/** Account POST with a fresh nonce for the current user. */
function stc_y_post( $action, array $fields = array() ) {
	return Eru_STC_Account::process( $action, array_merge( array( '_wpnonce' => wp_create_nonce( Eru_STC_Account::nonce_action( $action ) ) ), $fields ) );
}

/** Runs the queued invitation mails (Action Scheduler) now. @return int[] Ids of the actions that were pending. */
function stc_y_run_invites() {
	$ids = as_get_scheduled_actions(
		array(
			'hook'     => Eru_STC_Invitations::JOB,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
		),
		'ids'
	);
	foreach ( $ids as $id ) {
		ActionScheduler::runner()->process_action( $id );
	}
	return $ids;
}

/** Args of the pending invitation actions. @return array[] */
function stc_y_invite_args() {
	$out = array();
	foreach ( as_get_scheduled_actions( array( 'hook' => Eru_STC_Invitations::JOB, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 100 ) ) as $action ) {
		$out[] = $action->get_args();
	}
	return $out;
}

/** The confirmation URL inside a captured mail body, or ''. */
function stc_y_link( array $mail ) {
	return 1 === preg_match( '#https?://[^\s"\'<>]*' . Eru_STC_Invitations::PARAM . '=([A-Za-z0-9_\-.]+)#', html_entity_decode( (string) $mail['message'] ), $m ) ? $m[1] : '';
}

$second     = null;
$keep_audit = get_option( 'eru_stc_export_audit', null );
try {
	stc_settings( array( 'min_payout' => '250.00' ) );
	$a      = stc_agent( 'payA' );
	$b      = stc_agent( 'payB' );
	$arow   = Eru_STC_Agents::get( $a );
	$brow   = Eru_STC_Agents::get( $b );
	$a_user = (int) $arow->user_id;
	wp_set_current_user( $a_user );
	Eru_STC_Payout_Details::save(
		$a,
		array(
			'holder' => 'Demo Temsilci',
			'iban'   => stc_fake_iban(),
			'tckn'   => stc_fake_tckn(),
		)
	);

	// ---------------------------------------------------------------- (c) minimum
	stc_y_row( $a, 2500 );
	stc_y_row( $a, 1500 );
	$r = Eru_STC_Payouts::create_request( $a );
	stc_check( '(c) 40.00 below a 250.00 minimum: refused (eru_stc_payout_minimum)', is_wp_error( $r ) && 'eru_stc_payout_minimum' === $r->get_error_code() );
	$res = stc_y_post( 'payout_request' );
	stc_check( '(c) My Account request below the minimum: refused, no payout row', false === $res['ok'] && 0 === count( stc_y_payouts( $a ) ) );
	stc_settings( array( 'min_payout' => '0' ) );
	stc_check( '(c) default minimum is 0 (no minimum)', '0.00' === Eru_STC_Settings::defaults()['min_payout'] || '0' === Eru_STC_Settings::defaults()['min_payout'] );

	// Concurrency: another connection holds the agent's payout lock.
	add_filter( 'eru_stc_lock_wait', fn() => 1 );
	$second = stc_second_connection();
	$lock   = $wpdb->prefix . 'eru_stc_payout_' . $a;
	$got    = $second->query( "SELECT GET_LOCK('" . $second->real_escape_string( $lock ) . "', 1) AS l" )->fetch_assoc();
	stc_check( '(c) second connection holds the agent lock', '1' === (string) $got['l'] );
	$r = Eru_STC_Payouts::create_request( $a );
	stc_check( '(c) request while the lock is held: refused (busy), no payout row', is_wp_error( $r ) && 'eru_stc_payout_busy' === $r->get_error_code() && 0 === count( stc_y_payouts( $a ) ) );
	$second->query( "SELECT RELEASE_LOCK('" . $second->real_escape_string( $lock ) . "')" );
	$p1 = Eru_STC_Payouts::create_request( $a );
	$p2 = Eru_STC_Payouts::create_request( $a );
	stc_check( '(c) lock released: first request ok, second refused (open request), one payout row', is_int( $p1 ) && is_wp_error( $p2 ) && 'eru_stc_payout_open' === $p2->get_error_code() && 1 === count( stc_y_payouts( $a ) ) );
	$res = stc_y_post( 'payout_request' );
	stc_check( '(c) My Account double submit: refused, still one payout row', false === $res['ok'] && 1 === count( stc_y_payouts( $a ) ) );
	stc_check( '(c) snapshot of the payout details is ciphertext JSON', 0 === strpos( (string) Eru_STC_Payouts::get( $p1 )->details, '{' ) && false !== strpos( (string) Eru_STC_Payouts::get( $p1 )->details, 'v1:' ) );

	// ---------------------------------------------------------------- (d) mark paid
	$admin = stc_user( 'payadmin', 'administrator' );
	wp_set_current_user( $admin );
	$_POST    = array(
		'payout_id' => (string) $p1,
		'reference' => 'DEMO-001',
		'method'    => 'bank_transfer',
		'_wpnonce'  => wp_create_nonce( 'eru_stc_payout' ),
	);
	$_REQUEST = $_POST;
	$call     = stc_call( array( 'Eru_STC_Admin', 'handle_payout_paid' ) );
	$payout   = Eru_STC_Payouts::get( $p1 );
	$claimed  = $wpdb->get_results( $wpdb->prepare( "SELECT status, amount FROM {$ct} WHERE payout_id = %d", $p1 ) ); // phpcs:ignore
	stc_check( '(d) admin "ödendi": payout paid with method and reference, redirected', $call['stop'] && 'redirect' === $call['stop']->getMessage() && 'paid' === $payout->status && 'DEMO-001' === $payout->reference && 'bank_transfer' === $payout->method );
	stc_check( '(d) claimed rows paid, payout amount = sum of its rows (40.00)', 2 === count( $claimed ) && array( 'paid' ) === array_values( array_unique( array_column( $claimed, 'status' ) ) ) && '40.00' === stc_sum( $claimed ) && '40.0000' === $payout->amount );
	stc_check( '(d) rows_total() agrees', '40.00' === Eru_STC_Payouts::rows_total( $p1 ) );

	// Netting: a refund after the payout writes a negative adjustment; the next payout nets it.
	$product = stc_product( 'Odeme', '80.00' );
	stc_rule( array( 'scope' => 'agent', 'target_id' => $a, 'level' => 1, 'type' => 'percent', 'rate' => '10' ) );
	stc_settings( array( 'max_levels' => 1, 'hold_days' => 0 ) );
	$buyer = stc_user( 'paybuyer' );
	Eru_STC_Links::link( $buyer, $a, 'manual' );
	$o = stc_order( $buyer, array( array( $product, 1, '80.00', '0' ) ) );
	$o = stc_status( $o->get_id(), 'completed' );
	Eru_STC_Eligibility::run();
	wp_set_current_user( $a_user );
	$p3 = Eru_STC_Payouts::create_request( $a );
	Eru_STC_Payouts::mark_paid( $p3, 'DEMO-002', 'bank_transfer' );
	$items = array_keys( $o->get_items() );
	$ref   = wc_create_refund(
		array(
			'order_id'       => $o->get_id(),
			'amount'         => '40.00',
			'line_items'     => array(
				$items[0] => array(
					'qty'          => 0,
					'refund_total' => '40.00',
					'refund_tax'   => array(),
				),
			),
			'refund_payment' => false,
			'restock_items'  => false,
		)
	);
	$adj   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ct} WHERE order_id = %d AND entry_key <> ''", $o->get_id() ) ); // phpcs:ignore
	stc_check( '(d) refund after the paid payout: one approved adjustment of -4.00', ! is_wp_error( $ref ) && $adj && '-4.0000' === $adj->amount && 'approved' === $adj->status );
	stc_y_row( $a, 1000 );
	$p4    = Eru_STC_Payouts::create_request( $a );
	$rows4 = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ct} WHERE payout_id = %d", $p4 ) ); // phpcs:ignore
	stc_check( '(d) next payout claims the adjustment: 10.00 - 4.00 = 6.00 = sum of its rows', is_int( $p4 ) && 2 === count( $rows4 ) && '6.0000' === Eru_STC_Payouts::get( $p4 )->amount && '6.00' === stc_sum( $rows4 ) );
	Eru_STC_Payouts::mark_paid( $p4, 'DEMO-003' );
	$bal = Eru_STC_Commissions::balance( $a );
	stc_check( '(d) paid: adjustment row paid too, available 0.00, paid total 54.00 (40 + 8 + 6)', 'paid' === Eru_STC_Commissions::get( $adj->id )->status && '0.00' === $bal['available'] && '54.00' === $bal['paid'] );
	stc_check( '(d) a paid payout cannot be paid or cancelled again', is_wp_error( Eru_STC_Payouts::mark_paid( $p4 ) ) && is_wp_error( Eru_STC_Payouts::cancel( $p4 ) ) );

	// Final wave M1: the payout total is re-read after the claim (a row changed in between counts as it is).
	$m1 = stc_agent( 'payM1' );
	stc_y_row( $m1, 1000 );
	$m1_row = stc_y_row( $m1, 500 );
	$fired  = false;
	$race   = function ( $q ) use ( &$fired, $m1_row ) {
		global $wpdb;
		if ( ! $fired && false !== strpos( $q, 'SET payout_id = ' ) ) {
			$fired = true;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}eru_stc_commissions SET amount = '3.0000' WHERE id = %d", $m1_row ) ); // phpcs:ignore
		}
		return $q;
	};
	add_filter( 'query', $race );
	$pm1 = Eru_STC_Payouts::create_request( $m1 );
	remove_filter( 'query', $race );
	stc_check( '(d) M1: a row reduced between selection and claim → payout amount = sum of its rows (13.00)', $fired && is_int( $pm1 ) && '13.0000' === Eru_STC_Payouts::get( $pm1 )->amount && '13.00' === Eru_STC_Payouts::rows_total( $pm1 ) );

	// ---------------------------------------------------------------- (e) invitations
	as_unschedule_all_actions( Eru_STC_Invitations::JOB );
	$mails    = array();
	$existing = stc_user( 'invcust' );
	$linked   = stc_user( 'invlinked' );
	Eru_STC_Links::link( $linked, $b, 'manual' );
	$unknown  = 'stc-demo-nobody-' . STC_RUN . '@example.com';
	$r_exist  = Eru_STC_Invitations::request( $a, get_userdata( $existing )->user_email );
	$r_unk    = Eru_STC_Invitations::request( $a, $unknown );
	$r_linked = Eru_STC_Invitations::request( $a, get_userdata( $linked )->user_email );
	$r_self   = Eru_STC_Invitations::request( $a, get_userdata( $a_user )->user_email );
	stc_check( '(e) same response for existing, unknown, already-linked and own e-mails', $r_exist['ok'] && $r_exist === $r_unk && $r_exist === $r_linked && $r_exist === $r_self );
	$queued = stc_y_invite_args();
	stc_check( '(e) M1: every request queues the same job (4 actions, no mail sent in the request, no address in the args)', 4 === count( $queued ) && 0 === count( $mails ) && false === strpos( wp_json_encode( $queued ), '@' ) );
	stc_y_run_invites();
	stc_check( '(e) only the existing, unlinked customer gets a mail', 1 === count( $mails ) && get_userdata( $existing )->user_email === ( is_array( $mails[0]['to'] ) ? $mails[0]['to'][0] : $mails[0]['to'] ) );
	stc_check( '(e) no link yet', null === Eru_STC_Links::get( $existing ) && $b === Eru_STC_Links::agent_for( $linked ) );
	$token = stc_y_link( $mails[0] );
	stc_check( '(e) the mail carries a signed token link', '' !== $token && false === strpos( $token, '@' ) );
	$get = Eru_STC_Invitations::handle( 'GET', array( Eru_STC_Invitations::PARAM => $token ) );
	stc_check( '(e) GET shows a form with the token and a button, and changes nothing', 200 === $get['status'] && false !== strpos( $get['html'], '<form' ) && false !== strpos( $get['html'], 'type="hidden"' ) && false !== strpos( $get['html'], esc_attr( $token ) ) && false !== strpos( $get['html'], '<button' ) && null === Eru_STC_Links::get( $existing ) );
	$forged = substr( $token, 0, -3 ) . ( 'AAA' === substr( $token, -3 ) ? 'BBB' : 'AAA' );
	$post   = Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => $forged ) );
	stc_check( '(e) forged token: refused, no link', $post['status'] >= 400 && null === Eru_STC_Links::get( $existing ) );
	$plain = Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => get_userdata( $existing )->user_email ) );
	stc_check( '(e) a plain e-mail as token: refused', $plain['status'] >= 400 && null === Eru_STC_Links::get( $existing ) );
	$expired = Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => Eru_STC_Invitations::mint( $existing, $a, time() - 60, (string) get_user_meta( $existing, Eru_STC_Invitations::ID_META, true ) ) ) );
	stc_check( '(e) expired token: refused with "süresi doldu", no link', $expired['status'] >= 400 && false !== mb_stripos( $expired['html'], 'süresi doldu' ) && null === Eru_STC_Links::get( $existing ) );
	wp_set_current_user( 0 );
	$post = Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => $token ) );
	$link = Eru_STC_Links::get( $existing );
	stc_check( '(e) customer POST confirms: linked to the inviting agent, type invite', 200 === $post['status'] && $link && $a === (int) $link->agent_id && 'invite' === $link->link_type );
	$again = Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => $token ) );
	stc_check( '(e) confirming twice is harmless', 200 === $again['status'] && $a === Eru_STC_Links::agent_for( $existing ) );
	$steal = Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => Eru_STC_Invitations::mint( $linked, $a, time() + 3600, 'x' ) ) );
	stc_check( '(e) a valid token never moves a customer already linked to another agent', $b === Eru_STC_Links::agent_for( $linked ) && $steal['status'] < 500 );
	// M2: single use. After an admin unlink the old mail cannot re-link the customer; a new invitation can.
	stc_check( '(e) M2: the invite id is cleared on confirm', '' === (string) get_user_meta( $existing, Eru_STC_Invitations::ID_META, true ) );
	Eru_STC_Links::unlink( $existing );
	$old = Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => $token ) );
	stc_check( '(e) M2: old token after an admin unlink: refused, no link', $old['status'] >= 400 && null === Eru_STC_Links::get( $existing ) );
	$mails = array();
	wp_set_current_user( $a_user );
	Eru_STC_Invitations::request( $a, get_userdata( $existing )->user_email );
	stc_y_run_invites();
	$token2 = 1 === count( $mails ) ? stc_y_link( $mails[0] ) : '';
	stc_check( '(e) M2: a new invitation has a new token; the first one stays refused', '' !== $token2 && $token2 !== $token && Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => $token ) )['status'] >= 400 && null === Eru_STC_Links::get( $existing ) );
	$mails = array();
	$again = Eru_STC_Invitations::request( $b, get_userdata( $existing )->user_email );
	stc_y_run_invites();
	stc_check( '(e) M2: per-customer daily limit (another agent, within its own limit): no second mail the same day, the first token stays valid', $again['ok'] && 0 === count( $mails ) && 200 === Eru_STC_Invitations::handle( 'GET', array( Eru_STC_Invitations::PARAM => $token2 ) )['status'] );
	Eru_STC_Links::link( $existing, $a, 'invite' );
	stc_check( '(e) M2: a manual link/unlink cycle also voids the pending invitation', true === Eru_STC_Links::unlink( $existing ) && Eru_STC_Invitations::handle( 'POST', array( Eru_STC_Invitations::PARAM => $token2 ) )['status'] >= 400 && null === Eru_STC_Links::get( $existing ) );
	wp_set_current_user( 0 );
	// Job-time checks: the queued job re-checks eligibility when it runs.
	$e_agent = stc_agent( 'payE' );
	$y_cust  = stc_user( 'jobY' );
	$z_cust  = stc_user( 'jobZ' );
	$mails   = array();
	Eru_STC_Invitations::request( $e_agent, get_userdata( $y_cust )->user_email );
	Eru_STC_Links::link( $y_cust, $a, 'manual' );
	stc_y_run_invites();
	stc_check( '(e) job time: a customer linked to another agent before the job runs gets no mail', 0 === count( $mails ) && '' === (string) get_user_meta( $y_cust, Eru_STC_Invitations::ID_META, true ) );
	Eru_STC_Invitations::request( $e_agent, get_userdata( $z_cust )->user_email );
	Eru_STC_Agents::update( $e_agent, array( 'status' => 'suspended' ) );
	stc_y_run_invites();
	stc_check( '(e) job time: an agent suspended before the job runs sends no mail', 0 === count( $mails ) && '' === (string) get_user_meta( $z_cust, Eru_STC_Invitations::ID_META, true ) );

	// Rate limit (fresh agent, 3 an hour): the 4th and 5th requests send nothing and say "çok fazla".
	add_filter( 'eru_stc_invite_limits', fn() => array( 'hour' => 3, 'day' => 5 ) );
	$c     = stc_agent( 'payC' );
	$mails = array();
	$fresh = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$fresh[] = Eru_STC_Invitations::request( $c, get_userdata( stc_user( 'rl' . $i ) )->user_email );
	}
	stc_y_run_invites();
	stc_check( '(e) rate limit: 3 mails, then refused with "çok fazla", counted per agent', 3 === count( $mails ) && $fresh[2]['ok'] && false === $fresh[3]['ok'] && false === $fresh[4]['ok'] && false !== mb_stripos( $fresh[4]['message'], 'çok fazla' ) );
	wp_set_current_user( (int) $brow->user_id );
	$res = stc_y_post( 'invite', array( 'eru_stc_email' => get_userdata( stc_user( 'viaform' ) )->user_email ) );
	stc_check( '(e) My Account invite form uses the same neutral text', $res['ok'] && $res['message'] === $r_exist['message'] );
	stc_y_run_invites();

	// ---------------------------------------------------------------- (f) handlers
	stc_y_row( $b, 7000 );
	// Routes are registered per request on rest_api_init, only while the module is on (WP-CLI already ran
	// rest_api_init at boot): a fresh REST server stands for a new request.
	stc_settings( array( 'enabled' => 'no' ) );
	$GLOBALS['wp_rest_server'] = null;
	stc_check( '(f) module off: no eru-stc REST routes', ! isset( rest_get_server()->get_routes()['/eru-stc/v1/me'] ) );
	stc_settings();
	$GLOBALS['wp_rest_server'] = null;
	$methods = array();
	foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
		if ( 0 === strpos( $route, '/eru-stc/v1/' ) ) {
			foreach ( $handlers as $h ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) $h['methods'] ) ) );
			}
		}
	}
	stc_check( '(f) module on: 8 eru-stc routes, GET only', isset( rest_get_server()->get_routes()['/eru-stc/v1/me'] ) && array( 'GET' ) === array_values( array_unique( $methods ) ) && 8 === count( $methods ) );
	wp_set_current_user( $a_user );
	$req = new WP_REST_Request( 'GET', '/eru-stc/v1/me/commissions' );
	$req->set_param( 'agent_id', $b );
	$resp = rest_do_request( $req );
	$ids  = array_unique( array_map( fn( $r ) => (int) $r['agent_id'], (array) $resp->get_data() ) );
	stc_check( '(f) REST me/commissions: 200, only the agent\'s own rows (agent_id param ignored)', 200 === $resp->get_status() && array( $a ) === array_values( $ids ) );
	$resp = rest_do_request( new WP_REST_Request( 'GET', '/eru-stc/v1/commissions' ) );
	stc_check( '(f) REST admin commissions as an agent: 403', 403 === $resp->get_status() );
	$resp = rest_do_request( new WP_REST_Request( 'GET', '/eru-stc/v1/agents/' . $b ) );
	stc_check( '(f) REST another agent as an agent: 403', 403 === $resp->get_status() );
	$resp = rest_do_request( new WP_REST_Request( 'POST', '/eru-stc/v1/me/commissions' ) );
	stc_check( '(f) REST has no write routes (POST → 404)', 404 === $resp->get_status() );
	wp_set_current_user( 0 );
	$resp = rest_do_request( new WP_REST_Request( 'GET', '/eru-stc/v1/me' ) );
	stc_check( '(f) REST me as a guest: 401', 401 === $resp->get_status() );
	wp_set_current_user( $admin );
	$req = new WP_REST_Request( 'GET', '/eru-stc/v1/commissions' );
	$req->set_param( 'agent_id', $b );
	$resp = rest_do_request( $req );
	stc_check( '(f) REST admin commissions as admin: 200, filtered', 200 === $resp->get_status() && array( $b ) === array_values( array_unique( array_map( fn( $r ) => (int) $r['agent_id'], (array) $resp->get_data() ) ) ) );
	$resp = rest_do_request( new WP_REST_Request( 'GET', '/eru-stc/v1/agents/' . $a ) );
	$flat = wp_json_encode( $resp->get_data() );
	stc_check( '(f) REST agent detail as admin: masked payout details only', 200 === $resp->get_status() && false !== strpos( $flat, '****' ) && 0 === preg_match( '/TR\d{24}/', $flat ) );

	add_filter( 'wp_doing_ajax', '__return_true' );
	wp_set_current_user( $a_user );
	$_POST    = array(
		'agent_id' => (string) $b,
		'nonce'    => wp_create_nonce( 'eru_stc_admin' ),
	);
	$_REQUEST = $_POST;
	$call     = stc_call( array( 'Eru_STC_Admin', 'ajax_agent_commissions' ) );
	stc_check( '(f) AJAX another agent\'s commissions as an agent (own valid nonce): 403, no rows', 403 === $call['status'] && false === strpos( $call['output'], '"amount"' ) );
	wp_set_current_user( $admin );
	$_POST['nonce'] = wp_create_nonce( 'eru_stc_admin' );
	$_REQUEST       = $_POST;
	$call           = stc_call( array( 'Eru_STC_Admin', 'ajax_agent_commissions' ) );
	stc_check( '(f) AJAX as admin with nonce: rows of agent B', false !== strpos( $call['output'], '"success":true' ) && false !== strpos( $call['output'], '70.00' ) );
	$_POST['nonce'] = 'bad';
	$_REQUEST       = $_POST;
	$call           = stc_call( array( 'Eru_STC_Admin', 'ajax_agent_commissions' ) );
	stc_check( '(f) AJAX as admin without a valid nonce: 403', 403 === $call['status'] && false === strpos( $call['output'], '70.00' ) );
	remove_filter( 'wp_doing_ajax', '__return_true' );

	// Admin handlers without the capability / without a nonce.
	$pending = stc_y_row( $b, 1100, 0, array( 'status' => 'pending' ) );
	foreach ( array( 'agent' => (int) $brow->user_id, 'no-nonce' => $admin ) as $who => $uid ) {
		wp_set_current_user( $uid );
		$nonce    = 'agent' === $who ? wp_create_nonce( 'bulk-commissions' ) : '';
		$_POST    = array(
			'action'   => 'approve',
			'ids'      => array( (string) $pending ),
			'_wpnonce' => $nonce,
		);
		$_REQUEST = $_POST;
		$call     = stc_call( array( 'Eru_STC_Admin', 'commissions_bulk_request' ) );
		stc_check( "(f) bulk approve ({$who}): refused with 403, row still pending", $call['stop'] && 403 === $call['status'] && 'pending' === Eru_STC_Commissions::get( $pending )->status );
		$open     = Eru_STC_Payouts::create_request( $b );
		$_POST    = array(
			'payout_id' => (string) $open,
			'reference' => 'X',
			'_wpnonce'  => 'agent' === $who ? wp_create_nonce( 'eru_stc_payout' ) : '',
		);
		$_REQUEST = $_POST;
		$call     = stc_call( array( 'Eru_STC_Admin', 'handle_payout_paid' ) );
		stc_check( "(f) mark paid ({$who}): refused with 403, payout still requested", $call['stop'] && 403 === $call['status'] && 'requested' === Eru_STC_Payouts::get( $open )->status );
		Eru_STC_Payouts::cancel( $open );
		$before   = get_option( Eru_STC_Settings::OPTION );
		$_POST    = array(
			'hold_days' => '1',
			'_wpnonce'  => 'agent' === $who ? wp_create_nonce( 'eru_stc_settings' ) : '',
		);
		$_REQUEST = $_POST;
		$call     = stc_call( array( 'Eru_STC_Admin', 'handle_settings_save' ) );
		stc_check( "(f) settings save ({$who}): refused with 403, settings unchanged", $call['stop'] && 403 === $call['status'] && get_option( Eru_STC_Settings::OPTION ) === $before );
		$_POST    = array(
			'full_iban' => '1',
			'_wpnonce'  => 'agent' === $who ? wp_create_nonce( 'eru_stc_export' ) : '',
		);
		$_REQUEST = $_POST;
		$call     = stc_call( array( 'Eru_STC_Admin', 'handle_payouts_export' ) );
		stc_check( "(f) payout CSV export ({$who}): refused with 403, nothing written", $call['stop'] && 403 === $call['status'] && false === strpos( $call['output'], 'IBAN' ) );
	}
	wp_set_current_user( $admin );
	$_POST    = array(
		'action'   => 'approve',
		'ids'      => array( (string) $pending ),
		'_wpnonce' => wp_create_nonce( 'bulk-commissions' ),
	);
	$_REQUEST = $_POST;
	$call     = stc_call( array( 'Eru_STC_Admin', 'commissions_bulk_request' ) );
	stc_check( '(f) bulk approve as admin with nonce: approved', 'approved' === Eru_STC_Commissions::get( $pending )->status );
	$_POST    = array(
		'action'   => 'void',
		'ids'      => array( (string) $pending ),
		'_wpnonce' => wp_create_nonce( 'bulk-commissions' ),
	);
	$_REQUEST = $_POST;
	stc_call( array( 'Eru_STC_Admin', 'commissions_bulk_request' ) );
	$voided = Eru_STC_Commissions::get( $pending );
	stc_check( '(f) bulk void as admin: void with reason manual', 'void' === $voided->status && 'manual' === $voided->void_reason );

	// Self-registration: off by default (refused); on → a pending agent, which cannot request payouts.
	$applicant = stc_user( 'applicant' );
	wp_set_current_user( $applicant );
	$res = stc_y_post( 'apply' );
	stc_check( '(f) application refused while self-registration is off (default)', false === $res['ok'] && null === Eru_STC_Agents::get_by_user( $applicant ) );
	stc_settings( array( 'self_registration' => 'yes' ) );
	$res = stc_y_post( 'apply' );
	$app = Eru_STC_Agents::get_by_user( $applicant );
	stc_check( '(f) application on: pending agent created, second application refused', $res['ok'] && $app && 'pending' === $app->status && false === stc_y_post( 'apply' )['ok'] );
	stc_check( '(f) a pending agent cannot request a payout or invite', false === stc_y_post( 'payout_request' )['ok'] && false === stc_y_post( 'invite', array( 'eru_stc_email' => get_userdata( $existing )->user_email ) )['ok'] );

	// M3: a suspended agent (e.g. after erasure) cannot write payout details again.
	$sus = stc_agent( 'suspended', null, 'suspended' );
	wp_set_current_user( (int) Eru_STC_Agents::get( $sus )->user_id );
	$res = stc_y_post(
		'details_save',
		array(
			'eru_stc_holder' => 'Demo Askida',
			'eru_stc_iban'   => stc_fake_iban(),
		)
	);
	stc_check( '(f) M3: a suspended agent cannot save or delete payout details', false === $res['ok'] && null === Eru_STC_Agents::get( $sus )->payout_iban && false === stc_y_post( 'details_delete' )['ok'] );

	// M5: the full-IBAN export needs the confirmation checkbox and its own nonce, server side; each one is audited.
	wp_set_current_user( $admin );
	$_POST    = array(
		'full_iban' => '1',
		'status'    => '',
		'_wpnonce'  => wp_create_nonce( 'eru_stc_export' ),
	);
	$_REQUEST = $_POST;
	$before   = Eru_STC_Reports::export_audit();
	$call     = stc_call( array( 'Eru_STC_Admin', 'handle_payouts_export' ) );
	stc_check( '(f) M5: full IBAN without the confirmation: refused (redirect), nothing written, no audit entry', $call['stop'] && 'redirect' === $call['stop']->getMessage() && false === strpos( $call['output'], 'IBAN' ) && Eru_STC_Reports::export_audit() === $before );
	stc_check(
		'(f) M5: confirmation check needs the checkbox and its own nonce',
		is_wp_error( Eru_STC_Admin::full_export_check( array( 'full_iban' => '1', 'full_iban_ack' => '1', '_eru_stc_full' => 'bad' ) ) )
		&& is_wp_error( Eru_STC_Admin::full_export_check( array( 'full_iban' => '1', '_eru_stc_full' => wp_create_nonce( 'eru_stc_full_iban' ) ) ) )
		&& true === Eru_STC_Admin::full_export_check( array( 'full_iban' => '1', 'full_iban_ack' => '1', '_eru_stc_full' => wp_create_nonce( 'eru_stc_full_iban' ) ) )
	);
	for ( $i = 0; $i < 55; $i++ ) {
		Eru_STC_Reports::record_full_export( $admin, array( 'status' => 'requested' ), $i );
	}
	$audit = Eru_STC_Reports::export_audit();
	global $wpdb;
	$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Eru_STC_Reports::AUDIT_OPTION ) ); // phpcs:ignore
	stc_check( '(f) M5: audit keeps the last 50 entries (user, time, filter, rows), not autoloaded', 50 === count( $audit ) && $admin === (int) $audit[0]['user'] && 54 === (int) $audit[0]['rows'] && 'requested' === $audit[0]['status'] && $audit[0]['time'] > 0 && in_array( $autoload, array( 'no', 'off' ), true ) );

	// My Account controller: another user's nonce is refused.
	wp_set_current_user( $admin );
	$foreign = wp_create_nonce( Eru_STC_Account::nonce_action( 'details_save' ) );
	wp_set_current_user( $a_user );
	$before = $wpdb->get_row( $wpdb->prepare( "SELECT payout_iban FROM {$wpdb->prefix}eru_stc_agents WHERE id = %d", $a ) ); // phpcs:ignore
	$res    = Eru_STC_Account::process(
		'details_save',
		array(
			'_wpnonce'     => $foreign,
			'eru_stc_iban' => stc_fake_iban(),
		)
	);
	stc_check( '(f) My Account: another user\'s nonce refused, details unchanged', false === $res['ok'] && $before == $wpdb->get_row( $wpdb->prepare( "SELECT payout_iban FROM {$wpdb->prefix}eru_stc_agents WHERE id = %d", $a ) ) ); // phpcs:ignore

	echo "ALL PASS: stc-payouts\n";
	$status = 0;
} catch ( Throwable $e ) {
	echo 'FAIL: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	$status = 1;
} finally {
	if ( $second ) {
		$second->close();
	}
	null === $keep_audit ? delete_option( 'eru_stc_export_audit' ) : update_option( 'eru_stc_export_audit', $keep_audit, false );
	wp_set_current_user( 0 );
	$_POST    = array();
	$_REQUEST = array();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eru_stc_commissions WHERE note = %s", 'stc-test ' . STC_RUN ) ); // phpcs:ignore
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'eru_stc_eligibility', array(), 'eru-stc' );
		as_unschedule_all_actions( 'eru_stc_send_invite' );
	}
	stc_purge( false );
	stc_restore( $keep );
}
exit( $status );
