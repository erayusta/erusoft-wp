<?php
/**
 * Turkish sentences built from engine data: what a campaign gives, an offer ("1 adet daha ekleyin…"),
 * amounts in the store currency. The engine builds no text; the admin screens and the shop front use
 * these, so a campaign reads the same everywhere.
 *
 * Only facts go into these sentences: the campaign's own reward, the real missing amount, the real end
 * time. No stock counts, no visitor counts, no invented deadlines.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Engine\Offer\Offer;

final class Texts {

	/** An amount in minor units as plain text in the store currency ("45,90 ₺"). */
	public static function money( int $minor ): string {
		$decimals = wc_get_price_decimals();

		return html_entity_decode( wp_strip_all_tags( wc_price( (float) Money::toDecimal( $minor, $decimals ), array( 'decimals' => $decimals ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** An amount in minor units as WooCommerce price HTML. */
	public static function moneyHtml( int $minor ): string {
		$decimals = wc_get_price_decimals();

		return wc_price( (float) Money::toDecimal( $minor, $decimals ), array( 'decimals' => $decimals ) );
	}

	/** A percentage string for text ("12,5"). */
	public static function percent( string $pct ): string {
		return str_replace( '.', wc_get_price_decimal_separator(), $pct );
	}

	/** Names of the action types for the admin. */
	public static function typeLabels(): array {
		return array(
			'product_percent' => __( 'Üründe yüzde indirim', 'campaign-engine-for-woocommerce' ),
			'product_fixed'   => __( 'Üründe sabit tutar indirim', 'campaign-engine-for-woocommerce' ),
			'product_price'   => __( 'Kampanya fiyatı', 'campaign-engine-for-woocommerce' ),
			'cart_percent'    => __( 'Sepette yüzde indirim', 'campaign-engine-for-woocommerce' ),
			'cart_fixed'      => __( 'Sepette sabit tutar indirim', 'campaign-engine-for-woocommerce' ),
			'tiered'          => __( 'Kademeli indirim', 'campaign-engine-for-woocommerce' ),
			'set_reward'      => __( 'X al Y öde / N. üründe indirim', 'campaign-engine-for-woocommerce' ),
			'free_shipping'   => __( 'Ücretsiz kargo', 'campaign-engine-for-woocommerce' ),
			'future_reward'   => __( 'Harca kazan (sonraki alışverişe kupon)', 'campaign-engine-for-woocommerce' ),
		);
	}

	/**
	 * A short description of what an action row gives ("%12 indirim", "3 al 2 öde"), '' when unknown.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function action( array $row ): string {
		$type = (string) ( $row['type'] ?? '' );

		switch ( $type ) {
			case 'product_percent':
				/* translators: %s: percentage */
				return sprintf( __( '%%%s indirim', 'campaign-engine-for-woocommerce' ), self::percent( (string) ( $row['percent'] ?? '' ) ) );
			case 'cart_percent':
				/* translators: %s: percentage */
				return sprintf( __( 'Sepette %%%s indirim', 'campaign-engine-for-woocommerce' ), self::percent( (string) ( $row['percent'] ?? '' ) ) );
			case 'product_fixed':
				/* translators: %s: amount */
				return sprintf( __( 'Adet başına %s indirim', 'campaign-engine-for-woocommerce' ), self::money( (int) ( $row['amount'] ?? 0 ) ) );
			case 'cart_fixed':
				/* translators: %s: amount */
				return sprintf( __( 'Sepette %s indirim', 'campaign-engine-for-woocommerce' ), self::money( (int) ( $row['amount'] ?? 0 ) ) );
			case 'product_price':
				/* translators: %s: amount */
				return sprintf( __( 'Kampanya fiyatı %s', 'campaign-engine-for-woocommerce' ), self::money( (int) ( $row['price'] ?? 0 ) ) );
			case 'tiered':
				return self::tiered( $row );
			case 'set_reward':
				return self::set( $row );
			case 'free_shipping':
				return __( 'Ücretsiz kargo', 'campaign-engine-for-woocommerce' );
			case 'future_reward':
				if ( 'percent' === ( $row['reward'] ?? '' ) ) {
					/* translators: %s: percentage */
					return sprintf( __( 'Sonraki alışverişe %%%s değerinde kupon', 'campaign-engine-for-woocommerce' ), self::percent( (string) ( $row['value'] ?? '' ) ) );
				}
				/* translators: %s: amount */
				return sprintf( __( 'Sonraki alışverişe %s kupon', 'campaign-engine-for-woocommerce' ), self::money( (int) ( $row['value'] ?? 0 ) ) );
		}

		return '';
	}

	/**
	 * What a campaign gives: its own badge text, otherwise its first action described, with the cart
	 * threshold it needs ("850,00 ₺ ve üzeri alışverişte ücretsiz kargo"), so a badge never promises
	 * more than the campaign gives.
	 */
	public static function badge( Campaign $campaign ): string {
		$badge = is_string( $campaign->meta['badge'] ?? null ) ? trim( $campaign->meta['badge'] ) : '';

		if ( '' !== $badge ) {
			return $badge;
		}

		$text      = self::action( $campaign->actions[0] ?? array() );
		$threshold = self::threshold( $campaign );

		if ( '' === $text || '' === $threshold ) {
			return $text;
		}

		/* translators: 1: threshold ("850,00 ₺ ve üzeri"), 2: what the campaign gives */
		return sprintf( __( '%1$s alışverişte %2$s', 'campaign-engine-for-woocommerce' ), $threshold, self::lcfirst( $text ) );
	}

	/**
	 * The cart threshold a campaign requires ("850,00 ₺ ve üzeri", "3 adet ve üzeri"), read from an
	 * amount or quantity condition at the root of its rules or under AND; '' when there is none.
	 */
	public static function threshold( Campaign $campaign ): string {
		$node   = $campaign->rules;
		$leaves = array();

		if ( is_array( $node ) && isset( $node['condition'] ) ) {
			$leaves[] = $node;
		} elseif ( is_array( $node ) && 'and' === strtolower( (string) ( $node['op'] ?? '' ) ) ) {
			foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
				if ( is_array( $child ) && isset( $child['condition'] ) ) {
					$leaves[] = $child;
				}
			}
		}

		foreach ( $leaves as $leaf ) {
			$cmp   = (string) ( $leaf['cmp'] ?? '' );
			$value = $leaf['value'] ?? null;

			if ( ! in_array( $cmp, array( '>=', '>' ), true ) || ! is_numeric( $value ) ) {
				continue;
			}

			if ( in_array( $leaf['condition'], array( 'cart.subtotal', 'cart.eligible_subtotal' ), true ) ) {
				/* translators: %s: amount */
				return sprintf( '>=' === $cmp ? __( '%s ve üzeri', 'campaign-engine-for-woocommerce' ) : __( '%s üzeri', 'campaign-engine-for-woocommerce' ), self::money( (int) $value ) );
			}

			if ( in_array( $leaf['condition'], array( 'cart.quantity', 'cart.eligible_quantity' ), true ) ) {
				$units = (int) $value + ( '>' === $cmp ? 1 : 0 );
				/* translators: %d: units */
				return sprintf( __( '%d adet ve üzeri', 'campaign-engine-for-woocommerce' ), $units );
			}
		}

		return '';
	}

	/** @param array<string,mixed> $row */
	private static function set( array $row ): string {
		$size   = (int) ( $row['set_size'] ?? 0 );
		$count  = (int) ( $row['reward_count'] ?? 1 );
		$reward = (string) ( $row['reward'] ?? '' );

		if ( 'free' === $reward && $count > 0 ) {
			/* translators: 1: units bought, 2: units paid */
			return sprintf( __( '%1$d al %2$d öde', 'campaign-engine-for-woocommerce' ), $size, $size - $count );
		}

		if ( 'percent' === $reward && 1 === $count ) {
			/* translators: 1: position of the discounted unit, 2: percentage */
			return sprintf( __( '%1$d. üründe %%%2$s indirim', 'campaign-engine-for-woocommerce' ), $size, self::percent( (string) ( $row['value'] ?? '' ) ) );
		}

		if ( 'percent' === $reward && 0 === $count ) {
			/* translators: 1: set size, 2: percentage */
			return sprintf( __( '%1$d adetlik alımda %%%2$s indirim', 'campaign-engine-for-woocommerce' ), $size, self::percent( (string) ( $row['value'] ?? '' ) ) );
		}

		if ( 'fixed' === $reward ) {
			/* translators: 1: set size, 2: amount */
			return sprintf( __( 'Her %1$d adette %2$s indirim', 'campaign-engine-for-woocommerce' ), $size, self::money( (int) ( $row['value'] ?? 0 ) ) );
		}

		/* translators: %d: set size */
		return sprintf( __( '%d adetlik set indirimi', 'campaign-engine-for-woocommerce' ), $size );
	}

	/** @param array<string,mixed> $row */
	private static function tiered( array $row ): string {
		$tiers = (array) ( $row['tiers'] ?? array() );
		$first = is_array( $tiers[0] ?? null ) ? $tiers[0] : array();

		if ( array() === $first ) {
			return __( 'Kademeli indirim', 'campaign-engine-for-woocommerce' );
		}

		$min = 'subtotal' === ( $row['measure'] ?? 'quantity' ) ? self::money( (int) ( $first['min'] ?? 0 ) ) : (string) (int) ( $first['min'] ?? 0 );

		if ( 'fixed' === ( $first['type'] ?? '' ) ) {
			/* translators: 1: threshold (units or amount), 2: amount */
			$text = 'subtotal' === ( $row['measure'] ?? '' ) ? __( '%1$s ve üzerine %2$s indirimden başlayan kademeler', 'campaign-engine-for-woocommerce' ) : __( '%1$s adetten itibaren %2$s indirimden başlayan kademeler', 'campaign-engine-for-woocommerce' );

			return sprintf( $text, $min, self::money( (int) ( $first['value'] ?? 0 ) ) );
		}

		/* translators: 1: threshold (units or amount), 2: percentage */
		$text = 'subtotal' === ( $row['measure'] ?? '' ) ? __( '%1$s ve üzerine %%%2$s indirimden başlayan kademeler', 'campaign-engine-for-woocommerce' ) : __( '%1$s adetten itibaren %%%2$s indirimden başlayan kademeler', 'campaign-engine-for-woocommerce' );

		return sprintf( $text, $min, self::percent( (string) ( $first['value'] ?? '' ) ) );
	}

	/**
	 * The offer sentence: what is missing and what it gives.
	 *
	 * @param string $product_name name of the product to add more of (single-product offers), or ''
	 * @param array<string,mixed> $reward_row the campaign's first action (to tell coupon amounts from percentages)
	 */
	public static function offer( Offer $offer, string $product_name = '', array $reward_row = array() ): string {
		$gain    = self::offerReward( $offer, $reward_row );
		$missing = $offer->missing();

		if ( Offer::KIND_AMOUNT === $offer->kind ) {
			/* translators: 1: missing amount, 2: what the customer gets */
			return sprintf( __( 'Sepetinize %1$s tutarında daha ürün ekleyin, %2$s.', 'campaign-engine-for-woocommerce' ), self::money( $missing ), $gain );
		}

		if ( '' !== $product_name ) {
			/* translators: 1: product name, 2: missing units, 3: what the customer gets */
			return sprintf( _n( '%1$s ürününden %2$d adet daha ekleyin, %3$s.', '%1$s ürününden %2$d adet daha ekleyin, %3$s.', $missing, 'campaign-engine-for-woocommerce' ), $product_name, $missing, $gain );
		}

		/* translators: 1: missing units, 2: what the customer gets */
		return sprintf( _n( '%1$d adet daha ekleyin, %2$s.', '%1$d adet daha ekleyin, %2$s.', $missing, 'campaign-engine-for-woocommerce' ), $missing, $gain );
	}

	/** @param array<string,mixed> $reward_row */
	private static function offerReward( Offer $offer, array $reward_row ): string {
		$type  = (string) ( $offer->reward['type'] ?? '' );
		$value = (string) ( $offer->reward['value'] ?? '' );

		switch ( $type ) {
			case 'percent':
				/* translators: %s: percentage */
				return sprintf( __( '%%%s indirim kazanın', 'campaign-engine-for-woocommerce' ), self::percent( $value ) );
			case 'fixed':
				/* translators: %s: amount */
				return sprintf( __( '%s indirim kazanın', 'campaign-engine-for-woocommerce' ), self::money( (int) $value ) );
			case 'price':
				return __( 'kampanya fiyatından yararlanın', 'campaign-engine-for-woocommerce' );
			case 'free':
				return __( 'bir ürün ücretsiz olsun', 'campaign-engine-for-woocommerce' );
			case 'free_shipping':
				return __( 'kargo ücretsiz olsun', 'campaign-engine-for-woocommerce' );
			case 'reward_coupon':
				if ( 'percent' === ( $reward_row['reward'] ?? '' ) ) {
					/* translators: %s: percentage */
					return sprintf( __( 'sonraki alışverişiniz için %%%s değerinde kupon kazanın', 'campaign-engine-for-woocommerce' ), self::percent( $value ) );
				}
				/* translators: %s: amount */
				return sprintf( __( 'sonraki alışverişiniz için %s kupon kazanın', 'campaign-engine-for-woocommerce' ), self::money( (int) $value ) );
		}

		if ( $offer->value_known && $offer->value_minor > 0 ) {
			/* translators: %s: amount */
			return sprintf( __( '%s kazanın', 'campaign-engine-for-woocommerce' ), self::money( $offer->value_minor ) );
		}

		return __( 'kampanyadan yararlanın', 'campaign-engine-for-woocommerce' );
	}

	/** Turkish-aware upper-casing of the first letter ("ilk" → "İlk"). */
	public static function ucfirst( string $text ): string {
		$first = mb_substr( $text, 0, 1 );
		$map   = array(
			'i' => 'İ',
			'ı' => 'I',
		);

		return ( $map[ $first ] ?? mb_strtoupper( $first ) ) . mb_substr( $text, 1 );
	}

	/** Turkish-aware lower-casing of the first letter ("İlk" → "ilk", "Işık" → "ışık"). */
	public static function lcfirst( string $text ): string {
		$first = mb_substr( $text, 0, 1 );
		$map   = array(
			'İ' => 'i',
			'I' => 'ı',
		);

		return ( $map[ $first ] ?? mb_strtolower( $first ) ) . mb_substr( $text, 1 );
	}

	/** A date-time in the site's zone and format. */
	public static function dateTime( int $ts ): string {
		return wp_date( get_option( 'date_format', 'd.m.Y' ) . ' ' . get_option( 'time_format', 'H:i' ), $ts );
	}
}
