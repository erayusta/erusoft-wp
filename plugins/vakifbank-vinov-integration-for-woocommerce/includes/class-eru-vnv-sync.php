<?php
/**
 * Status sync and the per-order lifecycle actions (query, cancel).
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One run, under a run lock, takes at most LIMIT open rows, least recently checked first, and stops starting
 * new bank calls after BUDGET seconds:
 * - status 1 rows: the instruction is listed by id. Status 2 → the order is paid (payment_complete, once: a
 *   paid order or a `_eru_vnv_paid_at` marker stops a second time), 3 → the order is cancelled with a note,
 *   1 or not listed → checked again later. After `giveup_days` the row is closed with an order note asking
 *   the merchant to check the bank panel; the order status is left alone.
 * - status 0 rows (an attempt whose answer never arrived): looked up with the same matching rules as the
 *   payment (Eru_VNV_Payment::lookup(): order number + attempt key, cancelled items never adopted). Found →
 *   adopted (pending status, never paid here); otherwise retried until `giveup_days`, then closed with a
 *   bank-panel note. A failed attempt is closed without a call; an attempt still in flight is skipped.
 * Every transition runs under the same per-order lock as the payment, on a fresh read of the order.
 *
 * A cancelled order is never charged silently: cancelling an order with a waiting instruction (by any path)
 * queues a bank cancel by instruction id, an instruction found later for an already cancelled order is
 * cancelled the same way, and an approval that still lands on a cancelled order is flagged ("İncelenmeli"),
 * shown as a persistent admin notice, mailed to the site admin and announced with
 * `eru_vnv_paid_while_cancelled`. The order is never reopened automatically.
 *
 * The chain never stops: every run (also a locked or failing one) re-arms the sync while rows are open.
 */
class Eru_VNV_Sync {

	/** Rows per run. */
	const LIMIT = 50;

	/** No new bank call starts after this many seconds. */
	const BUDGET = 240;

	/** Run lock TTL (seconds). */
	const LOCK_TTL = 900;

