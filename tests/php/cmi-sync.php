<?php
/**
 * Consent Manager for İYS — İYS → site pull, nightly reconcile, the write-only external endpoint, scheduling
 * (pending-only re-arm, site-timezone hour) and the retention purge.
 *
 *   wp eval-file tests/php/cmi-sync.php
 */

require __DIR__ . '/cmi-helpers.php';
cmi_require_plugin();
foreach ( array( 'Eru_CMI_Reconcile', 'Eru_CMI_External', 'Eru_CMI_Retention' ) as $cmi_c ) {
	if ( ! class_exists( $cmi_c ) ) {
		echo "FAIL: {$cmi_c} missing\n";
		exit( 1 );
	}
}
$fake = cmi_fake_iys();

global $wpdb;
$old_settings = get_option( Eru_CMI_Settings::OPTION, null );
$failed       = false;
$qt           = $wpdb->prefix . 'eru_cmi_queue';
$ht           = $wpdb->prefix . 'eru_cmi_history';

/** Status of a recipient on a channel. */
$status = function ( $r, $t ) {
	$row = Eru_CMI_Repository::get( $r, $t );
	return $row ? $row->status : null;
};

/** Signed external request. @return array{0:int,1:mixed,2:string} */
$external = function ( $body, array $over = array() ) {
	$secret = $over['secret'] ?? Eru_CMI_Settings::get( 'ext_secret' );
	$ts     = (string) ( $over['ts'] ?? time() );
	$raw    = is_string( $body ) ? $body : (string) wp_json_encode( $body );
	$req    = new WP_REST_Request( $over['method'] ?? 'POST', '/eru-cmi/v1/external/consents' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'X-Eru-Cmi-Key', $over['key'] ?? $GLOBALS['cmi_ext_key'] );
	$req->set_header( 'X-Eru-Cmi-Timestamp', $ts );
	$req->set_header( 'X-Eru-Cmi-Signature', $over['sig'] ?? Eru_CMI_External::sign( $secret, $ts, $raw ) );
	$req->set_body( $raw );
	$res = rest_do_request( $req );
	return array( (int) $res->get_status(), $res->get_data(), (string) wp_json_encode( $res->get_data() ) );
};

