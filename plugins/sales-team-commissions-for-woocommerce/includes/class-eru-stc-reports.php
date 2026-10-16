<?php
/**
 * Reports: totals by agent and by month (months in the site's time zone, rows stored in UTC), order counts
 * per agent through the HPOS order meta table (posts fallback), and the CSV exports.
 *
 * Totals count live rows only (void rows are left out); adjustment rows count in the month they were
 * written, so a refund after a payout lowers the month of the refund.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Reports {

	/** Option holding the last full-IBAN exports (who, when, filter, rows); not autoloaded. */
	const AUDIT_OPTION = 'eru_stc_export_audit';
	const AUDIT_KEEP   = 50;

	/**
	 * Records a full-IBAN payout export.
	 *
	 * @param int   $user_id Admin.
	 * @param array $args    Filters used (status).
	 * @param int   $rows    Payout rows in the file.
	 */
	public static function record_full_export( $user_id, array $args, $rows ) {
		$list = self::export_audit();
		array_unshift(
			$list,
			array(
				'user'   => (int) $user_id,
				'time'   => time(),
				'status' => isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '',
				'rows'   => (int) $rows,
			)
		);
		$list = array_slice( $list, 0, self::AUDIT_KEEP );
		if ( false === get_option( self::AUDIT_OPTION, false ) ) {
			add_option( self::AUDIT_OPTION, $list, '', false );
		} else {
			update_option( self::AUDIT_OPTION, $list, false );
		}
	}

	/** @return array[] Full-IBAN exports, newest first (user, time, status, rows). */
	public static function export_audit() {
		$list = get_option( self::AUDIT_OPTION, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * UTC bounds of a month in the site's time zone.
	 *
	 * @param string $month "YYYY-MM" (default: this month).
	 * @return array{0:string,1:string,2:string} [ from UTC, to UTC, normalised month ].
	 */
	public static function month_range( $month = '' ) {
		$tz = wp_timezone();
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})$/', (string) $month, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 ) {
			$month = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m' );
		}
		$start = new DateTimeImmutable( $month . '-01 00:00:00', $tz );
		$end   = $start->modify( '+1 month' )->modify( '-1 second' );
		$utc   = new DateTimeZone( 'UTC' );
		return array( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $month );
	}

	/**
	 * Totals per agent for a period.
	 *
	 * @param string $from     UTC.
	 * @param string $to       UTC.
	 * @param int    $agent_id Only this agent (0 = all).
	 * @return array[] agent_id, label, code, orders, rows, total, pending, approved, paid (decimal strings).
	 */
	public static function by_agent( $from, $to, $agent_id = 0 ) {
		global $wpdb;
		$t      = Eru_STC_Commissions::t();
		$sql    = "SELECT agent_id, COUNT(DISTINCT order_id) AS orders, COUNT(*) AS n,
				SUM(CASE WHEN status <> 'void' THEN amount ELSE 0 END) AS total,
				SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS pending,
				SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) AS approved,
				SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS paid
			FROM {$t} WHERE earned_at BETWEEN %s AND %s" . ( $agent_id ? ' AND agent_id = %d' : '' ) . ' GROUP BY agent_id ORDER BY total DESC LIMIT 500';
		$params = $agent_id ? array( $from, $to, (int) $agent_id ) : array( $from, $to );
		$out    = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$agent = Eru_STC_Agents::get( (int) $r->agent_id );
			$out[] = array(
				'agent_id' => (int) $r->agent_id,
				'label'    => $agent ? Eru_STC_Agents::label( $agent ) : '#' . (int) $r->agent_id,
				'code'     => $agent ? $agent->referral_code : '',
				'orders'   => (int) $r->orders,
				'rows'     => (int) $r->n,
				'total'    => self::dec( $r->total ),
				'pending'  => self::dec( $r->pending ),
				'approved' => self::dec( $r->approved ),
				'paid'     => self::dec( $r->paid ),
			);
		}
		return $out;
	}

	/**
	 * Totals per month of a year.
	 *
	 * @param int $year     Year.
	 * @param int $agent_id Only this agent (0 = all).
	 * @return array[] month (YYYY-MM), orders, total, pending, approved, paid.
	 */
	public static function by_month( $year, $agent_id = 0 ) {
		$out = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$month              = sprintf( '%04d-%02d', (int) $year, $m );
			list( $from, $to )  = self::month_range( $month );
			$t                  = self::totals( $from, $to, $agent_id );
			$out[]              = array_merge( array( 'month' => $month ), $t );
		}
		return $out;
	}

	/**
	 * Totals of a period.
	 *
	 * @param string $from     UTC.
	 * @param string $to       UTC.
	 * @param int    $agent_id Only this agent (0 = all).
	 * @return array orders, total, pending, approved, paid.
	 */
	public static function totals( $from, $to, $agent_id = 0 ) {
		global $wpdb;
		$t      = Eru_STC_Commissions::t();
		$sql    = "SELECT COUNT(DISTINCT order_id) AS orders,
				SUM(CASE WHEN status <> 'void' THEN amount ELSE 0 END) AS total,
				SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS pending,
				SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) AS approved,
				SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS paid
			FROM {$t} WHERE earned_at BETWEEN %s AND %s" . ( $agent_id ? ' AND agent_id = %d' : '' );
		$params = $agent_id ? array( $from, $to, (int) $agent_id ) : array( $from, $to );
		$r      = $wpdb->get_row( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'orders'   => (int) ( $r->orders ?? 0 ),
			'total'    => self::dec( $r->total ?? 0 ),
			'pending'  => self::dec( $r->pending ?? 0 ),
			'approved' => self::dec( $r->approved ?? 0 ),
			'paid'     => self::dec( $r->paid ?? 0 ),
		);
	}

	/**
	 * All-time sums by status (dashboard tiles): pending, available (approved, unclaimed), requested, paid.
	 *
	 * @return array<string,string>
	 */
	public static function overall() {
		global $wpdb;
		$t   = Eru_STC_Commissions::t();
		$r   = $wpdb->get_row( "SELECT SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS pending, SUM(CASE WHEN status = 'approved' AND payout_id IS NULL THEN amount ELSE 0 END) AS available, SUM(CASE WHEN status = 'approved' AND payout_id IS NOT NULL THEN amount ELSE 0 END) AS requested, SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS paid FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		foreach ( array( 'pending', 'available', 'requested', 'paid' ) as $k ) {
			$out[ $k ] = self::dec( $r->$k ?? 0 );
		}
		return $out;
	}

	/**
	 * Orders stamped with each agent at checkout (_eru_stc_agent_id), one grouped query on the HPOS meta
	 * table (or postmeta when HPOS is off) using the meta key/value index. No order is loaded.
	 *
	 * @param int[] $agent_ids Agents.
	 * @return array<int,int> agent_id => order count.
	 */
	public static function order_counts( array $agent_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $agent_ids ) ) ) );
		$out = array_fill_keys( $ids, 0 );
		if ( ! $ids ) {
			return $out;
		}
		$in = "'" . implode( "','", $ids ) . "'";
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$meta = $wpdb->prefix . 'wc_orders_meta';
			$rows = $wpdb->get_results( "SELECT meta_value AS agent, COUNT(DISTINCT order_id) AS n FROM {$meta} WHERE meta_key = '_eru_stc_agent_id' AND meta_value IN ({$in}) GROUP BY meta_value" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( "SELECT pm.meta_value AS agent, COUNT(DISTINCT pm.post_id) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_order' WHERE pm.meta_key = '_eru_stc_agent_id' AND pm.meta_value IN ({$in}) GROUP BY pm.meta_value" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( $rows as $r ) {
			$out[ (int) $r->agent ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Payout CSV. Masked by default (holder initials, IBAN "TR** … **12 34"); with $full the full IBAN and
	 * holder for the bank transfer. The TCKN is always masked.
	 *
	 * @param array $args Eru_STC_Payouts::list() filters (agent_id, status, ids).
	 * @param bool  $full Full IBAN and holder.
	 * @return string CSV.
	 */
	public static function payouts_csv( array $args, $full = false ) {
		$rows = array();
		// Every matching payout, newest first, in batches of 500 with no cap. Keyset on the id: a payout created
		// while the file is read cannot shift a batch and duplicate a line of the transfer list.
		$before = 0;
		for ( $batch = array( 1 ); $batch; ) {
			$batch = Eru_STC_Payouts::list( array_merge( $args, array( 'limit' => 500, 'before_id' => $before ) ) );
			if ( $batch ) {
				$before = (int) end( $batch )->id;
			}
			foreach ( $batch as $p ) {
				$agent  = Eru_STC_Agents::get( (int) $p->agent_id );
				$d      = Eru_STC_Payout_Details::from_snapshot( $p->details, $full );
				$rows[] = array(
					(int) $p->id,
					$agent ? Eru_STC_Agents::label( $agent ) : '#' . (int) $p->agent_id,
					$agent ? $agent->referral_code : '',
					self::dec( $p->amount ),
					$p->currency,
					Eru_STC_Labels::payout_status( $p->status ),
					get_date_from_gmt( $p->requested_at, 'Y-m-d H:i' ),
					$p->paid_at ? get_date_from_gmt( $p->paid_at, 'Y-m-d H:i' ) : '',
					$p->method,
					$p->reference,
					$d['holder'],
					$d['iban'],
					$d['tckn'],
				);
			}
			if ( count( $batch ) < 500 ) {
				break;
			}
		}
		return Eru_STC_CSV::build(
			array(
				__( 'Talep no', 'sales-team-commissions-for-woocommerce' ),
				__( 'Temsilci', 'sales-team-commissions-for-woocommerce' ),
				__( 'Kod', 'sales-team-commissions-for-woocommerce' ),
				__( 'Tutar', 'sales-team-commissions-for-woocommerce' ),
				__( 'Para birimi', 'sales-team-commissions-for-woocommerce' ),
				__( 'Durum', 'sales-team-commissions-for-woocommerce' ),
				__( 'Talep tarihi', 'sales-team-commissions-for-woocommerce' ),
				__( 'Ödeme tarihi', 'sales-team-commissions-for-woocommerce' ),
				__( 'Yöntem', 'sales-team-commissions-for-woocommerce' ),
				__( 'Referans', 'sales-team-commissions-for-woocommerce' ),
				__( 'Hesap sahibi', 'sales-team-commissions-for-woocommerce' ),
				'IBAN',
				__( 'TCKN', 'sales-team-commissions-for-woocommerce' ),
			),
			$rows
		);
	}

	/**
	 * Report CSV of a month: one row per agent.
	 *
	 * @param string $month    YYYY-MM.
	 * @param int    $agent_id Only this agent (0 = all).
	 * @return string CSV.
	 */
	public static function agents_csv( $month, $agent_id = 0 ) {
		list( $from, $to, $month ) = self::month_range( $month );
		$rows                      = array();
		foreach ( self::by_agent( $from, $to, $agent_id ) as $r ) {
			$rows[] = array( $month, $r['agent_id'], $r['label'], $r['code'], $r['orders'], $r['total'], $r['pending'], $r['approved'], $r['paid'] );
		}
		return Eru_STC_CSV::build( self::report_header(), $rows );
	}

	/** @return string[] Header of the agent report CSV. */
	public static function report_header() {
		return array(
			__( 'Ay', 'sales-team-commissions-for-woocommerce' ),
			__( 'Temsilci no', 'sales-team-commissions-for-woocommerce' ),
			__( 'Temsilci', 'sales-team-commissions-for-woocommerce' ),
			__( 'Kod', 'sales-team-commissions-for-woocommerce' ),
			__( 'Sipariş', 'sales-team-commissions-for-woocommerce' ),
			__( 'Toplam', 'sales-team-commissions-for-woocommerce' ),
			__( 'Beklemede', 'sales-team-commissions-for-woocommerce' ),
			__( 'Onaylı', 'sales-team-commissions-for-woocommerce' ),
			__( 'Ödendi', 'sales-team-commissions-for-woocommerce' ),
		);
	}

	/**
	 * Commission rows CSV (filters of Eru_STC_Commissions::list(), at most 5 000 rows).
	 *
	 * @param array $args Filters.
	 * @return string CSV.
	 */
	public static function commissions_csv( array $args ) {
		$rows = array();
		for ( $offset = 0; $offset < 5000; $offset += 500 ) {
			$batch = Eru_STC_Commissions::list( array_merge( $args, array( 'limit' => 500, 'offset' => $offset ) ) );
			foreach ( $batch as $r ) {
				$agent  = Eru_STC_Agents::get( (int) $r->agent_id );
				$rows[] = array( (int) $r->id, (int) $r->order_id, $agent ? Eru_STC_Agents::label( $agent ) : '#' . (int) $r->agent_id, (int) $r->level, '' === $r->entry_key ? __( 'Komisyon', 'sales-team-commissions-for-woocommerce' ) : __( 'Düzeltme', 'sales-team-commissions-for-woocommerce' ), self::dec( $r->basis ), self::dec( $r->amount ), $r->currency, Eru_STC_Labels::commission_status( $r->status ), get_date_from_gmt( $r->earned_at, 'Y-m-d H:i' ), $r->payout_id ? (int) $r->payout_id : '' );
			}
			if ( count( $batch ) < 500 ) {
				break;
			}
		}
		return Eru_STC_CSV::build(
			array(
				__( 'No', 'sales-team-commissions-for-woocommerce' ),
				__( 'Sipariş', 'sales-team-commissions-for-woocommerce' ),
				__( 'Temsilci', 'sales-team-commissions-for-woocommerce' ),
				__( 'Seviye', 'sales-team-commissions-for-woocommerce' ),
				__( 'Tür', 'sales-team-commissions-for-woocommerce' ),
				__( 'Matrah', 'sales-team-commissions-for-woocommerce' ),
				__( 'Komisyon', 'sales-team-commissions-for-woocommerce' ),
				__( 'Para birimi', 'sales-team-commissions-for-woocommerce' ),
				__( 'Durum', 'sales-team-commissions-for-woocommerce' ),
				__( 'Kazanıldı', 'sales-team-commissions-for-woocommerce' ),
				__( 'Ödeme no', 'sales-team-commissions-for-woocommerce' ),
			),
			$rows
		);
	}

	/** @param mixed $v DB decimal. @return string Two-decimal string. */
	public static function dec( $v ) {
		return Eru_STC_Money::from_minor( Eru_STC_Money::to_minor( null === $v ? '0' : $v ) );
	}
}
