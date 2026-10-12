<?php
/**
 * Risk sinyalleri ve puan hesabı.
 *
 * Her sinyalin ağırlığı bir seçenektir (eru_ors_weight_<anahtar>); 0 = devre dışı.
 * Listeler (e-posta alan adları, ülkeler, izleme listesi) varsayılan olarak boştur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_Ors_Scorer {

	/** Sinyal tanımları: anahtar => [label, weight, check]. Filtre: eru_ors_signals. */
	public static function signals(): array {
		$signals = array(
			'watchlist'           => array(
				'label'  => __( 'İzleme listesi eşleşmesi', 'order-risk-score-for-woocommerce' ),
				'weight' => 100,
				'check'  => array( __CLASS__, 'check_watchlist' ),
			),
			'disposable_email'    => array(
				'label'  => __( 'Tek kullanımlık e-posta alan adı', 'order-risk-score-for-woocommerce' ),
				'weight' => 30,
				'check'  => array( __CLASS__, 'check_disposable_email' ),
			),
			'first_order'         => array(
				'label'  => __( 'Müşterinin ilk siparişi', 'order-risk-score-for-woocommerce' ),
				'weight' => 15,
				'check'  => array( __CLASS__, 'check_first_order' ),
			),
			'guest_order'         => array(
				'label'  => __( 'Misafir siparişi', 'order-risk-score-for-woocommerce' ),
				'weight' => 10,
				'check'  => fn( WC_Order $o ) => (int) $o->get_customer_id() <= 0,
			),
			'high_amount'         => array(
				'label'  => __( 'Yüksek tutar', 'order-risk-score-for-woocommerce' ),
				'weight' => 25,
				'check'  => array( __CLASS__, 'check_high_amount' ),
			),
			'address_mismatch'    => array(
				'label'  => __( 'Fatura ve teslimat adresi farklı', 'order-risk-score-for-woocommerce' ),
				'weight' => 20,
				'check'  => array( __CLASS__, 'check_address_mismatch' ),
			),
			'country_mismatch'    => array(
				'label'  => __( 'Fatura ve teslimat ülkesi farklı', 'order-risk-score-for-woocommerce' ),
				'weight' => 20,
				'check'  => array( __CLASS__, 'check_country_mismatch' ),
			),
			'risky_country'       => array(
				'label'  => __( 'Riskli ülke listesinde', 'order-risk-score-for-woocommerce' ),
				'weight' => 25,
				'check'  => array( __CLASS__, 'check_risky_country' ),
			),
			'manual_payment_high' => array(
				'label'  => __( 'Manuel ödeme + yüksek tutar', 'order-risk-score-for-woocommerce' ),
				'weight' => 25,
				'check'  => array( __CLASS__, 'check_manual_payment' ),
			),
		);
		return (array) apply_filters( 'eru_ors_signals', $signals );
	}

	public static function weight_for( string $key, array $def ): int {
		return max( 0, min( 100, (int) get_option( 'eru_ors_weight_' . $key, $def['weight'] ?? 0 ) ) );
	}

	public static function bands(): array {
		$medium = max( 0, min( 100, (int) get_option( 'eru_ors_band_medium', 30 ) ) );
		$high   = max( $medium, min( 100, (int) get_option( 'eru_ors_band_high', 60 ) ) );
		return array( 'medium' => $medium, 'high' => $high );
	}

	public static function band_for_score( int $score ): string {
		$b = self::bands();
		if ( $score >= $b['high'] ) {
			return 'high';
		}
		return $score >= $b['medium'] ? 'medium' : 'low';
	}

	public static function band_label( string $band ): string {
		return match ( $band ) {
			'high'   => __( 'Yüksek', 'order-risk-score-for-woocommerce' ),
			'medium' => __( 'Orta', 'order-risk-score-for-woocommerce' ),
			default  => __( 'Düşük', 'order-risk-score-for-woocommerce' ),
		};
	}

	/** Seçenekteki satır/virgül/noktalı virgül ayrımlı listeyi küçük harfli diziye çevirir. */
	public static function list_option( string $option ): array {
		$raw   = (string) get_option( $option, '' );
		$items = preg_split( '/[\r\n,;]+/', mb_strtolower( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_map( 'trim', (array) $items ) ) );
	}

	/* ---------- Kontroller ---------- */

	private static function email_domain( string $email ): string {
		$pos = strrpos( $email, '@' );
		return false === $pos ? '' : mb_strtolower( substr( $email, $pos + 1 ) );
	}

	public static function check_watchlist( WC_Order $order ): bool {
		$list = self::list_option( 'eru_ors_watchlist' );
		if ( ! $list ) {
			return false;
		}
		$email  = mb_strtolower( trim( $order->get_billing_email() ) );
		$domain = self::email_domain( $email );
		$phone  = preg_replace( '/\D+/', '', $order->get_billing_phone() );
		foreach ( $list as $item ) {
			if ( '' !== $email && $item === $email ) {
				return true;
			}
			if ( '@' === $item[0] && '' !== $domain && substr( $item, 1 ) === $domain ) {
				return true;
			}
			$digits = preg_replace( '/\D+/', '', $item );
			if ( '' !== $phone && strlen( $digits ) >= 7 && $digits === $phone && false === strpos( $item, '@' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function check_disposable_email( WC_Order $order ): bool {
		$domain = self::email_domain( $order->get_billing_email() );
		return '' !== $domain && in_array( $domain, self::list_option( 'eru_ors_disposable_domains' ), true );
	}

	public static function check_first_order( WC_Order $order ): bool {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 ) {
			return false; // Misafir siparişleri için ayrı sinyal var.
		}
		return ( new WC_Customer( $customer_id ) )->get_order_count() <= 1;
	}

	public static function check_high_amount( WC_Order $order ): bool {
		$threshold = (float) get_option( 'eru_ors_high_amount', 1000 );
		return $threshold > 0 && (float) $order->get_total() >= $threshold;
	}

	private static function norm( array $parts ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', mb_strtolower( implode( ' ', array_map( 'strval', $parts ) ) ) ) );
	}

	public static function check_address_mismatch( WC_Order $order ): bool {
		$billing  = self::norm( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_billing_postcode(), $order->get_billing_state() ) );
		$shipping = self::norm( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city(), $order->get_shipping_postcode(), $order->get_shipping_state() ) );
		return '' !== $billing && '' !== $shipping && $billing !== $shipping;
	}

	public static function check_country_mismatch( WC_Order $order ): bool {
		$b = strtoupper( $order->get_billing_country() );
		$s = strtoupper( $order->get_shipping_country() );
		return '' !== $b && '' !== $s && $b !== $s;
	}

	public static function check_risky_country( WC_Order $order ): bool {
		$list = array_map( 'strtoupper', self::list_option( 'eru_ors_risky_countries' ) );
		if ( ! $list ) {
			return false;
		}
		return in_array( strtoupper( $order->get_billing_country() ), $list, true )
			|| in_array( strtoupper( $order->get_shipping_country() ), $list, true );
	}

	public static function check_manual_payment( WC_Order $order ): bool {
		$gateways  = self::list_option( 'eru_ors_manual_gateways' );
		$threshold = (float) get_option( 'eru_ors_manual_amount', 500 );
		return in_array( mb_strtolower( (string) $order->get_payment_method() ), $gateways, true )
			&& $threshold > 0 && (float) $order->get_total() >= $threshold;
	}

	/* ---------- Hesaplama ---------- */

	/**
	 * @return array{score:int,band:string,signals:array<string,int>}
	 */
	public static function score_order( WC_Order $order, bool $force = false ): array {
		if ( ! $force && $order->get_meta( '_eru_ors_calculated_at' ) ) {
			return array(
				'score'   => (int) $order->get_meta( '_eru_ors_score' ),
				'band'    => (string) $order->get_meta( '_eru_ors_band' ),
				'signals' => (array) $order->get_meta( '_eru_ors_signals' ),
			);
		}

		$total    = 0;
		$detected = array();
		foreach ( self::signals() as $key => $def ) {
			$weight = self::weight_for( $key, $def );
			if ( $weight <= 0 || ! is_callable( $def['check'] ?? null ) ) {
				continue;
			}
			if ( call_user_func( $def['check'], $order ) ) {
				$detected[ $key ] = $weight;
				$total           += $weight;
			}
		}

		$score = (int) apply_filters( 'eru_ors_score', max( 0, min( 100, $total ) ), $order, $detected );
		$score = max( 0, min( 100, $score ) );
		$band  = self::band_for_score( $score );

		$order->update_meta_data( '_eru_ors_score', $score );
		$order->update_meta_data( '_eru_ors_band', $band );
		$order->update_meta_data( '_eru_ors_signals', $detected );
		$order->update_meta_data( '_eru_ors_calculated_at', current_time( 'mysql' ) );
		$order->save();

		do_action( 'eru_ors_scored', $order, $score, $band, $detected );

		return array( 'score' => $score, 'band' => $band, 'signals' => $detected );
	}

	public static function on_checkout_processed( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::score_order( $order );
		}
	}

	public static function on_order( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::score_order( $order );
		}
	}

	/** Boş (henüz doldurulmamış) siparişleri atla; doldurulmuşsa puanla. */
	public static function on_new_order( $order_id, $order = null ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order && '' !== $order->get_billing_email() ) {
			self::score_order( $order );
		}
	}
}