try {
	cmi_reset();
	cmi_fingerprint( 'sync' );

	// Inert default: the new switches start off and nothing is scheduled by them.
	$d = Eru_CMI_Settings::defaults();
	cmi_check( '[inert] pull, reconcile and the external API are off by default', 'no' === $d['pull_enabled'] && 'no' === $d['reconcile_enabled'] && 'no' === $d['ext_enabled'] && '' === $d['ext_secret'] );
	cmi_settings();
	cmi_check( '[inert] switched on without pull/reconcile: no pull or reconcile job', ! cmi_pending( 'eru_cmi_pull' ) && ! cmi_pending( 'eru_cmi_reconcile' ) );

	// (a) Pull: a page with 2 changes made at İYS updates local statuses as actor iys, no queue rows.
	cmi_reset();
	cmi_settings( array( 'pull_enabled' => 'yes' ) );
	cmi_check( '(a) pull switched on: one pending pull job', 1 === count( cmi_pending( 'eru_cmi_pull' ) ) );
	Eru_CMI_Repository::set( 'pull-b@example.com', 'EPOSTA', 'RET', array( 'source' => 'test', 'actor' => 'admin', 'note' => 'demo', 'consent_date' => time() - DAY_IN_SECONDS ) );
	$q_before = cmi_count( 'queue' );
	Eru_CMI_Scheduler::run_pull();
	$pa = Eru_CMI_Repository::get( 'pull-a@example.com', 'EPOSTA' );
	$pb = Eru_CMI_Repository::get( 'pull-b@example.com', 'EPOSTA' );
	$ha = Eru_CMI_History::for_consent( (int) $pa->id );
	$hb = Eru_CMI_History::for_consent( (int) $pb->id );
	cmi_check( '(a) two İYS changes applied: pull-a RET (new), pull-b RET → ONAY', 'RET' === $pa->status && 'ONAY' === $pb->status );
	cmi_check( '(a) history actor iys, source iys', 'iys' === end( $ha )->actor && 'iys' === end( $hb )->actor && 'iys' === end( $hb )->source );
	cmi_check( '(a) nothing re-enqueued by the pull', $q_before === cmi_count( 'queue' ) );
	$last = get_option( 'eru_cmi_pull_last' );
	cmi_check( '(a) last pull result stored (ok, 2 applied)', is_array( $last ) && ! empty( $last['ok'] ) && 2 === (int) $last['applied'] );
	cmi_check( '(a) the cursor of the selected environment was stored', 'demo-cursor-1' === get_option( Eru_CMI_Pull::cursor_option() ) );
	$pulls = $fake->find( '/consents/changes' );
	cmi_check( '(a) the pull asks for at most 999 changes', '999' === ( end( $pulls )['query']['limit'] ?? '' ) );

	// Pending-only re-arm: the pull re-arms itself while its own action is running.
	as_unschedule_all_actions( 'eru_cmi_pull' );
	$aid = as_schedule_single_action( time() - 5, 'eru_cmi_pull', array(), 'eru-cmi' );
	ActionScheduler::store()->log_execution( $aid ); // In progress: counted by as_has_scheduled_action().
	Eru_CMI_Scheduler::run_pull();
	$pend = cmi_pending( 'eru_cmi_pull' );
	ActionScheduler::store()->mark_complete( $aid );
	cmi_check( '(a) run from inside its own running action: the next pull is armed (pending-only check)', 1 === count( $pend ) && (int) $aid !== (int) $pend[0] );
	$next = as_next_scheduled_action( 'eru_cmi_pull', array(), 'eru-cmi' );
	cmi_check( '(a) no more pages → next pull in about an hour', is_int( $next ) && $next - time() > 50 * MINUTE_IN_SECONDS );
	$fake->pull_cursor = 'demo-cursor-1'; // Same cursor again → İYS has nothing newer.
	cmi_settings( array( 'pull_enabled' => 'no' ) );
	cmi_check( '(a) pull switched off: the pull job is dropped', ! cmi_pending( 'eru_cmi_pull' ) );
	$n = count( $fake->find( '/consents/changes' ) );
	Eru_CMI_Scheduler::run_pull();
	cmi_check( '(a) a stray pull job while switched off makes no request', $n === count( $fake->find( '/consents/changes' ) ) );

	// (b) Reconcile: 3 locally sent and accepted; İYS lacks one → exactly one re-queue; again the same night → none.
	cmi_reset();
	cmi_settings( array( 'reconcile_enabled' => 'yes', 'reconcile_hour' => '3' ) );
	$pend = cmi_pending( 'eru_cmi_reconcile' );
	$at   = as_next_scheduled_action( 'eru_cmi_reconcile', array(), 'eru-cmi' );
	cmi_check( '(b) reconcile switched on: one pending job at 03:00 site time, in the future', 1 === count( $pend ) && is_int( $at ) && $at > time() && '03:00' === wp_date( 'H:i', $at ) && $at - time() <= DAY_IN_SECONDS );
	foreach ( array( 'rc1', 'rc2', 'rc3' ) as $k ) {
		Eru_CMI_Repository::set( "{$k}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	cmi_check( '(b) three consents sent and accepted at İYS', 3 === cmi_count( 'queue', "status = 'accepted'" ) );
	unset( $fake->store['bireysel|eposta|rc2@example.com'] ); // İYS lost it.
	$rows_before = cmi_count( 'queue' );
	$r1          = Eru_CMI_Reconcile::step();
	$new         = cmi_queue_rows();
	$queued      = array_values( array_filter( $new, fn( $r ) => 'queued' === $r->status ) );
	cmi_check( '(b) first run: 3 checked, 2 match, exactly one re-queue (rc2), pass done', 3 === $r1['checked'] && 2 === $r1['match'] && 1 === $r1['requeued'] && $r1['done'] && $rows_before + 1 === cmi_count( 'queue' ) && 1 === count( $queued ) && false !== strpos( $queued[0]->payload, 'rc2@example.com' ) );
	$rc2 = Eru_CMI_Repository::get( 'rc2@example.com', 'EPOSTA' );
	cmi_check( '(b) the missing record no longer counts as known at İYS (sandbox)', false === strpos( (string) $rc2->iys_known, ',sandbox,' ) );
	$r2 = Eru_CMI_Reconcile::step();
	cmi_check( '(b) second run the same night → nothing checked, nothing re-queued', 0 === $r2['checked'] && 0 === $r2['requeued'] && ! empty( $r2['already'] ) && $rows_before + 1 === cmi_count( 'queue' ) );
	$state = get_option( Eru_CMI_Reconcile::state_option() );
	cmi_check( '(b) summary option: night, counts, finished', is_array( $state ) && wp_date( 'Y-m-d' ) === $state['night'] && 1 === (int) $state['requeued'] && ! empty( $state['finished'] ) );

	// Reconcile: İYS holds a newer, different status (changed at İYS) → applied locally, never overwritten.
	cmi_reset();
	cmi_settings( array( 'reconcile_enabled' => 'yes' ) );
	Eru_CMI_Repository::set( 'rc-new@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'consent_date' => time() - HOUR_IN_SECONDS ) );
	Eru_CMI_Repository::set( 'rc-old@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'consent_date' => time() - 60 ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	$tr = ( new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Istanbul' ) ) );
	$fake->store['bireysel|eposta|rc-new@example.com']['status']      = 'RET';
	$fake->store['bireysel|eposta|rc-new@example.com']['consentDate'] = $tr->format( 'Y-m-d H:i:s' );
	$fake->store['bireysel|eposta|rc-old@example.com']['status']      = 'RET';
	$fake->store['bireysel|eposta|rc-old@example.com']['consentDate'] = $tr->modify( '-2 hours' )->format( 'Y-m-d H:i:s' );
	$rows_before = cmi_count( 'queue' );
	$r           = Eru_CMI_Reconcile::step();
	$hn          = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( 'rc-new@example.com', 'EPOSTA' )->id );
	cmi_check( '(b) İYS newer and different → applied locally (RET, actor iys), not re-queued', 'RET' === $status( 'rc-new@example.com', 'EPOSTA' ) && 'iys' === end( $hn )->actor && 1 === $r['applied'] );
	cmi_check( '(b) İYS older and different → our newer status is re-queued once', 'ONAY' === $status( 'rc-old@example.com', 'EPOSTA' ) && 1 === $r['requeued'] && $rows_before + 1 === cmi_count( 'queue' ) );

	// Reconcile: transport errors are not "missing"; 429 stops the run with the cursor kept.
	cmi_reset();
	cmi_settings( array( 'reconcile_enabled' => 'yes' ) );
	foreach ( array( 'rl1', 'rl2' ) as $k ) {
		Eru_CMI_Repository::set( "{$k}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	$rows_before       = cmi_count( 'queue' );
	$fake->lookup_mode = '500';
	$r                 = Eru_CMI_Reconcile::step();
	cmi_check( '(b) 5xx on look-up: counted as error, nothing re-queued', 2 === $r['errors'] && 0 === $r['requeued'] && $rows_before === cmi_count( 'queue' ) );
	delete_option( Eru_CMI_Reconcile::state_option() );
	$fake->lookup_mode = '429';
	$r                 = Eru_CMI_Reconcile::step();
	$state             = get_option( Eru_CMI_Reconcile::state_option() );
	cmi_check( '(b) 429: the run stops, the pass is not done, the cursor stays before the throttled row', ! empty( $r['limited'] ) && ! $r['done'] && 0 === (int) $state['cursor'] && $rows_before === cmi_count( 'queue' ) );
	$fake->lookup_mode = 'ok';

	// Dry run: checks, writes nothing (no queue row, no state).
	cmi_reset();
	cmi_settings( array( 'reconcile_enabled' => 'yes' ) );
	Eru_CMI_Repository::set( 'dry1@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	unset( $fake->store['bireysel|eposta|dry1@example.com'] );
	$rows_before = cmi_count( 'queue' );
	$r           = Eru_CMI_Reconcile::step( true );
	cmi_check( '(b) --dry-run: reports one missing, re-queues nothing, stores no state', 1 === $r['requeued'] && $rows_before === cmi_count( 'queue' ) && false === get_option( Eru_CMI_Reconcile::state_option() ) );

	// Re-arm: a continuation is armed while the pass is unfinished, the next night once it is done.
	cmi_reset();
	cmi_settings( array( 'reconcile_enabled' => 'yes', 'reconcile_hour' => '4' ) );
	as_unschedule_all_actions( 'eru_cmi_reconcile' );
	$aid = as_schedule_single_action( time() - 5, 'eru_cmi_reconcile', array(), 'eru-cmi' );
	ActionScheduler::store()->log_execution( $aid );
	Eru_CMI_Scheduler::run_reconcile();
	$pend = cmi_pending( 'eru_cmi_reconcile' );
	$at   = $pend ? ActionScheduler::store()->get_date( $pend[0] )->getTimestamp() : 0; // as_next_scheduled_action() answers true while one runs.
	ActionScheduler::store()->mark_complete( $aid );
	cmi_check( '(b) run from inside its own action, pass done: next night armed at 04:00 (pending-only)', 1 === count( $pend ) && '04:00' === wp_date( 'H:i', $at ) );
	// Fix round 1: a pending next-night action (watchdog or settings save) must not swallow the continuation.
	cmi_reset();
	cmi_settings( array( 'reconcile_enabled' => 'yes', 'reconcile_hour' => '4' ) );
	foreach ( array( 'mp1', 'mp2', 'mp3' ) as $k ) {
		Eru_CMI_Repository::set( "{$k}@example.com", 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	}
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	$budget = fn() => 1;
	add_filter( 'eru_cmi_reconcile_budget', $budget );
	as_unschedule_all_actions( 'eru_cmi_reconcile' );
	$aid = as_schedule_single_action( time() - 5, 'eru_cmi_reconcile', array(), 'eru-cmi' );
	ActionScheduler::store()->log_execution( $aid );
	Eru_CMI_Scheduler::watchdog( true ); // Arms next night while the pass runs.
	cmi_check( '[rearm] the watchdog armed next night during the pass', 1 === count( cmi_pending( 'eru_cmi_reconcile' ) ) );
	Eru_CMI_Scheduler::run_reconcile();
	ActionScheduler::store()->mark_complete( $aid );
	$pend = cmi_pending( 'eru_cmi_reconcile' );
	$soon = $pend ? ActionScheduler::store()->get_date( $pend[0] )->getTimestamp() : 0;
	cmi_check( '[rearm] unfinished pass: exactly one pending run, the continuation within ~1 minute', 1 === count( $pend ) && $soon - time() <= 2 * MINUTE_IN_SECONDS );
	for ( $i = 0; $i < 6; $i++ ) {
		$st = Eru_CMI_Reconcile::state();
		if ( ! empty( $st['done'] ) ) {
			break;
		}
		$aid = (int) cmi_pending( 'eru_cmi_reconcile' )[0];
		ActionScheduler::store()->log_execution( $aid );
		Eru_CMI_Scheduler::run_reconcile();
		ActionScheduler::store()->mark_complete( $aid );
	}
	$st   = Eru_CMI_Reconcile::state();
	$pend = cmi_pending( 'eru_cmi_reconcile' );
	$at   = $pend ? ActionScheduler::store()->get_date( $pend[0] )->getTimestamp() : 0;
	cmi_check( '[rearm] the pass completes step by step (3 checked) and then waits for next night 04:00', ! empty( $st['done'] ) && 3 === (int) $st['checked'] && 1 === count( $pend ) && '04:00' === wp_date( 'H:i', $at ) );
	update_option( Eru_CMI_Reconcile::state_option(), array( 'done' => false, 'cursor' => 0 ) + $st, false );
	$fake->lookup_mode = '429';
	Eru_CMI_Scheduler::watchdog( true );
	$aid = as_schedule_single_action( time() - 5, 'eru_cmi_reconcile', array(), 'eru-cmi' );
	ActionScheduler::store()->log_execution( $aid );
	Eru_CMI_Scheduler::run_reconcile();
	ActionScheduler::store()->mark_complete( $aid );
	$fake->lookup_mode = 'ok';
	$pend = cmi_pending( 'eru_cmi_reconcile' );
	$at   = $pend ? ActionScheduler::store()->get_date( $pend[0] )->getTimestamp() : 0;
	cmi_check( '[rearm] throttled (429) with next night pending: one run, in about an hour', 1 === count( $pend ) && abs( $at - time() - HOUR_IN_SECONDS ) <= 2 * MINUTE_IN_SECONDS );
	remove_filter( 'eru_cmi_reconcile_budget', $budget );
	cmi_settings( array( 'reconcile_enabled' => 'no' ) );
	cmi_check( '(b) reconcile switched off: job dropped', ! cmi_pending( 'eru_cmi_reconcile' ) );

	// (e) External endpoint: off by default, write-only, key + HMAC + timestamp, ≤ 999 records, counts only.
	cmi_reset();
	cmi_settings();
	list( $code ) = $external( array(), array( 'key' => 'x', 'sig' => 'x' ) );
	cmi_check( '(e) switched off: no route (404)', 404 === $code );
	cmi_settings( array( 'ext_enabled' => 'yes' ) );
	cmi_check( '(e) switched on without a key: the route stays off', 0 === (int) Eru_CMI_Settings::front()['external'] );
	$GLOBALS['cmi_ext_key'] = Eru_CMI_External::generate();
	cmi_check( '(e) the key is stored only as a hash; the secret is never the key', '' === (string) array_search( $GLOBALS['cmi_ext_key'], get_option( Eru_CMI_Settings::OPTION ), true ) && 64 === strlen( Eru_CMI_Settings::get( 'ext_key_hash' ) ) );
	$GLOBALS['wp_rest_server'] = null; // Routes are registered on the next server start.
	$tr  = new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Istanbul' ) );
	$one = array(
		array( 'recipient' => 'ext1@example.com', 'type' => 'EPOSTA', 'status' => 'ONAY', 'consentDate' => $tr->format( 'Y-m-d H:i:s' ), 'source' => 'HS_WEB', 'recipientType' => 'BIREYSEL' ),
		array( 'recipient' => cmi_phone(), 'type' => 'MESAJ', 'status' => 'ONAY', 'consentDate' => $tr->format( 'Y-m-d H:i:s' ), 'source' => 'HS_FIZIKSEL_ORTAM' ),
		array( 'recipient' => 'not-an-address', 'type' => 'EPOSTA', 'status' => 'ONAY', 'consentDate' => $tr->format( 'Y-m-d H:i:s' ) ),
		array( 'recipient' => 'ext2@example.com', 'type' => 'EPOSTA', 'status' => 'ONAY' ),
	);
	list( $code, $data, $body ) = $external( $one, array( 'sig' => str_repeat( 'a', 64 ) ) );
	cmi_check( '(e) wrong signature → 401, nothing stored', 401 === $code && null === $status( 'ext1@example.com', 'EPOSTA' ) );
	list( $code ) = $external( $one, array( 'key' => 'cmi_wrong' ) );
	cmi_check( '(e) wrong key → 401', 401 === $code && null === $status( 'ext1@example.com', 'EPOSTA' ) );
	list( $code ) = $external( $one, array( 'ts' => time() - 6 * MINUTE_IN_SECONDS ) );
	cmi_check( '(e) stale timestamp (6 min) → 401', 401 === $code && null === $status( 'ext1@example.com', 'EPOSTA' ) );
	$many = array_fill( 0, 1000, $one[0] );
	list( $code ) = $external( $many );
	cmi_check( '(e) 1.000 records → 413, nothing stored', 413 === $code && null === $status( 'ext1@example.com', 'EPOSTA' ) );
	$ts_ok                      = time();
	list( $code, $data, $body ) = $external( $one, array( 'ts' => $ts_ok ) );
	cmi_check( '(e) valid request → 200 {accepted, rejected, errors}', 200 === $code && 2 === $data['accepted'] && 2 === $data['rejected'] && array( 'accepted', 'errors', 'rejected' ) === array_values( array_intersect( array( 'accepted', 'errors', 'rejected' ), array_keys( $data ) ) ) );
	$codes = wp_list_pluck( $data['errors'], 'code', 'index' );
	cmi_check( '(e) row errors carry only an index and a code (bad address, missing date)', array( 2, 3 ) === array_keys( $codes ) && 'recipient' === $codes[2] && 'date_required' === $codes[3] && array( 'code', 'index' ) === array_values( array_intersect( array( 'code', 'index' ), array_keys( $data['errors'][0] ) ) ) && 2 === count( $data['errors'][0] ) );
	cmi_check( '(e) no personal data in the response', false === strpos( $body, '@' ) && false === strpos( $body, '555' ) && false === strpos( $body, 'ext1' ) );
	$h = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( 'ext1@example.com', 'EPOSTA' )->id );
	cmi_check( '(e) stored as actor api, source external; İYS source passed on', 'ONAY' === $status( 'ext1@example.com', 'EPOSTA' ) && 'api' === end( $h )->actor && 'external' === end( $h )->source && false !== strpos( cmi_queue_rows( "status = 'queued'" )[1]->payload, 'HS_FIZIKSEL_ORTAM' ) );
	list( $code ) = $external( $one, array( 'ts' => $ts_ok ) );
	cmi_check( '(e) the same signed request again (replay) → 401', 401 === $code );
	list( $code, $data ) = $external( array( array( 'recipient' => 'ext1@example.com', 'type' => 'EPOSTA', 'status' => 'RET', 'consentDate' => $tr->format( 'Y-m-d H:i:s' ) ) ) );
	Eru_CMI_Repository::set( 'ext1@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	list( $code2, $data2 ) = $external( array( array( 'recipient' => 'ext1@example.com', 'type' => 'EPOSTA', 'status' => 'ONAY', 'consentDate' => $tr->modify( '+1 second' )->format( 'Y-m-d H:i:s' ) ) ) );
	cmi_check( '(e) the API cannot lift a RET (refused with the generic code not_applied)', 200 === $code2 && 0 === $data2['accepted'] && 'not_applied' === ( $data2['errors'][0]['code'] ?? '' ) && 'RET' === $status( 'ext1@example.com', 'EPOSTA' ) );
	Eru_CMI_Repository::set( 'ext3@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	list( $code3, $data3, $body3 ) = $external( array( array( 'recipient' => 'ext3@example.com', 'type' => 'EPOSTA', 'status' => 'RET', 'consentDate' => $tr->modify( '-1 day' )->format( 'Y-m-d H:i:s' ) ) ) );
	cmi_check( '(e) a record older than the stored one gets the same generic code (no state oracle)', 200 === $code3 && 'not_applied' === ( $data3['errors'][0]['code'] ?? '' ) && 'ONAY' === $status( 'ext3@example.com', 'EPOSTA' ) );
	cmi_check( '(e) no refusal reason in any response', false === strpos( $body3 . wp_json_encode( $data2 ), 'reconsent' ) && false === strpos( $body3, 'older' ) );
	list( $code ) = $external( '{"broken', array() );
	cmi_check( '(e) malformed JSON (signed) → 400', 400 === $code );
	$req = new WP_REST_Request( 'GET', '/eru-cmi/v1/external/consents' );
	cmi_check( '(e) no GET route', 404 === rest_do_request( $req )->get_status() || 405 === rest_do_request( $req )->get_status() );

	// Retention: API log by log_days; history IP/UA hashes by hash_days; erased consents' settled queue rows lose the address.
	cmi_reset();
	cmi_settings( array( 'log_days' => '3', 'hash_days' => '30' ) );
	Eru_CMI_Logger::api( 'sandbox', 'GET', '/demo', null, 200, '{}', 1 );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'eru_cmi_log SET created_at = %s', gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	Eru_CMI_Logger::api( 'sandbox', 'GET', '/demo2', null, 200, '{}', 1 );
	$r = Eru_CMI_Repository::set( 'ret1@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'ip' => '127.0.0.1', 'ua' => 'Demo UA' ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$ht} SET created_at = %s WHERE consent_id = %d", gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ), $r['id'] ) ); // phpcs:ignore
	Eru_CMI_Repository::set( 'ret2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'ip' => '127.0.0.1' ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Repository::set( 'ret1@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush(); // The RET is now "sent", waiting for İYS's answer.
	$erase = Eru_CMI_Privacy::erase( 'ret1@example.com' );
	$open  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$qt} WHERE consent_id = %d AND payload LIKE %s", $r['id'], '%ret1@example.com%' ) ); // phpcs:ignore
	cmi_check( '[retention] erasure keeps the address on a row still waiting for İYS', (int) $open >= 1 );
	Eru_CMI_Queue::poll();
	$stats = Eru_CMI_Retention::run();
	$left  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$qt} WHERE payload LIKE %s", '%ret1@example.com%' ) ); // phpcs:ignore
	cmi_check( '[retention] once settled, the purge removes the erased address from every queue row', 0 === (int) $left && $stats['queue'] >= 1 );
	cmi_check( '[retention] API log older than log_days purged, newer kept', 1 === cmi_count( 'log', "endpoint = '/demo2'" ) && 0 === cmi_count( 'log', "endpoint = '/demo'" ) );
	$h1 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ht} WHERE consent_id = %d ORDER BY id ASC LIMIT 1", $r['id'] ) ); // phpcs:ignore
	$c2 = (int) Eru_CMI_Repository::get( 'ret2@example.com', 'EPOSTA' )->id;
	$h2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ht} WHERE consent_id = %d ORDER BY id ASC LIMIT 1", $c2 ) ); // phpcs:ignore
	cmi_check( '[retention] IP/UA hashes older than hash_days cleared; status rows and newer hashes kept', '' === $h1->ip_hash && '' === $h1->ua_hash && 'ONAY' === $h1->new_status && '' !== $h2->ip_hash );
	cmi_check( '[retention] the daily purge job stays armed while there is retention work', 1 === count( cmi_pending( 'eru_cmi_log_purge' ) ) || Eru_CMI_Retention::has_work() );

	// Final review M-3 — erasure withdraws active marketing consents first: the RET reaches İYS, then the address goes.
	cmi_reset();
	cmi_settings();
	$fake->batch_mode = 'ok';
	Eru_CMI_Repository::set( 'erase-ret@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'user' ) );
	Eru_CMI_Repository::set( 'erase-new@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'user' ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	$ec = (int) Eru_CMI_Repository::get( 'erase-ret@example.com', 'EPOSTA' )->id;
	cmi_check( '[erase-ret] setup: the ONAY is registered at İYS', 'accepted' === Eru_CMI_Repository::get( 'erase-ret@example.com', 'EPOSTA' )->iys_status );
	$er   = Eru_CMI_Privacy::erase( 'erase-ret@example.com' );
	$row  = Eru_CMI_Repository::by_id( $ec );
	$open = cmi_queue_rows( 'consent_id = ' . $ec . " AND status = 'queued'" );
	$p    = $open ? json_decode( $open[0]->payload, true ) : array();
	$hist = Eru_CMI_History::for_consent( $ec );
	cmi_check( '[erase-ret] the eraser recorded a RET (history actor admin, source privacy) and anonymised the row', 'RET' === $row->status && Eru_CMI_Repository::is_placeholder( $row ) && 'RET' === $hist[1]->new_status && 'privacy' === $hist[1]->source );
	cmi_check( '[erase-ret] the RET waits in the queue with the address (it must reach İYS) and the eraser says so', 1 === count( $open ) && 'RET' === ( $p['status'] ?? '' ) && 'erase-ret@example.com' === ( $p['recipient'] ?? '' ) && 2 === count( $er['messages'] ) );
	cmi_check( '[erase-ret] eru_cmi_is_consented() → false for the erased address', false === eru_cmi_is_consented( 'erase-ret@example.com', 'EPOSTA' ) );
	$n = count( $fake->reqs );
	Eru_CMI_Queue::flush();
	$posts = array_values( array_filter( array_slice( $fake->reqs, $n ), fn( $r ) => false !== strpos( $r['path'], '/consents/request' ) ) );
	cmi_check( '[erase-ret] the withdrawal is sent to İYS', 1 === count( $posts ) && 'RET' === $posts[0]['body'][0]['status'] && 'erase-ret@example.com' === $posts[0]['body'][0]['recipient'] );
	Eru_CMI_Queue::poll();
	Eru_CMI_Retention::run();
	cmi_check( '[erase-ret] once İYS answered, the purge removes the address', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$qt} WHERE payload LIKE %s", '%erase-ret@example.com%' ) ) ); // phpcs:ignore
	cmi_check( '[erase-ret] another address is untouched', 'ONAY' === $status( 'erase-new@example.com', 'EPOSTA' ) );
	// An ONAY that never reached İYS: no RET is sent (H175), the unsent ONAY is closed and the address goes at once.
	Eru_CMI_Repository::set( 'erase-unsent@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'user' ) );
	$uc = (int) Eru_CMI_Repository::get( 'erase-unsent@example.com', 'EPOSTA' )->id;
	$n  = count( $fake->reqs );
	Eru_CMI_Privacy::erase( 'erase-unsent@example.com' );
	Eru_CMI_Queue::flush();
	cmi_check( '[erase-ret] never-sent ONAY: RET kept locally, nothing sent, no address left in the queue', 'RET' === Eru_CMI_Repository::by_id( $uc )->status && 0 === count( array_filter( array_slice( $fake->reqs, $n ), fn( $r ) => false !== strpos( $r['path'], '/consents/request' ) ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$qt} WHERE payload LIKE %s", '%erase-unsent@example.com%' ) ) ); // phpcs:ignore

	// Re-review minor — a consent the eraser could not withdraw (record busy) is never anonymised; WP retries.
	Eru_CMI_Repository::set( 'erase-busy@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'user' ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Queue::poll();
	$bc    = (int) Eru_CMI_Repository::get( 'erase-busy@example.com', 'EPOSTA' )->id;
	$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); // A second session holds the per-recipient lock.
	$lname = 'eru_cmi_' . substr( Eru_CMI_Recipient::hash( 'erase-busy@example.com' ), 0, 40 ) . '_EPOSTA';
	cmi_check( '[erase-busy] setup: another session holds the record lock', 1 === (int) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 1)', $lname ) ) );
	$h0  = count( Eru_CMI_History::for_consent( $bc ) );
	$q0  = cmi_count( 'queue', 'consent_id = ' . $bc );
	$er1 = Eru_CMI_Privacy::erase( 'erase-busy@example.com', 1 );
	$row = Eru_CMI_Repository::by_id( $bc );
	cmi_check( '[erase-busy] busy: done=false (WordPress calls the eraser again), row untouched, still ONAY', false === $er1['done'] && 'erase-busy@example.com' === $row->recipient && 'ONAY' === $row->status && $h0 === count( Eru_CMI_History::for_consent( $bc ) ) && $q0 === cmi_count( 'queue', 'consent_id = ' . $bc ) );
	cmi_check( '[erase-busy] ... eru_cmi_is_consented() unchanged', true === eru_cmi_is_consented( 'erase-busy@example.com', 'EPOSTA' ) );
	$erl = Eru_CMI_Privacy::erase( 'erase-busy@example.com', Eru_CMI_Privacy::BUSY_RETRIES );
	cmi_check( '[erase-busy] after the last retry: done, still untouched, and the messages say so', true === $erl['done'] && 'erase-busy@example.com' === Eru_CMI_Repository::by_id( $bc )->recipient && false !== strpos( implode( ' ', $erl['messages'] ), 'yeniden' ) && true === $erl['items_retained'] );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lname ) );
	$other->close();
	$er2  = Eru_CMI_Privacy::erase( 'erase-busy@example.com', 2 );
	$row  = Eru_CMI_Repository::by_id( $bc );
	$open = cmi_queue_rows( 'consent_id = ' . $bc . " AND status = 'queued'" );
	cmi_check( '[erase-busy] lock released, erased again: RET queued for İYS, row anonymised, done', true === $er2['done'] && 'RET' === $row->status && Eru_CMI_Repository::is_placeholder( $row ) && 1 === count( $open ) && 'RET' === json_decode( $open[0]->payload, true )['status'] && false === eru_cmi_is_consented( 'erase-busy@example.com', 'EPOSTA' ) );
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
	Eru_CMI_Rate_Limit::reset();
}
remove_filter( 'pre_http_request', array( $fake, 'route' ), 10 );
if ( $failed ) {
	exit( 1 );
}
echo "ALL PASS: cmi-sync\n";
