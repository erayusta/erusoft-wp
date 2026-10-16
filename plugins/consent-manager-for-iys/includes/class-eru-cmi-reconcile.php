<?php
/**
 * Nightly reconcile: "registered at İYS according to this site — does İYS really hold it?"
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One pass per night over the consents İYS accepted in the selected environment (rows with an address;
 * erased rows are skipped), in id order with a stored cursor, at most BUDGET rows and TIME_BUDGET seconds per
 * step. Each record is looked up at İYS (POST /consents/status):
 *
 * - same status → match;
 * - absent → the stored state is queued again (once per pass) and the environment is no longer counted as
 *   "known at İYS";
 * - different and newer at İYS (changed through İYS's own channels) → applied here as actor "iys", never
 *   overwritten; different and older at İYS → ours is queued again; no date at İYS → counted as a conflict and
 *   left alone;
 * - a transport error or 5xx → counted as an error, nothing re-queued (asked again on the next pass);
 * - 429 → the step stops before that row (cursor kept) and the scheduler waits an hour.
 *
 * Consents with a row still queued or waiting for İYS's answer are skipped: they are on their way. A pass that
 * finished tonight is not repeated tonight. The state of the last pass is kept in a per-environment option and
 * shown on the Kuyruk tab. A dry run asks İYS the same questions and writes nothing.
 */
class Eru_CMI_Reconcile {

	const BUDGET      = 500;
	const TIME_BUDGET = 20;

	/** @return string State option of the selected environment. */
	public static function state_option() {
		return 'eru_cmi_reconcile_' . Eru_CMI_Settings::env();
	}

	/** @return array|null State of the last pass in the selected environment. */
	public static function state() {
		$s = get_option( self::state_option(), null );
		return is_array( $s ) ? $s : null;
	}

	/** @return array Fresh pass state. */
	private static function fresh() {
		return array(
			'night'    => wp_date( 'Y-m-d' ),
			'env'      => Eru_CMI_Settings::env(),
			'cursor'   => 0,
			'done'     => false,
			'started'  => time(),
			'finished' => 0,
			'checked'  => 0,
			'match'    => 0,
			'requeued' => 0,
			'held'     => 0,
			'applied'  => 0,
			'conflict' => 0,
			'errors'   => 0,
			'skipped'  => 0,
			'limited'  => false,
		);
	}

	/**
	 * Runs one budgeted step of tonight's pass.
	 *
	 * @param bool $dry   Ask İYS, write nothing (no queue rows, no local changes, no state).
	 * @param int  $limit Rows per step (1–500).
	 * @return array checked, match, requeued, held (queued again but held back by the rules), applied, conflict,
	 *               errors, skipped, limited, done, already (tonight's pass was already finished), locked, error.
	 */
	public static function step( $dry = false, $limit = self::BUDGET ) {
		$stats = array_merge( self::fresh(), array( 'already' => false, 'locked' => false, 'error' => '' ) );
		if ( ! Eru_CMI_Settings::enabled() ) {
			$stats['error'] = __( 'İYS bağlantısı kapalı.', 'consent-manager-for-iys' );
			return $stats;
		}
		$state = $dry ? null : self::state();
		if ( $state && ! empty( $state['done'] ) && wp_date( 'Y-m-d' ) === ( $state['night'] ?? '' ) ) {
			$stats['already'] = true;
			$stats['done']    = true;
			return $stats;
		}
		if ( ! $state || ! empty( $state['done'] ) ) {
			$state = self::fresh();
		}
		$owner = $dry ? '' : Eru_CMI_Lock::acquire( 'reconcile', 10 * MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			$stats['locked'] = true;
			return $stats;
		}
		try {
			$step = self::run( $state, $dry, max( 1, min( self::BUDGET, (int) $limit ) ), (string) $owner );
		} finally {
			if ( ! $dry ) {
				Eru_CMI_Lock::release( 'reconcile', $owner );
			}
		}
		foreach ( array( 'checked', 'match', 'requeued', 'held', 'applied', 'conflict', 'errors', 'skipped' ) as $k ) {
			$state[ $k ] = (int) ( $state[ $k ] ?? 0 ) + $step[ $k ];
		}
		$state['cursor']  = $step['cursor'];
		$state['limited'] = $step['limited'];
		$state['done']    = $step['done'];
		if ( $step['done'] ) {
			$state['finished'] = time();
		}
		if ( ! $dry ) {
			update_option( self::state_option(), $state, false );
		}
		return array_merge( $step, array( 'already' => false, 'locked' => false, 'error' => '' ) );
	}

	/**
	 * A dry run over the whole selected environment (WP-CLI), step by step in memory.
	 *
	 * @return array Totals.
	 */
	public static function dry_run_all() {
		$state = self::fresh();
		$total = $state;
		for ( $i = 0; $i < 100000; $i++ ) {
			$step = self::run( $state, true, self::BUDGET, '' );
			foreach ( array( 'checked', 'match', 'requeued', 'held', 'applied', 'conflict', 'errors', 'skipped' ) as $k ) {
				$total[ $k ] += $step[ $k ];
			}
			$state['cursor'] = $step['cursor'];
			if ( $step['done'] || $step['limited'] ) {
				$total['done']    = $step['done'];
				$total['limited'] = $step['limited'];
				break;
			}
		}
		return $total;
	}

