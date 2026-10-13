<?php
/** Generated single-use coupons: REST endpoint, rate limit, daily budget, clean-up. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_EIC_Coupon {

	const META  = '_eru_eic_generated';
	const STATS = 'eru_eic_stats';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'eru_eic_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/** Daily cleanup of expired generated coupons: scheduled only while the popup is enabled. */
	public static function schedule(): void {
		$on   = ! empty( Eru_EIC_Settings::get()['enabled'] );
		$next = wp_next_scheduled( 'eru_eic_cleanup' );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'eru_eic_cleanup' );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( 'eru_eic_cleanup' );
		}
	}

	public static function routes(): void {
		register_rest_route(
			'eru-eic/v1',
			'/coupon',
			array(
				'methods'             => 'POST',
				// Public on purpose: visitors are anonymous. Abuse is bounded by the per-IP hourly limit and the daily budget.
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_issue' ),
			)
		);
	}

	public static function rest_issue( WP_REST_Request $request ): WP_REST_Response {
		$s = Eru_EIC_Settings::get();
		if ( empty( $s['enabled'] ) || 'generated' !== $s['mode'] ) {
			return self::respond( array( 'ok' => false ), 404 );
		}
		if ( ! self::within_ip_limit( (int) $s['ip_hourly'] ) ) {
			return self::respond( array( 'ok' => false, 'reason' => 'rate' ), 429 );
		}
		$issued = self::issue( $s );
		if ( ! $issued ) {
			return self::respond( array( 'ok' => false, 'reason' => 'budget' ), 200 );
		}
		return self::respond( array( 'ok' => true ) + $issued, 200 );
	}

	private static function respond( array $data, int $status ): WP_REST_Response {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store, max-age=0' );
		return $r;
	}

	/** Visitor IP for the rate limit. Default REMOTE_ADDR; behind a proxy/CDN return the real address with `eru_eic_client_ip`. */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for rate limiting.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'eru_eic_client_ip', $ip );
	}

	private static function within_ip_limit( int $max ): bool {
		$key = 'eru_eic_rl_' . md5( self::client_ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/** @return array{used:int,date:string,total:int} */
	public static function stats(): array {
		$st = get_option( self::STATS, array() );
		$st = wp_parse_args( is_array( $st ) ? $st : array(), array( 'date' => '', 'used' => 0, 'total' => 0 ) );
		if ( wp_date( 'Y-m-d' ) !== $st['date'] ) {
			$st['date'] = wp_date( 'Y-m-d' );
			$st['used'] = 0;
		}
		return $st;
	}

	/**
	 * Create one coupon if today's budget allows it.
	 *
	 * @return array<string,mixed>|null code, expires (unix) and amount label, or null when the budget is used up.
	 */
	public static function issue( array $s ): ?array {
		$st = self::stats();
		if ( (int) $s['daily_limit'] > 0 && $st['used'] >= (int) $s['daily_limit'] ) {
			return null;
		}
		$code = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$code = $s['prefix'] . strtoupper( wp_generate_password( 6, false, false ) );
			$code = preg_replace( '/[^A-Z0-9]/', '', $code );
			if ( ! wc_get_coupon_id_by_code( $code ) ) {
				break;
			}
			$code = '';
		}
		if ( '' === $code ) {
			return null;
		}
		$expires = time() + (int) $s['valid_hours'] * HOUR_IN_SECONDS;
		$coupon  = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' === $s['discount_type'] ? 'fixed_cart' : 'percent' );
		$coupon->set_amount( (string) $s['amount'] );
		$coupon->set_date_expires( $expires );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );
		if ( (float) $s['min_spend'] > 0 ) {
			$coupon->set_minimum_amount( (string) $s['min_spend'] );
		}
		$coupon->add_meta_data( self::META, 1, true );
		$coupon->save();

		$st['used']++;
		$st['total']++;
		update_option( self::STATS, $st, false );

		return array( 'code' => $code, 'expires' => $expires );
	}

	/** Delete generated coupons that expired more than a day ago (at most 200 per run). */
	public static function cleanup(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array( 'key' => self::META, 'value' => '1' ),
					array( 'key' => 'date_expires', 'value' => time() - DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
