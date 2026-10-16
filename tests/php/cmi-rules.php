<?php
/**
 * Consent Manager for İYS — consent store and İYS rules: upsert + history, H175, H467 business days
 * and re-stamp, TACIR, normalisation, eru_cmi_is_consented, filters, and the inert default.
 *
 *   wp eval-file tests/php/cmi-rules.php
 */

require __DIR__ . '/cmi-helpers.php';
cmi_require_plugin();
$fake = cmi_fake_iys();

global $wpdb;
$old_settings = get_option( Eru_CMI_Settings::OPTION, null );
$failed       = false;

try {
	cmi_reset();

	// (j) Activation with defaults: tables, no job, no HTTP; a consent written while switched off schedules nothing.
	delete_option( Eru_CMI_Settings::OPTION );
	delete_option( 'eru_cmi_db_version' );
	Eru_CMI_Plugin::activate();
	cmi_check( '(j) activation creates the four tables', 4 === count( array_filter( array( 'consents', 'history', 'queue', 'log' ), fn( $t ) => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'eru_cmi_' . $t ) ) ) ) );
	Eru_CMI_Scheduler::watchdog( true );
	cmi_check( '(j) defaults: master switch off, nothing scheduled, no HTTP', ! Eru_CMI_Settings::enabled() && array() === cmi_pending_group() && 0 === count( $fake->reqs ) );
	$r = Eru_CMI_Repository::set( 'off@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin' ) );
	Eru_CMI_Scheduler::watchdog( true );
	cmi_check( '(j) switched off: consent stored locally, still no job and no HTTP', ! is_wp_error( $r ) && 1 === cmi_count( 'consents' ) && array() === cmi_pending_group() && 0 === count( $fake->reqs ) );
	// Switched off: a request path costs zero queries and never reads the (non-autoloaded) settings option.
	cmi_check( '[flag] activation writes the autoloaded on/off flag as 0', '0' === get_option( 'eru_cmi_active' ) && in_array( $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'eru_cmi_active'" ), array( 'yes', 'on', 'auto-on', 'auto' ), true ) );
	$flag_request = function () use ( $wpdb ) {
		remove_action( 'action_scheduler_init', array( 'Eru_CMI_Scheduler', 'watchdog' ) );
		delete_option( 'eru_cmi_watchdog_at' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( Eru_CMI_Settings::OPTION, 'options' );
		wp_load_alloptions();
		$reads = 0;
		$count = function ( $pre ) use ( &$reads ) {
			++$reads;
			return $pre;
		};
		add_filter( 'pre_option_' . Eru_CMI_Settings::OPTION, $count );
		$q0 = $wpdb->num_queries;
		Eru_CMI_Installer::maybe_install();
		Eru_CMI_Scheduler::init();
		$q = $wpdb->num_queries - $q0;
		remove_filter( 'pre_option_' . Eru_CMI_Settings::OPTION, $count );
		$hooked = (bool) has_action( 'action_scheduler_init', array( 'Eru_CMI_Scheduler', 'watchdog' ) );
		remove_action( 'action_scheduler_init', array( 'Eru_CMI_Scheduler', 'watchdog' ) );
		return array( $q, $reads, $hooked );
	};
	cmi_settings( array( 'enabled' => 'no' ) );
	list( $q, $reads, $hooked ) = $flag_request();
	cmi_check( '[flag] switched off: request path makes 0 queries, 0 settings reads, no watchdog hook', 0 === $q && 0 === $reads && ! $hooked && '0' === get_option( 'eru_cmi_active' ) );
	cmi_settings();
	list( $q, $reads, $hooked ) = $flag_request();
	cmi_check( '[flag] switched on and configured: flag 1, watchdog hooked, still no settings read per request', '1' === get_option( 'eru_cmi_active' ) && $hooked && 0 === $reads );
	cmi_settings( array( 'sandbox_password' => '' ) );
	cmi_check( '[flag] switch on but credentials missing: flag 0', '0' === get_option( 'eru_cmi_active' ) );
	cmi_settings();
	delete_option( Eru_CMI_Settings::OPTION );
	cmi_check( '[flag] deleting the settings option drops the flag to 0 by itself', '0' === get_option( 'eru_cmi_active' ) );
	delete_option( 'eru_cmi_active' );
	list( $q, $reads, $hooked ) = $flag_request();
	cmi_check( '[flag] a missing flag is re-created once (as 0), so later requests read only autoloaded options', '0' === get_option( 'eru_cmi_active' ) && ! $hooked );
	list( $q, $reads, $hooked ) = $flag_request();
	cmi_check( '[flag] ... and the next request costs 0 queries', 0 === $q && 0 === $reads );
	cmi_reset();

	// Settings form: write-only password, clamped batch size, capability.
	cmi_settings();
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$post = array( '_tab' => 'connection', 'enabled' => '1', 'environment' => 'sandbox', 'batch_size' => '5000', 'sandbox_username' => 'demo-user', 'sandbox_password' => '', 'sandbox_iys_code' => '000000', 'sandbox_brand_code' => '000000', 'live_password' => '' );
	$out  = Eru_CMI_Settings::sanitize( $post );
	cmi_check( 'settings form: blank password keeps the stored one; batch size clamped to 1000', cmi_secret() === $out['sandbox_password'] && '1000' === $out['batch_size'] );
	$out = Eru_CMI_Settings::sanitize( array_merge( $post, array( 'sandbox_clear_password' => '1', 'batch_size' => '0' ) ) );
	cmi_check( 'settings form: "clear" removes the password; batch size at least 1', '' === $out['sandbox_password'] && '1' === $out['batch_size'] );
	wp_set_current_user( 0 );
	$out = Eru_CMI_Settings::sanitize( array_merge( $post, array( 'sandbox_password' => 'x' ) ) );
	cmi_check( 'settings form: without manage_woocommerce nothing changes', cmi_secret() === $out['sandbox_password'] );
	set_transient( Eru_CMI_Client::token_key_for_test(), 'demo-acc-x', 600 );
	cmi_settings( array( 'sandbox_password' => cmi_secret() . '-2' ) );
	cmi_check( 'changing the password drops the cached token', false === get_transient( Eru_CMI_Client::token_key_for_test() ) );

	// Normalisation.
	cmi_settings();
	cmi_check( 'settings option (holds the password) is not autoloaded', in_array( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Eru_CMI_Settings::OPTION ) ), array( 'no', 'off' ), true ) );
	cmi_check( 'e-mail normalised to lower case', 'ornek@example.com' === Eru_CMI_Recipient::normalize( ' Ornek@Example.COM ', 'EPOSTA' ) );
	cmi_check( 'phone normalised to E.164', cmi_phone_e164() === Eru_CMI_Recipient::normalize( cmi_phone(), 'MESAJ' ) );
	cmi_check( 'invalid recipient and type rejected', is_wp_error( Eru_CMI_Repository::set( 'not-an-email', 'EPOSTA', 'ONAY', array() ) ) && is_wp_error( Eru_CMI_Repository::set( 'ornek@example.com', 'FAKS', 'ONAY', array() ) ) && is_wp_error( Eru_CMI_Repository::set( 'ornek@example.com', 'EPOSTA', 'BELKI', array() ) ) );
	cmi_check( 'mask hides the full recipient', false === strpos( Eru_CMI_Recipient::mask( 'ornek@example.com' ), 'ornek@' ) && false === strpos( Eru_CMI_Recipient::mask( cmi_phone_e164() ), cmi_phone_e164() ) );

	// (a) Upsert + history only on change, consent_changed fired only on change, eru_cmi_record before enqueue.
	$changed = array();
	$records = array();
	$on_change = function ( $record, $prev ) use ( &$changed ) {
		$changed[] = array( $prev, $record['status'] );
	};
	$on_record = function ( $record ) use ( &$records ) {
		$records[] = $record;
		return $record;
	};
	add_action( 'eru_cmi_consent_changed', $on_change, 10, 2 );
	add_filter( 'eru_cmi_record', $on_record );
	$r1 = Eru_CMI_Repository::set( 'Ornek@Example.com ', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin', 'ip' => '127.0.0.1', 'ua' => 'Demo UA' ) );
	$r2 = Eru_CMI_Repository::set( 'ornek@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin' ) );
	cmi_check( '(a) same status twice: one consent row, one history row', 1 === cmi_count( 'consents' ) && 1 === cmi_count( 'history' ) && $r1['changed'] && ! $r2['changed'] && $r1['id'] === $r2['id'] );
	cmi_check( '(a) second identical call: no extra queue row, no change event', 1 === cmi_count( 'queue' ) && 1 === count( $changed ) && 1 === count( $records ) );
	$r3 = Eru_CMI_Repository::set( 'ornek@example.com', 'EPOSTA', 'RET', array( 'source' => 'test', 'actor' => 'user' ) );
	$h  = Eru_CMI_History::for_consent( $r1['id'] );
	cmi_check( '(a) status change: second history row ONAY -> RET with actor', 2 === count( $h ) && 'ONAY' === $h[1]->previous_status && 'RET' === $h[1]->new_status && 'user' === $h[1]->actor && $r3['changed'] && 'ONAY' === $r3['previous_status'] );
	cmi_check( '(a) history stores IP/UA as salted hashes only', 64 === strlen( $h[0]->ip_hash ) && false === strpos( $h[0]->ip_hash . $h[0]->ua_hash, '127.0.0.1' ) && $h[0]->ip_hash !== hash( 'sha256', '127.0.0.1' ) );
	cmi_check( '(a) consent_changed fired for both changes, filter saw both records', array( array( null, 'ONAY' ), array( 'ONAY', 'RET' ) ) === $changed && 2 === count( $records ) );
	$row = Eru_CMI_Repository::get( 'ORNEK@example.com', 'EPOSTA' );
	cmi_check( '(a) get() normalises and returns the row with hash', $row && 'RET' === $row->status && Eru_CMI_Recipient::hash( 'ornek@example.com' ) === $row->recipient_hash );
	remove_action( 'eru_cmi_consent_changed', $on_change, 10 );
	remove_filter( 'eru_cmi_record', $on_record );
	$block = fn() => false;
	add_filter( 'eru_cmi_record', $block );
	$before = cmi_count( 'queue' );
	Eru_CMI_Repository::set( 'filtered@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	remove_filter( 'eru_cmi_record', $block );
	cmi_check( '(a) eru_cmi_record returning false: stored, not queued', $before === cmi_count( 'queue' ) && null !== Eru_CMI_Repository::get( 'filtered@example.com', 'EPOSTA' ) );

	// eru_cmi_is_consented.
	cmi_check( 'is_consented: RET -> false, ONAY -> true, unknown -> null', false === eru_cmi_is_consented( 'ornek@example.com', 'EPOSTA' ) && true === eru_cmi_is_consented( 'filtered@example.com', 'EPOSTA' ) && null === eru_cmi_is_consented( 'nobody@example.com', 'EPOSTA' ) && null === eru_cmi_is_consented( 'bad', 'EPOSTA' ) );

	// (b) H175: first-ever RET is skipped and never sent; ONAY then RET are both sent.
	cmi_reset();
	cmi_settings();
	Eru_CMI_Repository::set( 'first-ret@example.com', 'EPOSTA', 'RET', array( 'source' => 'test', 'actor' => 'user' ) );
	$q = cmi_queue_rows();
	cmi_check( '(b) first RET -> queue row skipped with H175 reason', 1 === count( $q ) && 'skipped' === $q[0]->status && false !== strpos( $q[0]->last_error, 'H175' ) );
	Eru_CMI_Queue::flush();
	cmi_check( '(b) skipped row: no HTTP at all', 0 === count( $fake->reqs ) );
	cmi_check( '(b) consent row iys_status skipped', 'skipped' === Eru_CMI_Repository::get( 'first-ret@example.com', 'EPOSTA' )->iys_status );
	Eru_CMI_Repository::set( 'onay-ret@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	Eru_CMI_Repository::set( 'onay-ret@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	$q = cmi_queue_rows( "status = 'queued'" );
	cmi_check( '(b) ONAY sent, then RET: RET queued (İYS will hold the ONAY)', 1 === count( $q ) );
	Eru_CMI_Queue::flush();
	$posts = $fake->find( '/consents/request' );
	cmi_check( '(b) ONAY and RET sent in order, one per batch (no duplicate recipient in a batch)', 2 === count( $posts ) && 'ONAY' === $posts[0]['body'][0]['status'] && 'RET' === $posts[1]['body'][0]['status'] && 1 === count( $posts[0]['body'] ) && 1 === count( $posts[1]['body'] ) );
	cmi_check( '(b) both queue rows are sent', 2 === count( cmi_queue_rows( "status = 'sent'" ) ) );
	// [order] ONAY and RET both still unsent: the RET supersedes the ONAY; İYS holds nothing, so the RET is H175.
	$n = count( $fake->reqs );
	Eru_CMI_Repository::set( 'unsent@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$k = Eru_CMI_Repository::set( 'unsent@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	$uq = cmi_queue_rows( 'consent_id = ' . (int) $k['id'] );
	cmi_check( '[order] unsent ONAY then RET: ONAY superseded, RET skipped (H175), nothing sent', 'superseded' === $uq[0]->status && 'skipped' === $uq[1]->status && false !== strpos( $uq[1]->last_error, 'H175' ) && count( $fake->reqs ) === $n );
	cmi_check( '(b) payload has the İYS fields only', array( 'consentDate', 'recipient', 'recipientType', 'source', 'status', 'type' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $posts[0]['body'][0] ) ) && 'HS_WEB' === $posts[0]['body'][0]['source'] );

	// (c) H467 business days (pure).
	$tz  = new DateTimeZone( 'Europe/Istanbul' );
	$fri = ( new DateTimeImmutable( '2026-10-09 10:00:00', $tz ) )->getTimestamp();
	$wed = ( new DateTimeImmutable( '2026-10-14 09:00:00', $tz ) )->getTimestamp();
	$thu = ( new DateTimeImmutable( '2026-10-15 11:00:00', $tz ) )->getTimestamp();
	$wed3 = ( new DateTimeImmutable( '2026-10-14 03:59:00', $tz ) )->getTimestamp();
	$wed4 = ( new DateTimeImmutable( '2026-10-14 04:01:00', $tz ) )->getTimestamp();
	cmi_check( '(c) Friday 10:00 -> Wednesday: 3 business days by calendar', 3 === Eru_CMI_Rules::business_days_since( $fri, $wed, array() ) );
	cmi_check( '(c) [M-1] the deadline is the same time of day 3 business days later (Wednesday 10:00)', ( new DateTimeImmutable( '2026-10-14 10:00:00', $tz ) )->getTimestamp() === Eru_CMI_Rules::deadline( $fri, array() ) && 6 * HOUR_IN_SECONDS === Eru_CMI_Rules::SAFETY_MARGIN );
	cmi_check( '(c) [M-1] with the 6-hour safety margin: Wednesday 03:59 valid, 04:01 and 09:00 already aged', ! Eru_CMI_Rules::is_too_old( $fri, $wed3, array() ) && Eru_CMI_Rules::is_too_old( $fri, $wed4, array() ) && Eru_CMI_Rules::is_too_old( $fri, $wed, array() ) );
	cmi_check( '(c) Friday 10:00 -> Thursday 11:00: too old', Eru_CMI_Rules::is_too_old( $fri, $thu, array() ) );
	cmi_check( '(c) a public holiday (Monday) is not a business day: deadline moves to Thursday 10:00', ! Eru_CMI_Rules::is_too_old( $fri, $wed, array( '2026-10-12' ) ) && ( new DateTimeImmutable( '2026-10-15 10:00:00', $tz ) )->getTimestamp() === Eru_CMI_Rules::deadline( $fri, array( '2026-10-12' ) ) );
	$hol = fn() => array( '2026-10-12' );
	add_filter( 'eru_cmi_holidays', $hol );
	cmi_check( '(c) holidays come from the eru_cmi_holidays filter', array( '2026-10-12' ) === Eru_CMI_Rules::holidays() );
	remove_filter( 'eru_cmi_holidays', $hol );
	$v = Eru_CMI_Rules::evaluate( array( 'status' => 'ONAY', 'recipient_type' => 'BIREYSEL', 'consent_ts' => $fri ), array( 'known' => false, 'now' => $thu, 'restamp' => false, 'local' => true, 'holidays' => array() ) );
	cmi_check( '(c) evaluate(): old record without re-stamp -> skip H467', false === $v['send'] && 'H467' === $v['code'] );
	$v = Eru_CMI_Rules::evaluate( array( 'status' => 'ONAY', 'recipient_type' => 'BIREYSEL', 'consent_ts' => $fri ), array( 'known' => false, 'now' => $thu, 'restamp' => true, 'local' => true, 'holidays' => array() ) );
	cmi_check( '(c) evaluate(): re-stamp on and local -> send with now', true === $v['send'] && $thu === $v['consent_ts'] && $v['restamped'] );
	$v = Eru_CMI_Rules::evaluate( array( 'status' => 'ONAY', 'recipient_type' => 'BIREYSEL', 'consent_ts' => $fri ), array( 'known' => false, 'now' => $thu, 'restamp' => true, 'local' => false, 'holidays' => array() ) );
	cmi_check( '(c) evaluate(): re-stamp on but imported -> still skipped', false === $v['send'] && 'H467' === $v['code'] );
	$v = Eru_CMI_Rules::evaluate( array( 'status' => 'RET', 'recipient_type' => 'BIREYSEL', 'consent_ts' => $fri ), array( 'known' => true, 'now' => $thu, 'restamp' => false, 'local' => false, 'holidays' => array() ) );
	cmi_check( '(c) [I-2] evaluate(): an aged RET is always sent with now (re-stamp off, any actor)', true === $v['send'] && $thu === $v['consent_ts'] && $v['restamped'] );

	// (c) H467 through the queue: skipped by default, re-stamped to now when the setting is on.
	cmi_reset();
	cmi_settings();
	$old = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
	Eru_CMI_Repository::set( 'old@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin', 'consent_date' => time() - 10 * DAY_IN_SECONDS ) );
	$q = cmi_queue_rows();
	cmi_check( '(c) old consent date, re-stamp off -> skipped H467', 'skipped' === $q[0]->status && false !== strpos( $q[0]->last_error, 'H467' ) );
	cmi_settings( array( 'restamp_old' => 'yes' ) );
	Eru_CMI_Repository::set( 'old2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin', 'consent_date' => time() - 10 * DAY_IN_SECONDS ) );
	$q       = cmi_queue_rows( "status = 'queued'" );
	$payload = json_decode( $q[0]->payload, true );
	$sent_ts = ( new DateTimeImmutable( $payload['consentDate'], $tz ) )->getTimestamp();
	cmi_check( '(c) re-stamp on -> queued, payload consentDate = now (Istanbul time)', 1 === count( $q ) && abs( $sent_ts - time() ) <= 5 );
	$c = Eru_CMI_Repository::get( 'old2@example.com', 'EPOSTA' );
	cmi_check( '(c) re-stamp leaves the stored consent_date untouched', abs( strtotime( $c->consent_date . ' UTC' ) - ( time() - 10 * DAY_IN_SECONDS ) ) <= 5 );
	Eru_CMI_Repository::set( 'old3@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'import', 'actor' => 'import', 'consent_date' => time() - 10 * DAY_IN_SECONDS ) );
	cmi_check( '(c) re-stamp never applies to imported records', 'skipped' === Eru_CMI_Repository::get( 'old3@example.com', 'EPOSTA' )->iys_status );
	// A row that ages in the queue is re-checked at send time.
	cmi_settings( array( 'restamp_old' => 'no' ) );
	$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'eru_cmi_queue' ); // phpcs:ignore
	Eru_CMI_Repository::set( 'ages@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$q       = cmi_queue_rows();
	$payload = json_decode( $q[0]->payload, true );
	$payload['consentDate'] = ( new DateTimeImmutable( '@' . ( time() - 10 * DAY_IN_SECONDS ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
	$wpdb->update( $wpdb->prefix . 'eru_cmi_queue', array( 'payload' => wp_json_encode( $payload ) ), array( 'id' => $q[0]->id ) );
	$n = count( $fake->reqs );
	Eru_CMI_Queue::flush();
	cmi_check( '(c) a row that aged in the queue is skipped at send time, not sent', 'skipped' === cmi_queue_rows()[0]->status && 0 === count( array_filter( array_slice( $fake->reqs, $n ), fn( $r ) => false !== strpos( $r['path'], '/consents/request' ) ) ) );

	// Final review I-2 — an opt-out older than 3 business days always reaches İYS, dated now; the stored date stays.
	cmi_reset();
	cmi_settings( array( 'restamp_old' => 'no' ) );
	$old_ts = time() - 10 * DAY_IN_SECONDS;
	Eru_CMI_Repository::set( 'aged-ret@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'import', 'actor' => 'import', 'consent_date' => $old_ts - 10 * DAY_IN_SECONDS, 'mark_accepted' => true ) ); // Already registered at İYS.
	$ar     = Eru_CMI_Repository::set( 'aged-ret@example.com', 'EPOSTA', 'RET', array( 'source' => 'import', 'actor' => 'import', 'consent_date' => $old_ts ) );
	$p      = json_decode( cmi_queue_rows( 'id = ' . (int) $ar['queue_id'] )[0]->payload, true );
	cmi_check( '[I-2] aged RET with re-stamp off (imported): queued, consentDate = now', 'queued' === $ar['queue'] && abs( ( new DateTimeImmutable( $p['consentDate'], $tz ) )->getTimestamp() - time() ) <= 5 );
	cmi_check( '[I-2] the original withdrawal date is kept locally as evidence', abs( strtotime( Eru_CMI_Repository::get( 'aged-ret@example.com', 'EPOSTA' )->consent_date . ' UTC' ) - $old_ts ) <= 1 );
	$n = count( $fake->reqs );
	Eru_CMI_Queue::flush();
	$posts = array_values( array_filter( array_slice( $fake->reqs, $n ), fn( $r ) => false !== strpos( $r['path'], '/consents/request' ) ) );
	cmi_check( '[I-2] ... and sent to İYS as RET', 1 === count( $posts ) && 'RET' === $posts[0]['body'][0]['status'] && 'sent' === Eru_CMI_Repository::get( 'aged-ret@example.com', 'EPOSTA' )->iys_status );
	$ao = Eru_CMI_Repository::set( 'aged-onay@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin', 'consent_date' => $old_ts ) );
	cmi_check( '[I-2] a dated ONAY with re-stamp off is still skipped (H467)', 'skipped' === $ao['queue'] && false !== strpos( $ao['queue_reason'], 'H467' ) );
	// A RET that ages in the queue (İYS unreachable for days) is re-stamped at send time, never skipped.
	Eru_CMI_Repository::set( 'aged-q@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Queue::flush();
	$rq = Eru_CMI_Repository::set( 'aged-q@example.com', 'EPOSTA', 'RET', array( 'source' => 'link', 'actor' => 'user' ) );
	$row = cmi_queue_rows( 'id = ' . (int) $rq['queue_id'] )[0];
	$p   = json_decode( $row->payload, true );
	$p['consentDate'] = ( new DateTimeImmutable( '@' . $old_ts ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
	$wpdb->update( $wpdb->prefix . 'eru_cmi_queue', array( 'payload' => wp_json_encode( $p ) ), array( 'id' => $row->id ) );
	$n = count( $fake->reqs );
	Eru_CMI_Queue::flush();
	$posts = array_values( array_filter( array_slice( $fake->reqs, $n ), fn( $r ) => false !== strpos( $r['path'], '/consents/request' ) ) );
	$sent  = $posts ? $posts[0]['body'][0] : array();
	cmi_check( '[I-2] a RET that aged in the queue is sent re-stamped (not skipped)', 'sent' === cmi_queue_rows( 'id = ' . (int) $row->id )[0]->status && 'RET' === ( $sent['status'] ?? '' ) && abs( ( new DateTimeImmutable( (string) ( $sent['consentDate'] ?? '' ), $tz ) )->getTimestamp() - time() ) <= 5 && ! isset( $sent['_restamp'] ) && ! isset( $sent['_orig'] ) );

	// Final review M-2 — with re-stamp on, an aged ONAY is looked up first; a newer RET at İYS wins.
	cmi_reset();
	cmi_settings( array( 'restamp_old' => 'yes' ) );
	$fake->store = array();
	$iys_ret     = ( new DateTimeImmutable( '@' . ( time() - 2 * DAY_IN_SECONDS ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
	$fake->store['bireysel|eposta|m2-newer@example.com'] = array( 'recipient' => 'm2-newer@example.com', 'recipientType' => 'BIREYSEL', 'type' => 'EPOSTA', 'source' => 'HS_WEB', 'status' => 'RET', 'consentDate' => $iys_ret );
	$fake->store['bireysel|eposta|m2-older@example.com'] = array( 'recipient' => 'm2-older@example.com', 'recipientType' => 'BIREYSEL', 'type' => 'EPOSTA', 'source' => 'HS_WEB', 'status' => 'RET', 'consentDate' => ( new DateTimeImmutable( '@' . ( time() - 20 * DAY_IN_SECONDS ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' ) );
	$m1 = Eru_CMI_Repository::set( 'm2-newer@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'admin', 'actor' => 'admin', 'consent_date' => $old_ts ) );
	$m2 = Eru_CMI_Repository::set( 'm2-older@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'admin', 'actor' => 'admin', 'consent_date' => $old_ts ) );
	cmi_check( '[M-2] setup: both aged ONAYs queued for re-stamping', 'queued' === $m1['queue'] && 'queued' === $m2['queue'] );
	$n = count( $fake->reqs );
	Eru_CMI_Queue::flush();
	$new   = array_slice( $fake->reqs, $n );
	$looks = array_values( array_filter( $new, fn( $r ) => false !== strpos( $r['path'], '/consents/status' ) ) );
	$posts = array_values( array_filter( $new, fn( $r ) => false !== strpos( $r['path'], '/consents/request' ) ) );
	$sent  = $posts ? wp_list_pluck( $posts[0]['body'], 'recipient' ) : array();
	cmi_check( '[M-2] both looked up before re-stamping; only the one without a newer RET at İYS is sent', 2 === count( $looks ) && array( 'm2-older@example.com' ) === $sent );
	$c1 = Eru_CMI_Repository::get( 'm2-newer@example.com', 'EPOSTA' );
	$h1 = Eru_CMI_History::for_consent( (int) $c1->id );
	cmi_check( '[M-2] the newer RET at İYS is kept: row not sent, local consent RET (actor iys)', ! in_array( cmi_queue_rows( 'id = ' . (int) $m1['queue_id'] )[0]->status, array( 'queued', 'sent' ), true ) && 'RET' === $c1->status && 'iys' === end( $h1 )->actor );
	cmi_check( '[M-2] the other ONAY went out dated now', 'ONAY' === $posts[0]['body'][0]['status'] && abs( ( new DateTimeImmutable( $posts[0]['body'][0]['consentDate'], $tz ) )->getTimestamp() - time() ) <= 5 );
	// A failed look-up never sends the ONAY blind: it backs off.
	$fake->lookup_mode = '500';
	$m3                = Eru_CMI_Repository::set( 'm2-err@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'admin', 'actor' => 'admin', 'consent_date' => $old_ts ) );
	$n                 = count( $fake->reqs );
	Eru_CMI_Queue::flush();
	$fake->lookup_mode = 'ok';
	$r3                = cmi_queue_rows( 'id = ' . (int) $m3['queue_id'] )[0];
	cmi_check( '[M-2] look-up failed: not sent, one attempt spent, backed off', 0 === count( array_filter( array_slice( $fake->reqs, $n ), fn( $r ) => false !== strpos( $r['path'], '/consents/request' ) ) ) && 'queued' === $r3->status && 1 === (int) $r3->attempts );
	cmi_settings( array( 'restamp_old' => 'no' ) );

	// TACIR rule.
	cmi_reset();
	cmi_settings();
	$t = Eru_CMI_Repository::set( 'firma@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'company' => 'Demo Ltd' ) );
	cmi_check( 'TACIR setting off: company ignored, BIREYSEL', 'BIREYSEL' === $t['recipient_type'] );
	cmi_settings( array( 'tacir_from_company' => 'yes' ) );
	$t = Eru_CMI_Repository::set( 'firma2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'company' => 'Demo Ltd' ) );
	$b = Eru_CMI_Repository::set( 'kisi@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'company' => '' ) );
	cmi_check( 'TACIR setting on: company filled -> TACIR, empty -> BIREYSEL', 'TACIR' === $t['recipient_type'] && 'BIREYSEL' === $b['recipient_type'] );
	$tr = Eru_CMI_Repository::set( 'firma3@example.com', 'EPOSTA', 'RET', array( 'source' => 'test', 'company' => 'Demo Ltd' ) );
	cmi_check( 'TACIR: a first RET is a valid opt-out and is queued (H175 is for BIREYSEL)', 'queued' === $tr['queue'] );
	$x = Eru_CMI_Repository::set( 'firma2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'company' => '' ) );
	cmi_check( 'recipient type change with the same status is re-queued (İYS keys by type)', ! $x['changed'] && 'BIREYSEL' === $x['recipient_type'] && 'queued' === $x['queue'] );

	// Fix round 1 — a call without type information never re-types a TACIR recipient.
	cmi_reset();
	cmi_settings();
	$t = Eru_CMI_Repository::set( 'tacir@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'recipient_type' => 'TACIR' ) );
	$q = cmi_count( 'queue' );
	$a = Eru_CMI_Repository::set( 'tacir@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'account' ) );
	cmi_check( '[type] plain call on a TACIR row: stays TACIR, nothing queued', 'TACIR' === $a['recipient_type'] && 'TACIR' === Eru_CMI_Repository::get( 'tacir@example.com', 'EPOSTA' )->recipient_type && $q === cmi_count( 'queue' ) );
	$c = Eru_CMI_Repository::set( 'tacir@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'checkout', 'company' => '' ) );
	cmi_check( '[type] company key while the TACIR setting is off carries no type: unchanged', 'TACIR' === $c['recipient_type'] && $q === cmi_count( 'queue' ) );
	$r = Eru_CMI_Repository::set( 'tacir@example.com', 'EPOSTA', 'RET', array( 'source' => 'account' ) );
	$p = json_decode( cmi_queue_rows( 'id = ' . $r['queue_id'] )[0]->payload, true );
	cmi_check( '[type] status change without type info is queued under the stored type', 'TACIR' === $r['recipient_type'] && 'TACIR' === $p['recipientType'] );

	// Fix round 1 — re-consent after a skipped first record is queued again (fresh date); a repeated RET is not.
	cmi_reset();
	cmi_settings();
	Eru_CMI_Repository::set( 'again@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin', 'consent_date' => time() - 10 * DAY_IN_SECONDS ) );
	$again = Eru_CMI_Repository::set( 'again@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'account', 'actor' => 'user' ) );
	$c     = Eru_CMI_Repository::get( 'again@example.com', 'EPOSTA' );
	$h     = Eru_CMI_History::for_consent( $c->id );
	cmi_check( '[again] the user gives the same ONAY again after an H467 skip: queued with a fresh date and a history row', 'queued' === $again['queue'] && abs( strtotime( $c->consent_date . ' UTC' ) - time() ) <= 5 && 2 === count( $h ) && 'ONAY' === $h[1]->previous_status && 'ONAY' === $h[1]->new_status && 'account' === $h[1]->source );
	// A repeated ONAY from a non-user source never re-stamps the stored date; it follows restamp_old.
	$old_ts = time() - 10 * DAY_IN_SECONDS;
	Eru_CMI_Repository::set( 'again2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'admin', 'consent_date' => $old_ts ) );
	$c2 = Eru_CMI_Repository::get( 'again2@example.com', 'EPOSTA' );
	$q  = cmi_count( 'queue' );
	foreach ( array( 'import', 'admin', 'api' ) as $actor ) {
		Eru_CMI_Repository::set( 'again2@example.com', 'EPOSTA', 'ONAY', array( 'source' => $actor, 'actor' => $actor ) );
	}
	$c2b = Eru_CMI_Repository::get( 'again2@example.com', 'EPOSTA' );
	cmi_check( '[again] import/admin/api repeat with re-stamp off: date unchanged, nothing queued, no history', $c2->consent_date === $c2b->consent_date && $q === cmi_count( 'queue' ) && 1 === count( Eru_CMI_History::for_consent( $c2->id ) ) );
	cmi_settings( array( 'restamp_old' => 'yes' ) );
	Eru_CMI_Repository::set( 'again2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'import', 'actor' => 'import' ) );
	cmi_check( '[again] import repeat with re-stamp on: still not queued (imports are never re-stamped)', $q === cmi_count( 'queue' ) );
	$r2  = Eru_CMI_Repository::set( 'again2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'admin', 'actor' => 'admin' ) );
	$c2c = Eru_CMI_Repository::get( 'again2@example.com', 'EPOSTA' );
	$p2  = json_decode( cmi_queue_rows( 'id = ' . (int) $r2['queue_id'] )[0]->payload, true );
	cmi_check( '[again] admin repeat with re-stamp on: queued, payload re-stamped, stored date unchanged, history row added', 'queued' === $r2['queue'] && $c2->consent_date === $c2c->consent_date && abs( ( new DateTimeImmutable( $p2['consentDate'], new DateTimeZone( 'Europe/Istanbul' ) ) )->getTimestamp() - time() ) <= 5 && 2 === count( Eru_CMI_History::for_consent( $c2->id ) ) );
	cmi_settings( array( 'restamp_old' => 'no' ) );
	Eru_CMI_Repository::set( 'again-ret@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	$q = cmi_count( 'queue' );
	Eru_CMI_Repository::set( 'again-ret@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	cmi_check( '[again] a repeated RET after an H175 skip adds no queue row', $q === cmi_count( 'queue' ) );
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
echo "ALL PASS: cmi-rules\n";
