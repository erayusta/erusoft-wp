<?php
/**
 * Timed cart coupon: a real, per-session WooCommerce coupon that expires server-side.
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The countdown shows the remaining time of the coupon itself (its date_expires). After expiry WooCommerce rejects the
 * coupon and this session gets no new one until the cool-down ends. There is no client-side timer that could be reset.
 */
class Eru_CB_Timed {

	const SESSION_COUPON   = 'eru_cb_timed_coupon';
	const SESSION_COOLDOWN = 'eru_cb_timed_cooldown';
	const USER_COOLDOWN    = '_eru_cb_timed_cooldown';

	/** Hooks. */
	public static function init() {
		add_action( 'wc_ajax_eru_cb_claim', array( __CLASS__, 'ajax' ) );
	}

	/** @return string Transient key of the per-IP hourly claim counter. */
	public static function rl_key() {
		return 'eru_cb_trl_' . substr( Eru_CB_Coupons::ip_hash(), 0, 32 );
	}

	/** @return string Transient key of the global hourly claim counter. */
	public static function global_key() {
		return 'eru_cb_tgl';
	}

	/** @return int Unix time until which this visitor gets no new coupon. */
	private static function cooldown_until() {
		$until = (int) WC()->session->get( self::SESSION_COOLDOWN, 0 );
		if ( is_user_logged_in() ) {
			$until = max( $until, (int) get_user_meta( get_current_user_id(), self::USER_COOLDOWN, true ) );
		}
		return $until;
	}

	/**
	 * Current state for this session.
	 *
	 * @return array{state:string,code?:string,expires?:int,remaining?:int,applied?:bool} state: none|offer|active|expired.
	 */
	public static function state() {
		if ( ! Eru_CB_Settings::is_on( 'tc_enabled' ) || ! WC()->session || ! WC()->cart ) {
			return array( 'state' => 'none' );
		}
		$now = time();
		$rec = WC()->session->get( self::SESSION_COUPON );
		$cd  = self::cooldown_until();
		if ( is_array( $rec ) && ! empty( $rec['code'] ) ) {
			$id     = wc_get_coupon_id_by_code( $rec['code'] );
			$coupon = $id ? new WC_Coupon( $id ) : null;
			$exp    = ( $coupon && $coupon->get_date_expires() ) ? $coupon->get_date_expires()->getTimestamp() : 0;
			if ( $exp > $now ) {
				return array(
					'state'     => 'active',
					'code'      => $rec['code'],
					'expires'   => $exp,
					'remaining' => $exp - $now,
					'applied'   => WC()->cart->has_discount( $rec['code'] ),
				);
			}
			if ( $now < $cd ) {
				return array( 'state' => 'expired', 'code' => $rec['code'] );
			}
			WC()->session->set( self::SESSION_COUPON, null );
		}
		if ( $now < $cd ) {
			return array( 'state' => 'none' );
		}
		$min = (float) Eru_CB_Settings::get( 'tc_min_cart' );
		return ( ! WC()->cart->is_empty() && Eru_CB_Shipping::cart_amount() >= $min ) ? array( 'state' => 'offer' ) : array( 'state' => 'none' );
	}

