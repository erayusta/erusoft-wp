<?php
/**
 * Bank Transfer Auto-Approval for Garanti BBVA — client: fresh OAuth token per run, paging with
 * de-duplication, 401 re-mint once per page, partial failure and truncation abort the run, no credential
 * in the URL or the log. Every HTTP call is answered from invented bodies; anything else exits 1.
 *
 *   wp plugin activate bank-transfer-auto-approval-for-garanti-bbva
 *   wp eval-file tests/php/gha-client.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

foreach ( array( 'Eru_GHA_Settings', 'Eru_GHA_Client', 'Eru_GHA_Paginator', 'Eru_GHA_Approver', 'Eru_GHA_Ledger', 'Eru_GHA_Log' ) as $eru_gha_class ) {
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

$demo_secret = 'demo-' . 'client-secret-0000';
$demo_client = 'demo-client-id';
$tokens      = array();
$token_calls = 0;
$reqs        = array();
$mode        = 'two_pages';
$page_calls  = array();

$txn = function ( $i, $amount = 100.0, $text = 'DEMO HAREKET' ) {
	return array(
		'transactionInstanceId'   => sprintf( '2026-10-15T09:%02d:%02d.%06d', intdiv( $i, 60 ) % 60, $i % 60, $i ),
		'txnCreditDebitIndicator' => 'A',
		'amount'                  => $amount,
		'explanation'             => $text,
		'activityDate'            => '2026-10-15',
	);
};

$router = function ( $pre, $args, $url ) use ( &$tokens, &$token_calls, &$reqs, &$mode, &$page_calls, $txn ) {
	if ( wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) && false !== strpos( $url, 'wp-cron.php' ) ) {
		return new WP_Error( 'eru_gha_test', 'loopback cron blocked in tests' ); // Not an outbound call.
	}
	$resp = fn( $code, $body ) => array( 'headers' => array(), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( 'apis.garantibbva.com.tr' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	}
	$reqs[] = array( 'url' => $url, 'path' => $path, 'args' => $args );
	if ( '/auth/oauth/v2/token' === $path ) {
		++$token_calls;
		$t        = 'demo-tok-' . $token_calls . '-' . 'zzzz';
		$tokens[] = $t;
		return $resp( 200, array( 'access_token' => $t, 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
	}
	if ( '/balancesandmovements/accountinformation/transaction/v1/gettransactions' === $path ) {
		$body = json_decode( (string) $args['body'], true );
		$page = (int) ( $body['pageIndex'] ?? 0 );
		$auth = (string) ( $args['headers']['Authorization'] ?? '' );
		$page_calls[] = array( 'page' => $page, 'auth' => $auth );
		$list = function ( $from, $to ) use ( $txn ) {
			$out = array();
			for ( $i = $from; $i <= $to; $i++ ) {
				$out[] = $txn( $i );
			}
			return $out;
		};
		switch ( $mode ) {
			case 'two_pages':
				// Page 2 repeats the last item of page 1: 100 + 51 - 1 = 150 unique.
				// Page 3 is empty: the end signal.
				return $resp( 200, array( 'transactions' => 1 === $page ? $list( 1, 100 ) : ( 2 === $page ? $list( 100, 150 ) : array() ) ) );
			case '401_page2_once':
				if ( 2 === $page && 'Bearer ' . $tokens[0] === $auth ) {
					return $resp( 401, array( 'error' => 'invalid_token' ) );
				}
				return $resp( 200, array( 'transactions' => 1 === $page ? $list( 1, 100 ) : ( 2 === $page ? $list( 101, 120 ) : array() ) ) );
			case 'short_then_more':
				// A short first page is not the end: the bank may cap the page size.
				return $resp( 200, array( 'transactions' => 1 === $page ? $list( 1, 60 ) : ( 2 === $page ? $list( 61, 100 ) : array() ) ) );
			case '401_page2_always':
				if ( 2 === $page ) {
					return $resp( 401, array( 'error' => 'invalid_token' ) );
				}
				return $resp( 200, array( 'transactions' => $list( 1, 100 ) ) );
			case '500_page2':
				if ( 2 === $page ) {
					return $resp( 500, array( 'result' => array( 'messageText' => 'Demo servis hatası' ) ) );
				}
				return $resp( 200, array( 'transactions' => $list( 1, 100 ) ) );
			case 'endless':
				return $resp( 200, array( 'transactions' => $list( ( $page - 1 ) * 100 + 1, $page * 100 ) ) );
			case 'match_then_401':
				if ( 2 === $page ) {
					return $resp( 401, array( 'error' => 'invalid_token' ) );
				}
				$l   = $list( 1, 99 );
				$l[] = $txn( 500, 250.0, 'SIPARIS 4242 ODEME' );
				return $resp( 200, array( 'transactions' => $l ) );
		}
	}
	echo 'FAIL unmocked HTTP ' . $url . "\n";
	exit( 1 );
};
add_filter( 'pre_http_request', $router, 10, 3 );

global $wpdb;
$log_t    = Eru_GHA_Log::table();
$first_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$log_t}" ); // phpcs:ignore
$old      = get_option( Eru_GHA_Settings::OPTION, null );
$order    = null;
$num_cb   = null;

$cleanup = function () use ( &$order, &$num_cb ) {
	global $wpdb;
	if ( $order ) {
		Eru_GHA_Ledger::delete_for_test( $order->get_id() );
		$o = wc_get_order( $order->get_id() );
		if ( $o ) {
			$o->delete( true );
		}
		$order = null;
	}
	if ( $num_cb ) {
		remove_filter( 'woocommerce_order_number', $num_cb, 99 );
	}
	delete_transient( Eru_GHA_Client::CACHE );
	$wpdb->delete( $wpdb->options, array( 'option_name' => Eru_GHA_Lock::option( 'run' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_delete( Eru_GHA_Lock::option( 'run' ), 'options' );
};

// Runs on success, on failure (exit inside $check skips finally) and only once.
$finished = false;
$finish   = function () use ( &$finished, &$cleanup, &$reset, &$old, $wpdb, $log_t, &$first_id ) {
	if ( $finished ) {
		return;
	}
	$finished = true;
	$cleanup();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_t} WHERE id > %d", $first_id ) ); // phpcs:ignore
	if ( null === $old ) {
		delete_option( Eru_GHA_Settings::OPTION );
	} else {
		update_option( Eru_GHA_Settings::OPTION, $old, false );
	}
	delete_option( 'eru_gha_last_run' );
	as_unschedule_all_actions( 'eru_gha_daily', array(), 'eru-gha' );
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
	$wpdb->delete( $wpdb->options, array( 'option_name' => Eru_GHA_Lock::option( 'run' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_delete( Eru_GHA_Lock::option( 'run' ), 'options' );
	update_option( Eru_GHA_Settings::OPTION, array(), false );
	Eru_GHA_Settings::save_for_test(
		array(
			'enabled'        => 'no',
			'dry_run'        => 'no',
			'client_id'      => $demo_client,
			'client_secret'  => $demo_secret,
			'consent_id'     => 'DEMO-CONSENT',
			'account_number' => '0000000',
			'unit_number'    => '000',
			'iban'           => $iban( '000620000000000000000001' ),
			'notify_emails'  => 'ornek@example.com',
		)
	);

	// (a) token 200 → two pages → 150 unique.
	$client = new Eru_GHA_Client();
	$r      = $client->fetch_transactions( 48 );
	$check( '(a) two pages fetched, 150 unique transactions (duplicate across pages counted once)', $r['ok'] && 150 === count( $r['transactions'] ) && 3 === $r['pages'] );
	$check( '(a) one token per run', 1 === $token_calls );
	$tok_req = $reqs[0];
	$check( '(a) client credentials in the POST body, never in the URL', false === strpos( $tok_req['url'], $demo_secret ) && false === strpos( $tok_req['url'], '?' ) && $demo_secret === ( $tok_req['args']['body']['client_secret'] ?? '' ) && 'client_credentials' === ( $tok_req['args']['body']['grant_type'] ?? '' ) );
	$tx_body = json_decode( (string) $reqs[1]['args']['body'], true );
	$check( '(a) transaction request carries consent, account, page size 100 and a Bearer token', 'DEMO-CONSENT' === ( $tx_body['consentId'] ?? '' ) && 100 === (int) ( $tx_body['pageSize'] ?? 0 ) && 1 === (int) $tx_body['pageIndex'] && 'Bearer ' . $tokens[0] === $reqs[1]['args']['headers']['Authorization'] && false === strpos( $reqs[1]['url'], '?' ) );
	$client->fetch_transactions( 48 );
	$check( '(a) a second run mints a fresh token (no token cache)', 2 === $token_calls );

	// (h) 401 on page 2 → re-mint once → completes.
	$tokens      = array();
	$token_calls = 0;
	$page_calls  = array();
	$mode        = '401_page2_once';
	$r           = ( new Eru_GHA_Client() )->fetch_transactions( 48 );
	$check( '(h) 401 on page 2 → one re-mint → page retried and run completes (120 txns)', $r['ok'] && 120 === count( $r['transactions'] ) && 3 === $token_calls && 4 === count( $page_calls ) );

	// (h) a second 401 on the same page → aborted.
	$tokens      = array();
	$token_calls = 0;
	$page_calls  = array();
	$mode        = '401_page2_always';
	$r           = ( new Eru_GHA_Client() )->fetch_transactions( 48 );
	$check( '(h) a second 401 on the retried page aborts the fetch', ! $r['ok'] && '' !== $r['error'] && 2 === $token_calls && 3 === count( $page_calls ) );

	// Fix round 1 (3): a short page followed by more data is not the end; an empty page is.
	$mode = 'short_then_more';
	$r    = ( new Eru_GHA_Client() )->fetch_transactions( 48 );
	$check( 'a short page followed by more data keeps paging until an empty page (100 txns, 3 pages)', $r['ok'] && 100 === count( $r['transactions'] ) && 3 === $r['pages'] );

	// Fix round 1 (2): owner-checked lock.
	$wpdb->delete( $wpdb->options, array( 'option_name' => Eru_GHA_Lock::option( 'run' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_delete( Eru_GHA_Lock::option( 'run' ), 'options' );
	$t1 = Eru_GHA_Lock::acquire( 'run', 60 );
	$check( 'lock: a second acquire before expiry fails', $t1 && false === Eru_GHA_Lock::acquire( 'run', 60 ) );
	$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 120 ) . '|' . 'stale' ), array( 'option_name' => Eru_GHA_Lock::option( 'run' ) ) );
	wp_cache_delete( Eru_GHA_Lock::option( 'run' ), 'options' );
	$t2 = Eru_GHA_Lock::acquire( 'run', 60 );
	$check( 'lock: taken over only after expiry', is_string( $t2 ) && $t2 !== $t1 );
	Eru_GHA_Lock::release( 'run', $t1 );
	$check( 'lock: the old owner cannot release the new owner\'s lock', false === Eru_GHA_Lock::acquire( 'run', 60 ) );
	Eru_GHA_Lock::release( 'run', $t2 );
	$t3 = Eru_GHA_Lock::acquire( 'run', 60 );
	$check( 'lock: the owner releases it', is_string( $t3 ) );
	Eru_GHA_Lock::release( 'run', $t3 );

	// Partial failure → not ok.
	$mode = '500_page2';
	$r    = ( new Eru_GHA_Client() )->fetch_transactions( 48 );
	$check( 'HTTP 500 on page 2 → fetch not ok (no matching on partial data)', ! $r['ok'] && false !== strpos( $r['error'], '500' ) );

	// Truncation at 30 pages → not ok.
	$page_calls = array();
	$mode       = 'endless';
	$r          = ( new Eru_GHA_Client() )->fetch_transactions( 48 );
	$check( 'still full after 30 pages → truncated → not ok', ! $r['ok'] && ! empty( $r['truncated'] ) && 30 === count( $page_calls ) );

	// Lookback is clamped to 24–96 h.
	$mode = 'two_pages';
	$reqs = array();
	( new Eru_GHA_Client() )->fetch_transactions( 500 );
	$b     = json_decode( (string) $reqs[1]['args']['body'], true );
	$span  = strtotime( substr( $b['endDate'], 0, 19 ) ) - strtotime( substr( $b['startDate'], 0, 19 ) );
	$check( 'lookback clamped to 96 h', 96 * HOUR_IN_SECONDS === $span );

	// Settings: inert defaults, write-only secret, IBAN checksum, pattern check, capability, autoload.
	$d = Eru_GHA_Settings::defaults();
	$check( 'defaults are inert: master switch off, dry-run on, bacs + on-hold/pending → processing, tolerance 0', 'no' === $d['enabled'] && 'yes' === $d['dry_run'] && array( 'bacs' ) === $d['gateways'] && array( 'on-hold', 'pending' ) === $d['watch_statuses'] && 'processing' === $d['approved_status'] && 0.0 === (float) $d['tolerance'] );
	$prev_user = get_current_user_id();
	wp_set_current_user( 0 );
	$check( 'sanitize without manage_woocommerce keeps the saved value', $demo_secret === Eru_GHA_Settings::sanitize( array( 'client_secret' => 'x-new' ) )['client_secret'] );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$in  = array(
		'client_id'       => $demo_client,
		'client_secret'   => '',
		'consent_id'      => 'DEMO-CONSENT',
		'account_number'  => '0000000',
		'iban'            => substr_replace( $iban( '000620000000000000000001' ), '00', 2, 2 ), // Wrong checksum.
		'gateways'        => array( 'bacs', 'not-a-gateway' ),
		'watch_statuses'  => array( 'on-hold', 'processing' ),
		'approved_status' => 'processing',
		'ref_pattern'     => '\d+',
		'tolerance'       => '0,01',
		'lookback_hours'  => '200',
		'interval'        => '7',
	);
	$out = Eru_GHA_Settings::sanitize( $in );
	$check( 'blank secret keeps the stored one (write-only)', $demo_secret === $out['client_secret'] );
	$check( 'an IBAN with a wrong checksum is refused (old value kept)', $iban( '000620000000000000000001' ) === $out['iban'] );
	$check( 'a pattern without a capture group is refused (old value kept)', Eru_GHA_Settings::DEFAULT_PATTERN === $out['ref_pattern'] );
	$check( 'approved status is removed from the watched list; unknown gateway dropped', array( 'on-hold' ) === $out['watch_statuses'] && array( 'bacs' ) === $out['gateways'] );
	$check( 'tolerance "0,01" → 0.01, lookback clamped to 96, unknown interval → 15', '0.01' === $out['tolerance'] && '96' === $out['lookback_hours'] && '15' === $out['interval'] );
	wp_set_current_user( $prev_user );
	$al = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Eru_GHA_Settings::OPTION ) );
	$check( 'settings option is not autoloaded', in_array( $al, array( 'no', 'off', 'auto-off' ), true ) );

	// Schedule follows the master switch and interval.
	$pend = fn( $h ) => as_get_scheduled_actions( array( 'hook' => $h, 'group' => 'eru-gha', 'status' => ActionScheduler_Store::STATUS_PENDING ) );
	$check( 'master switch off → no polling job', array() === $pend( Eru_GHA_Cron::CHECK ) );
	Eru_GHA_Settings::save_for_test( array( 'enabled' => 'yes', 'interval' => '5' ) );
	$a = $pend( Eru_GHA_Cron::CHECK );
	$check( 'master switch on → one recurring job every 5 minutes', 1 === count( $a ) && 300 === (int) reset( $a )->get_schedule()->get_recurrence() );
	Eru_GHA_Settings::save_for_test( array( 'interval' => '30' ) );
	$a = $pend( Eru_GHA_Cron::CHECK );
	$check( 'interval change → rescheduled at 30 minutes', 1 === count( $a ) && 1800 === (int) reset( $a )->get_schedule()->get_recurrence() );
	Eru_GHA_Settings::save_for_test( array( 'enabled' => 'no' ) );
	$check( 'master switch off again → job removed', array() === $pend( Eru_GHA_Cron::CHECK ) );
	$check( '"Şimdi çalıştır" enqueues a single action once', Eru_GHA_Cron::enqueue( Eru_GHA_Cron::NOW ) && ! Eru_GHA_Cron::enqueue( Eru_GHA_Cron::NOW ) );
	as_unschedule_all_actions( Eru_GHA_Cron::NOW, array(), 'eru-gha' );

	// (h) end to end: a matching order + 401 twice on page 2 → run aborted, nothing approved.
	$order = wc_create_order();
	$order->set_payment_method( 'bacs' );
	$order->set_currency( 'TRY' );
	$order->set_billing_email( 'ornek@example.com' );
	$order->set_billing_first_name( 'Demo' );
	$order->set_total( 250 );
	$order->set_date_created( time() - 2 * HOUR_IN_SECONDS );
	$order->set_status( 'on-hold' );
	$order->update_meta_data( '_eru_gha_test', 1 );
	$order->save();
	$oid    = $order->get_id();
	$num_cb = function ( $n, $o ) use ( $oid ) {
		return (int) $o->get_id() === $oid ? '4242' : $n;
	};
	add_filter( 'woocommerce_order_number', $num_cb, 99, 2 );
	$mode = 'match_then_401';
	$sum  = Eru_GHA_Approver::run( array( 'dry_run' => false ) );
	$fresh = wc_get_order( $oid );
	$check( '(h) run aborted on the failed page: nothing approved, order still on-hold, no ledger row', 'aborted' === $sum['status'] && array() === $sum['approved'] && 'on-hold' === $fresh->get_status() && array() === Eru_GHA_Ledger::used_ids( array( '2026-10-15T09:08:20.000500' ) ) );

	// No credential or token in the log table or the last-run option.
	$text = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(CONCAT_WS(' ', message, context) SEPARATOR ' ') FROM {$log_t} WHERE id > %d", $first_id ) ); // phpcs:ignore
	$text .= wp_json_encode( get_option( 'eru_gha_last_run' ) );
	$leak  = false;
	foreach ( array_merge( array( $demo_secret, $demo_client ), $tokens ) as $s ) {
		$leak = $leak || false !== strpos( $text, $s );
	}
	$check( 'no client secret, client id or token in the log', ! $leak );
} finally {
	$finish();
}
echo "OK gha-client\n";