	/**
	 * The step itself.
	 *
	 * @param array  $state State (cursor).
	 * @param bool   $dry   Dry run.
	 * @param int    $limit Rows.
	 * @param string $owner Lock token ('' for a dry run).
	 * @return array Counts of this step plus cursor, limited, done.
	 */
	private static function run( array $state, $dry, $limit, $owner ) {
		global $wpdb;
		$env    = Eru_CMI_Settings::env();
		$out    = array( 'checked' => 0, 'match' => 0, 'requeued' => 0, 'held' => 0, 'applied' => 0, 'conflict' => 0, 'errors' => 0, 'skipped' => 0, 'cursor' => (int) $state['cursor'], 'limited' => false, 'done' => false );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Eru_CMI_Installer::table( 'consents' ) . " WHERE id > %d AND recipient NOT LIKE %s AND iys_known LIKE %s ORDER BY id ASC LIMIT %d", (int) $state['cursor'], '#%', '%' . $wpdb->esc_like( ',' . $env . ',' ) . '%', $limit ) ); // phpcs:ignore
		$client = new Eru_CMI_Client( $env );
		$start  = time();
		$broken = false;
		foreach ( $rows as $row ) {
			if ( time() - $start >= self::TIME_BUDGET ) {
				$broken = true;
				break;
			}
			if ( '' !== $owner ) {
				Eru_CMI_Lock::refresh( 'reconcile', $owner );
			}
			if ( Eru_CMI_Queue::is_open( (int) $row->id ) ) {
				++$out['skipped']; // On its way to İYS.
				$out['cursor'] = (int) $row->id;
				continue;
			}
			$r = $client->consent_status(
				array(
					'recipient'     => $row->recipient,
					'recipientType' => $row->recipient_type,
					'type'          => $row->type,
				)
			);
			if ( 429 === (int) $r['code'] ) {
				$out['limited'] = true;
				$broken         = true;
				break; // The cursor stays before this row.
			}
			++$out['checked'];
			$out['cursor'] = (int) $row->id;
			if ( $r['ok'] && '' !== $r['status'] && $r['status'] === $row->status ) {
				++$out['match'];
				continue;
			}
			$absent = ( $r['ok'] && '' === $r['status'] ) || 404 === (int) $r['code'];
			if ( ! $absent && ! $r['ok'] ) {
				++$out['errors']; // Not asked successfully: not "missing".
				continue;
			}
			if ( $absent ) {
				self::requeue( $row, true, $dry, $out );
				continue;
			}
			// Different status at İYS: the newer side wins.
			$data   = is_array( $r['data'] ) ? $r['data'] : array();
			$iys_ts = Eru_CMI_Rules::parse_date( (string) ( $data['consentDate'] ?? ( $data['creationDate'] ?? '' ) ) );
			$our_ts = (int) strtotime( $row->consent_date . ' UTC' );
			if ( null === $iys_ts ) {
				++$out['conflict'];
			} elseif ( $iys_ts >= $our_ts ) {
				if ( ! $dry ) {
					Eru_CMI_Pull::apply(
						array(
							'recipient'     => $row->recipient,
							'type'          => $row->type,
							'status'        => $r['status'],
							'recipientType' => (string) ( $data['recipientType'] ?? '' ),
							'consentDate'   => (string) ( $data['consentDate'] ?? ( $data['creationDate'] ?? '' ) ),
						)
					);
				}
				++$out['applied'];
			} else {
				self::requeue( $row, false, $dry, $out );
			}
		}
		$out['done'] = ! $broken && count( $rows ) < $limit;
		if ( ! $broken && count( $rows ) === $limit ) {
			// A full page: there may be more; the next step continues from the cursor.
			$out['done'] = ! (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Eru_CMI_Installer::table( 'consents' ) . ' WHERE id > %d AND recipient NOT LIKE %s AND iys_known LIKE %s LIMIT 1', $out['cursor'], '#%', '%' . $wpdb->esc_like( ',' . $env . ',' ) . '%' ) ); // phpcs:ignore
		}
		return $out;
	}

	/**
	 * Queues the stored state again under the recipient's lock.
	 *
	 * @param object $row    Consent row.
	 * @param bool   $absent İYS has no record (drop "known at İYS" for this environment).
	 * @param bool   $dry    Dry run.
	 * @param array  $out    Counters.
	 */
	private static function requeue( $row, $absent, $dry, array &$out ) {
		if ( $dry ) {
			++$out['requeued'];
			return;
		}
		$lock = Eru_CMI_Repository::lock( $row->recipient_hash, $row->type );
		if ( ! $lock ) {
			++$out['errors'];
			return;
		}
		try {
			$fresh = Eru_CMI_Repository::by_id( (int) $row->id );
			if ( ! $fresh || $fresh->status !== $row->status || Eru_CMI_Queue::is_open( (int) $row->id ) ) {
				++$out['skipped']; // Changed meanwhile: that change is queued on its own.
				return;
			}
			if ( $absent ) {
				Eru_CMI_Queue::forget_known( (int) $row->id );
				$fresh = Eru_CMI_Repository::by_id( (int) $row->id );
			}
			$q = Eru_CMI_Queue::requeue_consent( $fresh );
			++$out[ 'queued' === $q['status'] ? 'requeued' : 'held' ];
		} finally {
			Eru_CMI_Repository::unlock( $row->recipient_hash, $row->type );
		}
	}
}
