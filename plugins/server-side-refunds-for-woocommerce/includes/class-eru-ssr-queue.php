<?php
/**
 * Outbound queue: table, insert (de-duplicated), processing, backoff, purge, scheduling.
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Row state is derived, with no status column:
 * sent = sent_at set; failed = no sent_at and no next_attempt_at; pending = otherwise.
 */
class Eru_SSR_Queue {

	const DB_VERSION   = '1';
	const MAX_ATTEMPTS = 10;
	const BATCH        = 20;
	const GROUP        = 'eru-ssr';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'eru_ssr_queue';
	}

	public static function init() {
		self::maybe_install();
		add_action( 'eru_ssr_process', array( __CLASS__, 'run' ) );
		add_action( 'eru_ssr_sweep', array( __CLASS__, 'sweep' ) );
		add_action( 'eru_ssr_purge', array( __CLASS__, 'purge_job' ) );
		if ( get_option( 'eru_ssr_resume' ) ) {
			add_action( 'action_scheduler_init', array( __CLASS__, 'resume' ) );
		}
	}

	/** After a re-activation: the deactivation dropped the jobs, so queue them again when rows remain. */
	public static function resume() {
		delete_option( 'eru_ssr_resume' );
		if ( self::total_count() > 0 ) {
			self::schedule();
		}
	}

	/** Creates or migrates the table when the stored version differs (not only on activation). */
	public static function maybe_install() {
		if ( get_option( 'eru_ssr_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				destination varchar(20) NOT NULL,
				dedupe_key varchar(191) NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payload longtext NOT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_attempt_at datetime DEFAULT NULL,
				sent_at datetime DEFAULT NULL,
				last_error text DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY order_id (order_id),
				KEY due (sent_at, next_attempt_at)
			) {$charset};"
		);
		update_option( 'eru_ssr_db_version', self::DB_VERSION, true );
	}

	/** Seconds to wait after the given number of failed attempts. */
	public static function backoff( $attempts ) {
		return (int) min( 6 * HOUR_IN_SECONDS, 60 * ( 2 ** max( 0, (int) $attempts ) ) );
	}

	public static function status_of( $row ) {
		if ( ! empty( $row->sent_at ) ) {
			return 'sent';
		}
		return null === $row->next_attempt_at ? 'failed' : 'pending';
	}

	/**
	 * Inserts a row; a repeated dedupe key is silently ignored. Returns true when a new row was written.
	 */
	public static function insert( $destination, $dedupe_key, $order_id, array $payload ) {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (destination, dedupe_key, order_id, payload, attempts, next_attempt_at, created_at) VALUES (%s, %s, %d, %s, 0, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL
				$destination,
				$dedupe_key,
				(int) $order_id,
				wp_json_encode( $payload ),
				$now,
				$now
			)
		);
		if ( $done ) {
			self::schedule();
		}
		return (bool) $done;
	}

	/**
	 * Single action now, a sweep and a daily purge. Sweep and purge are chained single actions that
	 * re-arm themselves only while rows remain, so nothing stays scheduled once the queue is empty.
	 * Pending-only checks: a run that is already in progress may have selected its rows before this one was queued.
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( ! self::has_pending( 'eru_ssr_process' ) ) {
			as_schedule_single_action( time(), 'eru_ssr_process', array(), self::GROUP );
		}
		if ( ! self::has_pending( 'eru_ssr_sweep' ) ) {
			as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, 'eru_ssr_sweep', array(), self::GROUP );
		}
		if ( ! self::has_pending( 'eru_ssr_purge' ) ) {
			as_schedule_single_action( time() + DAY_IN_SECONDS, 'eru_ssr_purge', array(), self::GROUP );
		}
	}

	public static function total_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore
	}

	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE sent_at IS NULL AND next_attempt_at IS NOT NULL' ); // phpcs:ignore
	}

	/** Sweep: process, then chain the next sweep only while unsent rows remain. */
	public static function sweep() {
		self::run();
		if ( self::pending_count() > 0 && function_exists( 'as_schedule_single_action' ) && ! self::has_pending( 'eru_ssr_sweep' ) ) {
			as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, 'eru_ssr_sweep', array(), self::GROUP );
		}
	}

	/**
	 * True when a PENDING action exists for the hook. as_has_scheduled_action() also
	 * counts the running action, so a job re-arming itself must not use it.
	 */
	private static function has_pending( $hook ) {
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/** Daily purge job: purge, then chain the next one only while the table still has rows. */
	public static function purge_job() {
		self::purge();
		if ( self::total_count() > 0 && function_exists( 'as_schedule_single_action' ) && ! self::has_pending( 'eru_ssr_purge' ) ) {
			as_schedule_single_action( time() + DAY_IN_SECONDS, 'eru_ssr_purge', array(), self::GROUP );
		}
	}

	/** True when a row with this dedupe key exists. */
	public static function exists( $dedupe_key ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE dedupe_key = %s', $dedupe_key ) ); // phpcs:ignore
	}

	/**
	 * Sends up to BATCH due rows. Returns counts.
	 */
	public static function run( $limit = self::BATCH ) {
		global $wpdb;
		$stats = array( 'sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0 );
		// Atomic, owner-checked lock (INSERT IGNORE): a manual/CLI run overlapping a scheduled one cannot double-send.
		$owner = Eru_SSR_Lock::acquire( 'run', 10 * MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			return $stats;
		}
		try {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . ' WHERE sent_at IS NULL AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
					gmdate( 'Y-m-d H:i:s' ),
					max( 1, (int) $limit )
				)
			);
			foreach ( $rows as $row ) {
				Eru_SSR_Lock::refresh( 'run', $owner ); // Keep the lock fresh during a long batch.
				$result   = self::send( $row );
				$attempts = (int) $row->attempts + 1;
				if ( $result['ok'] ) {
					$wpdb->update( self::table(), array( 'sent_at' => gmdate( 'Y-m-d H:i:s' ), 'attempts' => $attempts, 'next_attempt_at' => null, 'last_error' => null ), array( 'id' => $row->id ) ); // phpcs:ignore
					++$stats['sent'];
				} elseif ( $result['permanent'] || $attempts >= self::MAX_ATTEMPTS ) {
					$wpdb->update( self::table(), array( 'attempts' => $attempts, 'next_attempt_at' => null, 'last_error' => $result['error'] ), array( 'id' => $row->id ) ); // phpcs:ignore
					++$stats['failed'];
				} else {
					$wpdb->update( self::table(), array( 'attempts' => $attempts, 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::backoff( $attempts ) ), 'last_error' => $result['error'] ), array( 'id' => $row->id ) ); // phpcs:ignore
					++$stats['retry'];
				}
			}
		} finally {
			Eru_SSR_Lock::release( 'run', $owner );
		}
		return $stats;
	}

	/** Dispatches a row to its destination. Returns {ok, permanent, error}. */
	private static function send( $row ) {
		$payload = json_decode( $row->payload, true );
		$payload = is_array( $payload ) ? $payload : array();
		if ( 'ga4' === $row->destination ) {
			return Eru_SSR_GA4::send( $payload );
		}
		if ( 'matomo' === $row->destination ) {
			return Eru_SSR_Matomo::send( $payload );
		}
		return array( 'ok' => false, 'permanent' => true, 'error' => 'unknown destination' );
	}

	/** Classifies an HTTP outcome. 2xx ok; WP_Error, 429 and 5xx retry; other statuses are permanent. */
	public static function classify( $response ) {
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'permanent' => false, 'error' => self::redact( $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'permanent' => false, 'error' => '' );
		}
		$error = 'HTTP ' . $code;
		$body  = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) );
		if ( '' !== $body ) {
			$error .= ': ' . mb_substr( $body, 0, 200 );
		}
		return array( 'ok' => false, 'permanent' => ! ( 429 === $code || $code >= 500 ), 'error' => self::redact( $error ) );
	}

	/** Strips credentials from anything that is logged or stored. */
	public static function redact( $text ) {
		$text = (string) $text;
		foreach ( array( Eru_SSR_Settings::get( 'ga4_api_secret' ), Eru_SSR_Settings::get( 'matomo_token' ) ) as $secret ) {
			if ( '' !== $secret ) {
				$text = str_replace( $secret, '[redacted]', $text );
			}
		}
		return preg_replace( '/(password|secret|token|api_key|token_auth|Authorization)(=|":\s*"|:\s*)[^&\s"]+/i', '$1$2[redacted]', $text );
	}

	/** Deletes sent rows, and failed rows (which still hold client/user ids), older than the retention period. */
	public static function purge() {
		global $wpdb;
		$days = max( 1, (int) Eru_SSR_Settings::get( 'retention_days' ) );
		$cut = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE (sent_at IS NOT NULL AND sent_at < %s) OR (sent_at IS NULL AND next_attempt_at IS NULL AND created_at < %s)', $cut, $cut ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/** Makes failed (or all failed, with null) rows pending again. */
	public static function retry( $id = null ) {
		global $wpdb;
		$where = 'sent_at IS NULL AND next_attempt_at IS NULL';
		$sql   = 'UPDATE ' . self::table() . ' SET attempts = 0, next_attempt_at = %s WHERE ' . $where; // phpcs:ignore WordPress.DB.PreparedSQL
		$args  = array( gmdate( 'Y-m-d H:i:s' ) );
		if ( $id ) {
			$sql   .= ' AND id = %d';
			$args[] = (int) $id;
		}
		$n = (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore
		if ( $n ) {
			self::schedule();
		}
		return $n;
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	public static function counts() {
		global $wpdb;
		$c = array( 'pending' => 0, 'sent' => 0, 'failed' => 0 );
		foreach ( $wpdb->get_results( 'SELECT sent_at IS NOT NULL AS s, next_attempt_at IS NOT NULL AS n, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY s, n' ) as $r ) { // phpcs:ignore
			$c[ $r->s ? 'sent' : ( $r->n ? 'pending' : 'failed' ) ] += (int) $r->c;
		}
		return $c;
	}

	/** Rows for the admin list. $status: pending|sent|failed|'' */
	public static function rows( $status = '', $limit = 50, $offset = 0, $order_id = 0 ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( 'sent' === $status ) {
			$where[] = 'sent_at IS NOT NULL';
		} elseif ( 'pending' === $status ) {
			$where[] = 'sent_at IS NULL AND next_attempt_at IS NOT NULL';
		} elseif ( 'failed' === $status ) {
			$where[] = 'sent_at IS NULL AND next_attempt_at IS NULL';
		}
		if ( $order_id ) {
			$where[] = 'order_id = %d';
			$args[]  = (int) $order_id;
		}
		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL
		$args[] = (int) $limit;
		$args[] = (int) $offset;
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore
	}
}