	/** @return array{ok:bool,reason:string,code?:string,expires?:int} Create (or re-apply) this session's coupon. */
	public static function claim() {
		$st = self::state();
		if ( 'active' === $st['state'] ) {
			if ( ! $st['applied'] ) {
				WC()->cart->apply_coupon( $st['code'] );
			}
			return array( 'ok' => true, 'reason' => 'active', 'code' => $st['code'], 'expires' => $st['expires'] );
		}
		if ( 'expired' === $st['state'] || self::cooldown_until() > time() ) {
			return array( 'ok' => false, 'reason' => 'cooldown' );
		}
		if ( 'offer' !== $st['state'] ) {
			return array( 'ok' => false, 'reason' => 'min' );
		}
		$key = self::rl_key();
		$n   = (int) get_transient( $key );
		if ( $n >= (int) Eru_CB_Settings::get( 'tc_ip_hourly' ) ) {
			return array( 'ok' => false, 'reason' => 'rate' );
		}
		if ( ! Eru_CB_Coupons::global_allow( self::global_key() ) ) {
			return array( 'ok' => false, 'reason' => 'busy' );
		}
		$expires = time() + (int) Eru_CB_Settings::get( 'tc_validity' ) * MINUTE_IN_SECONDS;
		$made    = Eru_CB_Coupons::create(
			array(
				'prefix'   => Eru_CB_Settings::get( 'tc_prefix' ),
				'type'     => Eru_CB_Settings::get( 'tc_type' ),
				'amount'   => Eru_CB_Settings::get( 'tc_amount' ),
				'min_cart' => Eru_CB_Settings::get( 'tc_min_cart' ),
				'expires'  => $expires,
			)
		);
		if ( ! $made ) {
			return array( 'ok' => false, 'reason' => 'error' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		$until = $expires + (int) Eru_CB_Settings::get( 'tc_cooldown_hours' ) * HOUR_IN_SECONDS;
		if ( ! headers_sent() && ! defined( 'WP_CLI' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->session->set( self::SESSION_COUPON, array( 'code' => $made['code'] ) );
		WC()->session->set( self::SESSION_COOLDOWN, $until );
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_COOLDOWN, $until );
		}
		WC()->cart->apply_coupon( $made['code'] );
		return array( 'ok' => true, 'reason' => 'new', 'code' => $made['code'], 'expires' => $expires );
	}

	/** wc-ajax handler. */
	public static function ajax() {
		nocache_headers();
		if ( ! check_ajax_referer( 'eru_cb', 'nonce', false ) ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'Oturum doğrulanamadı, sayfayı yenileyin.', 'conversion-boosters-for-woocommerce' ) ), 403 );
		}
		$r        = self::claim();
		$messages = array(
			'cooldown' => __( 'Bu oturum için kupon süresi doldu; yeni kupon daha sonra sunulacak.', 'conversion-boosters-for-woocommerce' ),
			'min'      => __( 'Sepet tutarı kupon için yeterli değil.', 'conversion-boosters-for-woocommerce' ),
			'busy'     => __( 'Şu anda çok fazla istek var, lütfen daha sonra tekrar deneyin.', 'conversion-boosters-for-woocommerce' ),
			'rate'     => __( 'Çok fazla deneme yapıldı, lütfen daha sonra tekrar deneyin.', 'conversion-boosters-for-woocommerce' ),
			'error'    => __( 'Kupon oluşturulamadı, lütfen tekrar deneyin.', 'conversion-boosters-for-woocommerce' ),
		);
		$r['message'] = $r['ok'] ? '' : ( $messages[ $r['reason'] ] ?? $messages['error'] );
		wp_send_json( $r );
	}

	/** @return string Box HTML for the current state ('' when nothing should be shown). */
	public static function box_html() {
		$st = self::state();
		if ( 'none' === $st['state'] ) {
			return '';
		}
		$amount = 'percent' === Eru_CB_Settings::get( 'tc_type' )
			? '%' . wc_format_localized_decimal( Eru_CB_Settings::get( 'tc_amount' ) )
			: wp_strip_all_tags( wc_price( (float) Eru_CB_Settings::get( 'tc_amount' ) ) );
		$mins   = (int) Eru_CB_Settings::get( 'tc_validity' );
		if ( 'offer' === $st['state'] ) {
			return '<div class="eru-cb-timed is-offer"><p class="eru-cb-timed__text">'
				/* translators: 1: discount, 2: minutes */
				. esc_html( sprintf( __( 'Sepetinize özel %1$s indirim kuponu alın. Kupon %2$d dakika geçerlidir.', 'conversion-boosters-for-woocommerce' ), $amount, $mins ) )
				. '</p><button type="button" class="button eru-cb-timed__claim" data-eru-cb-claim>' . esc_html__( 'Kuponu al', 'conversion-boosters-for-woocommerce' ) . '</button><p class="eru-cb-timed__err" role="alert"></p></div>';
		}
		if ( 'active' === $st['state'] ) {
			$mm = str_pad( (string) intdiv( $st['remaining'], 60 ), 2, '0', STR_PAD_LEFT ) . ':' . str_pad( (string) ( $st['remaining'] % 60 ), 2, '0', STR_PAD_LEFT );
			return '<div class="eru-cb-timed is-active" data-remaining="' . (int) $st['remaining'] . '"><p class="eru-cb-timed__text">'
				. ( $st['applied']
					/* translators: %s: coupon code */
					? sprintf( esc_html__( '%s kuponu sepetinize uygulandı.', 'conversion-boosters-for-woocommerce' ), '<strong>' . esc_html( $st['code'] ) . '</strong>' )
					/* translators: %s: coupon code */
					: sprintf( esc_html__( '%s kuponunuz hâlâ geçerli.', 'conversion-boosters-for-woocommerce' ), '<strong>' . esc_html( $st['code'] ) . '</strong>' ) )
				. ' <span class="eru-cb-timed__left">' . esc_html__( 'Kalan süre:', 'conversion-boosters-for-woocommerce' ) . ' <span class="eru-cb-timed__clock" role="timer">' . esc_html( $mm ) . '</span></span></p>'
				. ( $st['applied'] ? '' : '<button type="button" class="button eru-cb-timed__claim" data-eru-cb-claim>' . esc_html__( 'Kuponu uygula', 'conversion-boosters-for-woocommerce' ) . '</button><p class="eru-cb-timed__err" role="alert"></p>' )
				. '</div>';
		}
		return '<div class="eru-cb-timed is-expired"><p class="eru-cb-timed__text">'
			. esc_html__( 'Kupon süresi doldu.', 'conversion-boosters-for-woocommerce' ) . '</p></div>';
	}
}