	/** Flags that are an alarm (raised once per instruction). */
	const ALARMS = array( 'paid_while_cancelled', 'paid_elsewhere' );

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_cancelled' ), 10, 2 );
		// Trash and permanent deletion change no status, so they are hooked on their own (HPOS and posts).
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_discarded' ) );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'on_discarded' ), 10, 2 );
		foreach ( array( 'wp_trash_post', 'before_delete_post' ) as $hook ) {
			add_action(
				$hook,
				function ( $post_id ) {
					if ( 'shop_order' === get_post_type( $post_id ) ) {
						self::on_discarded( (int) $post_id );
					}
				}
			);
		}
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'payable_statuses' ), 10, 2 );
	}

	/**
	 * WooCommerce's payment_complete() changes the status only from on-hold/pending/failed/cancelled; a custom
	 * "talimat sonrası" status is added for Vinov orders so an approved instruction still marks them paid.
	 *
	 * @param string[]      $statuses Statuses.
	 * @param WC_Order|null $order    Order.
	 * @return string[]
	 */
	public static function payable_statuses( $statuses, $order = null ) {
		if ( $order instanceof WC_Order && Eru_VNV_Settings::GATEWAY_ID === $order->get_payment_method() ) {
			$statuses   = (array) $statuses;
			$statuses[] = Eru_VNV_Settings::pending_status();
			$statuses   = array_values( array_unique( $statuses ) );
		}
		return $statuses;
	}

	/**
	 * The shop no longer expects this instruction to be paid: the order is gone (permanently deleted), trashed,
	 * cancelled, refunded, or paid through another gateway.
	 *
	 * @param WC_Order|false|null $order Order.
	 * @return bool
	 */
	public static function discarded( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return true;
		}
		if ( $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			return true;
		}
		return Eru_VNV_Settings::GATEWAY_ID !== $order->get_payment_method() && $order->is_paid();
	}

	/** @param WC_Order $order Order. @return bool An admin asked to cancel the instruction of this paid order. */
	private static function cancel_requested( $order ) {
		return $order instanceof WC_Order && '' !== (string) $order->get_meta( '_eru_vnv_cancel_requested' );
	}

	/**
	 * An order is trashed or about to be deleted permanently: a waiting instruction is cancelled at the bank like
	 * for a cancelled order (the row is kept until that finishes). An unresolved attempt on an order that is
	 * being deleted can no longer be looked up, so it is flagged for review.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_discarded( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$row = Eru_VNV_Instructions::by_order( $order->get_id() );
		if ( $row && null === $row->closed_at && empty( $row->instruction_id ) ) {
			if ( in_array( current_action(), array( 'woocommerce_before_delete_order', 'before_delete_post' ), true ) ) {
				Eru_VNV_Instructions::close( $row->id, 0, 'order_missing' );
				Eru_VNV_Instructions::flag( $row->id, 'deleted_unresolved' );
				Eru_VNV_Logger::error( sprintf( 'sipariş %d silindi; sonucu belirsiz Vinov denemesi artık aranamaz, banka panelinden kontrol edin', $order->get_id() ) );
			}
			return; // A trashed order can still be resolved by the sync (and is then cancelled at the bank).
		}
		self::on_cancelled( $order->get_id(), $order );
	}

	/**
	 * An order with a waiting instruction was cancelled or refunded outside this plugin (status control, bulk
	 * action, REST, another plugin): the instruction must not stay payable, so a bank cancel is queued. A paid
	 * order gets one note instead (the bank is not called; refunds happen in the bank panel).
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_cancelled( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || '' === (string) $order->get_meta( '_eru_vnv_instruction_id' ) || '' !== (string) $order->get_meta( '_eru_vnv_cancelled_at' ) ) {
			return; // No instruction, or this plugin cancelled it already (bank status 3 or the box).
		}
		if ( '' !== (string) $order->get_meta( '_eru_vnv_paid_at' ) ) {
			if ( '' === (string) $order->get_meta( '_eru_vnv_paid_close_note' ) ) {
				$order->update_meta_data( '_eru_vnv_paid_close_note', time() );
				$order->save();
				$order->add_order_note( __( 'Vinov talimatı ödenmiş; banka çağrılmadı. İade gerekiyorsa banka panelinden yapın.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			return;
		}
		$row = Eru_VNV_Instructions::by_order( $order->get_id() );
		if ( $row && null === $row->closed_at && 1 === (int) $row->status ) {
			Eru_VNV_Cron::queue_cancel( $order->get_id() );
			Eru_VNV_Logger::info( sprintf( 'sipariş %d iptal edildi; talimat %s için banka iptali kuyruğa alındı', $order->get_id(), (string) $row->instruction_id ) );
		}
	}

	/**
	 * Action Scheduler: cancels the waiting instruction of a cancelled or refunded order at the bank. Nothing
	 * happens unless the order is still cancelled/refunded and its row is an open status-1 row. The instruction's
	 * status is read first: approved (2) → the paid-while-cancelled alarm (once), no cancel; already cancelled
	 * (3) → the row is closed and a stale "cancel_failed" flag cleared; waiting (1) → cancel by id; unreadable →
	 * retried within the bound. A refusal or the last failed try flags the order for review. The order's status
	 * is never changed here.
	 *
	 * @param int $order_id Order id.
	 * @param int $try      Attempt number.
	 */
	public static function bank_cancel_job( $order_id, $try = 1 ) {
		$order = wc_get_order( (int) $order_id );
		$row   = Eru_VNV_Instructions::by_order( (int) $order_id );
		if ( ! $row || null !== $row->closed_at || 1 !== (int) $row->status || empty( $row->instruction_id ) || ( $order && ! self::discarded( $order ) && ! self::cancel_requested( $order ) ) ) {
			return; // Nothing waiting, or the shop reopened the order.
		}
		$try = max( 1, (int) $try );
		$env = self::env( $row );
		$res = Eru_VNV_Settings::ready( $env ) ? ( new Eru_VNV_API_Client( $env ) )->list_instructions( array( 'id' => (int) $row->instruction_id ) ) : null;
		$now = $res && $res['success'] ? self::status_of( $res, (int) $row->instruction_id ) : -1;
		if ( 2 === $now ) {
			self::mark_paid( $row );
			return;
		}
		if ( 3 === $now ) {
			self::mark_cancelled( $row ); // Re-checks the order under the lock on a fresh read.
			return;
		}
		if ( 1 !== $now ) {
			self::retry_or_flag( $order ? $order : null, $row, $try, 'unreadable' );
			return;
		}
		$r = $order
			? self::cancel( $order, __( 'otomatik: sipariş iptal edildi', 'vakifbank-vinov-integration-for-woocommerce' ), 'auto_cancel', true )
			: self::cancel_missing( $row );
		if ( $r['ok'] || in_array( $r['code'], array( 'already_cancelled', 'paid', 'no_instruction', 'reopened' ), true ) ) {
			return;
		}
		self::retry_or_flag( $order ? $order : null, $row, 'bank_refused' === $r['code'] ? Eru_VNV_Cron::CANCEL_TRIES : $try, $r['code'] );
	}

	/**
	 * Cancels the waiting instruction of a permanently deleted order by the row's id and environment.
	 *
	 * @param object $row Row.
	 * @return array{ok:bool, code:string, message:string, data:array}
	 */
	private static function cancel_missing( $row ) {
		$key  = 'order_' . (int) $row->order_id;
		$lock = Eru_VNV_Lock::acquire( $key, Eru_VNV_Payment::LOCK_TTL );
		if ( false === $lock ) {
			return self::refuse( 'busy', self::outcome_message( 'busy' ) );
		}
		try {
			$fresh = Eru_VNV_Instructions::by_order( (int) $row->order_id );
			if ( ! $fresh || null !== $fresh->closed_at || 1 !== (int) $fresh->status ) {
				return self::refuse( 'already_cancelled', '' );
			}
			$res = ( new Eru_VNV_API_Client( self::env( $fresh ) ) )->cancel_instruction( (int) $fresh->instruction_id );
			if ( $res['success'] && false !== ( $res['data']['Result']['IsSucceeded'] ?? $res['data']['IsSucceeded'] ?? null ) ) {
				Eru_VNV_Instructions::unflag( $fresh->id, 'cancel_failed' );
				Eru_VNV_Instructions::close( $fresh->id, 3, 'auto_cancel' );
				Eru_VNV_Logger::info( sprintf( 'silinen sipariş %d: talimat %s bankada iptal edildi', (int) $fresh->order_id, (string) $fresh->instruction_id ) );
				return array(
					'ok'      => true,
					'code'    => 'cancelled',
					'message' => '',
					'data'    => array(),
				);
			}
			return self::refuse( $res['ambiguous'] ? 'ambiguous' : 'bank_refused', $res['error'] );
		} finally {
			Eru_VNV_Lock::release( $key, $lock );
		}
	}

	/**
	 * Re-queues the bank cancel with a growing delay, or flags the order once the tries are used up.
	 *
	 * @param WC_Order|null $order Order (null: permanently deleted).
	 * @param object        $row   Row.
	 * @param int           $try   Attempt number (CANCEL_TRIES = no more tries).
	 * @param string        $why   Reason code for the log.
	 */
	private static function retry_or_flag( $order, $row, $try, $why ) {
		if ( $try < Eru_VNV_Cron::CANCEL_TRIES ) {
			Eru_VNV_Cron::queue_cancel( (int) $row->order_id, $try + 1, 15 * MINUTE_IN_SECONDS * $try );
			return;
		}
		Eru_VNV_Instructions::flag( $row->id, 'cancel_failed' );
		Eru_VNV_Logger::error( sprintf( 'talimat %s bankada iptal edilemedi (sipariş %d, %s)', (string) $row->instruction_id, (int) $row->order_id, $why ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order->add_order_note( sprintf( /* translators: %s: instruction id */ __( 'Vinov talimatı %s bankada iptal edilemedi; sipariş iptal durumunda ama talimat hâlâ ödenebilir. Lütfen talimatı banka panelinden iptal edin.', 'vakifbank-vinov-integration-for-woocommerce' ), (string) $row->instruction_id ) );
	}

	/**
	 * One sync run.
	 *
	 * @param array $args {order?: int} Only this order's open row.
	 * @return array{status:string, touched:int[], paid:int, cancelled:int, gave_up:int, adopted:int, errors:int}
	 */
	public static function run( array $args = array() ) {
		$sum  = array(
			'status'    => 'ok',
			'touched'   => array(),
			'paid'      => 0,
			'cancelled' => 0,
			'gave_up'   => 0,
			'adopted'   => 0,
			'errors'    => 0,
		);
		$lock = Eru_VNV_Lock::acquire( 'sync', self::LOCK_TTL );
		if ( false === $lock ) {
			$sum['status'] = 'locked';
			Eru_VNV_Cron::ensure(); // The other run may be a CLI call that does not re-arm the scheduled chain.
			return $sum;
		}
		try {
			if ( ! empty( $args['order'] ) ) {
				$r    = Eru_VNV_Instructions::by_order( (int) $args['order'] );
				$rows = $r && null === $r->closed_at ? array( $r ) : array();
			} else {
				$rows = Eru_VNV_Instructions::due( self::LIMIT );
			}
			$start  = time();
			$streak = 0;
			foreach ( $rows as $row ) {
				if ( time() - $start >= self::BUDGET ) {
					break;
				}
				$out              = self::check_row( $row, false );
				$sum['touched'][] = (int) $row->id;
				$map              = array(
					'paid'      => 'paid',
					'cancelled' => 'cancelled',
					'gave_up'   => 'gave_up',
					'adopted'   => 'adopted',
					'error'     => 'errors',
				);
				if ( isset( $map[ $out ] ) ) {
					++$sum[ $map[ $out ] ];
				}
				$streak = 'error' === $out ? $streak + 1 : 0;
				if ( $streak >= 5 ) {
					$sum['status'] = 'bank_error'; // The bank is down: leave the rest for the next run.
					break;
				}
			}
		} finally {
			Eru_VNV_Lock::release( 'sync', $lock );
			Eru_VNV_Cron::ensure(); // Also when a row throws: the chain must not die.
		}
		if ( $sum['touched'] ) {
			Eru_VNV_Logger::info( sprintf( 'senkron: %d kayıt, ödendi=%d iptal=%d bırakıldı=%d bulundu=%d hata=%d', count( $sum['touched'] ), $sum['paid'], $sum['cancelled'], $sum['gave_up'], $sum['adopted'], $sum['errors'] ) );
		}
		return $sum;
	}

	/** @param object $row Row. @return string test | live */
	private static function env( $row ) {
		return in_array( (string) $row->env, Eru_VNV_Settings::ENVS, true ) ? (string) $row->env : Eru_VNV_Settings::env();
	}

	/**
	 * Checks one row and applies what the bank says.
	 *
	 * @param object $row    Row.
	 * @param bool   $manual From the order box or WP-CLI (no give-up).
	 * @return string Outcome: waiting | not_listed | paid | already_paid | cancelled | already_cancelled | review |
	 *                gave_up | adopted | unresolved | absent | in_flight | failed | repaired | busy | error |
	 *                not_ready | order_missing.
	 */
	public static function check_row( $row, $manual = false ) {
		$env = self::env( $row );
		if ( ! Eru_VNV_Settings::ready( $env ) ) {
			Eru_VNV_Instructions::touch( $row->id );
			return 'not_ready';
		}
		$client = new Eru_VNV_API_Client( $env );
		if ( empty( $row->instruction_id ) ) {
			return self::resolve_attempt( $row, $client, $manual );
		}
		$res = $client->list_instructions( array( 'id' => (int) $row->instruction_id ) );
		if ( ! $res['success'] ) {
			Eru_VNV_Instructions::touch( $row->id );
			return 'error';
		}
		$code = self::status_of( $res, (int) $row->instruction_id );
		if ( 2 === $code ) {
			return self::mark_paid( $row );
		}
		if ( 3 === $code ) {
			return self::mark_cancelled( $row );
		}
		Eru_VNV_Instructions::touch( $row->id );
		if ( ! $manual && null === $row->closed_at && self::expired( $row ) ) {
			return self::give_up( $row );
		}
		return 1 === $code ? 'waiting' : 'not_listed';
	}

	/** @param array $res List result. @param int $id Instruction id. @return int InstructionStatusCode (0 = not listed). */
	private static function status_of( array $res, $id ) {
		foreach ( (array) ( $res['data']['WorkPlaceInstruction'] ?? array() ) as $it ) {
			if ( is_array( $it ) && (int) ( $it['Id'] ?? 0 ) === (int) $id ) {
				return (int) ( $it['InstructionStatusCode'] ?? 0 );
			}
		}
		return 0;
	}

	/** @param object $row Row. @return bool Older than the give-up period. */
	private static function expired( $row ) {
		$at = strtotime( (string) $row->created_at . ' UTC' );
		return $at && $at < time() - Eru_VNV_Settings::giveup_days() * DAY_IN_SECONDS;
	}

	/**
	 * Runs $fn on a fresh read of the row's order under the payment's per-order lock.
	 *
	 * @param object   $row Row.
	 * @param callable $fn  fn( WC_Order|false $order ): string.
	 * @return string Outcome ('busy' when the lock is held).
	 */
	private static function locked( $row, callable $fn ) {
		$key  = 'order_' . (int) $row->order_id;
		$lock = Eru_VNV_Lock::acquire( $key, Eru_VNV_Payment::LOCK_TTL );
		if ( false === $lock ) {
			Eru_VNV_Instructions::touch( $row->id );
			return 'busy';
		}
		try {
			Eru_VNV_Payment::evict( (int) $row->order_id );
			return (string) $fn( wc_get_order( (int) $row->order_id ) );
		} finally {
			Eru_VNV_Lock::release( $key, $lock );
		}
	}

	/** Status 2: the customer approved the instruction. @param object $row Row. @return string */
	private static function mark_paid( $row ) {
		return self::locked(
			$row,
			function ( $order ) use ( $row ) {
				$iid  = (string) $row->instruction_id;
				$seen = Eru_VNV_Instructions::by_order( (int) $row->order_id );
				if ( $seen && in_array( $seen->review, self::ALARMS, true ) ) {
					if ( null === $seen->closed_at ) {
						Eru_VNV_Instructions::close( $row->id, 2, 'review' );
					}
					return 'review'; // The alarm was raised already: no second note, e-mail or action.
				}
				if ( ! $order ) {
					self::paid_while_cancelled( null, $row ); // Approved for a permanently deleted order.
					return 'review';
				}
				if ( (string) $order->get_meta( '_eru_vnv_instruction_id' ) !== $iid ) {
					Eru_VNV_Instructions::close( $row->id, 2, 'review' );
					Eru_VNV_Instructions::flag( $row->id, 'id_mismatch' );
					return 'review';
				}
				if ( '' !== (string) $order->get_meta( '_eru_vnv_paid_at' ) ) {
					Eru_VNV_Instructions::close( $row->id, 2, 'paid' );
					return 'already_paid';
				}
				if ( self::discarded( $order ) ) {
					// Cancelled, refunded, trashed or paid with another gateway: never payment_complete().
					self::paid_while_cancelled( $order, $row, Eru_VNV_Settings::GATEWAY_ID !== $order->get_payment_method() && $order->is_paid() ? 'paid_elsewhere' : 'paid_while_cancelled' );
					return 'review';
				}
				if ( $order->is_paid() ) {
					Eru_VNV_Instructions::close( $row->id, 2, 'paid' );
					return 'already_paid';
				}
				$order->update_meta_data( '_eru_vnv_paid_at', time() );
				$order->save();
				$order->add_order_note( sprintf( /* translators: %s: instruction id */ __( 'Vinov talimatı %s müşteri tarafından bankada onaylandı.', 'vakifbank-vinov-integration-for-woocommerce' ), $iid ) );
				$order->payment_complete( $iid );
				Eru_VNV_Instructions::close( $row->id, 2, 'paid' );
				// The payment is recorded either way; a total edited after the instruction is flagged.
				$stored = (string) $order->get_meta( '_eru_vnv_amount' );
				$want   = Eru_VNV_Installments::to_minor( '' !== $stored ? $stored : $row->amount );
				$have   = Eru_VNV_Installments::to_minor( $order->get_total() );
				if ( $want !== $have ) {
					$order->add_order_note( sprintf( /* translators: 1: instruction amount, 2: order total */ __( 'Dikkat: onaylanan Vinov talimat tutarı (%1$s) sipariş toplamından (%2$s) farklı. Lütfen siparişi kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ), wc_format_decimal( Eru_VNV_Installments::from_minor( $want ), 2 ), wc_format_decimal( Eru_VNV_Installments::from_minor( $have ), 2 ) ) );
					Eru_VNV_Instructions::flag( $row->id, 'amount_mismatch' );
				}
				/**
				 * Fires after an approved instruction marked its order paid.
				 *
				 * @param WC_Order $order Order.
				 * @param int      $id    Instruction id.
				 */
				do_action( 'eru_vnv_instruction_paid', $order, (int) $iid );
				return 'paid';
			}
		);
	}

	/** Status 3: cancelled or refused at the bank. @param object $row Row. @return string */
	private static function mark_cancelled( $row ) {
		return self::locked(
			$row,
			function ( $order ) use ( $row ) {
				Eru_VNV_Instructions::unflag( $row->id, 'cancel_failed' ); // The bank shows it cancelled after all.
				if ( ! $order ) {
					Eru_VNV_Instructions::close( $row->id, 3, 'order_missing' );
					return 'order_missing';
				}
				$iid = (string) $row->instruction_id;
				if ( self::discarded( $order ) ) {
					// Cancelled, refunded, trashed (never pulled out of the trash) or paid elsewhere: just close.
					Eru_VNV_Instructions::close( $row->id, 3, 'cancelled' );
					return 'already_cancelled';
				}
				if ( '' !== (string) $order->get_meta( '_eru_vnv_paid_at' ) || $order->is_paid() ) {
					$order->add_order_note( sprintf( /* translators: %s: instruction id */ __( 'Vinov talimatı %s bankada iptal görünüyor, ancak sipariş ödenmiş durumda; durum değiştirilmedi. Lütfen banka panelinden kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ), $iid ) );
					Eru_VNV_Instructions::close( $row->id, 3, 'review' );
					Eru_VNV_Instructions::flag( $row->id, 'cancelled_while_paid' );
					return 'review';
				}
				$order->update_meta_data( '_eru_vnv_cancelled_at', time() );
				$order->save();
				$order->update_status( 'cancelled', sprintf( /* translators: %s: instruction id */ __( 'Vinov talimatı %s bankada iptal edildi ya da müşteri tarafından reddedildi.', 'vakifbank-vinov-integration-for-woocommerce' ), $iid ) );
				Eru_VNV_Instructions::close( $row->id, 3, 'cancelled' );
				return 'cancelled';
			}
		);
	}

	/**
	 * Status 2 on an order the shop gave up on (cancelled, refunded, trashed, permanently deleted, or paid with
	 * another gateway): the money may be collected twice or for nothing. Nothing is reopened; the row is
	 * flagged, the admin is told (persistent notice, e-mail) and an action fires — once per instruction.
	 *
	 * @param WC_Order|null $order  Fresh order (under the lock), or null when it was deleted.
	 * @param object        $row    Row.
	 * @param string        $reason paid_while_cancelled | paid_elsewhere.
	 */
	private static function paid_while_cancelled( $order, $row, $reason = 'paid_while_cancelled' ) {
		$iid    = (string) $row->instruction_id;
		$number = $order ? (string) $order->get_order_number() : (string) (int) $row->order_id;
		$state  = $order ? wc_get_order_status_name( $order->get_status() ) : __( 'kalıcı olarak silinmiş', 'vakifbank-vinov-integration-for-woocommerce' );
		if ( $order ) {
			$order->add_order_note(
				'paid_elsewhere' === $reason
					/* translators: %s: instruction id */
					? sprintf( __( 'DİKKAT: Vinov talimatı %s bankada onaylandı, ancak sipariş başka bir ödeme yöntemiyle ödenmiş. Müşteriden iki kez tahsilat yapılmış olabilir; lütfen iadeyi banka panelinden yapın.', 'vakifbank-vinov-integration-for-woocommerce' ), $iid )
					/* translators: 1: instruction id, 2: order status */
					: sprintf( __( 'DİKKAT: Vinov talimatı %1$s bankada onaylandı, ancak sipariş "%2$s" durumunda. Para tahsil edilmiş olabilir. Sipariş otomatik olarak yeniden açılmadı; lütfen siparişi inceleyin ve gerekiyorsa iadeyi banka panelinden yapın.', 'vakifbank-vinov-integration-for-woocommerce' ), $iid, $state )
			);
		}
		Eru_VNV_Instructions::close( $row->id, 2, 'review' );
		Eru_VNV_Instructions::flag( $row->id, $reason );
		Eru_VNV_Logger::error( sprintf( 'talimat %s onaylandı ama sipariş %d bu talimatla ödenmeyecek (%s)', $iid, (int) $row->order_id, $reason ) );
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		wp_mail(
			(string) get_option( 'admin_email' ),
			'paid_elsewhere' === $reason
				/* translators: 1: site name, 2: order number */
				? sprintf( __( '[%1$s] Vinov: başka yöntemle ödenmiş sipariş #%2$s için talimat bankada onaylandı', 'vakifbank-vinov-integration-for-woocommerce' ), $site, $number )
				/* translators: 1: site name, 2: order number */
				: sprintf( __( '[%1$s] Vinov: iptal edilmiş sipariş #%2$s için talimat bankada onaylandı', 'vakifbank-vinov-integration-for-woocommerce' ), $site, $number ),
			implode(
				"\n",
				array(
					/* translators: %s: order number */
					sprintf( __( 'Sipariş: #%s', 'vakifbank-vinov-integration-for-woocommerce' ), $number ),
					/* translators: %s: order status */
					sprintf( __( 'Sipariş durumu: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $state ),
					/* translators: %s: instruction id */
					sprintf( __( 'Talimat no: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $iid ),
					/* translators: %s: amount */
					sprintf( __( 'Talimat tutarı: %s', 'vakifbank-vinov-integration-for-woocommerce' ), wc_format_decimal( (float) $row->amount, 2 ) ),
					'',
					__( 'Müşteri talimatı bankada onayladı; para tahsil edilmiş olabilir. Sipariş otomatik olarak yeniden açılmadı. Siparişi inceleyin; gerekiyorsa iadeyi banka panelinden yapın.', 'vakifbank-vinov-integration-for-woocommerce' ),
					'',
					$order ? $order->get_edit_order_url() : admin_url( 'admin.php?page=eru-vnv&tab=talimatlar&durum=incelenmeli' ),
				)
			)
		);
		/**
		 * Fires once when an instruction was approved at the bank for an order the shop gave up on (cancelled,
		 * refunded, trashed, permanently deleted, or paid with another gateway).
		 *
		 * @param WC_Order|null $order    Order, or null when it was deleted permanently.
		 * @param int           $id       Instruction id.
		 * @param int           $order_id Order id.
		 */
		do_action( 'eru_vnv_paid_while_cancelled', $order, (int) $iid, (int) $row->order_id );
	}

	/** Ends tracking after the give-up period, with a note (under the order lock, fresh read). @param object $row Row. @return string */
	private static function give_up( $row ) {
		$days = Eru_VNV_Settings::giveup_days();
		return self::locked(
			$row,
			function ( $order ) use ( $row, $days ) {
				if ( $order ) {
					$order->add_order_note(
						empty( $row->instruction_id )
							/* translators: %d: days */
							? sprintf( __( 'Vinov: sonucu belirsiz kalan ödeme denemesi %d gün içinde banka kayıtlarında bulunamadı; otomatik takip durduruldu. Talimatın oluşup oluşmadığını lütfen banka panelinden kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ), $days )
							/* translators: 1: instruction id, 2: days */
							: sprintf( __( 'Vinov talimatı %1$s %2$d gün içinde onaylanmadı; otomatik durum takibi durduruldu. Lütfen banka panelinden kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ), (string) $row->instruction_id, $days )
					);
				}
				Eru_VNV_Instructions::close( $row->id, (int) $row->status, 'gave_up' );
				return 'gave_up';
			}
		);
	}

	/**
	 * Status 0: an attempt whose create answer never arrived.
	 *
	 * @param object             $row    Row.
	 * @param Eru_VNV_API_Client $client Client of the row's environment.
	 * @param bool               $manual No give-up.
	 * @return string
	 */
	private static function resolve_attempt( $row, Eru_VNV_API_Client $client, $manual ) {
		$order = wc_get_order( (int) $row->order_id );
		if ( ! $order ) {
			Eru_VNV_Instructions::close( $row->id, 0, 'order_missing' );
			return 'order_missing';
		}
		if ( 'sending' === $order->get_meta( '_eru_vnv_attempt_state' ) && (int) $order->get_meta( '_eru_vnv_attempt_at' ) > time() - Eru_VNV_Payment::LOCK_TTL ) {
			Eru_VNV_Instructions::touch( $row->id );
			return 'in_flight'; // The payment request may still be talking to the bank.
		}
		$out = self::locked(
			$row,
			function ( $fresh ) use ( $row, $client ) {
				if ( ! $fresh ) {
					Eru_VNV_Instructions::close( $row->id, 0, 'order_missing' );
					return 'order_missing';
				}
				$iid = (string) $fresh->get_meta( '_eru_vnv_instruction_id' );
				if ( '' !== $iid ) {
					Eru_VNV_Instructions::record( $fresh, (int) $iid, (int) $fresh->get_meta( '_eru_vnv_installments' ) );
					return 'repaired';
				}
				$state   = (string) $fresh->get_meta( '_eru_vnv_attempt_state' );
				$attempt = (string) $fresh->get_meta( '_eru_vnv_attempt' );
				if ( '' === $attempt || ! in_array( $state, array( 'sending', 'ambiguous' ), true ) ) {
					Eru_VNV_Instructions::close( $row->id, 0, 'failed' );
					return 'failed';
				}
				$found = Eru_VNV_Payment::lookup( $client, $fresh, $attempt );
				if ( 'found' === $found['state'] ) {
					return self::adopt( $fresh, (int) $found['id'] );
				}
				Eru_VNV_Instructions::touch( $row->id );
				return 'error' === $found['state'] ? 'unresolved' : 'absent';
			}
		);
		if ( ! $manual && in_array( $out, array( 'unresolved', 'absent' ), true ) && self::expired( $row ) ) {
			return self::give_up( $row );
		}
		return $out;
	}

	/**
	 * Adopts an instruction found for an ambiguous attempt (never marks the order paid).
	 *
	 * @param WC_Order $order Fresh order (under the lock).
	 * @param int      $id    Instruction id.
	 * @return string
	 */
	private static function adopt( WC_Order $order, $id ) {
		if ( ! self::discarded( $order ) && $order->has_status( array( 'pending', 'failed', 'on-hold', 'checkout-draft' ) ) ) {
			$released = self::stock_released( $order );
			Eru_VNV_Payment::adopt( $order, $id );
			$row = $released ? Eru_VNV_Instructions::by_order( $order->get_id() ) : null;
			if ( $row ) {
				// WooCommerce's hold ran out before the instruction was found: stock was just reduced again.
				Eru_VNV_Instructions::flag( $row->id, 'stock_released' );
				$order->add_order_note( __( 'Vinov: WooCommerce\'in stok tutma süresi talimat bulunmadan önce dolmuştu; stok şimdi düşüldü ve yetersizse eksiye düşmüş olabilir. Lütfen stoğu kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
		} else {
			// The shop already moved on: keep the id and never reopen the order.
			$order->update_meta_data( '_eru_vnv_instruction_id', (string) $id );
			$order->update_meta_data( '_eru_vnv_attempt_state', 'created' );
			$order->set_transaction_id( (string) $id );
			$order->save();
			Eru_VNV_Instructions::record( $order, $id, (int) $order->get_meta( '_eru_vnv_installments' ) );
			if ( self::discarded( $order ) ) {
				// The order will not be paid with this instruction (cancelled, refunded, trashed or paid with another
				// gateway): it must not stay payable at the bank, so it is cancelled by its id (status read first).
				$order->add_order_note( sprintf( /* translators: %s: instruction id */ __( 'Vinov talimatı %s banka kayıtlarında bulundu; sipariş bu talimatla ödenmeyeceği için (iptal, iade, çöp ya da başka ödeme yöntemi) talimat bankada iptal ediliyor.', 'vakifbank-vinov-integration-for-woocommerce' ), (string) $id ) );
				Eru_VNV_Cron::queue_cancel( $order->get_id() );
			} else {
				$order->add_order_note( sprintf( /* translators: 1: instruction id, 2: order status */ __( 'Vinov talimatı %1$s banka kayıtlarında bulundu, ancak sipariş "%2$s" durumunda olduğu için değiştirilmedi. Lütfen banka panelinden kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ), (string) $id, wc_get_order_status_name( $order->get_status() ) ) );
				$row = Eru_VNV_Instructions::by_order( $order->get_id() );
				if ( $row ) {
					Eru_VNV_Instructions::flag( $row->id, 'adopted_closed' );
				}
			}
		}
		return 'adopted';
	}

	/**
	 * Stock of a managed item was released before adoption: not reduced for this order yet and no active
	 * WooCommerce reservation left.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function stock_released( WC_Order $order ) {
		global $wpdb;
		if ( $order->get_data_store()->get_stock_reduced( $order->get_id() ) ) {
			return false;
		}
		$managed = false;
		foreach ( $order->get_items() as $item ) {
			$p = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( $p && $p->managing_stock() ) {
				$managed = true;
				break;
			}
		}
		if ( ! $managed ) {
			return false;
		}
		$t = $wpdb->prefix . 'wc_reserved_stock';
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		return ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE order_id = %d AND expires > %s", $order->get_id(), gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array A refusal. */
	private static function refuse( $code, $message ) {
		return array(
			'ok'      => false,
			'code'    => (string) $code,
			'message' => (string) $message,
			'data'    => array(),
		);
	}

	/**
	 * "Durumu sorgula": applies the bank's status to the order (no give-up) and reads the installment detail.
	 * The answer holds amounts, maturities and statuses only — no names or identity numbers.
	 *
	 * @param WC_Order $order Order.
	 * @return array{ok:bool, code:string, message:string, data:array}
	 */
	public static function query( WC_Order $order ) {
		$row = Eru_VNV_Instructions::by_order( $order->get_id() );
		if ( ! $row && '' !== (string) $order->get_meta( '_eru_vnv_instruction_id' ) ) {
			Eru_VNV_Instructions::record( $order, (int) $order->get_meta( '_eru_vnv_instruction_id' ), (int) $order->get_meta( '_eru_vnv_installments' ) );
			$row = Eru_VNV_Instructions::by_order( $order->get_id() );
		}
		if ( ! $row ) {
			return self::refuse( 'not_tracked', __( 'Bu sipariş için izlenen bir Vinov talimatı yok.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( ! Eru_VNV_Settings::ready( self::env( $row ) ) ) {
			return self::refuse( 'not_ready', __( 'Vinov ayarları eksik: API adresi ve istemci bilgilerini girin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$shown = null;
		if ( null !== $row->closed_at ) {
			// Tracking ended: only show what the bank says now; no transition, note, e-mail or action.
			$out = 'closed';
			if ( ! empty( $row->instruction_id ) ) {
				$res = ( new Eru_VNV_API_Client( self::env( $row ) ) )->list_instructions( array( 'id' => (int) $row->instruction_id ) );
				if ( ! $res['success'] ) {
					return self::refuse( 'error', __( 'Bankadan durum alınamadı. Birkaç dakika sonra yeniden deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
				}
				$code  = self::status_of( $res, (int) $row->instruction_id );
				$shown = $code > 0 ? $code : null;
			}
		} else {
			$out = self::check_row( $row, true );
			if ( 'error' === $out ) {
				return self::refuse( 'error', __( 'Bankadan durum alınamadı. Birkaç dakika sonra yeniden deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
		}
		$row   = Eru_VNV_Instructions::by_order( $order->get_id() );
		$debts = array();
		if ( $row && ! empty( $row->instruction_id ) ) {
			$d      = ( new Eru_VNV_API_Client( self::env( $row ) ) )->instruction_detail( (int) $row->instruction_id );
			$labels = array(
				1 => __( 'Vadesi gelmedi', 'vakifbank-vinov-integration-for-woocommerce' ),
				2 => __( 'Tahsil edildi', 'vakifbank-vinov-integration-for-woocommerce' ),
				3 => __( 'İptal', 'vakifbank-vinov-integration-for-woocommerce' ),
			);
			foreach ( $d['success'] ? (array) ( $d['data']['DebtInformations'] ?? array() ) : array() as $debt ) {
				if ( ! is_array( $debt ) ) {
					continue;
				}
				$code    = (int) ( $debt['DebtStatusCode'] ?? 0 );
				$debts[] = array(
					'amount'   => wc_format_decimal( (float) ( $debt['DebtAmount'] ?? 0 ), 2 ),
					'maturity' => preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) ( $debt['MaturityDate'] ?? '' ), $m ) ? $m[0] : '',
					'status'   => $labels[ $code ] ?? (string) $code,
				);
			}
		}
		return array(
			'ok'      => true,
			'code'    => $out,
			'message' => self::outcome_message( $out ),
			'data'    => array(
				'status' => null !== $shown ? $shown : ( $row ? (int) $row->status : 0 ),
				'label'  => Eru_VNV_Instructions::label( null !== $shown ? $shown : ( $row ? (int) $row->status : 0 ) ),
				'debts'  => $debts,
			),
		);
	}

	/** @param string $out Outcome. @return string Admin message. */
	public static function outcome_message( $out ) {
		$m = array(
			'waiting'           => __( 'Talimat müşteri onayı bekliyor.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'not_listed'        => __( 'Talimat bankanın listesinde görünmüyor; daha sonra yeniden sorgulanacak.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'paid'              => __( 'Talimat onaylandı; sipariş ödendi olarak işaretlendi.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'already_paid'      => __( 'Talimat onaylı; sipariş zaten ödendi.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'cancelled'         => __( 'Talimat bankada iptal; sipariş iptal edildi.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'already_cancelled' => __( 'Talimat iptal; sipariş zaten iptal.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'review'            => __( 'Bankadaki durum siparişle uyuşmuyor; sipariş notuna bakın.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'adopted'           => __( 'Belirsiz deneme banka kayıtlarında bulundu ve siparişe bağlandı.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'unresolved'        => __( 'Banka kayıtları bu denemeyi kesin olarak göstermiyor; banka panelinden kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'absent'            => __( 'Bu deneme banka kayıtlarında bulunamadı.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'in_flight'         => __( 'Ödeme isteği hâlâ sürüyor olabilir; birkaç dakika sonra yeniden sorgulayın.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'busy'              => __( 'Bu sipariş şu anda başka bir işlemde; birazdan yeniden deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'closed'            => __( 'Bu talimatın takibi kapalı; bankadaki güncel durum gösteriliyor, siparişte bir şey değiştirilmedi.', 'vakifbank-vinov-integration-for-woocommerce' ),
		);
		return $m[ $out ] ?? __( 'Durum güncellendi.', 'vakifbank-vinov-integration-for-woocommerce' );
	}

	/**
	 * Cancels a waiting instruction by its id (no partial amounts, no payment operations). Refused for a paid
	 * or already cancelled instruction; under the per-order lock on a fresh read.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $actor  Who asked (shown in the note).
	 * @param string   $reason   Close reason of the row (admin_cancel | auto_cancel).
	 * @param bool     $from_job From the queued bank cancel: the fresh order must still be cancelled or
	 *                           refunded, and its status is never changed (only a note is added).
	 * @return array{ok:bool, code:string, message:string, data:array}
	 */
	public static function cancel( WC_Order $order, $actor, $reason = 'admin_cancel', $from_job = false ) {
		$iid = (string) $order->get_meta( '_eru_vnv_instruction_id' );
		if ( '' === $iid ) {
			return self::refuse( 'no_instruction', __( 'Bu siparişte Vinov talimatı yok.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$key  = 'order_' . $order->get_id();
		$lock = Eru_VNV_Lock::acquire( $key, Eru_VNV_Payment::LOCK_TTL );
		if ( false === $lock ) {
			return self::refuse( 'busy', self::outcome_message( 'busy' ) );
		}
		try {
			Eru_VNV_Payment::evict( $order->get_id() );
			$fresh = wc_get_order( $order->get_id() );
			$row   = Eru_VNV_Instructions::by_order( $order->get_id() );
			if ( ! $fresh || (string) $fresh->get_meta( '_eru_vnv_instruction_id' ) !== $iid ) {
				return self::refuse( 'no_instruction', __( 'Bu siparişte Vinov talimatı yok.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			if ( $from_job && ! self::discarded( $fresh ) && ! self::cancel_requested( $fresh ) ) {
				return self::refuse( 'reopened', __( 'Sipariş yeniden açılmış; talimat iptal edilmedi.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			if ( '' !== (string) $fresh->get_meta( '_eru_vnv_cancelled_at' ) || ( $row && 3 === (int) $row->status ) ) {
				return self::refuse( 'already_cancelled', __( 'Talimat zaten iptal edilmiş; bankaya yeni istek gönderilmedi.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			$paid_here = $fresh->is_paid() && Eru_VNV_Settings::GATEWAY_ID === $fresh->get_payment_method();
			if ( '' !== (string) $fresh->get_meta( '_eru_vnv_paid_at' ) || $paid_here || ( $row && 2 === (int) $row->status ) ) {
				return self::refuse( 'paid', __( 'Onaylanmış (ödenmiş) talimat buradan iptal edilmez. İadeyi banka panelinden ya da mağaza dışında yapın.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			if ( ! $from_job && ( $fresh->is_paid() || null !== $fresh->get_date_paid() ) ) {
				// Paid through another gateway (CLI or admin-ajax): the order's status, stock and e-mails stay as
				// they are. The waiting instruction is cancelled by the status-first bank-cancel job.
				if ( ! self::cancel_requested( $fresh ) ) {
					$fresh->update_meta_data( '_eru_vnv_cancel_requested', time() );
					$fresh->save();
					$fresh->add_order_note( sprintf( /* translators: 1: instruction id, 2: who asked */ __( 'Vinov talimatı %1$s için iptal istendi (%2$s). Sipariş ödenmiş olduğundan durumu değiştirilmedi; talimat bankadaki durumu okunarak iptal ediliyor.', 'vakifbank-vinov-integration-for-woocommerce' ), $iid, $actor ) );
				}
				Eru_VNV_Cron::queue_cancel( $fresh->get_id() );
				return array(
					'ok'      => true,
					'code'    => 'queued',
					'message' => __( 'Sipariş ödenmiş; durumu değiştirilmedi. Talimat, bankadaki durumu okunarak iptal edilmek üzere kuyruğa alındı.', 'vakifbank-vinov-integration-for-woocommerce' ),
					'data'    => array(),
				);
			}
			$env = (string) $fresh->get_meta( '_eru_vnv_env' );
			$env = in_array( $env, Eru_VNV_Settings::ENVS, true ) ? $env : Eru_VNV_Settings::env();
			if ( ! Eru_VNV_Settings::ready( $env ) ) {
				return self::refuse( 'not_ready', __( 'Vinov ayarları eksik: API adresi ve istemci bilgilerini girin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			$res = ( new Eru_VNV_API_Client( $env ) )->cancel_instruction( (int) $iid );
			$ok  = $res['success'] && false !== ( $res['data']['Result']['IsSucceeded'] ?? $res['data']['IsSucceeded'] ?? null );
			if ( $ok ) {
				$fresh->update_meta_data( '_eru_vnv_cancelled_at', time() );
				$fresh->save();
				/* translators: 1: instruction id, 2: who cancelled */
				$note = sprintf( __( 'Vinov talimatı %1$s iptal edildi (%2$s).', 'vakifbank-vinov-integration-for-woocommerce' ), $iid, $actor );
				if ( $from_job || $fresh->has_status( array( 'cancelled', 'trash' ) ) ) {
					$fresh->add_order_note( $note );
				} else {
					$fresh->update_status( 'cancelled', $note );
				}
				if ( $row ) {
					Eru_VNV_Instructions::unflag( $row->id, 'cancel_failed' );
					Eru_VNV_Instructions::close( $row->id, 3, $reason );
				}
				Eru_VNV_Logger::info( sprintf( 'talimat %s iptal edildi (sipariş %d)', $iid, $fresh->get_id() ) );
				/**
				 * Fires after an instruction was cancelled at the bank from the shop.
				 *
				 * @param WC_Order $order Order.
				 * @param int      $id    Instruction id.
				 */
				do_action( 'eru_vnv_instruction_cancelled', $fresh, (int) $iid );
				return array(
					'ok'      => true,
					'code'    => 'cancelled',
					'message' => __( 'Talimat iptal edildi; sipariş iptal durumuna alındı.', 'vakifbank-vinov-integration-for-woocommerce' ),
					'data'    => array(),
				);
			}
			if ( $res['ambiguous'] ) {
				$fresh->add_order_note( sprintf( /* translators: %s: instruction id */ __( 'Vinov talimatı %s için iptal isteğine bankadan yanıt alınamadı; "Durumu sorgula" ile kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ), $iid ) );
				return self::refuse( 'ambiguous', __( 'Bankadan yanıt alınamadı. İptalin gerçekleşip gerçekleşmediğini "Durumu sorgula" ile kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			$fresh->add_order_note( sprintf( /* translators: 1: instruction id, 2: bank status code */ __( 'Vinov talimatı %1$s için iptal isteği bankada reddedildi (kod: %2$s).', 'vakifbank-vinov-integration-for-woocommerce' ), $iid, '' !== $res['status_code'] ? $res['status_code'] : 'HTTP ' . $res['http_code'] ) );
			return self::refuse( 'bank_refused', '' !== $res['error'] ? sprintf( /* translators: %s: bank message */ __( 'Banka iptali reddetti: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $res['error'] ) : __( 'Banka iptali reddetti.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		} finally {
			Eru_VNV_Lock::release( $key, $lock );
		}
	}
}
