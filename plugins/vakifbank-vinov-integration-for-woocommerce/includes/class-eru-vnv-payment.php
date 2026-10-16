<?php
/**
 * Payment processing: re-checks the bank verification server-side and creates the instruction idempotently.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * process() refuses — without any API call — unless the session holds a bank verification that is at most
 * 10 minutes old and matches the posted identity number (HMAC, hash_equals), the posted phone, the posted
 * installment count (allowed and equal to the verified one), the order total in minor units and the cart hash.
 * Posted "verified" flags are never read. Under a per-order lock the order is reloaded (an instruction already
 * on it → refused), then the verification id is claimed atomically (Eru_VNV_Session::claim(); a second request
 * with the same verification → refused) and the session is cleared, all before the bank is called. So one
 * verification creates at most one instruction, and one order never gets a second one.
 *
 * Timing: the lock is treated as stale only after LOCK_TTL (600 s). No new bank step starts once BUDGET
 * (240 s) has passed; a single step takes at most about 130 s (token 20 s + call 45 s, twice after a 401), so
 * a run ends before the lock could be taken over.
 *
 * Idempotency: the attempt key (`_eru_vnv_attempt`) and state are saved on the order before createInstruction.
 * A timeout, a 5xx or any reply without a parsed bank status is ambiguous: the instruction list is searched
 * for the order number and the attempt key first; found → adopted, absent → one retry with the same key,
 * lookup failed or inconclusive → refused and left "ambiguous", so the next attempt on the order looks up
 * before creating anything. Only a parsed, non-success bank status is a definitive refusal.
 */
class Eru_VNV_Payment {

	/** Seconds after which an order lock counts as stale. */
	const LOCK_TTL = 600;

