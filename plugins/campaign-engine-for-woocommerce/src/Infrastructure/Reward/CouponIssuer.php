<?php
/**
 * Turns a matured reward into a WooCommerce coupon: fixed cart amount, one use, bound to the customer's
 * e-mail, with an expiry date (the campaign's own validity, otherwise the `reward_coupon_days` setting),
 * the campaign's minimum cart and categories when given.
 *
 * The coupon carries `_eru_ce_reward_id`; issuing looks that up first, so a run that died after saving
 * the coupon but before updating the reward reuses the coupon instead of creating a second one.
 * Coupons are never deleted: a reversed reward's coupon is disabled (draft, expired today).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Reward;

use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use WC_Coupon;

final class CouponIssuer {

	public const REWARD_META = '_eru_ce_reward_id';
	private const ALPHABET   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * @param array<string,mixed> $reward reward row
	 * @return array{coupon_id:int,code:string,expires:int}|null
	 */
	public static function issue( array $reward ): ?array {
		$existing = self::find( (int) $reward['id'] );

		if ( $existing ) {
			$expires = $existing->get_date_expires();

			return array(
				'coupon_id' => $existing->get_id(),
				'code'      => $existing->get_code(),
				'expires'   => $expires ? $expires->getTimestamp() : 0,
			);
		}

		$code = self::code();

		if ( '' === $code ) {
			return null;
		}

		$days     = (int) $reward['valid_days'] > 0 ? (int) $reward['valid_days'] : Options::couponDays();
		$expires  = time() + $days * DAY_IN_SECONDS;
		$decimals = wc_get_price_decimals();
		$coupon   = new WC_Coupon();

		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( Money::toDecimal( (int) $reward['amount_minor'], $decimals ) );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( false );
		$coupon->set_date_expires( $expires );
		/* translators: %d: reward id */
		$coupon->set_description( sprintf( __( 'Kampanya ödülü #%d', 'campaign-engine-for-woocommerce' ), (int) $reward['id'] ) );

		if ( '' !== (string) $reward['email'] ) {
			$coupon->set_email_restrictions( array( (string) $reward['email'] ) );
		}

		if ( (int) $reward['min_cart_minor'] > 0 ) {
			$coupon->set_minimum_amount( Money::toDecimal( (int) $reward['min_cart_minor'], $decimals ) );
		}

		$categories = json_decode( (string) $reward['category_ids'], true );

		if ( is_array( $categories ) && array() !== $categories ) {
			$coupon->set_product_categories( array_map( 'intval', $categories ) );
		}

		$coupon->update_meta_data( self::REWARD_META, (int) $reward['id'] );
		$id = $coupon->save();

		if ( ! $id ) {
			return null;
		}

		return array(
			'coupon_id' => (int) $id,
			'code'      => $code,
			'expires'   => $expires,
		);
	}

	/** Lowers the amount of an unused reward coupon. */
	public static function setAmount( int $coupon_id, int $amount_minor ): void {
		$coupon = new WC_Coupon( $coupon_id );

		if ( $coupon->get_id() > 0 ) {
			$coupon->set_amount( Money::toDecimal( max( 0, $amount_minor ), wc_get_price_decimals() ) );
			$coupon->save();
		}
	}

	/** Disables a coupon without deleting it: not published, expired now, marked. */
	public static function disable( int $coupon_id ): void {
		$coupon = new WC_Coupon( $coupon_id );

		if ( $coupon->get_id() <= 0 ) {
			return;
		}

		$coupon->set_date_expires( time() );
		$coupon->update_meta_data( '_eru_ce_disabled', time() );
		$coupon->save();

		wp_update_post(
			array(
				'ID'          => $coupon_id,
				'post_status' => 'draft',
			)
		);
	}

	/** Privacy eraser: the coupon stays tied to nobody (an address that cannot receive mail). */
	public static function anonymise( int $coupon_id ): void {
		$coupon = new WC_Coupon( $coupon_id );

		if ( $coupon->get_id() > 0 && array() !== $coupon->get_email_restrictions() ) {
			$coupon->set_email_restrictions( array( wp_privacy_anonymize_data( 'email', 'x@x' ) ) );
			$coupon->save();
		}
	}

	private static function find( int $reward_id ): ?WC_Coupon {
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::REWARD_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $reward_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array() === $ids ? null : new WC_Coupon( (int) $ids[0] );
	}

	private static function code(): string {
		/**
		 * Filters the reward coupon prefix (letters and digits).
		 *
		 * @param string $prefix Default "ODUL".
		 */
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) apply_filters( 'eru_ce_coupon_prefix', 'ODUL' ) ) );

		for ( $i = 0; $i < 8; $i++ ) {
			$tail = '';

			for ( $j = 0; $j < 8; $j++ ) {
				$tail .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
			}

			$code = ( '' === $prefix ? '' : $prefix . '-' ) . $tail;

			if ( ! wc_get_coupon_id_by_code( $code ) ) {
				return $code;
			}
		}

		return '';
	}
}
