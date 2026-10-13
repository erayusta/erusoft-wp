<?php
/**
 * Status → SMS rule engine.
 *
 * On a status change every matching rule writes one log row. In test mode the row is the end of
 * the line: status `test`, rendered text, nothing sent to any number. Otherwise
 * the row is queued in Action Scheduler so bulk status changes never wait for the SMS API; the
 * worker sends it. Code rules lock the order when queued; the code itself is generated in the
 * worker and only its hash is stored — the plain code is never saved anywhere.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Rules {

	const AS_HOOK  = 'eru_osms_send';
	const AS_GROUP = 'eru-osms';
	const BACKOFF  = array( 300, 900, 3600 );

	public static function status_changed( $order_id, $from, $to, $order = null ): void {
		if ( ! (int) Eru_OSMS_Settings::get( 'enabled' ) ) {
			return;
		}
		$o = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $o || ! Eru_OSMS_Order::eligible( $o ) ) {
			return;
		}
		foreach ( self::matching( Eru_OSMS_Settings::rules(), (string) $from, (string) $to, Eru_OSMS_Order::methods( $o ) ) as $rule ) {
			self::enqueue( $o, $rule, 'rule' );
		}
	}

	public static function matching( array $rules, string $from, string $to, array $methods ): array {
		return array_values( array_filter( $rules, fn( $r ) => is_array( $r ) && Eru_OSMS_Decision::rule_matches( $r, $from, $to, $methods ) ) );
	}

	/**
	 * @param string $source rule | resend
	 * @return int log id (0 = skipped as duplicate)
	 */
	public static function enqueue( WC_Order $o, array $rule, string $source, int $user_id = 0 ): int {
		if ( 'rule' === $source && Eru_OSMS_Log::rule_logged( $o->get_id(), (string) $rule['id'] ) ) {
			return 0;
		}
		$has_code = Eru_OSMS_Decision::has_code( $rule );
		// The code placeholder STAYS in the stored text, so the log is masked by construction.
		$text  = Eru_OSMS_Text::fill( $rule['text'], Eru_OSMS_Order::variables( $o ) );
		$phone = Eru_OSMS_Order::phone( $o );
		$seg   = Eru_OSMS_Text::segments( Eru_OSMS_Text::fill( $text, array( 'teslimat_kodu' => '000000' ) ) );
		$base  = array(
			'order_id' => $o->get_id(),
			'source'   => $source,
			'rule_id'  => (string) $rule['id'],
			'phone'    => (string) $phone,
			'text'     => $text,
			'has_code' => $has_code ? 1 : 0,
			'segments' => $seg['segments'],
			'user_id'  => $user_id,
		);

		if ( null === $phone ) {
			return Eru_OSMS_Log::add( $base + array( 'status' => 'skipped', 'error' => __( 'Geçerli bir cep telefonu yok.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ) );
		}
		if ( ! Eru_OSMS_Settings::test_allows( $phone ) ) {
			// Test mode: log the rendered text instead of sending. Code rules do not lock the order
			// (nobody would know the code).
			return Eru_OSMS_Log::add( $base + array( 'status' => 'test', 'error' => __( 'Test modu: gönderilmedi, yalnızca kaydedildi.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ) );
		}
		if ( ! Eru_OSMS_Settings::has_credentials() ) {
			return Eru_OSMS_Log::add( $base + array( 'status' => 'error', 'error' => __( 'İleti Merkezi kimlik bilgileri veya gönderici başlığı eksik; gönderilmedi.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ) );
		}

		if ( $has_code ) {
			// Lock is active from the moment of queueing: staff cannot mark the order delivered while the SMS waits.
			Eru_OSMS_Delivery::open( $o->get_id(), (string) $rule['id'], Eru_OSMS_Decision::status( $rule['verify_target'] ?? '' ) );
		}
		$log_id = Eru_OSMS_Log::add( $base + array( 'status' => 'queued' ) );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::AS_HOOK, array( 'log_id' => $log_id ), self::AS_GROUP );
		}
		return $log_id;
	}

	/** Action Scheduler worker: every queued send (rule and resend) goes through here. */
	public static function worker( $log_id ): void {
		$log = Eru_OSMS_Log::get( (int) $log_id );
		if ( ! $log || ! in_array( $log['status'], array( 'queued', 'error' ), true ) ) {
			return;
		}
		$order_id = (int) $log['order_id'];
		$has_code = (int) $log['has_code'] && $order_id;
		$text     = (string) $log['text'];
		$attempt  = (int) $log['attempts'] + 1;

		// The switch may have been turned off while the send waited in the queue.
		if ( ! (int) Eru_OSMS_Settings::get( 'enabled' ) ) {
			Eru_OSMS_Log::update( (int) $log['id'], array( 'status' => 'skipped', 'error' => __( 'Ana şalter kapalı; gönderilmedi.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ) );
			self::delivery_sms_status( $log, 'skipped' );
			return;
		}

		if ( ! Eru_OSMS_Settings::test_allows( $log['phone'] ) ) {
			Eru_OSMS_Log::update( (int) $log['id'], array( 'status' => 'test', 'attempts' => $attempt, 'error' => __( 'Test modu: gönderilmedi, yalnızca kaydedildi.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ) );
			self::delivery_sms_status( $log, 'test' );
			return;
		}

		if ( $has_code ) {
			$skip = self::code_skip_reason( $log );
			if ( '' !== $skip ) {
				Eru_OSMS_Log::update( (int) $log['id'], array( 'status' => 'skipped', 'error' => $skip ) );
				return;
			}
			$code = Eru_OSMS_Text::new_code();
			$text = Eru_OSMS_Text::fill( $text, array( 'teslimat_kodu' => $code ) );
			// Hash before sending: even if the response is lost, the customer's code still verifies.
			Eru_OSMS_Delivery::update(
				$order_id,
				array(
					'code_hash'       => wp_hash_password( $code ),
					'code_created_at' => current_time( 'mysql' ),
					'attempts'        => 0,
					'locked'          => 0,
				)
			);
			unset( $code );
		}

		$r = Eru_OSMS_Client::send( (string) $log['phone'], $text );
		unset( $text );

		if ( 'sent' === $r['class'] ) {
			Eru_OSMS_Log::update( (int) $log['id'], array( 'status' => 'sent', 'attempts' => $attempt, 'error' => '', 'api_response' => $r['response'] ) );
			self::delivery_sms_status( $log, 'sent' );
			return;
		}

		Eru_OSMS_Log::update(
			(int) $log['id'],
			array(
				'status'       => 'error',
				'attempts'     => $attempt,
				'error'        => mb_substr( $r['error'], 0, 255 ),
				'api_response' => $r['response'],
			)
		);
		self::delivery_sms_status( $log, 'error' );

		// Code + uncertain: the SMS may have gone out — do not retry with a new code; staff can resend.
		$retry = $attempt <= count( self::BACKOFF ) && 'final' !== $r['class'] && ! ( $has_code && 'uncertain' === $r['class'] );
		if ( $retry && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::BACKOFF[ $attempt - 1 ], self::AS_HOOK, array( 'log_id' => (int) $log['id'] ), self::AS_GROUP );
		}
	}

	/**
	 * Why a queued code send is no longer valid ('' if it is):
	 * the order is no longer waiting for a code, or a newer code send (resend) replaced this one.
	 */
	private static function code_skip_reason( array $log ): string {
		if ( ! Eru_OSMS_Decision::is_locked( Eru_OSMS_Delivery::get( (int) $log['order_id'] ) ) ) {
			return __( 'Sipariş artık teslimat kodu beklemiyor.', 'order-sms-delivery-codes-for-ileti-merkezi' );
		}
		if ( Eru_OSMS_Log::last_code_id( (int) $log['order_id'] ) !== (int) $log['id'] ) {
			return __( 'Yerini daha yeni bir kod gönderimi aldı.', 'order-sms-delivery-codes-for-ileti-merkezi' );
		}
		return '';
	}

	private static function delivery_sms_status( array $log, string $status ): void {
		if ( (int) $log['has_code'] && (int) $log['order_id'] && Eru_OSMS_Delivery::get( (int) $log['order_id'] ) ) {
			Eru_OSMS_Delivery::update( (int) $log['order_id'], array( 'sms_status' => $status ) );
		}
	}
}