	/** No new bank step starts after this many seconds. */
	const BUDGET = 240;

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $posted {tckn, phone, installments} from the checkout form.
	 * @return array{ok:bool, code:string, message:string, instruction_id:int}
	 */
	public static function process( WC_Order $order, array $posted ) {
		if ( '' !== (string) $order->get_meta( '_eru_vnv_instruction_id' ) ) {
			return self::refuse( 'already_created', __( 'Bu sipariş için Vinov talimatı zaten oluşturuldu.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( ! Eru_VNV_Settings::enabled() ) {
			return self::refuse( 'disabled', __( 'Vinov ile ödeme şu anda kullanılamıyor.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$state = Eru_VNV_Session::get();
		if ( empty( $state['verified_at'] ) ) {
			return self::refuse( 'not_verified', __( 'Lütfen önce SMS doğrulamasını tamamlayın.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( ! Eru_VNV_Session::verified() ) {
			return self::refuse( 'expired', __( 'Doğrulamanın süresi doldu. Lütfen yeniden kod isteyin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$tckn = Eru_VNV_Identity::clean_tckn( $posted['tckn'] ?? '' );
		if ( ! Eru_VNV_Identity::valid_tckn( $tckn ) || ! Eru_VNV_Session::matches_tckn( $tckn ) ) {
			return self::refuse( 'tckn_mismatch', __( 'TC kimlik numarası doğrulanan numarayla aynı değil.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$phone = Eru_VNV_Identity::normalize_phone( $posted['phone'] ?? '' );
		if ( '' === $phone || ! Eru_VNV_Session::matches_phone( $phone ) ) {
			return self::refuse( 'phone_mismatch', __( 'Telefon numarası doğrulama kodunun gönderildiği numarayla aynı değil.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$n = Eru_VNV_Installments::normalize( $posted['installments'] ?? '' );
		if ( 0 === $n ) {
			return self::refuse( 'installments_invalid', __( 'Geçersiz taksit seçimi.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( (int) $state['installments'] !== $n ) {
			return self::refuse( 'installments_changed', __( 'Taksit seçimi doğrulamadan sonra değişti. Lütfen yeniden doğrulayın.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$total = Eru_VNV_Installments::to_minor( $order->get_total() );
		if ( $total <= 0 || (int) $state['total_minor'] !== $total ) {
			return self::refuse( 'total_changed', __( 'Sipariş tutarı doğrulanan tutarla aynı değil. Lütfen yeniden doğrulayın.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$hash = WC()->cart ? (string) WC()->cart->get_cart_hash() : '';
		if ( '' === $hash || ! hash_equals( (string) $state['cart_hash'], $hash ) || ( '' !== (string) $order->get_cart_hash() && ! hash_equals( (string) $state['cart_hash'], (string) $order->get_cart_hash() ) ) ) {
			return self::refuse( 'cart_changed', __( 'Sepet doğrulamadan sonra değişti. Lütfen yeniden doğrulayın.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}

		$lock = Eru_VNV_Lock::acquire( 'order_' . $order->get_id(), self::LOCK_TTL );
		if ( false === $lock ) {
			return self::refuse( 'busy', __( 'Bu sipariş için ödeme işlemi sürüyor. Lütfen birkaç dakika sonra sipariş durumunu kontrol edin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		try {
			// Another request (another PHP process) may have finished this order while we waited: drop every
			// cached copy and read the stored order.
			self::evict( $order->get_id() );
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh || '' !== (string) $fresh->get_meta( '_eru_vnv_instruction_id' ) || 'created' === $fresh->get_meta( '_eru_vnv_attempt_state' ) ) {
				return self::refuse( 'already_created', __( 'Bu sipariş için Vinov talimatı zaten oluşturuldu.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			// Single use across parallel requests: exactly one request wins this verification.
			if ( ! Eru_VNV_Session::claim() ) {
				return self::refuse( 'not_verified', __( 'Lütfen önce SMS doğrulamasını tamamlayın.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			Eru_VNV_Session::clear();
			return self::create( $fresh, $tckn, $phone, $n, $total );
		} finally {
			Eru_VNV_Lock::release( 'order_' . $order->get_id(), $lock );
		}
	}

	/**
	 * Drops the cached copies of an order (WooCommerce's order cache under HPOS, post/meta caches otherwise).
	 *
	 * @param int $id Order id.
	 */
	public static function evict( $id ) {
		$id = (int) $id;
		if ( class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) && function_exists( 'wc_get_container' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
			} catch ( Throwable $e ) {
				unset( $e ); // No order cache in this WooCommerce: nothing to drop.
			}
		}
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		wp_cache_delete( $id, 'posts' );
	}

	/** @return array A refusal. */
	private static function refuse( $code, $message ) {
		return array(
			'ok'             => false,
			'code'           => (string) $code,
			'message'        => (string) $message,
			'instruction_id' => 0,
		);
	}

	/**
	 * Creates (or adopts) the instruction under the lock.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $tckn  Verified identity number (sent to the bank, never stored).
	 * @param string   $phone Normalised phone.
	 * @param int      $n     Installments.
	 * @param int      $total Amount in minor units (= order total).
	 * @return array
	 */
	private static function create( WC_Order $order, $tckn, $phone, $n, $total ) {
		$client  = new Eru_VNV_API_Client();
		$start   = time();
		$in_time = fn() => time() - $start < self::BUDGET;
		$attempt = (string) $order->get_meta( '_eru_vnv_attempt' );
		$prev    = (string) $order->get_meta( '_eru_vnv_attempt_state' );

		// An earlier attempt may have reached the bank: look it up before creating anything.
		if ( '' !== $attempt && in_array( $prev, array( 'sending', 'ambiguous' ), true ) ) {
			$found = self::lookup( $client, $order, $attempt );
			if ( 'found' === $found['state'] ) {
				return self::finish( $order, $found['id'], $n, $total, $tckn, $phone, $client->env(), true );
			}
			if ( 'error' === $found['state'] ) {
				return self::ambiguous( $order );
			}
		}
		if ( '' === $attempt || 'failed' === $prev ) {
			$attempt = strtoupper( wp_generate_password( 10, false ) );
		}
		$order->update_meta_data( '_eru_vnv_attempt', $attempt );
		$order->update_meta_data( '_eru_vnv_attempt_at', time() );
		$order->update_meta_data( '_eru_vnv_attempt_state', 'sending' );
		$order->update_meta_data( '_eru_vnv_env', $client->env() );
		$order->update_meta_data( '_eru_vnv_installments', (int) $n );
		$order->update_meta_data( '_eru_vnv_tckn_mask', Eru_VNV_Identity::mask( $tckn ) );
		$order->update_meta_data( '_eru_vnv_phone', $phone );
		$order->save();
		/**
		 * Fires after an attempt was saved on the order and before createInstruction is called. The status
		 * sync tracks the attempt from here on, so an attempt cut off mid-call is looked up later.
		 *
		 * @param WC_Order $order Order.
		 */
		do_action( 'eru_vnv_attempt_started', $order );

		$params = array(
			'tckn'           => $tckn,
			'amount_minor'   => $total,
			'installments'   => $n,
			'invoice_number' => (string) $order->get_order_number(),
			'description'    => self::description( $order, $attempt ),
		);
		if ( $n > 1 ) {
			$params['plan'] = Eru_VNV_Installments::plan( $total, $n );
		} else {
			$params['expiry_date'] = Eru_VNV_API_Client::date( '+' . Eru_VNV_Settings::expiry_days() . ' days' );
		}

		for ( $try = 1; $try <= 2; $try++ ) {
			$res     = $client->create_instruction( $params );
			$id      = (int) ( $res['data']['Result']['Id'] ?? 0 );
			$created = $res['success'] && true === ( $res['data']['Result']['IsSucceeded'] ?? null ) && $id > 0;
			if ( $created ) {
				return self::finish( $order, $id, $n, $total, $tckn, $phone, $client->env(), false );
			}
			// Definitive only when the bank's parsed answer says no: a non-success status, or success with an
			// explicit IsSucceeded false. Success without a boolean result is ambiguous (look up first).
			$said_no = ! $res['success'] || false === ( $res['data']['Result']['IsSucceeded'] ?? null );
			if ( ! $res['ambiguous'] && $said_no ) {
				$order->update_meta_data( '_eru_vnv_attempt_state', 'failed' );
				$order->add_order_note( sprintf( /* translators: %s: bank status code */ __( 'Vinov talimatı oluşturulamadı (banka kodu: %s).', 'vakifbank-vinov-integration-for-woocommerce' ), '' !== $res['status_code'] ? $res['status_code'] : 'HTTP ' . $res['http_code'] ) );
				$order->save();
				/**
				 * Fires when the bank definitively refused an attempt (nothing was created).
				 *
				 * @param WC_Order $order Order.
				 */
				do_action( 'eru_vnv_attempt_failed', $order );
				return self::refuse( 'bank_refused', '' !== $res['error'] ? sprintf( /* translators: %s: bank message */ __( 'Vinov talimatı oluşturulamadı: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $res['error'] ) : __( 'Vinov talimatı oluşturulamadı.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			// Ambiguous (timeout, 5xx, unparsed reply, success without an id): the bank may have created it.
			if ( ! $in_time() ) {
				break;
			}
			$found = self::lookup( $client, $order, $attempt );
			if ( 'found' === $found['state'] ) {
				return self::finish( $order, $found['id'], $n, $total, $tckn, $phone, $client->env(), true );
			}
			if ( 'error' === $found['state'] || ! $in_time() ) {
				break;
			}
		}
		return self::ambiguous( $order );
	}

	/** @return string Instruction description carrying the attempt key. */
	private static function description( WC_Order $order, $attempt ) {
		/* translators: 1: order number, 2: attempt key */
		return sprintf( __( 'Sipariş %1$s / %2$s', 'vakifbank-vinov-integration-for-woocommerce' ), $order->get_order_number(), $attempt );
	}

	/** Leaves the order "ambiguous" and refuses. @return array */
	private static function ambiguous( WC_Order $order ) {
		$order->update_meta_data( '_eru_vnv_attempt_state', 'ambiguous' );
		$order->add_order_note( __( 'Vinov: banka yanıtı alınamadı, talimatın oluşup oluşmadığı belirsiz. Bir sonraki denemede önce banka kayıtları sorgulanacak.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		$order->save();
		return self::refuse( 'ambiguous', __( 'Bankadan yanıt alınamadı. Lütfen birkaç dakika sonra yeniden doğrulayıp tekrar deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
	}

	/**
	 * Looks an attempt up in the bank's instruction list. A match needs this order's number as InvoiceNumber
	 * and the attempt key inside InstructionDescription; cancelled instructions (status 3) are never adopted.
	 * A non-cancelled item with this order's number but no description cannot be told apart, so the result is
	 * "error" (the order stays ambiguous, no retry). The documented list shape has no paging fields, so one
	 * response is read.
	 *
	 * @param Eru_VNV_API_Client $client  Client.
	 * @param WC_Order           $order   Order.
	 * @param string             $attempt Attempt key.
	 * @return array{state:string, id:int} found | absent | error
	 */
	public static function lookup( Eru_VNV_API_Client $client, WC_Order $order, $attempt ) {
		$at  = (int) $order->get_meta( '_eru_vnv_attempt_at' );
		$at  = $at > 0 ? $at : time();
		$tz  = new DateTimeZone( Eru_VNV_API_Client::TIMEZONE );
		$day = ( new DateTimeImmutable( '@' . $at ) )->setTimezone( $tz );
		$res = $client->list_instructions(
			array(
				'instruction_start_date' => $day->modify( '-1 day' )->format( 'Y-m-d\T00:00:00P' ),
				'instruction_end_date'   => $day->modify( '+1 day' )->format( 'Y-m-d\T23:59:59P' ),
			)
		);
		if ( ! $res['success'] ) {
			return array(
				'state' => 'error',
				'id'    => 0,
			);
		}
		$number  = (string) $order->get_order_number();
		$unknown = false;
		foreach ( (array) ( $res['data']['WorkPlaceInstruction'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) || (string) ( $it['InvoiceNumber'] ?? '' ) !== $number || (int) ( $it['Id'] ?? 0 ) <= 0 || 3 === (int) ( $it['InstructionStatusCode'] ?? 0 ) ) {
				continue;
			}
			$desc = isset( $it['InstructionDescription'] ) && is_scalar( $it['InstructionDescription'] ) ? (string) $it['InstructionDescription'] : '';
			if ( '' === $desc ) {
				$unknown = true;
				continue;
			}
			if ( '' !== (string) $attempt && false !== strpos( $desc, (string) $attempt ) ) {
				return array(
					'state' => 'found',
					'id'    => (int) $it['Id'],
				);
			}
		}
		return array(
			'state' => $unknown ? 'error' : 'absent',
			'id'    => 0,
		);
	}

	/**
	 * Adopts an instruction the status sync found in the bank's list for an ambiguous attempt (same matching
	 * rules as lookup()). The order moves to the pending status exactly as after a direct create; it is never
	 * marked paid here — only a later status-2 poll does that. Call under the per-order lock.
	 *
	 * @param WC_Order $order Order (fresh read).
	 * @param int      $id    Instruction id from lookup().
	 * @return array Success result.
	 */
	public static function adopt( WC_Order $order, $id ) {
		$n = max( 1, (int) $order->get_meta( '_eru_vnv_installments' ) );
		$e = (string) $order->get_meta( '_eru_vnv_env' );
		return self::finish( $order, (int) $id, $n, Eru_VNV_Installments::to_minor( $order->get_total() ), null, null, in_array( $e, Eru_VNV_Settings::ENVS, true ) ? $e : Eru_VNV_Settings::env(), true );
	}

	/**
	 * Records the instruction, moves the order to the pending status and empties the cart.
	 *
	 * @param WC_Order    $order   Order.
	 * @param int         $id      Instruction id.
	 * @param int         $n       Installments.
	 * @param int         $total   Amount in minor units.
	 * @param string|null $tckn    Identity number (masked here), or null to keep the stored mask.
	 * @param string|null $phone   Normalised phone, or null to keep the stored one.
	 * @param string      $env     test | live.
	 * @param bool        $adopted Found by a lookup after an ambiguous answer.
	 * @return array Success result.
	 */
	private static function finish( WC_Order $order, $id, $n, $total, $tckn, $phone, $env, $adopted ) {
		$order->update_meta_data( '_eru_vnv_instruction_id', (string) $id );
		$order->update_meta_data( '_eru_vnv_installments', (int) $n );
		$order->update_meta_data( '_eru_vnv_amount', wc_format_decimal( Eru_VNV_Installments::from_minor( $total ), 2 ) );
		$order->update_meta_data( '_eru_vnv_env', $env );
		$order->update_meta_data( '_eru_vnv_attempt_state', 'created' );
		if ( null !== $tckn ) {
			$order->update_meta_data( '_eru_vnv_tckn_mask', Eru_VNV_Identity::mask( $tckn ) );
		}
		if ( null !== $phone ) {
			$order->update_meta_data( '_eru_vnv_phone', $phone );
		}
		$order->set_transaction_id( (string) $id );
		$order->save();
		$note = sprintf(
			/* translators: 1: instruction id, 2: installment count */
			__( 'Vinov talimatı oluşturuldu (ID: %1$s, %2$d taksit). Müşteri onayı bekleniyor.', 'vakifbank-vinov-integration-for-woocommerce' ),
			$id,
			$n
		);
		if ( $adopted ) {
			$note .= ' ' . __( '(Belirsiz yanıttan sonra banka kayıtlarında bulundu.)', 'vakifbank-vinov-integration-for-woocommerce' );
		}
		$order->update_status( Eru_VNV_Settings::pending_status(), $note );
		wc_maybe_reduce_stock_levels( $order->get_id() );
		if ( null !== $tckn && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		Eru_VNV_Logger::info( sprintf( 'talimat %s sipariş %d için oluşturuldu (%d taksit)', $id, $order->get_id(), $n ) );
		/**
		 * Fires after an instruction was created (or adopted after an ambiguous answer) for an order.
		 *
		 * @param WC_Order $order Order.
		 * @param int      $id    Instruction id.
		 * @param int      $n     Installments.
		 */
		do_action( 'eru_vnv_instruction_created', $order, (int) $id, (int) $n );
		return array(
			'ok'             => true,
			'code'           => $adopted ? 'adopted' : 'created',
			'message'        => '',
			'instruction_id' => (int) $id,
		);
	}
}
