<?php
/**
 * Server-Side Refunds — queue processing, backoff, permanent failures, secrets, purge.
 *
 *   wp eval-file tests/php/ssr-queue.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_SSR_Queue' ) ) {
	echo "FAIL: Eru_SSR_Queue missing (plugin not active?)\n";
	exit( 1 );
}

// Router: mode decides the answer; any URL outside the two demo hosts exits 1.
$mode = 204;
$body = '';
$reqs = array();
$router = function ( $pre, $args, $url ) use ( &$mode, &$reqs, &$body ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! in_array( $host, array( 'www.google-analytics.com', 'analytics.example.com' ), true ) ) {
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	}
	$reqs[] = array( 'url' => $url, 'args' => $args );
	if ( 'timeout' === $mode ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	}
	return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => (int) $mode, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $router, 10, 3 );

$demo_ga_key = 'demo-' . 'secret-abc';
$demo_mt_key = 'demo' . 'token123';
$opt     = Eru_SSR_Settings::OPTION;
$old_opt = get_option( $opt, null );
update_option(
	$opt,
	array(
		'ga4_enabled' => 'yes', 'ga4_measurement_id' => 'G-DEMO000000', 'ga4_api_secret' => $demo_ga_key,
		'matomo_enabled' => 'yes', 'matomo_url' => 'https://analytics.example.com/', 'matomo_site_id' => '1', 'matomo_token' => $demo_mt_key, 'matomo_mode' => 'a',
		'ev_partial' => 'yes', 'ev_full' => 'yes', 'ev_cancelled' => 'yes', 'ev_failed' => 'yes',
		'send_user_id' => 'no', 'fallback_client_id' => 'yes', 'retention_days' => '30',
	),
	false
);

global $wpdb;
$t   = Eru_SSR_Queue::table();
$seq = 0;
$add = function ( $dest ) use ( &$seq ) {
	++$seq;
	$p = array( 'eru_ssr_event_type' => 'partial', 'transaction_id' => '9' . $seq, 'order_number' => '9' . $seq, 'currency' => 'TRY', 'value' => 300, 'client_id' => '111.222', 'user_id' => '', 'ts' => time(), 'refund_id' => $seq );
	Eru_SSR_Queue::insert( $dest, 'test:' . $seq . ':' . $dest, 900000 + $seq, $p );
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Eru_SSR_Queue::table() . ' WHERE dedupe_key = %s', 'test:' . $seq . ':' . $dest ) ); // phpcs:ignore
};
$row = function ( $id ) use ( $wpdb, $t ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
};

try {
	Eru_SSR_Queue::maybe_install();
	$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore

	// (g) 204 -> sent.
	$id = $add( 'ga4' );
	Eru_SSR_Queue::run();
	$r = $row( $id );
	$check( '(g) 204: sent_at set', ! empty( $r->sent_at ) && 1 === (int) $r->attempts );
	$check( '(g) GA4 request goes to /mp/collect with JSON body', false !== strpos( $reqs[0]['url'], 'www.google-analytics.com/mp/collect?' ) && 'refund' === json_decode( $reqs[0]['args']['body'], true )['events'][0]['name'] );
	$n = count( $reqs );
	Eru_SSR_Queue::run();
	$check( '(g) sent rows are not sent again', count( $reqs ) === $n );

	// (g) 500 -> attempts 1, next ~ +120s.
	$mode = 500;
	$id   = $add( 'matomo' );
	Eru_SSR_Queue::run();
	$r = $row( $id );
	$delta = strtotime( $r->next_attempt_at . ' UTC' ) - time();
	$check( '(g) 500: attempts 1, next_attempt_at ~ now+120s', 1 === (int) $r->attempts && empty( $r->sent_at ) && $delta >= 110 && $delta <= 130 );
	$check( '(g) backoff schedule: 120, 240, ... capped at 6h', 120 === Eru_SSR_Queue::backoff( 1 ) && 240 === Eru_SSR_Queue::backoff( 2 ) && 21600 === Eru_SSR_Queue::backoff( 9 ) );
	$n = count( $reqs );
	Eru_SSR_Queue::run();
	$check( '(g) not retried before it is due', count( $reqs ) === $n );

	// (h) token in body, not URL; nothing secret in stored error/payload.
	$last = end( $reqs );
	$check( '(h) Matomo token_auth is in the POST body, not the URL', false === strpos( $last['url'], $demo_mt_key ) && false === strpos( $last['url'], 'token_auth' ) && $demo_mt_key === ( $last['args']['body']['token_auth'] ?? '' ) );
	$check( '(h) Matomo request is a form POST to matomo.php', 'POST' === $last['args']['method'] && 'https://analytics.example.com/matomo.php' === $last['url'] );
	$r = $row( $id );
	$blob = $r->last_error . $r->payload;
	$check( '(h) last_error / payload hold no secret or token', false === strpos( $blob, $demo_mt_key ) && false === strpos( $blob, $demo_ga_key ) );
	$ga_id = $add( 'ga4' );
	$mode  = 500;
	Eru_SSR_Queue::run();
	$check( '(h) GA4 secret redacted in last_error', false === strpos( $row( $ga_id )->last_error, $demo_ga_key ) );
	$check( '(h) redact() strips api_secret and token_auth', false === strpos( Eru_SSR_Queue::redact( 'x?api_secret=abc&measurement_id=G-1 token_auth=zzz' ), 'abc' ) );

	// (g) WP_Error timeout -> retried.
	$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
	$mode = 'timeout';
	$id   = $add( 'ga4' );
	Eru_SSR_Queue::run();
	$r = $row( $id );
	$check( '(g) timeout (WP_Error): attempts 1 and rescheduled', 1 === (int) $r->attempts && ! empty( $r->next_attempt_at ) && false !== strpos( $r->last_error, 'timed out' ) );

	// (g) 400 -> failed immediately; 429 -> retried.
	$mode = 400;
	$id   = $add( 'ga4' );
	Eru_SSR_Queue::run();
	$r = $row( $id );
	$check( '(g) 400: failed immediately (no next attempt)', null === $r->next_attempt_at && empty( $r->sent_at ) && 1 === (int) $r->attempts && 'failed' === Eru_SSR_Queue::status_of( $r ) );
	$mode = 429;
	$id   = $add( 'ga4' );
	Eru_SSR_Queue::run();
	$check( '(g) 429: retried', null !== $row( $id )->next_attempt_at );

	// (g) after 10 failures -> failed.
	$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
	$mode = 503;
	$id   = $add( 'matomo' );
	for ( $i = 1; $i <= 10; $i++ ) {
		$wpdb->update( $t, array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $id ) );
		Eru_SSR_Queue::run();
		$st = Eru_SSR_Queue::status_of( $row( $id ) );
		if ( $i < 10 ) {
			$check( "(g) failure $i of 10 still pending", 'pending' === $st );
		}
	}
	$r = $row( $id );
	$check( '(g) after 10 failures: failed, attempts 10', 'failed' === Eru_SSR_Queue::status_of( $r ) && 10 === (int) $r->attempts );

	// Retry resets a failed row.
	Eru_SSR_Queue::retry( $id );
	$r = $row( $id );
	$check( 'retry(): failed row becomes pending with attempts 0', 'pending' === Eru_SSR_Queue::status_of( $r ) && 0 === (int) $r->attempts );

	// 204 on Matomo = success, 200 as well.
	$mode = 204;
	Eru_SSR_Queue::run();
	$check( 'Matomo 204 treated as success', 'sent' === Eru_SSR_Queue::status_of( $row( $id ) ) );

	// Dedupe at the DB level.
	$p = array( 'value' => 1 );
	Eru_SSR_Queue::insert( 'ga4', 'dup:1', 5, $p );
	Eru_SSR_Queue::insert( 'ga4', 'dup:1', 5, $p );
	$check( 'INSERT IGNORE on dedupe_key keeps one row', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE dedupe_key = 'dup:1'" ) ); // phpcs:ignore

	// (i) purge removes sent rows older than retention only.
	$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
	$old_sent = $add( 'ga4' );
	$new_sent = $add( 'ga4' );
	$old_pend = $add( 'ga4' );
	$old_fail = $add( 'ga4' );
	$new_fail = $add( 'ga4' );
	$wpdb->update( $t, array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'id' => $old_sent ) );
	$wpdb->update( $t, array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ), array( 'id' => $new_sent ) );
	$wpdb->update( $t, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ), array( 'id' => $old_pend ) );
	$wpdb->update( $t, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ), 'next_attempt_at' => null ), array( 'id' => $old_fail ) );
	$wpdb->update( $t, array( 'next_attempt_at' => null ), array( 'id' => $new_fail ) );
	$gone = Eru_SSR_Queue::purge();
	$check( '(i) purge deletes old sent and old failed rows only', 2 === $gone && null === $row( $old_sent ) && null === $row( $old_fail ) && null !== $row( $new_sent ) && null !== $row( $old_pend ) && null !== $row( $new_fail ) );

	// Sweep and purge chains stop once the queue is empty.
	if ( function_exists( 'as_has_scheduled_action' ) ) {
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
		as_unschedule_all_actions( 'eru_ssr_sweep' );
		as_unschedule_all_actions( 'eru_ssr_purge' );
		$add( 'ga4' );
		Eru_SSR_Queue::schedule();
		$check( 'rows present: sweep and purge are scheduled once each', as_has_scheduled_action( 'eru_ssr_sweep' ) && as_has_scheduled_action( 'eru_ssr_purge' ) );
		as_unschedule_all_actions( 'eru_ssr_sweep' );
		$mode = 503;
		Eru_SSR_Queue::sweep();
		$check( 'sweep with unsent rows chains exactly one next sweep', 1 === count( as_get_scheduled_actions( array( 'hook' => 'eru_ssr_sweep', 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) ) );
		// Inside Action Scheduler the sweep runs as an in-progress action; it must still re-arm.
		as_unschedule_all_actions( 'eru_ssr_sweep' );
		$running = as_schedule_single_action( time(), 'eru_ssr_sweep', array(), Eru_SSR_Queue::GROUP );
		ActionScheduler::store()->log_execution( $running );
		Eru_SSR_Queue::sweep();
		$check( 'sweep running as an action still chains the next sweep', 1 === count( as_get_scheduled_actions( array( 'hook' => 'eru_ssr_sweep', 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) ) );
		ActionScheduler::store()->mark_complete( $running );
		$mode = 204;
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
		as_unschedule_all_actions( 'eru_ssr_sweep' );
		as_unschedule_all_actions( 'eru_ssr_purge' );
		Eru_SSR_Queue::sweep();
		Eru_SSR_Queue::purge_job();
		$check( 'empty queue: sweep and purge are not scheduled again', ! as_has_scheduled_action( 'eru_ssr_sweep' ) && ! as_has_scheduled_action( 'eru_ssr_purge' ) );
	}

	// Test buttons.
	$mode = 200;
	$body = wp_json_encode( array( 'validationMessages' => array() ) );
	$r    = Eru_SSR_GA4::test();
	$check( 'GA4 test: debug endpoint, no messages = ok', $r['ok'] && false !== strpos( end( $reqs )['url'], '/debug/mp/collect?' ) );
	$body = wp_json_encode( array( 'validationMessages' => array( array( 'fieldPath' => 'events', 'description' => 'Demo problem', 'validationCode' => 'VALUE_INVALID' ) ) ) );
	$r    = Eru_SSR_GA4::test();
	$check( 'GA4 test: validationMessages reported, not ok', ! $r['ok'] && false !== strpos( implode( ' ', $r['messages'] ), 'Demo problem' ) );
	$mode = 'timeout';
	$r    = Eru_SSR_GA4::test();
	$check( 'GA4 test: transport error reported', ! $r['ok'] && false !== strpos( implode( ' ', $r['messages'] ), 'timed out' ) );
	$mode = 200;
	$body = wp_json_encode( array( 'status' => 'success', 'tracked' => 0, 'invalid' => 1, 'invalid_indices' => array( 0 ) ) );
	$r    = Eru_SSR_Matomo::test();
	$last = end( $reqs );
	$check( 'Matomo test: invalid=1/tracked=0 is ok; token in the JSON body only', $r['ok'] && false === strpos( $last['url'], $demo_mt_key ) && false !== strpos( $last['args']['body'], 'idsite=0' ) );
	$body = wp_json_encode( array( 'status' => 'success', 'tracked' => 1, 'invalid' => 0 ) );
	$r    = Eru_SSR_Matomo::test();
	$check( 'Matomo test: anything that would record data is not ok', ! $r['ok'] );
	$mode = 400;
	$body = wp_json_encode( array( 'message' => 'token_auth is not valid' ) );
	$r    = Eru_SSR_Matomo::test();
	$check( 'Matomo test: failure message surfaced', ! $r['ok'] && false !== strpos( implode( ' ', $r['messages'] ), 'not valid' ) );
	$body = '';

	// (j) guard.
	$mode = 204;
	$check( '(j) unmocked-HTTP guard is installed', false !== has_filter( 'pre_http_request', $router ) );
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
	$failed_run = true;
} finally {
	remove_filter( 'pre_http_request', $router, 10 );
	$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'eru_ssr_sweep' );
		as_unschedule_all_actions( 'eru_ssr_process' );
		as_unschedule_all_actions( 'eru_ssr_purge' );
	}
	null === $old_opt ? delete_option( $opt ) : update_option( $opt, $old_opt, false );
}
if ( ! empty( $failed_run ) ) {
	exit( 1 );
}
echo "ALL PASS\n";
