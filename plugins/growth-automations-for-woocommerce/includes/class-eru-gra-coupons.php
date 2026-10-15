<?php
/**
 * Unique, expiring, single-use coupons bound to one e-mail, and their daily clean-up.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coupon generator shared by every automation.
 */
class Eru_GRA_Coupons {

	const META = '_eru_gra_generated';

	/**
	 * Create one coupon. The code is always new (`<PREFIX>-<8 chars>`).
	 *
	 * @param array $a prefix, type, amount, min_cart, expires (unix), email.
	 * @return array|null code, expires, id, or null when no free code was found.
	 */
	public static function create( array $a ) {
		$code = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$try = preg_replace( '/[^A-Z0-9\-]/', '', strtoupper( $a['prefix'] . '-' . wp_generate_password( 8, false, false ) ) );
			/**
			 * Filters the candidate coupon code (tests and custom schemes). A code that already exists is never used.
			 *
			 * @param string $try Candidate.
			 */
			$try = (string) apply_filters( 'eru_gra_coupon_candidate', $try );
			if ( '' !== $try && ! wc_get_coupon_id_by_code( $try ) ) {
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
		if ( (float) $a['min_cart'] > 0 ) {
			$coupon->set_minimum_amount( (string) $a['min_cart'] );
		}
		if ( ! empty( $a['email'] ) ) {
			$coupon->set_email_restrictions( array( $a['email'] ) );
		}
		$coupon->add_meta_data( self::META, 1, true );
		$id = $coupon->save();
		if ( ! $id ) {
			return null;
		}
		Eru_GRA_DB::flag_data(); // The daily clean-up keeps running even if the automation is switched off later.
		return array( 'code' => $code, 'expires' => (int) $a['expires'], 'id' => $id );
	}

	/** Delete one batch of expired, unused, generated coupons; re-arms itself while more remain. */
	public static function cleanup() {
		$size = Eru_GRA_Settings::batch_size();
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
			Eru_GRA_Runner::rearm_hook( 'eru_gra_cleanup_more', array() );
		}
	}
}
