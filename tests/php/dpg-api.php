<?php
/**
 * Dopigo Integration — API client: token cache, 401 refresh, 429/503 retry through Action Scheduler
 * (no sleep), direct-token mode, and no credential in the log table.
 *
 *   wp eval-file tests/php/dpg-api.php
 */

$ph_local = '0' . implode( ' ', array( '555', '000', '00', '00' ) ); // Assembled: not real data.

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

foreach ( array( 'Eru_DPG_API', 'Eru_DPG_Settings', 'Eru_DPG_Cron', 'Eru_DPG_Logger', 'Eru_DPG_Products' ) as $eru_dpg_class ) {
	if ( ! class_exists( $eru_dpg_class ) ) {
		echo "FAIL: {$eru_dpg_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}

$demo_pass   = 'demo-' . 'api-pass-0000';
$demo_direct = 'demo-' . 'direct-key-0000';
$tokens      = array( 'demo-tok-' . 'aaaa1111', 'demo-tok-' . 'bbbb2222', 'demo-tok-' . 'cccc3333' );

// Router: auth answers with the next token; the products list answers by mode.
$auth_calls = 0;
$mode       = 'ok';      // ok | 401-first | 429 | 503 | timeout | auth-fail | 401
$reqs       = array();
$router = function ( $pre, $args, $url ) use ( &$auth_calls, &$mode, &$reqs, $tokens, $demo_pass ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$m    = strtoupper( $args['method'] ?? 'GET' );
	$resp = function ( $code, $body ) {
		return array( 'headers' => array(), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
	};
	if ( 'panel.dopigo.com' !== $host ) {
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	}
	$reqs[] = array( 'method' => $m, 'path' => $path, 'url' => $url, 'args' => $args );
	if ( 'POST' === $m && '/users/get_auth_token/' === $path ) {
		if ( 'auth-fail' === $mode ) {
			// A server that echoes the submitted form back: the log must still not contain it.
			return $resp( 400, array( 'non_field_errors' => array( 'Unable to log in.' ), 'echo' => 'password=' . $demo_pass ) );
		}
		$t = $tokens[ min( $auth_calls, count( $tokens ) - 1 ) ];
		++$auth_calls;
		return $resp( 200, array( 'token' => $t ) );
	}
	$auth = $args['headers']['Authorization'] ?? '';
	if ( 'GET' === $m && '/api/v1/products/all/' === $path ) {
		if ( '401-first' === $mode && 'Token ' . $tokens[0] === $auth ) {
			return $resp( 401, array( 'detail' => 'Invalid token.' ) );
		}
		if ( '401' === $mode ) {
			return $resp( 401, array( 'detail' => 'Invalid token ' . substr( $auth, 6 ) ) );
		}
		if ( '429' === $mode || '503' === $mode ) {
			return $resp( (int) $mode, 'slow down' );
		}
		if ( 'timeout' === $mode ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		return $resp( 200, array( 'count' => 0, 'next' => null, 'results' => array() ) );
	}
	if ( 'PUT' === $m && 0 === strpos( $path, '/api/v1/products/update_product_by_foreign_sku/' ) ) {
		if ( '429' === $mode ) {
			return $resp( 429, 'rate limited, token ' . substr( $auth, 6 ) );
		}
		return $resp( 200, array( 'ok' => true ) );
	}
	echo 'FAIL unmocked HTTP ' . $m . ' ' . $url . "\n";
	exit( 1 );
};
add_filter( 'pre_http_request', $router, 10, 3 );

$pending_ids = function ( $hook, $args = null ) {
	$q = array( 'hook' => $hook, 'group' => 'eru-dpg', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 100 );
	if ( null !== $args ) {
		$q['args'] = $args;
	}
	return as_get_scheduled_actions( $q, 'ids' );
};

$old_settings = get_option( Eru_DPG_Settings::OPTION, null );
$product      = null;
$cleanup = function () use ( &$product ) {
	$id = wc_get_product_id_by_sku( 'DEMO-API-001' );
	if ( $id ) {
		wc_get_product( $id )->delete( true );
	}
	as_unschedule_all_actions( 'eru_dpg_push_product', null, 'eru-dpg' );
	delete_option( 'eru_dpg_token' );
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_DPG_Logger::table() . ' WHERE ref = %s', 'dpg-api-test' ) ); // phpcs:ignore
};
$cleanup();

global $wpdb;
$log_t    = Eru_DPG_Logger::table();
$first_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$log_t}" ); // phpcs:ignore

update_option( Eru_DPG_Settings::OPTION, array(), false );
Eru_DPG_Settings::save_for_test(
	array(
		'enabled'   => 'yes',
		'auth_mode' => 'password',
		'username'  => 'demo-user',
		'password'  => $demo_pass,
		'dry_run'   => 'no',
	)
);

try {
	// -----------------------------------------------------------------------------------------
	// (h) 401 → refresh → 200.
	// -----------------------------------------------------------------------------------------
	$mode = '401-first';
	$api  = new Eru_DPG_API();
	$r    = $api->get_products( 10, 0 );
	$check( '(h) 401 → token refresh → 200 succeeds', ! is_wp_error( $r ) && array() === $r );
	$check( '(h) exactly 2 auth calls (initial + refresh)', 2 === $auth_calls );
	$tok = get_option( 'eru_dpg_token' );
	$check( '(h) refreshed token cached for 12 h in a non-autoloaded option', is_array( $tok ) && $tokens[1] === $tok['token'] && $tok['expires'] > time() + 11 * HOUR_IN_SECONDS && $tok['expires'] <= time() + 12 * HOUR_IN_SECONDS + 5 && ! in_array( (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'eru_dpg_token' ) ), array( 'yes', 'on', 'auto-on', 'auto' ), true ) );
	$mode = 'ok';
	$api->get_products( 10, 0 );
	$check( '(h) the cached token is reused (no third auth call)', 2 === $auth_calls );
	$auth_post = array_values( array_filter( $reqs, fn( $x ) => '/users/get_auth_token/' === $x['path'] ) );
	$check( '(h) credentials go in the POST body, never in the URL', false === strpos( $auth_post[0]['url'], $demo_pass ) && false === strpos( $auth_post[0]['url'], 'password' ) && $demo_pass === ( $auth_post[0]['args']['body']['password'] ?? '' ) );

	// A second 401 after refresh is an error, not a loop.
	$mode = '401';
	$n    = count( $reqs );
	$r    = $api->get_products( 10, 0 );
	$check( '(h) persistent 401: one refresh, then a non-retryable error (3 requests)', is_wp_error( $r ) && ! Eru_DPG_API::is_retryable( $r ) && 3 === count( $reqs ) - $n );

	// -----------------------------------------------------------------------------------------
	// (h) 429 / 503 / timeout → retryable, no sleep.
	// -----------------------------------------------------------------------------------------
	foreach ( array( '429', '503', 'timeout' ) as $m ) {
		$mode = $m;
		$n    = count( $reqs );
		$t0   = microtime( true );
		$r    = $api->get_products( 10, 0 );
		$el   = microtime( true ) - $t0;
		$check( "(h) {$m}: retryable error after a single request, no sleep (" . round( $el, 3 ) . ' s)', is_wp_error( $r ) && Eru_DPG_API::is_retryable( $r ) && 1 === count( $reqs ) - $n && $el < 1.0 );
	}

	$check( '(h) backoff 1, 2, 4, 8, 16, 30, 30 minutes', array( 1, 2, 4, 8, 16, 30, 30 ) === array_map( array( 'Eru_DPG_Cron', 'backoff_minutes' ), array( 1, 2, 3, 4, 5, 6, 9 ) ) );

	// Queued product push hits 429 → AS action rescheduled ~1 min ahead, nothing slept.
	Eru_DPG_Settings::save_for_test( array( 'stock_direction' => 'wc_to_dp', 'queue_updates' => 'yes' ) );
	$product = new WC_Product_Simple();
	$product->set_name( 'Demo Ürün API' );
	$product->set_sku( 'DEMO-API-001' );
	$product->set_regular_price( '1000' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 4 );
	$product->save();
	$pid = $product->get_id();
	$check( '(h) product save queued one push', 1 === count( $pending_ids( 'eru_dpg_push_product', array( $pid ) ) ) );
	as_unschedule_all_actions( 'eru_dpg_push_product', null, 'eru-dpg' );

	$mode = '429';
	$t0   = microtime( true );
	do_action( 'eru_dpg_push_product', $pid );
	$el   = microtime( true ) - $t0;
	$ids  = $pending_ids( 'eru_dpg_push_product', array( $pid ) );
	$next = $ids ? as_next_scheduled_action( 'eru_dpg_push_product', array( $pid ), 'eru-dpg' ) : 0;
	$check( '(h) 429 on the queued push → one retry action scheduled, elapsed < 1 s (' . round( $el, 3 ) . ' s)', 1 === count( $ids ) && $el < 1.0 );
	$check( '(h) first retry ~1 minute ahead', $next >= time() + 50 && $next <= time() + 70 );

	// Simulate the retry running inside Action Scheduler (its own action is "running", not pending).
	$running = reset( $ids );
	ActionScheduler::store()->log_execution( $running );
	do_action( 'eru_dpg_push_product', $pid );
	ActionScheduler::store()->mark_complete( $running );
	$ids  = $pending_ids( 'eru_dpg_push_product', array( $pid ) );
	$next = $ids ? as_next_scheduled_action( 'eru_dpg_push_product', array( $pid ), 'eru-dpg' ) : 0;
	$check( '(h) second 429 while running re-arms itself once, ~2 minutes ahead', 1 === count( $ids ) && $next >= time() + 110 && $next <= time() + 130 );

	$mode = 'ok';
	$running = reset( $ids );
	ActionScheduler::store()->log_execution( $running );
	do_action( 'eru_dpg_push_product', $pid );
	ActionScheduler::store()->mark_complete( $running );
	$check( '(h) success clears the retry: nothing pending, attempt counter reset', 0 === count( $pending_ids( 'eru_dpg_push_product', array( $pid ) ) ) && 0 === Eru_DPG_Cron::attempts( 'eru_dpg_push_product', array( $pid ) ) );

	// -----------------------------------------------------------------------------------------
	// Direct-token mode: no auth call, 401 is not refreshed.
	// -----------------------------------------------------------------------------------------
	Eru_DPG_Settings::save_for_test( array( 'auth_mode' => 'token', 'token' => $demo_direct, 'stock_direction' => 'off' ) );
	$a0   = $auth_calls;
	$mode = 'ok';
	$n    = count( $reqs );
	$r    = ( new Eru_DPG_API() )->get_products( 10, 0 );
	$last = end( $reqs );
	$check( '(h) direct token mode sends the key as Token header without an auth call', ! is_wp_error( $r ) && $a0 === $auth_calls && 'Token ' . $demo_direct === $last['args']['headers']['Authorization'] );
	$mode = '401';
	$n    = count( $reqs );
	$r    = ( new Eru_DPG_API() )->get_products( 10, 0 );
	$check( '(h) direct token mode: 401 is reported without refresh', is_wp_error( $r ) && 1 === count( $reqs ) - $n && $a0 === $auth_calls );

	// Failed login with a server that echoes the password.
	Eru_DPG_Settings::save_for_test( array( 'auth_mode' => 'password' ) );
	delete_option( 'eru_dpg_token' );
	$mode = 'auth-fail';
	$r    = ( new Eru_DPG_API() )->get_products( 10, 0 );
	$check( '(h) failed login → error, no token cached', is_wp_error( $r ) && false === get_option( 'eru_dpg_token' ) );

	// Logger redaction helper.
	$pw_key = 'pass' . 'word';
	$tk_key = 'tok' . 'en';
	Eru_DPG_Logger::log( 'auth', 'info', 'Authorization: Token ' . $tokens[2] . ' ' . $pw_key . '=' . $demo_pass, array( $pw_key => $demo_pass, $tk_key => $tokens[2], 'note' => 'ornek@example.com ' . $ph_local ), '', 'dpg-api-test' );

	// -----------------------------------------------------------------------------------------
	// (j) No password / token in the log table.
	// -----------------------------------------------------------------------------------------
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$log_t} WHERE id > %d", $first_id ), ARRAY_A ); // phpcs:ignore
	$blob = wp_json_encode( $rows );
	$check( '(j) the run wrote log rows', count( $rows ) >= 3 );
	$leaks = array();
	foreach ( array_merge( array( $demo_pass, $demo_direct ), $tokens ) as $secret ) {
		if ( false !== strpos( $blob, $secret ) ) {
			$leaks[] = $secret;
		}
	}
	$check( '(j) no password or token value in any log row', array() === $leaks );
	$check( '(j) personal data in log text is masked', false === strpos( $blob, 'ornek@example.com' ) && false === strpos( $blob, $ph_local ) );
	$check( '(j) settings option (credentials) is not autoloaded', ! in_array( (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Eru_DPG_Settings::OPTION ) ), array( 'yes', 'on', 'auto-on', 'auto' ), true ) );

	echo "ALL PASS\n";
} catch ( Throwable $e ) {
	echo 'ERROR: ' . $e->getMessage() . "\n";
	$cleanup();
	null === $old_settings ? delete_option( Eru_DPG_Settings::OPTION ) : update_option( Eru_DPG_Settings::OPTION, $old_settings, false );
	exit( 1 );
}
$cleanup();
null === $old_settings ? delete_option( Eru_DPG_Settings::OPTION ) : update_option( Eru_DPG_Settings::OPTION, $old_settings, false );
remove_filter( 'pre_http_request', $router, 10 );
