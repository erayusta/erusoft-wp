<?php
/**
 * Delivery code: per-order row (eru_osms_delivery), verification, resend and codeless transition record.
 *
 * A locked order (result = pending) reaches its target status through staff ONLY via verify().
 * API clients and system jobs are not blocked; codeless_check() records them with their source.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Delivery {

	/** Request-level cache: the lock is read several times per request. */
	private static array $cache = array();

	/** Order whose status verify() is changing right now — codeless_check() skips it. */
	public static int $verifying = 0;

	public static function relations(): array {
		return array(
			'self'     => __( 'Kendisi', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'relative' => __( 'Yakını', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'other'    => __( 'Diğer', 'order-sms-delivery-codes-for-ileti-merkezi' ),
		);
	}

	/** Statuses that mean "delivered" (filterable). The rule's own verify target is always included. */
	public static function delivered_statuses(): array {
		return array_map( 'strval', (array) apply_filters( 'eru_osms_delivered_statuses', array( 'completed' ) ) );
	}

	/* ---- row access ---- */

	public static function get( int $order_id ): ?array {
		if ( $order_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $order_id, self::$cache ) ) {
			return self::$cache[ $order_id ];
		}
		global $wpdb;
		$t   = Eru_OSMS_Installer::delivery_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		self::$cache[ $order_id ] = $row ? $row : null;
		return self::$cache[ $order_id ];
	}

	/** Open (or reset) the row: from now on the order is LOCKED. */
	public static function open( int $order_id, string $rule_id, string $target ): void {
		global $wpdb;
		$reset = array(
			'rule_id'            => $rule_id,
			'target_status'      => $target,
			'code_hash'          => '',
			'code_created_at'    => null,
			'attempts'           => 0,
			'locked'             => 0,
			'sms_status'         => 'queued',
			'result'             => 'pending',
			'delivered_by'       => 0,
			'recipient_name'     => '',
			'recipient_relation' => '',
			'note'               => '',
			'codeless_source'    => '',
			'codeless_user_id'   => 0,
			'previous_status'    => '',
			'delivered_at'       => null,
		);
		if ( self::get( $order_id ) ) {
			self::update( $order_id, $reset );
			return;
		}
		$reset['order_id']   = $order_id;
		$reset['created_at'] = current_time( 'mysql' );
		$wpdb->insert( Eru_OSMS_Installer::delivery_table(), $reset ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		unset( self::$cache[ $order_id ] );
	}

	public static function update( int $order_id, array $fields ): void {
		global $wpdb;
		$wpdb->update( Eru_OSMS_Installer::delivery_table(), $fields, array( 'order_id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		unset( self::$cache[ $order_id ] );
	}

	public static function flush_cache(): void {
		self::$cache = array();
	}

	/** Should staff be blocked from moving this order to $status? */
	public static function blocks( int $order_id, string $status ): bool {
		return Eru_OSMS_Decision::staff_blocked( self::get( $order_id ), $status, self::delivered_statuses() );
	}

	/* ---- verification ---- */

	/** Delivery code lifetime in seconds (default 48 hours; 0 = no limit). */
	public static function code_ttl(): int {
		return max( 0, (int) apply_filters( 'eru_osms_code_ttl', 48 * HOUR_IN_SECONDS ) );
	}

	/** Seconds since the current code was created; -1 when unknown. */
	private static function code_age( $row ): int {
		$created = is_array( $row ) && ! empty( $row['code_created_at'] ) ? strtotime( (string) $row['code_created_at'] . ' ' . wp_timezone_string() ) : false;
		return false === $created ? -1 : max( 0, time() - $created );
	}

	/**
	 * @return array|WP_Error ['result' => 'ok'] on success; on error data ['left' => int]
	 */
	public static function verify( WC_Order $o, string $code, string $name, string $relation, string $note ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'name', __( 'Teslim alan kişinin adı soyadı zorunlu.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		}
		if ( ! isset( self::relations()[ $relation ] ) ) {
			return new WP_Error( 'relation', __( 'Teslim alanın yakınlığını seçin.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		}

		$code = (string) preg_replace( '/\D+/', '', $code );
		$row  = self::get( $o->get_id() );
		$ok   = '' !== $code && is_array( $row ) && '' !== (string) $row['code_hash'] && wp_check_password( $code, $row['code_hash'] );
		$d    = Eru_OSMS_Decision::verify( $row, $ok, (int) Eru_OSMS_Settings::get( 'max_attempts' ), self::code_age( $row ), self::code_ttl() );
		$uid  = get_current_user_id();

		switch ( $d['result'] ) {
			case 'not_pending':
				return new WP_Error( 'state', __( 'Bu sipariş teslimat kodu beklemiyor.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
			case 'locked':
				return new WP_Error( 'locked', __( 'Kod çok sayıda hatalı deneme nedeniyle kilitli. "Kodu tekrar gönder" ile yeni kod üretin.', 'order-sms-delivery-codes-for-ileti-merkezi' ), array( 'left' => 0 ) );
			case 'expired':
				return new WP_Error( 'expired', __( 'Kodun süresi doldu. "Kodu tekrar gönder" ile yeni kod üretin.', 'order-sms-delivery-codes-for-ileti-merkezi' ), array( 'left' => $d['left'] ) );
			case 'no_code':
				return new WP_Error( 'no_code', __( 'Müşteriye henüz kod gönderilmedi (SMS kuyrukta ya da gönderilemedi). Birkaç saniye bekleyin ya da "Kodu tekrar gönder" kullanın.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
			case 'wrong':
			case 'now_locked':
				self::update(
					$o->get_id(),
					array(
						'attempts' => (int) $row['attempts'] + 1,
						'locked'   => 'now_locked' === $d['result'] ? 1 : 0,
					)
				);
				/* translators: 1: user name, 2: attempts left */
				$o->add_order_note( sprintf( __( 'Teslimat kodu hatalı girildi (%1$s). Kalan deneme: %2$d', 'order-sms-delivery-codes-for-ileti-merkezi' ), Eru_OSMS_Order::user_name( $uid ), $d['left'] ) );
				/* translators: %d: attempts left */
				$msg = 'now_locked' === $d['result'] ? __( 'Kod hatalı. Deneme hakkı bitti, kod kilitlendi; "Kodu tekrar gönder" ile yeni kod üretin.', 'order-sms-delivery-codes-for-ileti-merkezi' ) : sprintf( __( 'Kod hatalı. Kalan deneme: %d', 'order-sms-delivery-codes-for-ileti-merkezi' ), $d['left'] );
				return new WP_Error( $d['result'], $msg, array( 'left' => $d['left'] ) );
		}

		self::update(
			$o->get_id(),
			array(
				'result'             => 'with_code',
				'delivered_by'       => $uid,
				'recipient_name'     => mb_substr( $name, 0, 150 ),
				'recipient_relation' => $relation,
				'note'               => mb_substr( sanitize_textarea_field( $note ), 0, 500 ),
				'delivered_at'       => current_time( 'mysql' ),
				'previous_status'    => $o->get_status(),
			)
		);

		self::$verifying = $o->get_id();
		$o->update_status(
			$row['target_status'],
			/* translators: 1: staff user, 2: recipient name, 3: relation */
			sprintf( __( 'Teslimat kodu doğrulandı. Teslim eden: %1$s, teslim alan: %2$s (%3$s).', 'order-sms-delivery-codes-for-ileti-merkezi' ), Eru_OSMS_Order::user_name( $uid ), $name, self::relations()[ $relation ] ),
			true
		);
		self::$verifying = 0;

		return array( 'result' => 'ok' );
	}

	/**
	 * New code + SMS queued again. The old code is invalid immediately.
	 *
	 * @return int|WP_Error log id
	 */
	public static function resend( WC_Order $o ) {
		$row = self::get( $o->get_id() );
		if ( ! Eru_OSMS_Decision::is_locked( $row ) ) {
			return new WP_Error( 'state', __( 'Bu sipariş teslimat kodu beklemiyor.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		}
		if ( ! (int) Eru_OSMS_Settings::get( 'enabled' ) ) {
			return new WP_Error( 'off', __( 'SMS gönderimi kapalı (ana şalter).', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		}
		$rule = Eru_OSMS_Settings::rule( (string) $row['rule_id'] );
		if ( ! $rule || ! Eru_OSMS_Decision::has_code( $rule ) ) {
			return new WP_Error( 'rule', __( 'Kodlu kural bulunamadı ya da metninde {teslimat_kodu} yok.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		}
		if ( null === Eru_OSMS_Order::phone( $o ) ) {
			return new WP_Error( 'phone', __( 'Siparişte geçerli bir cep telefonu yok. Fatura telefonunu düzeltip kaydedin, sonra tekrar deneyin.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		}
		$id = Eru_OSMS_Rules::enqueue( $o, $rule, 'resend', get_current_user_id() );
		/* translators: %s: user name */
		$o->add_order_note( sprintf( __( 'Teslimat kodu yeniden gönderildi (%s). Önceki kod geçersiz.', 'order-sms-delivery-codes-for-ileti-merkezi' ), Eru_OSMS_Order::user_name( get_current_user_id() ) ) );
		return $id;
	}

	/**
	 * A locked order reached a delivery status without verification: record who did it.
	 * Runs regardless of the master switch so open rows stay audited.
	 */
	public static function codeless_check( $order_id, $from, $to, $order = null ): void {
		if ( (int) $order_id === self::$verifying ) {
			return;
		}
		if ( ! self::blocks( (int) $order_id, (string) $to ) ) {
			return;
		}
		$ctx    = Eru_OSMS_Order::context();
		$uid    = (int) $ctx['user_id'];
		$source = Eru_OSMS_Decision::codeless_source( $ctx );

		self::update(
			(int) $order_id,
			array(
				'result'           => 'without_code',
				'codeless_source'  => $source,
				'codeless_user_id' => $uid,
				'previous_status'  => Eru_OSMS_Decision::status( $from ),
				'delivered_at'     => current_time( 'mysql' ),
			)
		);
		$o = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $o ) {
			/* translators: %s: source label */
			$o->add_order_note( sprintf( __( 'Teslimat kodu doğrulanmadan teslim durumuna geçti (%s).', 'order-sms-delivery-codes-for-ileti-merkezi' ), self::source_label( $source, $uid ) ) );
		}
	}

	public static function source_label( string $source, int $uid ): string {
		if ( 'api' === $source ) {
			return $uid > 0 ? 'API · ' . Eru_OSMS_Order::user_name( $uid ) : 'API';
		}
		if ( 'user' === $source ) {
			/* translators: %s: user name */
			return sprintf( __( 'kullanıcı: %s', 'order-sms-delivery-codes-for-ileti-merkezi' ), Eru_OSMS_Order::user_name( $uid ) );
		}
		return __( 'sistem', 'order-sms-delivery-codes-for-ileti-merkezi' );
	}
}
