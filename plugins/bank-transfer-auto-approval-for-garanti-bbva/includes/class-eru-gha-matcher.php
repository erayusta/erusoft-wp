<?php
/**
 * Pure matching: transaction normalisation, reference extraction, amount tolerance, ambiguity detection.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No database, no HTTP. The approver feeds it order snapshots and normalised transactions and acts on
 * the result. An order is approved only when exactly one unused credit (or, with split payments on, the
 * sum of two or more unused credits from one sender) fits its total and no other order claims the same
 * transaction. Credits with an amount of zero or less never count.
 */
class Eru_GHA_Matcher {

	/** An order may be created at most this long after the transfer. */
	const ORDER_AFTER_TXN = HOUR_IN_SECONDS;

	/**
	 * Normalises a bank transaction of the Account Transactions shape.
	 *
	 * @param array $raw Raw item.
	 * @return array{id:string, credit:bool, amount:float, currency:string, text:string, time:int, payer:string, payer_iban:string, date:string}
	 */
	public static function normalize( array $raw ) {
		$text  = is_scalar( $raw['explanation'] ?? null ) ? (string) $raw['explanation'] : '';
		$payer = '';
		$iban  = '';
		foreach ( (array) ( $raw['enrichmentInformation'] ?? array() ) as $e ) {
			if ( ! is_array( $e ) || 'EFT' !== ( $e['enrichmentCode'] ?? '' ) || ! is_array( $e['enrichmentValue'] ?? null ) ) {
				continue;
			}
			$v = $e['enrichmentValue'];
			if ( is_scalar( $v['remark'] ?? null ) && '' !== (string) $v['remark'] ) {
				$text .= ' ' . $v['remark'];
			}
			if ( is_scalar( $v['senderName'] ?? null ) && '' === $payer ) {
				$payer = (string) $v['senderName'];
			}
			if ( is_scalar( $v['senderIBANEFT'] ?? null ) && '' === $iban ) {
				$iban = (string) $v['senderIBANEFT'];
			}
		}
		$id  = is_scalar( $raw['transactionInstanceId'] ?? null ) ? trim( (string) $raw['transactionInstanceId'] ) : '';
		$cur = '';
		foreach ( array( 'currencyCode', 'currency', 'txnCurrency' ) as $k ) {
			if ( is_scalar( $raw[ $k ] ?? null ) && '' !== (string) $raw[ $k ] ) {
				$cur = strtoupper( (string) $raw[ $k ] );
				break;
			}
		}
		$cur  = in_array( $cur, array( 'TL', 'YTL', '949' ), true ) ? 'TRY' : $cur;
		$time = 0;
		$tz   = new DateTimeZone( Eru_GHA_Client::TIMEZONE );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $id, $m ) ) {
			$d    = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $m[1] . ' ' . $m[2], $tz );
			$time = $d ? $d->getTimestamp() : 0;
		}
		if ( ! $time ) {
			foreach ( array( 'activityDate', 'valueDate' ) as $k ) {
				if ( is_scalar( $raw[ $k ] ?? null ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $raw[ $k ], $m ) ) {
					// Date only: the end of that day, so the 1-hour order rule stays lenient.
					$d    = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $m[0] . ' 23:59:59', $tz );
					$time = $d ? $d->getTimestamp() : 0;
					break;
				}
			}
		}
		return array(
			'id'         => $id,
			'credit'     => 'A' === ( $raw['txnCreditDebitIndicator'] ?? '' ),
			// Signed as sent: a credit with amount <= 0 (reversal, correction) never matches.
			'amount'     => self::parse_amount( $raw['amount'] ?? 0 ),
			'currency'   => $cur,
			'text'       => trim( preg_replace( '/\s+/u', ' ', $text ) ),
			'time'       => $time,
			'payer'      => trim( $payer ),
			'payer_iban' => '' !== $iban ? Eru_GHA_Log::mask_iban( $iban ) : '',
			'sender'     => self::sender_key( $iban, $payer ),
			'date'       => $time ? wp_date( 'd.m.Y H:i', $time ) : '',
		);
	}

	/**
	 * Same-sender key for split payments: a keyed hash (HMAC with the site's auth salt) of the sender IBAN,
	 * else of the folded sender name; '' when unknown. Not reversible without the salt.
	 *
	 * @param string $iban Sender IBAN.
	 * @param string $name Sender name.
	 * @return string Hash.
	 */
	public static function sender_key( $iban, $name ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
		if ( '' !== $iban ) {
			return 'i:' . hash_hmac( 'sha256', $iban, wp_salt( 'auth' ) );
		}
		$name = preg_replace( '/\s+/u', ' ', trim( Eru_GHA_Log::fold( (string) $name ) ) );
		return '' !== $name ? 'n:' . hash_hmac( 'sha256', $name, wp_salt( 'auth' ) ) : '';
	}

	/**
	 * @param mixed $v Number or string ("1.000,00", "999.995").
	 * @return float
	 */
	public static function parse_amount( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v;
		}
		$s = preg_replace( '/[^\d,.\-]/', '', (string) $v );
		if ( false !== strpos( $s, ',' ) && false !== strpos( $s, '.' ) ) {
			$s = strrpos( $s, ',' ) > strrpos( $s, '.' ) ? str_replace( array( '.', ',' ), array( '', '.' ), $s ) : str_replace( ',', '', $s );
		} elseif ( false !== strpos( $s, ',' ) ) {
			$s = str_replace( ',', '.', $s );
		}
		return (float) $s;
	}

	/**
	 * @param float $total  Order total.
	 * @param float $amount Credit amount.
	 * @param float $tol    Tolerance.
	 * @return bool
	 */
	public static function amount_ok( $total, $amount, $tol ) {
		return abs( (float) $total - (float) $amount ) <= max( 0.0, (float) $tol ) + 0.000001;
	}

	/**
	 * Compiles the reference pattern (or, when set, the prefix template) into a PCRE.
	 *
	 * @param string $pattern  Regex body with at least one capture group (group 1 is the reference).
	 * @param string $template Optional template such as "SIP-{number}"; when set, only it counts.
	 * @return array{regex:string, error:string}
	 */
	public static function compile( $pattern, $template = '' ) {
		$template = trim( (string) $template );
		if ( '' !== $template ) {
			if ( 1 !== substr_count( $template, '{number}' ) ) {
				return array(
					'regex' => '',
					'error' => __( 'Şablon tam bir kez {number} içermeli.', 'bank-transfer-auto-approval-for-garanti-bbva' ),
				);
			}
			list( $before, $after ) = explode( '{number}', $template );
			$part                   = function ( $s ) {
				$out = '';
				foreach ( preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
					$out .= preg_match( '/[\p{L}\p{N}]/u', $ch ) ? preg_quote( $ch, '~' ) : '[\s\-_.\/#:]*';
				}
				return $out;
			};
			$lead    = preg_match( '/^[\p{L}\p{N}]/u', $before ) ? '(?<![\p{L}\p{N}])' : '';
			$trail   = preg_match( '/[\p{L}\p{N}]$/u', $after ) ? '(?![\p{L}\p{N}])' : '';
			$pattern = $lead . $part( $before ) . '(?<!\d)(\d{1,20})(?!\d)' . $part( $after ) . $trail;
			$regex   = '~' . $pattern . '~iu';
		} else {
			$pattern = (string) $pattern;
			if ( '' === trim( $pattern ) ) {
				return array(
					'regex' => '',
					'error' => __( 'Desen boş.', 'bank-transfer-auto-approval-for-garanti-bbva' ),
				);
			}
			$regex = '~' . preg_replace( '/(?<!\\\\)~/', '\\~', $pattern ) . '~u';
		}
		$probe = '~(?:' . substr( $regex, 1, strrpos( $regex, '~' ) - 1 ) . ')|~' . substr( $regex, strrpos( $regex, '~' ) + 1 );
		$m     = array();
		$ok    = @preg_match( $probe, '', $m, PREG_UNMATCHED_AS_NULL ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an invalid admin pattern is reported, not raised.
		if ( false === $ok ) {
			$e = error_get_last();
			return array(
				'regex' => '',
				'error' => __( 'Geçersiz düzenli ifade', 'bank-transfer-auto-approval-for-garanti-bbva' ) . ( $e ? ': ' . preg_replace( '/^preg_match\(\):\s*/', '', (string) $e['message'] ) : '' ),
			);
		}
		$groups = count( array_filter( array_keys( $m ), 'is_int' ) ) - 1;
		if ( $groups < 1 ) {
			return array(
				'regex' => '',
				'error' => __( 'Desende bir yakalama grubu (parantez) olmalı; 1. grup sipariş numarasıdır.', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			);
		}
		return array(
			'regex' => $regex,
			'error' => '',
		);
	}

	/**
	 * @param string $text     Description text.
	 * @param array  $compiled Result of compile().
	 * @return string[] Unique references (capture group 1).
	 */
	public static function references( $text, array $compiled ) {
		if ( '' !== $compiled['error'] || '' === $compiled['regex'] ) {
			return array();
		}
		$m = array();
		if ( ! @preg_match_all( $compiled['regex'], (string) $text, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $m[1] ?? array() ), 'strlen' ) ) );
	}

	/**
	 * @param array  $orders   Snapshots: id, number, total, currency, created (unix).
	 * @param array  $txns     Normalised transactions.
	 * @param array  $used_ids Transaction ids already in the ledger.
	 * @param array  $opts     regex (compiled), tolerance, split (bool), currency (account currency).
	 * @return array{approve:array<int,string[]>, review:array<int,array{reason:string,txns:string[]}>, mismatch:array<int,string[]>}
	 */
	public static function match( array $orders, array $txns, array $used_ids, array $opts ) {
		$tol     = (float) ( $opts['tolerance'] ?? 0 );
		$split   = ! empty( $opts['split'] );
		$acct    = strtoupper( (string) ( $opts['currency'] ?? 'TRY' ) );
		$used    = array_flip( array_map( 'strval', $used_ids ) );
		$by_ref  = array();
		$credits = array();
		foreach ( $txns as $t ) {
			if ( empty( $t['credit'] ) || '' === $t['id'] || isset( $used[ $t['id'] ] ) || isset( $credits[ $t['id'] ] ) ) {
				continue;
			}
			if ( '' !== $t['currency'] && $t['currency'] !== $acct ) {
				continue;
			}
			if ( $t['amount'] <= 0 || ! $t['time'] ) {
				continue;
			}
			$credits[ $t['id'] ] = $t;
			foreach ( self::references( $t['text'], $opts['regex'] ) as $ref ) {
				$by_ref[ $ref ][] = $t['id'];
			}
		}

		$fits   = array();
		$mixed  = array();
		$cands  = array();
		$claims = array();
		$prop   = array();
		foreach ( $orders as $o ) {
			$oid = (int) $o['id'];
			$num = ltrim( (string) $o['number'], '#' );
			if ( strtoupper( (string) $o['currency'] ) !== $acct || ! isset( $by_ref[ $num ] ) ) {
				continue;
			}
			$c = array();
			foreach ( array_unique( $by_ref[ $num ] ) as $id ) {
				if ( (int) $o['created'] <= $credits[ $id ]['time'] + self::ORDER_AFTER_TXN ) {
					$c[] = $id;
				}
			}
			if ( ! $c ) {
				continue;
			}
			$cands[ $oid ] = $c;
			$fits[ $oid ]  = array_values( array_filter( $c, fn( $id ) => self::amount_ok( $o['total'], $credits[ $id ]['amount'], $tol ) ) );
			if ( 1 === count( $fits[ $oid ] ) ) {
				$prop[ $oid ] = $fits[ $oid ];
			} elseif ( ! $fits[ $oid ] && $split && count( $c ) >= 2 ) {
				// Only credits from one sender may be summed; an unknown sender is never grouped.
				$groups = array();
				foreach ( $c as $id ) {
					$groups[ '' !== $credits[ $id ]['sender'] ? $credits[ $id ]['sender'] : 'u:' . $id ][] = $id;
				}
				$sum_of = fn( $ids ) => array_sum( array_map( fn( $id ) => $credits[ $id ]['amount'], $ids ) );
				$fit    = array_values( array_filter( $groups, fn( $g ) => count( $g ) >= 2 && self::amount_ok( $o['total'], $sum_of( $g ), $tol ) ) );
				if ( 1 === count( $fit ) ) {
					$prop[ $oid ] = $fit[0];
				} elseif ( count( $fit ) > 1 ) {
					$mixed[ $oid ] = 'multiple_txns';
				} elseif ( self::amount_ok( $o['total'], $sum_of( $c ), $tol ) ) {
					$mixed[ $oid ] = 'mixed_senders';
				}
			}
			foreach ( $prop[ $oid ] ?? ( isset( $mixed[ $oid ] ) ? $c : $fits[ $oid ] ) as $id ) {
				$claims[ $id ][ $oid ] = true;
			}
		}

		$out = array(
			'approve'  => array(),
			'review'   => array(),
			'mismatch' => array(),
		);
		foreach ( $cands as $oid => $c ) {
			if ( isset( $mixed[ $oid ] ) ) {
				$out['review'][ $oid ] = array(
					'reason' => $mixed[ $oid ],
					'txns'   => $c,
				);
				continue;
			}
			if ( count( $fits[ $oid ] ) >= 2 ) {
				$out['review'][ $oid ] = array(
					'reason' => 'multiple_txns',
					'txns'   => $fits[ $oid ],
				);
				continue;
			}
			if ( ! isset( $prop[ $oid ] ) ) {
				$out['mismatch'][ $oid ] = $c;
				continue;
			}
			$shared = array_filter( $prop[ $oid ], fn( $id ) => count( $claims[ $id ] ) > 1 );
			if ( $shared ) {
				$out['review'][ $oid ] = array(
					'reason' => 'ambiguous_txn',
					'txns'   => $prop[ $oid ],
				);
				continue;
			}
			$out['approve'][ $oid ] = $prop[ $oid ];
		}
		return $out;
	}
}
