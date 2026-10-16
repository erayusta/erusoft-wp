<?php
/**
 * Store settings in the `eru_ce_settings` option (autoload no).
 *
 * Keys and defaults:
 *  - stacking                   best | first | stack (how campaigns share a line);
 *  - max_cart_discount_percent  cap on the whole cart's campaign discount, '' = none;
 *  - min_unit_price_minor       no unit is pushed below this price;
 *  - with_coupons               'yes' = campaigns still apply when a coupon is in the cart;
 *  - reward_maturation_days     0 / 7 / 14 / 30 days between payment and the reward coupon;
 *  - reward_coupon_days         coupon lifetime when the campaign gives none;
 *  - reward_refund_policy       full (any refund cancels the reward) | pro_rata;
 *  - reservation_mode           reserve (hold a use at checkout) | paid_only (count paid orders only);
 *  - reservation_ttl_hours      an unpaid "pending" order gives its reserved use back after this;
 *  - cache_adaptor              none | litespeed | custom (fires `eru_ce_cache_purge`);
 *  - retention_days             e-mails, hashes and audit rows of closed records are removed after this.
 *
 * Writes go through update(), which validates the engine values with the engine's own readers. A
 * stored value that still fails (edited by hand, older build) never breaks a cart: engineSettings()
 * falls back field by field to the safest value and reports it through ConfigErrors.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Settings;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Settings;
use Erusoft\CampaignEngine\Infrastructure\ConfigErrors;
use WP_Error;

final class Options {

	public const OPTION = 'eru_ce_settings';

	/** Engine values of the last successful save, the fallback when the stored ones cannot be read. */
	public const LAST_GOOD_OPTION = 'eru_ce_settings_last_good';

	/**
	 * Cart cap used when the stored cap cannot be read. A cap the merchant set must never turn into
	 * "no cap", so an unreadable one becomes this.
	 */
	public const SAFE_CART_CAP = '50';

	public const MATURATION_DAYS = array( 0, 7, 14, 30 );

	private static ?array $cache = null;
	private static ?Settings $engine = null;
	private static bool $corrupt = false;

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'stacking'                  => 'best',
			'max_cart_discount_percent' => '',
			'min_unit_price_minor'      => 0,
			'with_coupons'              => 'yes',
			'reward_maturation_days'    => 14,
			'reward_coupon_days'        => 30,
			'reward_refund_policy'      => 'pro_rata',
			'reservation_mode'          => 'reserve',
			'reservation_ttl_hours'     => 24,
			'cache_adaptor'             => 'none',
			'retention_days'            => 365,
		);
	}

	/** @return array<string,mixed> stored values over the defaults */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored        = get_option( self::OPTION, null );
			self::$corrupt = null !== $stored && false !== $stored && ! is_array( $stored );
			self::$cache   = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return self::$cache;
	}

	/** @return mixed */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function flush(): void {
		self::$cache  = null;
		self::$engine = null;
	}

	/**
	 * Validates and merges $values into the stored settings.
	 *
	 * @param array<string,mixed> $values
	 * @return true|WP_Error error data: field, reason
	 */
	public static function update( array $values ) {
		$stored = get_option( self::OPTION, array() );
		$next   = array_merge( is_array( $stored ) ? $stored : array(), $values );
		$merged = array_merge( self::defaults(), $next );

		if ( null === self::flagOf( $merged['with_coupons'] ) ) {
			return self::error( 'with_coupons', 'invalid' );
		}

		try {
			$engine = Settings::fromArray( self::engineRow( $merged ) );
		} catch ( ArgumentError $e ) {
			return self::error( $e->field, $e->reason );
		}

		$checks = array(
			'reward_maturation_days' => static fn( $v ): bool => in_array( (int) $v, self::MATURATION_DAYS, true ) && is_numeric( $v ),
			'reward_coupon_days'     => static fn( $v ): bool => is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 3650,
			'reward_refund_policy'   => static fn( $v ): bool => in_array( $v, array( 'full', 'pro_rata' ), true ),
			'reservation_mode'       => static fn( $v ): bool => in_array( $v, array( 'reserve', 'paid_only' ), true ),
			'reservation_ttl_hours'  => static fn( $v ): bool => is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 720,
			'cache_adaptor'          => static fn( $v ): bool => in_array( $v, array( 'none', 'litespeed', 'custom' ), true ),
			'retention_days'         => static fn( $v ): bool => is_numeric( $v ) && (int) $v == $v && (int) $v >= 30 && (int) $v <= 3650, // phpcs:ignore Universal.Operators.StrictComparisons
		);

		foreach ( $checks as $key => $ok ) {
			if ( ! $ok( $merged[ $key ] ) ) {
				return self::error( $key, 'invalid' );
			}
		}

		$clean = array_intersect_key( $next, self::defaults() );
		update_option( self::OPTION, $clean, false );
		update_option(
			self::LAST_GOOD_OPTION,
			array(
				'stacking'                  => $engine->stacking,
				'max_cart_discount_percent' => (string) $engine->max_cart_discount_percent,
				'min_unit_price_minor'      => $engine->min_unit_price_minor,
			),
			false
		);
		self::flush();
		ConfigErrors::clear( 'settings' );

		return true;
	}

	/**
	 * Engine settings. A stored value that cannot be read never throws here and never fails open; each
	 * field falls back on its own and the problem is recorded once for the admin notice:
	 *  - stacking         the last saved value, else "best";
	 *  - cart cap         the last saved cap when it was a real cap, else SAFE_CART_CAP (never "no cap");
	 *  - price floor      the last saved floor; with none on record the cart cap is forced down to
	 *                     SAFE_CART_CAP instead, so the discount stays bounded;
	 *  - with_coupons     false (campaigns do not stack with WooCommerce coupons);
	 *  - a corrupted (non-array) option counts as every field unreadable.
	 */
	public static function engineSettings(): Settings {
		if ( null !== self::$engine ) {
			return self::$engine;
		}

		$all  = self::all();
		$row  = self::engineRow( $all );
		$with = self::flagOf( $all['with_coupons'] ?? 'yes' );

		if ( ! self::$corrupt && null !== $with ) {
			try {
				self::$engine = Settings::fromArray( $row );

				return self::$engine;
			} catch ( ArgumentError $e ) {
				unset( $e );
			}
		}

		$last = get_option( self::LAST_GOOD_OPTION, array() );
		$last = is_array( $last ) ? $last : array();

		if ( self::$corrupt ) {
			ConfigErrors::record( 'settings', 0, self::OPTION, 'invalid' );
		}

		$read = static function ( callable $reader ) {
			if ( self::$corrupt ) {
				return array( false, null );
			}

			try {
				return array( true, $reader() );
			} catch ( ArgumentError $e ) {
				ConfigErrors::record( 'settings', 0, $e->field, $e->reason );

				return array( false, null );
			}
		};

		list( $ok, $stacking ) = $read( static fn() => Args::choice( $row, 'stacking', Settings::STACKING, 'best' ) );

		if ( ! $ok ) {
			$stacking = in_array( $last['stacking'] ?? null, Settings::STACKING, true ) ? $last['stacking'] : 'best';
		}

		$raw_cap          = $row['max_cart_discount_percent'] ?? null;
		list( $ok, $cap ) = $read( static fn() => null === $raw_cap || '' === $raw_cap ? null : Args::percent( $raw_cap, 'max_cart_discount_percent' ) );

		if ( ! $ok ) {
			$cap = self::validCap( $last['max_cart_discount_percent'] ?? null ) ?? self::SAFE_CART_CAP;
		}

		$raw_floor          = $row['min_unit_price_minor'] ?? null;
		list( $ok, $floor ) = $read( static fn() => null === $raw_floor || '' === $raw_floor ? 0 : Args::minor( $raw_floor, 'min_unit_price_minor' ) );

		if ( ! $ok ) {
			$kept = $last['min_unit_price_minor'] ?? null;

			if ( is_int( $kept ) || ( is_string( $kept ) && ctype_digit( $kept ) ) ) {
				$floor = (int) $kept;
			} else {
				// No floor on record: bound the discount through the cart cap instead.
				$floor = 0;
				$cap   = null === $cap || (float) $cap > (float) self::SAFE_CART_CAP ? self::SAFE_CART_CAP : $cap;
			}
		}

		if ( null === $with ) {
			ConfigErrors::record( 'settings', 0, 'with_coupons', 'invalid' );
		}

		self::$engine = new Settings( (string) $stacking, null === $cap ? null : (string) $cap, (int) $floor, self::$corrupt ? false : ( $with ?? false ) );

		return self::$engine;
	}

	/** A strict yes/no reading; null when the value is neither. */
	private static function flagOf( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( in_array( $value, array( 'yes', '1', 1, 'true' ), true ) ) {
			return true;
		}

		if ( in_array( $value, array( 'no', '0', 0, 'false', '' ), true ) ) {
			return false;
		}

		return null;
	}

	private static function validCap( $cap ): ?string {
		if ( null === $cap || '' === $cap ) {
			return null;
		}

		try {
			return Args::percent( $cap, 'max_cart_discount_percent' );
		} catch ( ArgumentError $e ) {
			return null;
		}
	}

	public static function maturationDays(): int {
		$days = (int) self::get( 'reward_maturation_days' );

		return in_array( $days, self::MATURATION_DAYS, true ) ? $days : 14;
	}

	public static function couponDays(): int {
		$days = (int) self::get( 'reward_coupon_days' );

		return $days >= 1 && $days <= 3650 ? $days : 30;
	}

	public static function refundPolicy(): string {
		return 'full' === self::get( 'reward_refund_policy' ) ? 'full' : 'pro_rata';
	}

	/** Days the plugin keeps e-mails, hashes and audit rows of closed records (30–3650, default 365). */
	public static function retentionDays(): int {
		$days = (int) self::get( 'retention_days' );

		return $days >= 30 && $days <= 3650 ? $days : 365;
	}

	public static function reserves(): bool {
		return 'paid_only' !== self::get( 'reservation_mode' );
	}

	public static function reservationTtl(): int {
		$hours = (int) self::get( 'reservation_ttl_hours' );

		return ( $hours >= 1 && $hours <= 720 ? $hours : 24 ) * HOUR_IN_SECONDS;
	}

	/** @param array<string,mixed> $all */
	private static function engineRow( array $all ): array {
		return array(
			'stacking'                  => $all['stacking'] ?? 'best',
			'max_cart_discount_percent' => $all['max_cart_discount_percent'] ?? '',
			'min_unit_price_minor'      => $all['min_unit_price_minor'] ?? 0,
			'with_coupons'              => self::flagOf( $all['with_coupons'] ?? 'yes' ) ?? false,
		);
	}

	private static function error( string $field, string $reason ): WP_Error {
		return new WP_Error(
			'eru_ce_invalid_setting',
			/* translators: 1: field name, 2: reason code */
			sprintf( __( 'Ayar kaydedilmedi: "%1$s" alanı geçersiz (%2$s).', 'campaign-engine-for-woocommerce' ), $field, $reason ),
			array(
				'field'  => $field,
				'reason' => $reason,
			)
		);
	}
}
