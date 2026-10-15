<?php
/**
 * Unique, expiring, single-use coupons and their daily clean-up.
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generator shared by the newsletter and the timed cart coupon.
 */
class Eru_CB_Coupons {

	const META = '_eru_cb_generated';
	const HOOK = 'eru_cb_daily';

	/** Hooks. */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'eru_cb_cleanup_more', array( __CLASS__, 'cleanup' ) );
		add_action( 'eru_cb_purge_more', array( 'Eru_CB_Newsletter', 'purge' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
	}

	/** Daily job: coupon clean-up and subscriber retention. */
	public static function daily() {
		self::cleanup();
		Eru_CB_Newsletter::purge();
		if ( ! Eru_CB_Newsletter::any_subscribers() ) {
			delete_option( 'eru_cb_has_data' );
		}
	}

	/** @return int Batch size for clean-up and purge runs. */
	public static function batch_size() {
		return max( 1, (int) apply_filters( 'eru_cb_batch_size', 200 ) );
	}

	/**
	 * Queue a follow-up run of a draining job. The check is pending-only: `as_has_scheduled_action()` also counts the
	 * action that is running right now, so a job re-arming itself would never queue its next batch.
	 *
	 * @param string $hook Action hook.
	 */
	public static function rearm( $hook ) {
		if ( function_exists( 'as_schedule_single_action' ) && ! self::has_pending( $hook ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, $hook, array(), 'eru-cb' );
		}
	}

	/** @param string $hook Action hook. @return bool A PENDING action exists for the hook. */
	public static function has_pending( $hook ) {
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => 'eru-cb',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/**
	 * Circuit breaker for distributed abuse: counts one event and tells whether the global hourly cap still allows it.
	 *
	 * @param string $key Transient key. @return bool
	 */
	public static function global_allow( $key ) {
		$n = (int) get_transient( $key );
		if ( $n >= (int) Eru_CB_Settings::get( 'global_hourly' ) ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * The daily job runs while a coupon module is on or while subscriber data exists (retention must run with modules off).
	 * On `init` it does nothing unless one of those holds, so an inert install makes no queries; settings saves pass $full.
	 *
	 * @param bool $full Also unschedule when no longer needed.
	 */
	public static function maybe_schedule( $full = false ) {
		$need = Eru_CB_Settings::coupons_on() || (bool) get_option( 'eru_cb_has_data' );
		if ( ! $need && ! $full ) {
			return;
		}
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$has = as_has_scheduled_action( self::HOOK, array(), 'eru-cb' );
		if ( $need && ! $has ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), 'eru-cb' );
		} elseif ( ! $need && $has ) {
			as_unschedule_all_actions( self::HOOK, array(), 'eru-cb' );
		}
	}

	/**
	 * Create one coupon. The code is always new (`<prefix><6 chars>`), never an existing code.
	 *
	 * @param array $a prefix, type, amount, min_cart, expires (unix), email (optional restriction).
	 * @return array|null code, expires and coupon id, or null if no free code was found.
	 */
	public static function create( array $a ) {
		$code = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$try = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $a['prefix'] . wp_generate_password( 6, false, false ) ) );
			if ( ! wc_get_coupon_id_by_code( $try ) ) {
				$code = $try;
				break;
			}
		}
		if ( '' === $code ) {
			return null;
		}
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' === $a['type'] ? 'fixed_cart' : 'percent' );
		$coupon->set_amount( (string) $a['amount'] );
		$coupon->set_date_expires( (int) $a['expires'] );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );
		if ( (float) $a['min_cart'] > 0 ) {
			$coupon->set_minimum_amount( (string) $a['min_cart'] );
		}
		if ( ! empty( $a['email'] ) ) {
			$coupon->set_email_restrictions( array( $a['email'] ) );
		}
		$coupon->add_meta_data( self::META, 1, true );
		$id = $coupon->save();
		return array( 'code' => $code, 'expires' => (int) $a['expires'], 'id' => $id );
	}

	/** Delete one batch of expired, unused, generated coupons; re-arms itself while more remain. */
	public static function cleanup() {
		$size = self::batch_size();
		$ids  = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => $size,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array( 'key' => self::META, 'value' => '1' ),
					array( 'key' => 'date_expires', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC' ),
					array( 'key' => 'usage_count', 'value' => '0', 'compare' => '=', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( count( $ids ) >= $size ) {
			self::rearm( 'eru_cb_cleanup_more' );
		}
	}

	/** Visitor IP. Default REMOTE_ADDR; behind a proxy return the real address with `eru_cb_client_ip`. */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for rate limits.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'eru_cb_client_ip', $ip );
	}

	/** Salted hash of the visitor IP; the raw address is never stored. */
	public static function ip_hash() {
		return hash_hmac( 'sha256', self::client_ip(), wp_salt( 'auth' ) );
	}

	/** Whether the request comes from this site (Origin, else Referer). Missing headers fail. */
	public static function same_origin() {
		$src = '';
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$src = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$src = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		$host = wp_parse_url( $src, PHP_URL_HOST );
		return $host && 0 === strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Softer check for requests that already carry a secret (the confirm token): refuse only when
	 * the browser names a different site. Absent headers or `Origin: null` (no-referrer policies) pass.
	 */
	public static function not_cross_origin() {
		$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $key ) {
			$value = isset( $_SERVER[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) : '';
			$host  = ( '' === $value || 'null' === strtolower( $value ) ) ? '' : (string) wp_parse_url( $value, PHP_URL_HOST );
			if ( '' !== $host && 0 !== strcasecmp( $host, $own ) ) {
				return false;
			}
		}
		return true;
	}
}
