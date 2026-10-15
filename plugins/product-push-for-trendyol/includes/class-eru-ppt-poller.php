<?php
/**
 * Batch result polling (own Action Scheduler job every 10 minutes) and approval refresh.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each run takes a lock, asks Trendyol for at most PER_RUN open batches (least recently polled first) and writes every
 * item result back to its own simple product or variation by barcode (content updates by contentId).
 * A failed item marks only that object. Batches that never finish are given up after GIVE_UP_AGE; their
 * items become "unknown" and are looked up before anything is created again. Afterwards up to
 * REFRESH_PER_RUN items waiting for approval are checked against the approved listing.
 */
class Eru_PPT_Poller {

	const PER_RUN         = 20;
	const REFRESH_PER_RUN = 20;
	const REFRESH_EVERY   = HOUR_IN_SECONDS;
	const GIVE_UP_AGE     = 3 * DAY_IN_SECONDS;
	const MAX_TRIES       = 50;
	const NOTICE          = 'eru_ppt_notice';

	/** Scheduled run. */
	public static function run() {
		if ( ! Eru_PPT_Settings::is_enabled() ) {
			return;
		}
		if ( ! Eru_PPT_Lock::acquire( 'poll', 10 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		try {
			$sum = self::poll_batches();
			self::refresh_approvals();
			if ( $sum['ok'] || $sum['failed'] ) {
				self::notice( $sum );
			}
		} finally {
			Eru_PPT_Lock::release( 'poll' );
		}
	}

	/** @return array{ok:int,failed:int,batches:int,products:int[]} */
	private static function poll_batches() {
		$sum = array(
			'ok'       => 0,
			'failed'   => 0,
			'batches'  => 0,
			'products' => array(),
		);
		$all = Eru_PPT_Pusher::batches();
		if ( ! $all ) {
			return $sum;
		}
		// Least recently polled first, so a stuck batch cannot starve newer ones.
		uasort( $all, fn( $a, $b ) => array( (int) ( $a['p'] ?? 0 ), (int) ( $a['t'] ?? 0 ) ) <=> array( (int) ( $b['p'] ?? 0 ), (int) ( $b['t'] ?? 0 ) ) );
		$done = 0;
		foreach ( $all as $id => $b ) {
			if ( $done++ >= self::PER_RUN ) {
				break;
			}
			$id  = (string) $id;
			$age = time() - (int) ( $b['t'] ?? 0 );
			$r   = Eru_PPT_Client::call( 'batch', null, array( 'id' => $id ) );
			if ( ! $r['ok'] ) {
				$b['tries'] = (int) ( $b['tries'] ?? 0 ) + 1;
				$b['p']     = time();
				if ( $b['tries'] >= self::MAX_TRIES || $age > self::GIVE_UP_AGE ) {
					self::give_up( $id, $b, $sum );
					Eru_PPT_Pusher::batch_delete( $id );
				} else {
					Eru_PPT_Pusher::batch_put( $id, $b ); // Only this batch's own row is written.
				}
				continue;
			}
			$status = strtoupper( (string) ( $r['data']['status'] ?? '' ) );
			if ( ! in_array( $status, array( 'COMPLETED', 'FAILED' ), true ) ) {
				if ( $age > self::GIVE_UP_AGE ) {
					self::give_up( $id, $b, $sum );
					Eru_PPT_Pusher::batch_delete( $id );
				} else {
					$b['p'] = time();
					Eru_PPT_Pusher::batch_put( $id, $b );
				}
				continue;
			}
			$results = array();
			foreach ( (array) ( $r['data']['items'] ?? array() ) as $it ) {
				if ( ! is_array( $it ) ) {
					continue;
				}
				$req = is_array( $it['requestItem'] ?? null ) ? $it['requestItem'] : array();
				$bc  = (string) ( $req['barcode'] ?? $req['product']['barcode'] ?? '' );
				$key = '' !== $bc ? $bc : ( isset( $req['contentId'] ) ? 'c:' . absint( $req['contentId'] ) : '' );
				if ( '' === $key ) {
					continue;
				}
				$results[ $key ] = array(
					'ok'      => 'SUCCESS' === strtoupper( (string) ( $it['status'] ?? '' ) ),
					'reasons' => self::reasons( $it['failureReasons'] ?? array() ),
				);
			}
			foreach ( (array) ( $b['items'] ?? array() ) as $key => $oids ) {
				$res = $results[ (string) $key ] ?? null;
				foreach ( (array) $oids as $oid ) {
					self::close( (int) $oid, $id, (string) ( $b['op'] ?? '' ), $res, $sum );
				}
			}
			++$sum['batches'];
			Eru_PPT_Pusher::batch_delete( $id );
		}
		return $sum;
	}

	/** @param mixed $reasons failureReasons (strings or {message}). @return string[] */
	private static function reasons( $reasons ) {
		$out = array();
		foreach ( (array) $reasons as $f ) {
			$m = is_array( $f ) ? (string) ( $f['message'] ?? $f['reason'] ?? '' ) : (string) $f;
			$m = trim( wp_strip_all_tags( $m ) );
			if ( '' !== $m ) {
				$out[] = mb_substr( $m, 0, 500 );
			}
		}
		return $out ? $out : array( __( 'Trendyol öğeyi reddetti (gerekçe verilmedi).', 'product-push-for-trendyol' ) );
	}

	/**
	 * Writes one item result to its object.
	 *
	 * @param int        $oid   Object.
	 * @param string     $batch Batch.
	 * @param string     $op    create|content|variant|unapproved.
	 * @param array|null $res   ['ok','reasons'] or null when the item is missing from the result.
	 * @param array      $sum   Summary.
	 */
	private static function close( $oid, $batch, $op, $res, array &$sum ) {
		if ( ! wc_get_product( $oid ) ) {
			return;
		}
		$open   = array_values( array_diff( Eru_PPT_Pusher::arr( $oid, 'open' ), array( $batch ) ) );
		$status = (string) Eru_PPT_Pusher::get( $oid, 'status' );
		$errors = Eru_PPT_Pusher::arr( $oid, 'errors' );
		$set    = array( 'open' => $open );
		$pid    = wp_get_post_parent_id( $oid );
		$pid    = $pid ? $pid : $oid;

		if ( null === $res ) {
			$errors[] = __( 'Öğe batch sonucunda yok; bir sonraki gönderimde Trendyol\'da aranacak.', 'product-push-for-trendyol' );
			$set     += array(
				'status' => 'failed',
				'errors' => array_values( array_unique( $errors ) ),
			);
			if ( 'create' === $op ) {
				$set['remote'] = 'unknown';
			}
			++$sum['failed'];
			$sum['products'][] = $pid;
		} elseif ( $res['ok'] ) {
			if ( 'create' === $op ) {
				$set['remote']     = 'unapproved';
				$set['checked_at'] = 0;
			}
			if ( ! $open && 'failed' !== $status ) {
				$set['status'] = 'success';
				$set['errors'] = array();
			}
			++$sum['ok'];
		} else {
			$set['status'] = 'failed';
			$set['errors'] = array_values( array_unique( array_merge( 'pending' === $status ? array() : $errors, $res['reasons'] ) ) );
			if ( 'create' === $op ) {
				$set['remote'] = ''; // Trendyol refused it: nothing was created, a corrected push may create it.
			}
			++$sum['failed'];
			$sum['products'][] = $pid;
		}
		Eru_PPT_Pusher::set( $oid, $set );
	}

	/** @param string $id Batch. @param array $b Batch row. @param array $sum Summary. */
	private static function give_up( $id, array $b, array &$sum ) {
		foreach ( (array) ( $b['items'] ?? array() ) as $oids ) {
			foreach ( (array) $oids as $oid ) {
				self::close( (int) $oid, $id, (string) ( $b['op'] ?? '' ), null, $sum );
			}
		}
	}

	/** Items waiting for approval, checked at most once an hour each. */
	public static function refresh_approvals() {
		$ids = get_posts(
			array(
				'post_type'   => array( 'product', 'product_variation' ),
				'post_status' => 'any',
				'numberposts' => self::REFRESH_PER_RUN,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_eru_ppt_remote',
						'value' => 'unapproved',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_eru_ppt_checked_at',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_eru_ppt_checked_at',
							'value'   => time() - self::REFRESH_EVERY,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);
		foreach ( $ids as $oid ) {
			$o = wc_get_product( $oid );
			if ( ! $o ) {
				continue;
			}
			$bc = Eru_PPT_Builder::barcode( $o );
			if ( '' === $bc ) {
				continue;
			}
			$f   = Eru_PPT_Pusher::find( $bc, 'approved' );
			$set = array( 'checked_at' => time() );
			if ( $f['ok'] && $f['found'] ) {
				$set['remote'] = 'approved';
				if ( $f['content_id'] ) {
					$set['content_id'] = $f['content_id'];
				}
			}
			Eru_PPT_Pusher::set( $oid, $set );
		}
	}

	/** @param array $sum Summary merged into the unseen admin notice. */
	private static function notice( array $sum ) {
		$n = get_option( self::NOTICE, array() );
		$n = is_array( $n ) ? $n : array();
		$n = array(
			't'        => time(),
			'ok'       => (int) ( $n['ok'] ?? 0 ) + $sum['ok'],
			'failed'   => (int) ( $n['failed'] ?? 0 ) + $sum['failed'],
			'batches'  => (int) ( $n['batches'] ?? 0 ) + $sum['batches'],
			'products' => array_slice( array_values( array_unique( array_merge( (array) ( $n['products'] ?? array() ), $sum['products'] ) ) ), 0, 20 ),
		);
		if ( false === get_option( self::NOTICE, false ) ) {
			add_option( self::NOTICE, $n, '', false );
		} else {
			update_option( self::NOTICE, $n, false );
		}
	}
}
