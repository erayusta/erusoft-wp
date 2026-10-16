<?php
/**
 * İYS queue: enqueue with the rules, batch send under an atomic lock, asynchronous status mapping,
 * backoff, ambiguous-failure look-up, stale-request handling and manual retry. Per environment.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Row life cycle:
 *   queued  → sent (in an İYS request, waiting for its per-record result) → accepted | rejected | skipped
 *   queued  → skipped (H175/H467 before sending; never sent)
 *   queued  → failed (6 failed attempts: 5/20/60/180/600 min apart)
 *   sent    → queued again once when İYS gives no final answer within 24 h, then failed.
 *   any unsent row → superseded when a newer row of the same consent is queued (never sent after it).
 *
 * Order per consent (recipient, type, recipientType, environment): an older status is never sent after a
 * newer one. Queuing a row supersedes older rows that never left; an older row whose answer was lost
 * blocks the newer one until a look-up settles it (accepted at İYS, or superseded when İYS lacks it);
 * stale and retried rows are superseded instead of resent when a newer row exists.
 *
 * Environments: every row belongs to the environment that was selected when it was queued. Sending,
 * polling, counting and retrying only ever see rows of the selected environment and use that environment's
 * client, so switching sandbox ↔ live never moves a row to the other system. "Known at İYS" (H175) is
 * also per environment.
 *
 * Ambiguous failures: a client batch key is written to the rows before each POST. When the answer is lost
 * (transport error, 5xx other than 503, 2xx without a request id, or a crash before the rows were marked)
 * the rows keep the key; the next run looks each record up at İYS first and marks it accepted when İYS
 * already holds that status, so it is resent only when İYS does not have it.
 *
 * A batch never holds the same recipient twice. A refused batch that names records rejects those only;
 * an unnamed 400/422 refusal is split in halves until the faulty record is isolated.
 *
 * A failed login (the client's auth_failed) means nothing left the site: the rows back off like a 401, are
 * never split or rejected, and a row whose answer was lost keeps its batch key (İYS was not asked, so it is
 * not "absent at İYS"). One failed login stops the run's other İYS calls.
 *
 * H467 at send time: an aged RET is sent dated now; an aged ONAY is re-stamped only with the setting on and
 * for a change made here, and only after a look-up at İYS: when İYS holds a RET at least as new as the
 * ONAY's own date (the person withdrew through İYS), that RET is applied here and the ONAY is not sent.
 */
class Eru_CMI_Queue {

	const MAX_ATTEMPTS   = 6;
	const STALE_HOURS    = 24;
	const POLL_IDS       = 50;
	const LOOKUPS        = 100;
	const REQUEST_BUDGET = 32;
	const STATUSES       = array( 'queued', 'sent', 'accepted', 'rejected', 'skipped', 'failed', 'superseded' );

	/** 4xx answers that mean "a record in this batch is malformed": the batch is split to find it. */
	const SPLIT_CODES = array( 400, 422 );

	/** @return string Table. */
	public static function table() {
		return Eru_CMI_Installer::table( 'queue' );
	}

	/** @return string Selected environment. */
	private static function env() {
		return Eru_CMI_Settings::env();
	}

	/** Minutes to wait after the 1st, 2nd … failed attempt. */
	const BACKOFF = array( 5, 20, 60, 180, 600 );

	/** @param int $attempts Failed attempts so far. @return int Seconds to the next try. */
	public static function backoff( $attempts ) {
		$i = max( 1, min( count( self::BACKOFF ), (int) $attempts ) ) - 1;
		return 60 * self::BACKOFF[ $i ];
	}

	/** @return string Now (UTC, MySQL). */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** @param int[] $ids Ids. @return string Comma list of integers (safe to inline). */
	private static function in( array $ids ) {
		$ids = array_map( 'intval', $ids );
		return $ids ? implode( ',', $ids ) : '0';
	}

