<?php
/**
 * Consent Manager for İYS — queue and İYS client: batching, async status mapping, token cache and
 * 401 handling, pull limit and cursor, backoff, stale requests, pending-only re-arm, watchdog and logs.
 *
 *   wp eval-file tests/php/cmi-queue.php
 */

require __DIR__ . '/cmi-helpers.php';
cmi_require_plugin();
$fake = cmi_fake_iys();

global $wpdb;
$old_settings = get_option( Eru_CMI_Settings::OPTION, null );
$failed       = false;
$qt           = $wpdb->prefix . 'eru_cmi_queue';

try {
	cmi_reset();
	cmi_settings();

	// (d) 1.200 queued, batch 500 -> three POSTs 500/500/200 with distinct request IDs.
	for ( $i = 1; $i <= 1200; $i++ ) {
		Eru_CMI_Repository::set( "demo{$i}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin' ) );
	}
	cmi_check( '(d) 1.200 rows queued', 1200 === cmi_count( 'queue', "status = 'queued'" ) );
	cmi_check( '(h) enqueue arms one flush ~30 s out and one tick (pending)', 1 === count( cmi_pending( 'eru_cmi_flush' ) ) && 1 === count( cmi_pending( 'eru_cmi_tick' ) ) );
	$next = as_next_scheduled_action( 'eru_cmi_flush', array(), 'eru-cmi' );
	cmi_check( '(h) flush is due about 30 s after enqueue', is_int( $next ) && $next - time() >= 20 && $next - time() <= 35 );
	$t0 = microtime( true );
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::flush();
	$posts = $fake->find( '/consents/request' );
	$sizes = array_map( fn( $p ) => count( $p['body'] ), $posts );
	cmi_check( '(d) three batch POSTs of 500/500/200 (a fourth flush sends nothing)', array( 500, 500, 200 ) === $sizes );
	$req_ids = $wpdb->get_col( "SELECT DISTINCT request_id FROM {$qt} WHERE status = 'sent'" ); // phpcs:ignore
	cmi_check( '(d) each batch has its own request_id; rows carry sub_index 0..n-1', 3 === count( array_unique( $req_ids ) ) && 1200 === cmi_count( 'queue', "status = 'sent'" ) && 499 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sub_index) FROM {$qt} WHERE request_id = %s", $req_ids[0] ) ) ); // phpcs:ignore
	cmi_check( '(e) one password grant for every call so far', 1 === $fake->password_grants && 0 === $fake->refresh_grants );

	// Async status: in progress -> nothing changes.
	$fake->status_mode = 'pending';
	Eru_CMI_Queue::poll();
	cmi_check( '(d) status in progress (202): rows stay sent', 1200 === cmi_count( 'queue', "status = 'sent'" ) );
	$fake->status_mode            = 'ok';
	$fake->failures['demo-req-1'] = array( 3 => 'H120' );
	Eru_CMI_Queue::poll();
	$rej = cmi_queue_rows( "status = 'rejected'" );
	cmi_check( '(d) sub-index 3 of request 1 rejected with the İYS code, all others accepted', 1 === count( $rej ) && 'demo-req-1' === $rej[0]->request_id && 3 === (int) $rej[0]->sub_index && false !== strpos( $rej[0]->last_error, 'H120' ) && 1199 === cmi_count( 'queue', "status = 'accepted'" ) );
	$c4 = Eru_CMI_Repository::get( 'demo4@example.com', 'EPOSTA' );
	$c5 = Eru_CMI_Repository::get( 'demo5@example.com', 'EPOSTA' );
	cmi_check( '(d) consent rows mirror it: demo4 rejected, demo5 accepted with sync time', 'rejected' === $c4->iys_status && 'accepted' === $c5->iys_status && ! empty( $c5->iys_synced_at ) && empty( $c4->iys_synced_at ) );
	cmi_check( '(e) still one password grant after the status polls', 1 === $fake->password_grants );

	// A batch-level error naming one index: only that row is rejected, the others wait without losing an attempt.
	cmi_reset();
	cmi_settings();
	for ( $i = 1; $i <= 4; $i++ ) {
		Eru_CMI_Repository::set( "idx{$i}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	$fake->batch_mode  = 'index-error';
	$fake->index_error = 1;
	Eru_CMI_Queue::flush();
	$q = cmi_queue_rows();
	cmi_check( '(d) indexed batch error: row 1 rejected (H120), rows 0/2/3 still queued with 0 attempts', 'rejected' === $q[1]->status && false !== strpos( $q[1]->last_error, 'H120' ) && 'queued' === $q[0]->status && 'queued' === $q[2]->status && 0 === (int) $q[0]->attempts );
	$fake->batch_mode = 'ok';
	Eru_CMI_Queue::flush();
	cmi_check( '(d) next flush sends the remaining three', 3 === cmi_count( 'queue', "status = 'sent'" ) );

	// (e) 401 -> refresh once -> retry succeeds.
	$fake->expired[] = 'demo-acc-1';
	$fake->expired[] = 'demo-acc-2'; // In case the cache was rebuilt after the reset.
	$grants          = $fake->password_grants;
	$tok             = Eru_CMI_Client::cached_access_token_for_test();
	$fake->expired[] = $tok;
	Eru_CMI_Repository::set( 'tok1@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$before = count( $fake->find( '/consents/request' ) );
	Eru_CMI_Queue::flush();
	cmi_check( '(e) 401: exactly one refresh grant, no password grant, retry succeeded', 1 === $fake->refresh_grants && $grants === $fake->password_grants && 'sent' === Eru_CMI_Repository::get( 'tok1@example.com', 'EPOSTA' )->iys_status && 2 === count( $fake->find( '/consents/request' ) ) - $before );
	// Refresh fails -> full re-auth.
	$fake->expired[]     = Eru_CMI_Client::cached_access_token_for_test();
	$fake->refresh_fails = true;
	Eru_CMI_Repository::set( 'tok2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	cmi_check( '(e) refresh rejected -> password grant -> retry succeeded', 2 === $fake->refresh_grants && $grants + 1 === $fake->password_grants && 'sent' === Eru_CMI_Repository::get( 'tok2@example.com', 'EPOSTA' )->iys_status );
	$fake->refresh_fails = false;
	cmi_check( '(e) token cache key carries env + username hash, never the username', false !== strpos( Eru_CMI_Client::token_key_for_test(), 'sandbox' ) && false === strpos( Eru_CMI_Client::token_key_for_test(), 'demo-user' ) );

	// Stale requests: older than 24 h without a final status -> re-queued once, then failed.
	$fake->status_mode = 'pending';
	$sid               = (int) $wpdb->get_var( "SELECT id FROM {$qt} WHERE status = 'sent' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore
	$wpdb->update( $qt, array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ), array( 'id' => $sid ) );
	Eru_CMI_Queue::poll();
	$r = cmi_queue_rows( 'id = ' . $sid )[0];
	cmi_check( '(g) stale > 24 h: re-queued once (request id cleared)', 'queued' === $r->status && 1 === (int) $r->requeued && empty( $r->request_id ) );
	$fake->status_mode = 'ok';
	cmi_make_due();
	$fake->status_mode = 'pending';
	Eru_CMI_Queue::flush();
	$wpdb->update( $qt, array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ), array( 'id' => $sid ) );
	Eru_CMI_Queue::poll();
	cmi_check( '(g) stale again after the re-queue -> failed', 'failed' === cmi_queue_rows( 'id = ' . $sid )[0]->status );
	$fake->status_mode = 'ok';

	// (f) Pull: limit 1000 -> 999; cursor stored only after a successful page.
	delete_option( Eru_CMI_Pull::cursor_option() );
	$fake->pull_mode = '500';
	$res             = Eru_CMI_Pull::page( 1000 );
	$pulls           = $fake->find( '/consents/changes' );
	cmi_check( '(f) requested limit 1000 -> URL carries limit=999', '999' === ( end( $pulls )['query']['limit'] ?? '' ) );
	cmi_check( '(f) failed page: no cursor stored', ! $res['ok'] && '' === (string) get_option( Eru_CMI_Pull::cursor_option(), '' ) );
	Eru_CMI_Repository::set( 'pull-a@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'consent_date' => time() - HOUR_IN_SECONDS ) );
	$queued_before   = cmi_count( 'queue' );
	$pa_row          = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$qt}" ); // phpcs:ignore
	$fake->pull_mode = 'ok';
	$res             = Eru_CMI_Pull::page( 1000 );
	cmi_check( '(f) successful page: cursor stored, two changes applied', $res['ok'] && 'demo-cursor-1' === get_option( Eru_CMI_Pull::cursor_option() ) && 2 === $res['applied'] );
	$pa = Eru_CMI_Repository::get( 'pull-a@example.com', 'EPOSTA' );
	$ph = Eru_CMI_History::for_consent( $pa->id );
	cmi_check( '(f) İYS change applied locally with actor iys, never re-queued', 'RET' === $pa->status && 'iys' === end( $ph )->actor && $queued_before === cmi_count( 'queue' ) && 'accepted' === $pa->iys_status );
	cmi_check( '(f) the older local change still waiting in the queue is superseded, not sent over İYS', 'superseded' === cmi_queue_rows( 'id = ' . $pa_row )[0]->status );
	$pb = Eru_CMI_Repository::get( 'pull-b@example.com', 'EPOSTA' );
	cmi_check( '(f) a recipient new to the store is created from İYS as accepted', $pb && 'ONAY' === $pb->status && 'accepted' === $pb->iys_status && ! empty( $pb->iys_synced_at ) );
	$fake->pull_cursor = 'demo-cursor-2';
	$fake->pull_mode   = '500';
	Eru_CMI_Pull::page( 50 );
	$pulls = $fake->find( '/consents/changes' );
	cmi_check( '(f) next page sends after=<stored cursor>; a failed page keeps the old cursor', 'demo-cursor-1' === ( end( $pulls )['query']['after'] ?? '' ) && '50' === end( $pulls )['query']['limit'] && 'demo-cursor-1' === get_option( Eru_CMI_Pull::cursor_option() ) );
	$fake->pull_mode = 'h509';
	Eru_CMI_Pull::page( 50 );
	cmi_check( '(f) cursor rejected by İYS (H509) -> cursor reset', '' === (string) get_option( Eru_CMI_Pull::cursor_option(), '' ) );
	$fake->pull_mode = 'ok';

	// (g) Backoff: 503 -> attempts 1, next try ~ now + 300 s; 5 failures -> failed; no sleep.
	cmi_reset();
	cmi_settings();
	Eru_CMI_Repository::set( 'backoff@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$fake->batch_mode = '503';
	$t0               = microtime( true );
	Eru_CMI_Queue::flush();
	$el = microtime( true ) - $t0;
	$r  = cmi_queue_rows()[0];
	$d  = strtotime( $r->next_try_at . ' UTC' ) - time();
	cmi_check( '(g) 503: attempts 1, next_try_at ~ now+300 s, still queued', 1 === (int) $r->attempts && 'queued' === $r->status && $d >= 290 && $d <= 305 );
	cmi_check( '(g) no sleep: the failing flush took < 1 s', $el < 1.0 );
	cmi_check( '(g) backoff schedule 5/20/60/180/600 min, six attempts', array( 300, 1200, 3600, 10800, 36000 ) === array_map( array( 'Eru_CMI_Queue', 'backoff' ), array( 1, 2, 3, 4, 5 ) ) && 6 === Eru_CMI_Queue::MAX_ATTEMPTS );
	$n = count( $fake->find( '/consents/request' ) );
	Eru_CMI_Queue::flush();
	cmi_check( '(g) not retried before it is due', count( $fake->find( '/consents/request' ) ) === $n );
	$fake->batch_mode = 'timeout';
	for ( $i = 2; $i <= 5; $i++ ) {
		cmi_make_due();
		Eru_CMI_Queue::flush();
	}
	cmi_check( '(g) after 5 failures still queued (one attempt left)', 'queued' === cmi_queue_rows()[0]->status && 5 === (int) cmi_queue_rows()[0]->attempts );
	cmi_make_due();
	Eru_CMI_Queue::flush();
	$r = cmi_queue_rows()[0];
	cmi_check( '(g) after 6 failures (503/timeouts) -> failed, attempts 6', 'failed' === $r->status && 6 === (int) $r->attempts && 'failed' === Eru_CMI_Repository::get( 'backoff@example.com', 'EPOSTA' )->iys_status );
	$fake->batch_mode = 'ok';
	cmi_check( '(g) retry() puts failed rows back with attempts 0', 1 === Eru_CMI_Queue::retry() && 'queued' === cmi_queue_rows()[0]->status && 0 === (int) cmi_queue_rows()[0]->attempts );

	// (h) Re-arm while the flush action is RUNNING: exactly one new pending flush.
	cmi_reset();
	cmi_settings();
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore
	for ( $i = 1; $i <= 600; $i++ ) {
		Eru_CMI_Repository::set( "rearm{$i}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	$wpdb->query( 'COMMIT' ); // phpcs:ignore
	Eru_CMI_Scheduler::unschedule_all();
	Eru_CMI_Scheduler::reset_memo();
	$running = as_schedule_single_action( time(), 'eru_cmi_flush', array(), 'eru-cmi' );
	ActionScheduler::store()->log_execution( $running );
	cmi_check( '(h) setup: no pending flush, one running', array() === cmi_pending( 'eru_cmi_flush' ) );
	Eru_CMI_Scheduler::run_flush();
	cmi_check( '(h) callback sent one batch of 500, 100 still due', 100 === cmi_count( 'queue', "status = 'queued'" ) );
	cmi_check( '(h) exactly one new PENDING flush action', 1 === count( cmi_pending( 'eru_cmi_flush' ) ) );
	ActionScheduler::store()->mark_complete( $running );
	Eru_CMI_Scheduler::run_flush();
	cmi_check( '(h) a second run drains the rest and leaves exactly one pending (no duplicates)', 0 === cmi_count( 'queue', "status = 'queued'" ) && count( cmi_pending( 'eru_cmi_flush' ) ) <= 1 );
	// Master switch off -> nothing sent, nothing scheduled.
	Eru_CMI_Scheduler::unschedule_all();
	$wpdb->query( "UPDATE {$qt} SET status = 'queued', request_id = NULL, sub_index = NULL" ); // phpcs:ignore
	cmi_make_due();
	cmi_settings( array( 'enabled' => 'no' ) );
	Eru_CMI_Scheduler::unschedule_all();
	$n       = count( $fake->reqs );
	$running = as_schedule_single_action( time(), 'eru_cmi_flush', array(), 'eru-cmi' );
	ActionScheduler::store()->log_execution( $running );
	Eru_CMI_Scheduler::run_flush();
	Eru_CMI_Scheduler::run_tick();
	cmi_check( '(h) master off: callback sends nothing and schedules nothing', count( $fake->reqs ) === $n && array() === cmi_pending_group() );
	ActionScheduler::store()->mark_complete( $running );

	// Watchdog: switch on with a backlog and no job -> one tick; a second run adds none.
	cmi_settings();
	Eru_CMI_Scheduler::unschedule_all();
	Eru_CMI_Scheduler::reset_memo();
	Eru_CMI_Scheduler::watchdog( true );
	Eru_CMI_Scheduler::watchdog( true );
	cmi_check( '(h) watchdog re-arms a missing tick exactly once', 1 === count( cmi_pending( 'eru_cmi_tick' ) ) );
	update_option( 'eru_cmi_watchdog_at', time(), true );
	Eru_CMI_Scheduler::unschedule_all();
	Eru_CMI_Scheduler::watchdog();
	cmi_check( '(h) watchdog is cheap: at most once per hour', array() === cmi_pending( 'eru_cmi_tick' ) );
	// The tick re-arms itself (pending-only) while work is open, even when running as an action.
	$running = as_schedule_single_action( time(), 'eru_cmi_tick', array(), 'eru-cmi' );
	ActionScheduler::store()->log_execution( $running );
	Eru_CMI_Scheduler::run_tick();
	cmi_check( '(h) running tick with open work chains exactly one pending tick', 1 === count( cmi_pending( 'eru_cmi_tick' ) ) );
	ActionScheduler::store()->mark_complete( $running );
	$wpdb->query( "DELETE FROM {$qt}" ); // phpcs:ignore
	Eru_CMI_Scheduler::unschedule_all();
	Eru_CMI_Scheduler::run_tick();
	cmi_check( '(h) empty queue: the tick stops (nothing pending)', array() === cmi_pending( 'eru_cmi_tick' ) && array() === cmi_pending( 'eru_cmi_flush' ) );
	// Deactivation drops every job.
	Eru_CMI_Repository::set( 'deact@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Plugin::deactivate();
	cmi_check( 'deactivation unschedules every eru-cmi job', array() === cmi_pending_group() );

	// (i) Logs: no password, no token, no full recipient; bodies capped at 8 KB.
	$lt   = $wpdb->prefix . 'eru_cmi_log';
	$logs = $wpdb->get_results( "SELECT * FROM {$lt}" ); // phpcs:ignore
	$blob = implode( "\n", array_map( fn( $l ) => $l->endpoint . ' ' . $l->request . ' ' . $l->response, $logs ) );
	cmi_check( '(i) API calls were logged', count( $logs ) > 3 && false !== strpos( $blob, '/oauth2/token' ) );
	cmi_check( '(i) no password in the log', false === strpos( $blob, cmi_secret() ) );
	cmi_check( '(i) no access or refresh token in the log', false === strpos( $blob, 'demo-acc-' ) && false === strpos( $blob, 'demo-ref-' ) );
	cmi_check( '(i) no full recipient in the log', false === strpos( $blob, 'demo1@example.com' ) && false === strpos( $blob, 'rearm1@example.com' ) && false === strpos( $blob, 'pull-a@example.com' ) && false === strpos( $blob, cmi_phone_e164() ) );
	cmi_check( '(i) every logged body is at most 8 KB', 0 === count( array_filter( $logs, fn( $l ) => strlen( (string) $l->request ) > 8192 || strlen( (string) $l->response ) > 8192 ) ) );
	$wpdb->update( $lt, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ), array( 'id' => $logs[0]->id ) );
	Eru_CMI_Logger::purge();
	cmi_check( '(i) log retention: rows older than 7 days are purged', null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$lt} WHERE id = %d", $logs[0]->id ) ) && count( $logs ) - 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt}" ) ); // phpcs:ignore
	cmi_check( '(i) redact() masks phones and e-mails and strips secrets', false === strpos( Eru_CMI_Logger::redact( '{"recipient":"' . cmi_phone_e164() . '","password":"x1","access_token":"y2"} ornek@example.com Bearer abc.def' ), cmi_phone_e164() ) && false === strpos( Eru_CMI_Logger::redact( 'ornek@example.com Bearer abcdef "refresh_token":"' . str_repeat( 'z', 6 ) . '"' ), str_repeat( 'z', 6 ) ) && false === strpos( Eru_CMI_Logger::redact( 'ornek@example.com' ), 'ornek@example.com' ) );

	// Fix round 1 — environment isolation: sandbox rows never reach live; "known at İYS" is per environment.
	cmi_reset();
	cmi_settings();
	foreach ( array( 'env1', 'env2', 'envk' ) as $u ) {
		Eru_CMI_Repository::set( "{$u}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	cmi_check( '[env] sandbox: three rows accepted, rows tagged sandbox', 3 === cmi_count( 'queue', "status = 'accepted' AND env = 'sandbox'" ) );
	Eru_CMI_Repository::set( 'env3@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Repository::set( 'env4@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Repository::set( 'env5@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$snap = wp_json_encode( cmi_queue_rows() );
	cmi_settings( array( 'environment' => 'live' ) );
	Eru_CMI_Scheduler::unschedule_all();
	$n = count( $fake->reqs );
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	Eru_CMI_Scheduler::run_flush();
	Eru_CMI_Scheduler::run_tick();
	Eru_CMI_Queue::retry( null, true );
	cmi_check( '[env] switched to live: flush/poll/tick/retry make zero HTTP calls', count( $fake->reqs ) === $n );
	cmi_check( '[env] switched to live: sandbox rows untouched', wp_json_encode( cmi_queue_rows() ) === $snap );
	cmi_check( '[env] live sees no open or due work, counts are per environment', ! Eru_CMI_Queue::has_open() && ! Eru_CMI_Queue::has_due() && 0 === array_sum( Eru_CMI_Queue::counts() ) && array() === cmi_pending_group() );
	$k = Eru_CMI_Repository::set( 'envk@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	cmi_check( '[env] accepted in sandbox is not "known" in live: first live RET skipped (H175)', 'skipped' === $k['queue'] && false !== strpos( $k['queue_reason'], 'H175' ) );
	Eru_CMI_Repository::set( 'envlive@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	$live = array_filter( array_slice( $fake->reqs, $n ), fn( $r ) => false !== strpos( $r['url'], 'https://api.iys.org.tr/' ) && false !== strpos( $r['path'], '/consents/request' ) );
	cmi_check( '[env] a live row goes to the live host with only live records', 1 === count( $live ) && 1 === count( array_values( $live )[0]['body'] ) && 'envlive@example.com' === array_values( $live )[0]['body'][0]['recipient'] );
	cmi_settings( array( 'environment' => 'sandbox' ) );
	cmi_check( '[env] back to sandbox: the switch re-arms the tick for the sandbox backlog', 1 === count( cmi_pending( 'eru_cmi_tick' ) ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	cmi_check( '[env] back to sandbox: waiting rows flush and resolve', 0 === cmi_count( 'queue', "env = 'sandbox' AND status IN ('queued','sent')" ) && 6 === cmi_count( 'queue', "env = 'sandbox' AND status = 'accepted'" ) );
	cmi_check( '[env] the live rows were not touched by the sandbox run', 1 === cmi_count( 'queue', "env = 'live' AND status = 'sent'" ) );

	// Fix round 1 — ambiguous failure: a client batch key is stored before the POST; the next run looks records up instead of resending.
	cmi_reset();
	cmi_settings();
	$fake->store = array();
	$looks0      = count( $fake->find( '/consents/status' ) );
	Eru_CMI_Repository::set( 'amb1@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Repository::set( 'amb2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$fake->batch_mode = 'timeout-after';
	Eru_CMI_Queue::flush();
	$q = cmi_queue_rows();
	cmi_check( '[amb] timeout after İYS processed the batch: rows keep their batch key, attempt 1', 'queued' === $q[0]->status && 1 === (int) $q[0]->attempts && '' !== (string) $q[0]->batch_key && $q[0]->batch_key === $q[1]->batch_key );
	$fake->batch_mode = 'ok';
	cmi_make_due();
	$posts = count( $fake->find( '/consents/request' ) );
	Eru_CMI_Queue::flush();
	cmi_check( '[amb] next run looks both records up and does not resend', $looks0 + 2 === count( $fake->find( '/consents/status' ) ) && count( $fake->find( '/consents/request' ) ) === $posts && 2 === cmi_count( 'queue', "status = 'accepted'" ) );
	$fake->batch_mode = 'timeout';
	Eru_CMI_Repository::set( 'amb3@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	$fake->batch_mode = 'ok';
	cmi_make_due();
	Eru_CMI_Queue::flush();
	cmi_check( '[amb] not processed before the timeout: look-up says absent, record is resent', $looks0 + 3 === count( $fake->find( '/consents/status' ) ) && 'sent' === Eru_CMI_Repository::get( 'amb3@example.com', 'EPOSTA' )->iys_status && count( $fake->find( '/consents/request' ) ) === $posts + 2 );

	$sl = $wpdb->get_col( 'SELECT CONCAT(request, response) FROM ' . $wpdb->prefix . "eru_cmi_log WHERE endpoint LIKE '%/consents/status'" ); // phpcs:ignore
	cmi_check( '[amb] look-ups are logged with masked recipients only', count( $sl ) >= 3 && false === strpos( implode( ' ', $sl ), 'amb1@example.com' ) );

	// Fix round 1 — one malformed record (unindexed 400) is isolated by splitting; the rest go through.
	cmi_reset();
	cmi_settings();
	for ( $i = 1; $i <= 9; $i++ ) {
		Eru_CMI_Repository::set( ( 6 === $i ? 'bad' : 'ok' ) . "{$i}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	$fake->batch_mode = 'poison';
	$posts            = count( $fake->find( '/consents/request' ) );
	Eru_CMI_Queue::flush();
	$bad = Eru_CMI_Repository::get( 'bad6@example.com', 'EPOSTA' );
	cmi_check( '[split] unindexed 400: only the bad record is rejected, the other 8 are sent', 'rejected' === $bad->iys_status && 8 === cmi_count( 'queue', "status = 'sent'" ) && false !== strpos( cmi_queue_rows( "status = 'rejected'" )[0]->last_error, 'H120' ) );
	cmi_check( '[split] bounded number of requests (bisection)', count( $fake->find( '/consents/request' ) ) - $posts <= 9 );

	// Fix round 1 — indexed errors that match no record: backoff (attempt spent), no 30 s no-progress loop.
	cmi_reset();
	cmi_settings();
	Eru_CMI_Repository::set( 'nomatch@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$fake->batch_mode = 'index-nomatch';
	Eru_CMI_Queue::flush();
	$r = cmi_queue_rows()[0];
	cmi_check( '[nomatch] unknown error index: attempt spent, backed off, nothing due', 1 === (int) $r->attempts && 'queued' === $r->status && ! Eru_CMI_Queue::has_due() && false !== strpos( (string) $r->last_error, 'H120' ) );
	$fake->batch_mode = 'ok';

	// Fix round 2 — order per consent: an older ONAY in ambiguous backoff is settled before a newer RET is sent.
	$order_case = function ( $mode ) use ( $fake ) {
		cmi_reset();
		cmi_settings();
		$fake->store = array();
		Eru_CMI_Repository::set( 'order@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
		$fake->batch_mode = $mode;
		$from             = count( $fake->reqs );
		Eru_CMI_Queue::flush();
		$fake->batch_mode = 'ok';
		$mid              = count( $fake->reqs );
		Eru_CMI_Repository::set( 'order@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
		Eru_CMI_Queue::flush();
		cmi_check( "[order:{$mode}] the RET waits while the older ONAY is unresolved (nothing sent, no busy loop)", count( $fake->reqs ) === $mid && ! Eru_CMI_Queue::has_due() );
		cmi_make_due();
		Eru_CMI_Queue::flush();
		Eru_CMI_Queue::flush();
		$sent = array();
		foreach ( array_slice( $fake->reqs, $from ) as $r ) {
			if ( false !== strpos( $r['path'], '/consents/request' ) && 'POST' === $r['method'] ) {
				foreach ( $r['body'] as $rec ) {
					$sent[] = $rec['status'];
				}
			}
		}
		return $sent;
	};
	$sent = $order_case( 'timeout-after' );
	cmi_check( '[order] ONAY lost in transit but held by İYS: looked up (accepted), then RET; never ONAY after RET', array( 'ONAY', 'RET' ) === $sent && 'accepted' === cmi_queue_rows( "payload LIKE '%\"ONAY\"%'" )[0]->status && 1 === cmi_count( 'queue', "status = 'sent'" ) );
	$sent = $order_case( 'timeout' );
	cmi_check( '[order] ONAY never reached İYS: superseded by the RET (not resent); never ONAY after RET', array( 'ONAY', 'RET' ) === $sent && 'superseded' === cmi_queue_rows( "payload LIKE '%\"ONAY\"%'" )[0]->status );
	// A newer row also wins over an older one that went stale or failed.
	cmi_reset();
	cmi_settings();
	$fake->batch_mode = 'ok';
	Eru_CMI_Repository::set( 'stale@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Repository::set( 'stale@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	$fake->status_mode = 'pending';
	$wpdb->query( $wpdb->prepare( "UPDATE {$qt} SET sent_at = %s", gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ) ); // phpcs:ignore
	Eru_CMI_Queue::poll();
	$sq = cmi_queue_rows();
	cmi_check( '[order] stale > 24 h: the older ONAY is superseded (not re-queued); the newest RET is re-queued', 'superseded' === $sq[0]->status && 'queued' === $sq[1]->status );
	$fake->status_mode = 'ok';
	$wpdb->update( $qt, array( 'status' => 'failed' ), array( 'id' => $sq[0]->id ) );
	cmi_check( '[order] retry() never re-queues a row that has a newer row for the same consent', 0 === Eru_CMI_Queue::retry( (int) $sq[0]->id ) && 'superseded' === cmi_queue_rows( 'id = ' . (int) $sq[0]->id )[0]->status );

	// Fix round 2 — only 400/422 are split; other 4xx (403, 404 …) back the batch off as a configuration fault.
	cmi_reset();
	cmi_settings();
	for ( $i = 1; $i <= 3; $i++ ) {
		Eru_CMI_Repository::set( "cfg{$i}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	$fake->batch_mode = '403';
	$posts            = count( $fake->find( '/consents/request' ) );
	Eru_CMI_Queue::flush();
	cmi_check( '[cfg] 403 on a batch: one request, no split, all rows backed off (attempt 1), none rejected', 1 === count( $fake->find( '/consents/request' ) ) - $posts && 3 === cmi_count( 'queue', "status = 'queued' AND attempts = 1" ) && 0 === cmi_count( 'queue', "status = 'rejected'" ) );
	$fake->batch_mode = 'ok';

	// Final review I-1 — a refused login (OAuth2 400 invalid_grant) is an auth failure, not a malformed record:
	// back off, never split, never reject, never "absent at İYS" in the look-up step.
	cmi_reset();
	cmi_settings();
	$fake->store      = array();
	$fake->batch_mode = 'ok';
	Eru_CMI_Repository::set( 'auth-amb@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$fake->batch_mode = 'timeout-after';
	Eru_CMI_Queue::flush(); // Answer lost: the row keeps its batch key for a look-up.
	$fake->batch_mode = 'ok';
	Eru_CMI_Repository::set( 'auth-amb@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) ); // A newer row of the same consent.
	for ( $i = 1; $i <= 6; $i++ ) {
		Eru_CMI_Repository::set( "auth{$i}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	cmi_make_due();
	Eru_CMI_Client::forget_tokens();
	$fake->password_mode = '400';
	$before              = wp_json_encode( array_map( fn( $r ) => array( $r->id, $r->status, $r->batch_key ), cmi_queue_rows() ) );
	$n                   = count( $fake->reqs );
	$grants              = $fake->password_grants;
	Eru_CMI_Queue::flush();
	$after = array_slice( $fake->reqs, $n );
	$api   = array_filter( $after, fn( $r ) => false !== strpos( $r['path'], '/consents' ) );
	$rows  = cmi_queue_rows();
	$amb   = $rows[0];
	cmi_check( '[auth] password grant answered 400: no consent request or look-up left the site, at most two logins tried', array() === $api && $fake->password_grants - $grants >= 1 && $fake->password_grants - $grants <= 2 );
	cmi_check( '[auth] nothing rejected, superseded, accepted or failed; no batch split', 0 === cmi_count( 'queue', "status IN ('rejected','superseded','accepted','failed','sent')" ) && 8 === cmi_count( 'queue', "status = 'queued'" ) );
	cmi_check( '[auth] the row whose answer was lost keeps its batch key (not "absent at İYS")', '' !== (string) $amb->batch_key && 2 === (int) $amb->attempts && false !== strpos( (string) $amb->last_error, '400' ) );
	cmi_check( '[auth] the new rows spent one attempt each and back off (nothing due)', 6 === cmi_count( 'queue', "status = 'queued' AND attempts = 1 AND batch_key IS NULL" ) && ! Eru_CMI_Queue::has_due() );
	cmi_check( '[auth] consent rows are never mirrored as rejected', 0 === cmi_count( 'consents', "iys_status = 'rejected'" ) && 'skipped' !== Eru_CMI_Repository::get( 'auth1@example.com', 'EPOSTA' )->iys_status );
	// The admin fixes the password: the same rows go through, the lost one is looked up first.
	$fake->password_mode = 'ok';
	cmi_make_due();
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::flush();
	cmi_check( '[auth] after the login works again: look-up settles the lost ONAY (accepted), the rest are sent, nothing rejected', 'accepted' === cmi_queue_rows( 'id = ' . (int) $amb->id )[0]->status && 7 === cmi_count( 'queue', "status = 'sent'" ) && 0 === cmi_count( 'queue', "status = 'rejected'" ) );
	// Reconcile and pull with a refused login: errors only; nothing re-queued, "known at İYS" kept, cursor kept.
	Eru_CMI_Queue::poll();
	Eru_CMI_Client::forget_tokens();
	$fake->password_mode = '400';
	$known_before        = cmi_count( 'consents', "iys_known LIKE '%,sandbox,%'" );
	$q_before            = cmi_count( 'queue' );
	$rec                 = Eru_CMI_Reconcile::step( true );
	cmi_check( '[auth] reconcile (dry) under a refused login: errors counted, nothing "missing"', $rec['errors'] >= 1 && 0 === $rec['requeued'] && 0 === $rec['applied'] );
	delete_option( Eru_CMI_Reconcile::state_option() );
	$rec = Eru_CMI_Reconcile::step();
	cmi_check( '[auth] reconcile under a refused login: nothing re-queued, "known at İYS" kept', 0 === $rec['requeued'] && $q_before === cmi_count( 'queue' ) && $known_before === cmi_count( 'consents', "iys_known LIKE '%,sandbox,%'" ) && $known_before > 0 );
	update_option( Eru_CMI_Pull::cursor_option(), 'demo-cursor-1', false );
	$pr = Eru_CMI_Pull::page( 10 );
	cmi_check( '[auth] pull under a refused login: page fails, cursor kept', ! $pr['ok'] && 'demo-cursor-1' === get_option( Eru_CMI_Pull::cursor_option() ) );
	$fake->password_mode = 'ok';
} catch ( Throwable $e ) {
	$failed = true;
	if ( ! $e instanceof RuntimeException ) {
		echo 'FAIL: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	if ( class_exists( 'Eru_CMI_Scheduler' ) ) {
		cmi_reset();
	}
	if ( null === $old_settings ) {
		delete_option( 'eru_cmi_settings' );
	} else {
		update_option( 'eru_cmi_settings', $old_settings, false );
	}
	if ( class_exists( 'Eru_CMI_Settings' ) ) {
		Eru_CMI_Settings::sync_flag();
	}
}
// Tests are over: let WordPress's own shutdown loopback (wp-cron spawn) through the fake.
remove_filter( 'pre_http_request', array( $fake, 'route' ), 10 );
if ( $failed ) {
	exit( 1 );
}
echo "ALL PASS: cmi-queue\n";