	/**
	 * True when İYS (selected environment) holds, or may hold, a record for this consent: something was
	 * accepted there earlier, or a row of this environment was sent (waiting for its result, or its answer
	 * was lost). Rows that never left do not count: a newer row supersedes them.
	 *
	 * @param object $consent Consent row.
	 * @return bool
	 */
	public static function known_at_iys( $consent ) {
		$env = self::env();
		if ( false !== strpos( (string) ( $consent->iys_known ?? '' ), ',' . $env . ',' ) ) {
			return true;
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . " WHERE consent_id = %d AND env = %s AND ( status = 'sent' OR ( status = 'queued' AND batch_key IS NOT NULL ) ) LIMIT 1", (int) $consent->id, $env ) ); // phpcs:ignore
	}

	/** @param string $rt BIREYSEL | TACIR. @return string LIKE pattern for a payload of that recipient type. */
	private static function rt_like( $rt ) {
		global $wpdb;
		return '%' . $wpdb->esc_like( '"recipientType":"' . $rt . '"' ) . '%';
	}

	/** @param object $row Row. @return string Recipient type in its payload. */
	private static function row_rt( $row ) {
		$p = json_decode( (string) $row->payload, true );
		return is_array( $p ) ? (string) ( $p['recipientType'] ?? '' ) : '';
	}

	/**
	 * @param object $row Row.
	 * @return bool A newer row exists for the same consent, environment and recipient type.
	 */
	private static function has_newer( $row ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE consent_id = %d AND env = %s AND id > %d AND payload LIKE %s LIMIT 1', (int) $row->consent_id, (string) $row->env, (int) $row->id, self::rt_like( self::row_rt( $row ) ) ) ); // phpcs:ignore
	}

	/**
	 * @param object $row Row.
	 * @return object|null An older row of the same consent and environment whose answer was lost (it must
	 *                     be settled first).
	 */
	private static function blocker( $row ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE consent_id = %d AND env = %s AND id < %d AND status = 'queued' AND batch_key IS NOT NULL ORDER BY id ASC LIMIT 1", (int) $row->consent_id, (string) $row->env, (int) $row->id ) ); // phpcs:ignore
	}

	/** @param object[] $rows Rows. @param string $why Reason. Marks rows superseded. */
	private static function mark_superseded( array $rows, $why ) {
		global $wpdb;
		foreach ( $rows as $row ) {
			$wpdb->update( self::table(), array( 'status' => 'superseded', 'batch_key' => null, 'next_try_at' => null, 'last_error' => $why, 'updated_at' => self::now() ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
		}
	}

	/** @return string Reason text for a newer local change. */
	private static function newer_reason() {
		return __( 'Aynı izin için daha yeni bir değişiklik kuyruğa alındı; bu kayıt gönderilmeyecek.', 'consent-manager-for-iys' );
	}

	/**
	 * Records that İYS (an environment) holds a record for the consent.
	 *
	 * @param int         $consent_id Consent.
	 * @param string|null $env        Environment (default: selected).
	 */
	public static function mark_known( $consent_id, $env = null ) {
		global $wpdb;
		$env = $env ?? self::env();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . Eru_CMI_Installer::table( 'consents' ) . " SET iys_status = 'accepted', iys_synced_at = %s, iys_known = IF( LOCATE( %s, iys_known ) > 0, iys_known, CONCAT( IF( iys_known = '', ',', iys_known ), %s, ',' ) ) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				self::now(),
				',' . $env . ',',
				$env,
				(int) $consent_id
			)
		);
	}

	/**
	 * Records that İYS (an environment) does not hold the consent (reconcile found it missing), so H175 treats a
	 * later RET as a first record again.
	 *
	 * @param int         $consent_id Consent.
	 * @param string|null $env        Environment (default: selected).
	 */
	public static function forget_known( $consent_id, $env = null ) {
		global $wpdb;
		$env = $env ?? self::env();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Eru_CMI_Installer::table( 'consents' ) . " SET iys_known = IF( REPLACE( iys_known, %s, ',' ) = ',', '', REPLACE( iys_known, %s, ',' ) ) WHERE id = %d", ',' . $env . ',', ',' . $env . ',', (int) $consent_id ) ); // phpcs:ignore
	}

	/** @param int $consent_id Consent. @return bool A row of the selected environment is queued or waiting for İYS. */
	public static function is_open( $consent_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . " WHERE consent_id = %d AND env = %s AND status IN ('queued','sent') LIMIT 1", (int) $consent_id, self::env() ) ); // phpcs:ignore
	}

	/**
	 * Queues the stored state of a consent again (reconcile: sent earlier, missing or older at İYS). The rules
	 * apply as for any change: the stored date is kept and re-stamped only when the setting allows it for a
	 * change made on this site (the last history row's actor decides).
	 *
	 * @param object $consent Consent row (real address, not a placeholder).
	 * @return array{status:string,reason:string,id:int}
	 */
	public static function requeue_consent( $consent ) {
		global $wpdb;
		$last   = self::last_row( (int) $consent->id );
		$p      = $last ? json_decode( (string) $last->payload, true ) : null;
		$actor  = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT actor FROM ' . Eru_CMI_Installer::table( 'history' ) . ' WHERE consent_id = %d ORDER BY id DESC LIMIT 1', (int) $consent->id ) ); // phpcs:ignore
		$record = array(
			'consent_id'      => (int) $consent->id,
			'recipient'       => (string) $consent->recipient,
			'type'            => (string) $consent->type,
			'status'          => (string) $consent->status,
			'previous_status' => (string) $consent->status,
			'recipient_type'  => (string) $consent->recipient_type,
			'consent_ts'      => (int) strtotime( $consent->consent_date . ' UTC' ),
			'iys_source'      => Eru_CMI_Rules::iys_source( is_array( $p ) ? (string) ( $p['source'] ?? '' ) : '' ),
		);
		return self::enqueue( $record, $consent, array( 'actor' => '' !== $actor ? $actor : 'import' ) );
	}

	/** @param int $consent_id Consent. @return object|null Latest row of the selected environment. */
	public static function last_row( $consent_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE consent_id = %d AND env = %s ORDER BY id DESC LIMIT 1', (int) $consent_id, self::env() ) ); // phpcs:ignore
	}

	/**
	 * Applies the rules and writes a queue row (queued or skipped) for the selected environment.
	 *
	 * @param array  $record  consent_id, recipient, type, status, recipient_type, consent_ts, iys_source.
	 * @param object $consent Consent row.
	 * @param array  $context Context (actor).
	 * @return array{status:string,reason:string,id:int}
	 */
	public static function enqueue( array $record, $consent, array $context ) {
		global $wpdb;
		$local   = Eru_CMI_Rules::is_local( (string) ( $context['actor'] ?? '' ) );
		$verdict = Eru_CMI_Rules::evaluate(
			$record,
			array(
				'known'    => self::known_at_iys( $consent ),
				'now'      => time(),
				'restamp'  => Eru_CMI_Settings::on( 'restamp_old' ),
				'local'    => $local,
				'holidays' => Eru_CMI_Rules::holidays(),
			)
		);
		$payload = array(
			'recipient'     => (string) $record['recipient'],
			'recipientType' => (string) $record['recipient_type'],
			'type'          => (string) $record['type'],
			'source'        => Eru_CMI_Rules::iys_source( $record['iys_source'] ?? '' ),
			'status'        => (string) $record['status'],
			'consentDate'   => Eru_CMI_Rules::iys_date( $verdict['consent_ts'] ),
		);
		$extra = array( '_restamp' => $local ); // May be re-stamped at send time if it ages in the queue (ONAY: local changes only).
		if ( $verdict['restamped'] ) {
			$extra['_orig'] = Eru_CMI_Rules::iys_date( (int) $record['consent_ts'] ); // The date the person gave.
			if ( 'ONAY' === $record['status'] ) {
				$extra['_lookup'] = true; // Look İYS up before sending: a newer RET there must win.
			}
		}
		$status = $verdict['send'] ? 'queued' : 'skipped';
		$now    = self::now();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'consent_id'  => (int) $record['consent_id'],
				'env'         => self::env(),
				'payload'     => (string) wp_json_encode( $payload + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'      => $status,
				'attempts'    => 0,
				'next_try_at' => $verdict['send'] ? $now : null,
				'last_error'  => $verdict['send'] ? null : $verdict['reason'],
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		// Older rows of this consent that never left: a queued row replaces them all; a skipped one replaces
		// those with a different status (they contradict the current state).
		$older = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE consent_id = %d AND env = %s AND id < %d AND status = 'queued' AND batch_key IS NULL AND payload LIKE %s", (int) $record['consent_id'], self::env(), $id, self::rt_like( (string) $record['recipient_type'] ) ) ); // phpcs:ignore
		if ( ! $verdict['send'] ) {
			$older = array_filter( $older, fn( $o ) => ( json_decode( (string) $o->payload, true )['status'] ?? '' ) !== $record['status'] );
		}
		self::mark_superseded( $older, self::newer_reason() );
		self::mirror( array( (int) $record['consent_id'] ), $status, self::env() );
		if ( $verdict['send'] ) {
			Eru_CMI_Scheduler::soon();
		}
		return array(
			'status' => $status,
			'reason' => $verdict['reason'],
			'id'     => $id,
		);
	}

	/**
	 * Copies a row outcome to consent rows; "accepted" also records the environment as known.
	 *
	 * @param int[]  $consent_ids Consents.
	 * @param string $status      Queue status.
	 * @param string $env         Environment of the rows.
	 */
	private static function mirror( array $consent_ids, $status, $env ) {
		global $wpdb;
		if ( 'accepted' === $status ) {
			foreach ( $consent_ids as $cid ) {
				self::mark_known( $cid, $env );
			}
			return;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Eru_CMI_Installer::table( 'consents' ) . ' SET iys_status = %s WHERE id IN (' . self::in( $consent_ids ) . ')', $status ) ); // phpcs:ignore
	}

	/**
	 * Updates rows and mirrors the status to their consents.
	 *
	 * @param object[] $rows   Queue rows.
	 * @param string   $status New status.
	 * @param array    $extra  More columns.
	 */
	private static function set_rows( array $rows, $status, array $extra = array() ) {
		global $wpdb;
		foreach ( $rows as $row ) {
			$wpdb->update( self::table(), array_merge( array( 'status' => $status, 'updated_at' => self::now() ), $extra ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
			self::mirror( array( (int) $row->consent_id ), $status, (string) $row->env );
		}
	}

	/**
	 * Spends one attempt on each row: back off, or fail after the last attempt.
	 *
	 * @param object[] $rows     Rows.
	 * @param string   $error    Error text.
	 * @param bool     $keep_key Keep the batch key (the outcome at İYS is unknown).
	 * @param array    $stats    Counters.
	 */
	private static function attempt_failed( array $rows, $error, $keep_key, array &$stats ) {
		global $wpdb;
		foreach ( $rows as $row ) {
			$attempts = (int) $row->attempts + 1;
			$cols     = array(
				'attempts'   => $attempts,
				'last_error' => $error,
			);
			if ( ! $keep_key ) {
				$cols['batch_key'] = null;
			}
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				self::set_rows( array( $row ), 'failed', $cols + array( 'next_try_at' => null ) );
				++$stats['failed'];
			} else {
				$wpdb->update( self::table(), $cols + array( 'next_try_at' => gmdate( 'Y-m-d H:i:s', time() + self::backoff( $attempts ) ), 'updated_at' => self::now() ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
				++$stats['retry'];
			}
		}
	}

	/** @return bool Queued rows of the selected environment due now. */
	public static function has_due() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . " WHERE env = %s AND status = 'queued' AND (next_try_at IS NULL OR next_try_at <= %s) LIMIT 1", self::env(), self::now() ) ); // phpcs:ignore
	}

	/** @return bool Rows of the selected environment still in progress (queued or waiting for İYS). */
	public static function has_open() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . " WHERE env = %s AND status IN ('queued','sent') LIMIT 1", self::env() ) ); // phpcs:ignore
	}

	/**
	 * One run: settle rows whose last answer was lost, then send one batch of due rows (split only when
	 * İYS refuses it without naming a record). The scheduler chains the next run.
	 *
	 * @return array{sent:int,skipped:int,rejected:int,retry:int,failed:int,settled:int,locked:bool}
	 */
	public static function flush() {
		global $wpdb;
		$stats = array( 'sent' => 0, 'skipped' => 0, 'rejected' => 0, 'retry' => 0, 'failed' => 0, 'settled' => 0, 'locked' => false );
		if ( ! Eru_CMI_Settings::enabled() ) {
			return $stats;
		}
		$owner = Eru_CMI_Lock::acquire( 'flush', 10 * MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			$stats['locked'] = true;
			return $stats;
		}
		try {
			$env    = self::env();
			$client = new Eru_CMI_Client( $env );
			$auth   = self::settle( $client, $stats, $owner ); // Login error ('' = none): nothing else goes to İYS this run.
			$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . " WHERE env = %s AND status = 'queued' AND batch_key IS NULL AND (next_try_at IS NULL OR next_try_at <= %s) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
					$env,
					self::now(),
					Eru_CMI_Settings::batch_size()
				)
			);
			$batch    = array();
			$records  = array();
			$seen     = array();
			$now      = time();
			$holidays = Eru_CMI_Rules::holidays();
			$restamp  = Eru_CMI_Settings::on( 'restamp_old' );
			$lookups  = self::LOOKUPS;
			foreach ( $rows as $row ) {
				$block = self::blocker( $row );
				if ( $block ) {
					// Wait for the older row's look-up; no busy loop: become due when it does.
					$wpdb->update( self::table(), array( 'next_try_at' => $block->next_try_at ?? self::now(), 'updated_at' => self::now() ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
					continue;
				}
				$p = json_decode( (string) $row->payload, true );
				if ( ! is_array( $p ) || empty( $p['recipient'] ) ) {
					self::set_rows( array( $row ), 'failed', array( 'last_error' => 'invalid payload', 'next_try_at' => null ) );
					++$stats['failed'];
					continue;
				}
				$ts = Eru_CMI_Rules::parse_date( (string) ( $p['consentDate'] ?? '' ) );
				if ( null !== $ts && Eru_CMI_Rules::is_too_old( $ts, $now, $holidays ) ) {
					$is_ret = 'RET' === ( $p['status'] ?? '' );
					if ( $is_ret || ( $restamp && ! empty( $p['_restamp'] ) ) ) {
						// An aged RET always goes, dated now; an aged ONAY only with the setting, after a look-up.
						$p['_orig']       = $p['_orig'] ?? (string) $p['consentDate'];
						$p['consentDate'] = Eru_CMI_Rules::iys_date( $now );
						if ( ! $is_ret ) {
							$p['_lookup'] = true;
						}
						$wpdb->update( self::table(), array( 'payload' => wp_json_encode( $p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
					} else {
						self::set_rows( array( $row ), 'skipped', array( 'last_error' => 'H467: ' . __( 'İzin tarihi 3 iş gününden eski; İYS kabul etmez.', 'consent-manager-for-iys' ), 'next_try_at' => null ) );
						++$stats['skipped'];
						continue;
					}
				}
				$key = strtolower( ( $p['recipientType'] ?? '' ) . '|' . ( $p['type'] ?? '' ) . '|' . $p['recipient'] );
				if ( isset( $seen[ $key ] ) ) {
					continue; // Same recipient twice in one request: İYS would refuse the whole batch. Next run.
				}
				if ( ! empty( $p['_lookup'] ) ) {
					if ( '' !== $auth ) {
						self::attempt_failed( array( $row ), $auth, false, $stats );
						continue;
					}
					if ( $lookups <= 0 ) {
						continue; // Look-up budget of this run used up: the row stays due for the next run.
					}
					--$lookups;
					Eru_CMI_Lock::refresh( 'flush', $owner );
					$check = self::check_restamp( $client, $row, $p );
					if ( 'retry' === $check['verdict'] ) {
						self::attempt_failed( array( $row ), $check['error'], false, $stats );
						$auth = $check['auth'] ? $check['error'] : $auth;
						continue;
					}
					if ( 'skip' === $check['verdict'] ) {
						++$stats['skipped'];
						continue;
					}
				}
				$seen[ $key ] = true;
				unset( $p['_restamp'], $p['_orig'], $p['_lookup'] );
				$batch[]   = $row;
				$records[] = $p;
			}
			if ( $batch && '' !== $auth ) {
				self::attempt_failed( $batch, $auth, false, $stats ); // The login failed: nothing is sent this run.
			} elseif ( $batch ) {
				$budget = self::REQUEST_BUDGET;
				self::send_part( $client, $batch, $records, $budget, $stats, $owner );
			}
			return $stats;
		} finally {
			Eru_CMI_Lock::release( 'flush', $owner );
		}
	}

	/**
	 * Sends a set of rows as one request and handles every outcome; splits on an unnamed 4xx refusal.
	 *
	 * @param Eru_CMI_Client $client  Client of the rows' environment.
	 * @param object[]       $rows    Rows (list).
	 * @param array[]        $records İYS records, same order.
	 * @param int            $budget  Requests left in this run.
	 * @param array          $stats   Counters.
	 * @param string         $owner   Lock token.
	 */
	private static function send_part( Eru_CMI_Client $client, array $rows, array $records, &$budget, array &$stats, $owner ) {
		global $wpdb;
		$rows    = array_values( $rows );
		$records = array_values( $records );
		if ( $budget <= 0 ) {
			return; // Request budget of this run used up: the rows stay queued and due for the next run.
		}
		$ids     = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$in      = self::in( $ids );
		// Idempotency: the key is on the rows before the request leaves.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET batch_key = %s, updated_at = %s WHERE id IN ({$in})", wp_generate_uuid4(), self::now() ) ); // phpcs:ignore
		Eru_CMI_Lock::refresh( 'flush', $owner );
		--$budget;
		$res  = $client->send_batch( $records );
		$code = (int) $res['code'];

		// The login failed: the batch never left. Back off like a 401: no split, no rejection, key dropped.
		if ( ! empty( $res['auth_failed'] ) ) {
			self::attempt_failed( $rows, $res['error'], false, $stats );
			return;
		}

		if ( $res['ok'] && '' !== $res['request_id'] ) {
			$t = self::now();
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'sent', request_id = %s, sub_index = FIELD(id, {$in}) - 1, sent_at = %s, next_try_at = NULL, last_error = NULL, batch_key = NULL, updated_at = %s WHERE id IN ({$in})", substr( $res['request_id'], 0, 100 ), $t, $t ) ); // phpcs:ignore
			self::mirror( array_map( 'intval', wp_list_pluck( $rows, 'consent_id' ) ), 'sent', $client->env() );
			$stats['sent'] += count( $rows );
			return;
		}

		// İYS refused the batch and named records: settle those; the others were not processed and wait.
		$bad     = $res['ok'] ? array() : self::error_indexes( $res['data'] );
		$matched = array_intersect_key( $bad, $rows );
		if ( $matched ) {
			foreach ( $rows as $i => $row ) {
				if ( isset( $matched[ $i ] ) ) {
					$status = Eru_CMI_Rules::classify_code( $matched[ $i ]['code'] );
					self::set_rows( array( $row ), $status, array( 'batch_key' => null, 'next_try_at' => null, 'last_error' => Eru_CMI_Logger::redact( trim( $matched[ $i ]['code'] . ': ' . $matched[ $i ]['message'], ': ' ) ) ) );
					++$stats[ 'accepted' === $status ? 'sent' : ( 'skipped' === $status ? 'skipped' : 'rejected' ) ];
				} else {
					$wpdb->update( self::table(), array( 'batch_key' => null, 'updated_at' => self::now() ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
				}
			}
			return;
		}
		if ( $bad ) {
			// Named records that are not in this request: the faulty row is unknown; spend an attempt (no loop).
			self::attempt_failed( $rows, __( 'İYS bir hata bildirdi ama işaret ettiği kayıt bu partide yok.', 'consent-manager-for-iys' ) . ' ' . $res['error'], false, $stats );
			return;
		}

		// Other definite refusals (403, 404 …): a configuration fault (codes, address, permission), not a bad
		// record: back the batch off and let the admin fix the settings.
		if ( ! $res['ok'] && $code >= 400 && $code < 500 && ! in_array( $code, array_merge( self::SPLIT_CODES, array( 401, 408, 429 ) ), true ) ) {
			self::attempt_failed( $rows, __( 'İYS isteği reddetti; bağlantı ayarlarını (İYS kodu, marka kodu, adres, yetki) kontrol edin.', 'consent-manager-for-iys' ) . ' ' . $res['error'], false, $stats );
			return;
		}

		// 400/422 without a record index: a malformed record; split the batch to isolate it.
		if ( ! $res['ok'] && in_array( $code, self::SPLIT_CODES, true ) ) {
			if ( 1 === count( $rows ) ) {
				self::set_rows( $rows, 'rejected', array( 'batch_key' => null, 'next_try_at' => null, 'last_error' => $res['error'] ) );
				++$stats['rejected'];
				return;
			}
			if ( $budget >= 2 ) {
				$half = (int) ceil( count( $rows ) / 2 );
				self::send_part( $client, array_slice( $rows, 0, $half ), array_slice( $records, 0, $half ), $budget, $stats, $owner );
				self::send_part( $client, array_slice( $rows, $half ), array_slice( $records, $half ), $budget, $stats, $owner );
				return;
			}
			self::attempt_failed( $rows, $res['error'], false, $stats );
			return;
		}

		// Lost answer (transport error, 5xx other than 503, 2xx without a request id): İYS may hold the
		// records, so the key stays for a look-up. 401/408/429/503: not processed, key dropped.
		$ambiguous = 0 === $code || ( $code >= 500 && 503 !== $code ) || $res['ok'];
		$error     = '' !== $res['error'] ? $res['error'] : __( 'İYS yanıtında istek numarası yok.', 'consent-manager-for-iys' );
		self::attempt_failed( $rows, $error, $ambiguous, $stats );
	}

	/**
	 * Due rows that still carry a batch key (their answer was lost): ask İYS about each record. Same status
	 * at İYS → accepted without resending; absent or different → key dropped, sent in this run. A failed
	 * login is not an answer: that row and the remaining ones back off with their key (İYS was not asked).
	 *
	 * @param Eru_CMI_Client $client Client.
	 * @param array          $stats  Counters.
	 * @param string         $owner  Lock token.
	 * @return string The login error, or '' when İYS could be asked.
	 */
	private static function settle( Eru_CMI_Client $client, array &$stats, $owner ) {
		global $wpdb;
		$auth = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE env = %s AND status = 'queued' AND batch_key IS NOT NULL AND (next_try_at IS NULL OR next_try_at <= %s) ORDER BY id ASC LIMIT %d", $client->env(), self::now(), self::LOOKUPS ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			if ( '' !== $auth ) {
				self::attempt_failed( array( $row ), $auth, true, $stats ); // Same cause; no second login this run.
				continue;
			}
			Eru_CMI_Lock::refresh( 'flush', $owner );
			$p = json_decode( (string) $row->payload, true );
			$p = is_array( $p ) ? $p : array();
			$r = $client->consent_status( $p );
			if ( ! empty( $r['auth_failed'] ) ) {
				$auth = (string) $r['error'];
				self::attempt_failed( array( $row ), $auth, true, $stats );
				continue;
			}
			if ( $r['ok'] && '' !== $r['status'] && ( $p['status'] ?? '' ) === $r['status'] ) {
				self::set_rows( array( $row ), 'accepted', array( 'batch_key' => null, 'next_try_at' => null, 'last_error' => null ) );
				++$stats['settled'];
			} elseif ( $r['ok'] || ( $r['code'] >= 400 && $r['code'] < 500 && ! in_array( (int) $r['code'], array( 401, 408, 429 ), true ) ) ) {
				if ( self::has_newer( $row ) ) {
					self::mark_superseded( array( $row ), self::newer_reason() ); // İYS lacks it and a newer change exists.
				} else {
					$wpdb->update( self::table(), array( 'batch_key' => null, 'updated_at' => self::now() ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
				}
			} else {
				self::attempt_failed( array( $row ), $r['error'], true, $stats );
			}
		}
		return $auth;
	}

	/**
	 * Before an aged ONAY is sent re-stamped (dated now): does İYS hold a withdrawal at least as new as the
	 * ONAY's own date? Then that RET is applied here (actor iys, as the pull would) and the ONAY is not sent.
	 *
	 * @param Eru_CMI_Client $client Client.
	 * @param object         $row    Queue row.
	 * @param array          $p      Its payload (with _orig, the consent's own date).
	 * @return array{verdict:string,error:string,auth:bool} verdict send | skip | retry.
	 */
	private static function check_restamp( Eru_CMI_Client $client, $row, array $p ) {
		global $wpdb;
		$r = $client->consent_status( $p );
		if ( ! empty( $r['auth_failed'] ) || ( ! $r['ok'] && 404 !== (int) $r['code'] ) ) {
			// İYS could not be asked: never send blind; back off.
			return array( 'verdict' => 'retry', 'error' => (string) $r['error'], 'auth' => ! empty( $r['auth_failed'] ) );
		}
		if ( ! $r['ok'] || 'RET' !== $r['status'] ) {
			return array( 'verdict' => 'send', 'error' => '', 'auth' => false ); // Absent, or ONAY at İYS.
		}
		$data   = is_array( $r['data'] ) ? $r['data'] : array();
		$date   = (string) ( $data['consentDate'] ?? ( $data['creationDate'] ?? '' ) );
		$iys_ts = '' !== $date ? Eru_CMI_Rules::parse_date( $date ) : null;
		$own_ts = Eru_CMI_Rules::parse_date( (string) ( $p['_orig'] ?? '' ) );
		if ( null !== $iys_ts && null !== $own_ts && $iys_ts < $own_ts ) {
			return array( 'verdict' => 'send', 'error' => '', 'auth' => false ); // Our ONAY is the newer change.
		}
		if ( null !== $iys_ts ) {
			Eru_CMI_Pull::apply(
				array(
					'recipient'     => (string) ( $p['recipient'] ?? '' ),
					'type'          => (string) ( $p['type'] ?? '' ),
					'status'        => 'RET',
					'recipientType' => (string) ( $data['recipientType'] ?? '' ),
					'consentDate'   => $date,
				)
			);
		}
		// apply() supersedes the queued rows of the consent; close this one too if it is still queued.
		$fresh = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $row->id ) ); // phpcs:ignore
		if ( $fresh && 'queued' === $fresh->status ) {
			self::set_rows( array( $fresh ), 'skipped', array( 'batch_key' => null, 'next_try_at' => null, 'last_error' => __( 'İYS\'de bu izinden daha yeni bir RET var; ONAY yeniden damgalanıp gönderilmedi.', 'consent-manager-for-iys' ) ) );
		}
		return array( 'verdict' => 'skip', 'error' => '', 'auth' => false );
	}

	/**
	 * Per-record errors of a refused batch, keyed by index.
	 *
	 * @param mixed $data Response body.
	 * @return array<int,array{code:string,message:string}>
	 */
	private static function error_indexes( $data ) {
		$out  = array();
		$list = is_array( $data ) ? ( $data['errors'] ?? ( $data['data']['errors'] ?? null ) ) : null;
		foreach ( is_array( $list ) ? $list : array() as $e ) {
			if ( is_array( $e ) && isset( $e['index'] ) && is_numeric( $e['index'] ) ) {
				$out[ (int) $e['index'] ] = array(
					'code'    => (string) ( $e['code'] ?? '' ),
					'message' => mb_substr( (string) ( $e['message'] ?? '' ), 0, 200 ),
				);
			}
		}
		return $out;
	}

	/**
	 * Polls İYS for requests (selected environment) waiting for per-record results; handles stale ones.
	 *
	 * @return array{accepted:int,rejected:int,skipped:int,requeued:int,failed:int,superseded:int,locked:bool}
	 */
	public static function poll() {
		global $wpdb;
		$stats = array( 'accepted' => 0, 'rejected' => 0, 'skipped' => 0, 'requeued' => 0, 'failed' => 0, 'superseded' => 0, 'locked' => false );
		if ( ! Eru_CMI_Settings::enabled() ) {
			return $stats;
		}
		$owner = Eru_CMI_Lock::acquire( 'poll', 10 * MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			$stats['locked'] = true;
			return $stats;
		}
		try {
			$env = self::env();
			// No final answer within 24 h: send once more, then give up.
			$cut   = gmdate( 'Y-m-d H:i:s', time() - self::STALE_HOURS * HOUR_IN_SECONDS );
			$stale = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE env = %s AND status = 'sent' AND sent_at < %s ORDER BY id ASC LIMIT 1000", $env, $cut ) ); // phpcs:ignore
			foreach ( $stale as $row ) {
				if ( self::has_newer( $row ) ) {
					self::mark_superseded( array( $row ), self::newer_reason() );
					++$stats['superseded'];
				} elseif ( (int) $row->requeued ) {
					self::set_rows( array( $row ), 'failed', array( 'last_error' => __( 'İYS 24 saat içinde sonuç vermedi.', 'consent-manager-for-iys' ) ) );
					++$stats['failed'];
				} else {
					self::set_rows( array( $row ), 'queued', array( 'requeued' => 1, 'request_id' => null, 'sub_index' => null, 'sent_at' => null, 'next_try_at' => self::now(), 'last_error' => __( 'İYS 24 saat içinde sonuç vermedi; bir kez yeniden gönderilecek.', 'consent-manager-for-iys' ) ) );
					++$stats['requeued'];
				}
			}
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT request_id FROM ' . self::table() . " WHERE env = %s AND status = 'sent' AND request_id IS NOT NULL AND request_id <> '' LIMIT %d", $env, self::POLL_IDS ) ); // phpcs:ignore
			if ( ! $ids ) {
				return $stats;
			}
			$client = new Eru_CMI_Client( $env );
			foreach ( $ids as $request_id ) {
				Eru_CMI_Lock::refresh( 'poll', $owner );
				$res = $client->request_status( $request_id );
				if ( ! $res['ok'] || $res['pending'] ) {
					continue; // Unknown or still in progress: the next run asks again; 24 h caps the wait.
				}
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE env = %s AND status = 'sent' AND request_id = %s", $env, $request_id ) ); // phpcs:ignore
				foreach ( $rows as $row ) {
					$item = $res['items'][ (int) $row->sub_index ] ?? null;
					if ( ! is_array( $item ) ) {
						continue;
					}
					$state = strtolower( (string) ( $item['status'] ?? '' ) );
					if ( in_array( $state, array( 'success', 'successful', 'ok' ), true ) ) {
						self::set_rows( array( $row ), 'accepted', array( 'last_error' => null ) );
						++$stats['accepted'];
					} elseif ( in_array( $state, array( 'failure', 'failed', 'error' ), true ) ) {
						$err    = is_array( $item['error'] ?? null ) ? ( isset( $item['error'][0] ) ? $item['error'][0] : $item['error'] ) : array();
						$code   = (string) ( $err['code'] ?? '' );
						$status = Eru_CMI_Rules::classify_code( $code );
						self::set_rows( array( $row ), $status, array( 'last_error' => Eru_CMI_Logger::redact( mb_substr( trim( $code . ': ' . ( $err['message'] ?? '' ), ': ' ), 0, 300 ) ) ) );
						++$stats[ $status ];
					}
				}
			}
			return $stats;
		} finally {
			Eru_CMI_Lock::release( 'poll', $owner );
		}
	}

	/**
	 * Puts failed (and, with $include_rejected, rejected) rows of the selected environment back in the
	 * queue with 0 attempts.
	 *
	 * @param int|null $id               One row, or all.
	 * @param bool     $include_rejected Also rejected rows.
	 * @return int Rows re-queued.
	 */
	public static function retry( $id = null, $include_rejected = false ) {
		global $wpdb;
		$states = $include_rejected ? "'failed','rejected'" : "'failed'";
		$sql    = 'SELECT * FROM ' . self::table() . " WHERE env = %s AND status IN ({$states})";
		$args   = array( self::env() );
		if ( $id ) {
			$sql   .= ' AND id = %d';
			$args[] = (int) $id;
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT 5000', ...$args ) ); // phpcs:ignore
		$newer = array_filter( $rows, fn( $r ) => self::has_newer( $r ) );
		self::mark_superseded( $newer, self::newer_reason() );
		$rows = array_values( array_diff_key( $rows, $newer ) );
		self::set_rows( $rows, 'queued', array( 'attempts' => 0, 'requeued' => 0, 'request_id' => null, 'sub_index' => null, 'sent_at' => null, 'batch_key' => null, 'next_try_at' => self::now(), 'last_error' => null ) );
		if ( $rows ) {
			Eru_CMI_Scheduler::soon();
		}
		return count( $rows );
	}

	/**
	 * Closes rows of the selected environment by hand (Kuyruk tab): queued rows that have not left yet, failed
	 * and rejected rows become "skipped" and are never sent. Rows waiting for İYS's answer are left alone.
	 *
	 * @param int[]  $ids    Row ids.
	 * @param string $reason Reason kept with the row.
	 * @return int Rows closed.
	 */
	public static function skip( array $ids, $reason ) {
		global $wpdb;
		if ( ! $ids ) {
			return 0;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE env = %s AND id IN (' . self::in( $ids ) . ") AND ( ( status = 'queued' AND batch_key IS NULL ) OR status IN ('failed','rejected') )", self::env() ) ); // phpcs:ignore
		self::set_rows( $rows, 'skipped', array( 'batch_key' => null, 'next_try_at' => null, 'last_error' => $reason ) );
		return count( $rows );
	}

	/**
	 * Puts the given rows of the selected environment back in the queue (Kuyruk tab bulk action): failed and
	 * rejected rows only; a row with a newer row for the same consent is superseded instead.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows re-queued.
	 */
	public static function retry_ids( array $ids ) {
		$n = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 ) {
				$n += self::retry( $id, true );
			}
		}
		return $n;
	}

	/**
	 * Marks still-queued rows of a consent (selected environment) as superseded: a newer change from İYS
	 * replaced them.
	 *
	 * @param int    $consent_id Consent.
	 * @param string $reason     Reason.
	 */
	public static function supersede( $consent_id, $reason ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'superseded', next_try_at = NULL, batch_key = NULL, last_error = %s, updated_at = %s WHERE consent_id = %d AND env = %s AND status = 'queued'", $reason, self::now(), (int) $consent_id, self::env() ) ); // phpcs:ignore
	}

	/**
	 * @param string|null $env Environment (default: selected).
	 * @return array<string,int> Row count per status.
	 */
	public static function counts( $env = null ) {
		global $wpdb;
		$env = in_array( $env, Eru_CMI_Settings::ENVS, true ) ? $env : self::env();
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM ' . self::table() . ' WHERE env = %s GROUP BY status', $env ) ) ?: array() as $r ) { // phpcs:ignore
			$out[ $r->status ] = (int) $r->c;
		}
		return $out;
	}
}
