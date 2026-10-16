<?php
/**
 * Who a campaign is for, as far as the shop front may say.
 *
 * A campaign is visitor-conditional when whether it applies depends on who is looking or when they look
 * inside the season: a customer condition (first or N-th order, role, logged in, order history), the
 * payment method, a weekday or time-of-day condition, a repeating time window in its schedule, or a
 * per-customer usage limit. Product and shop pages never change a price for such a campaign and never
 * call it "geçerli": they show it with a condition note built from its rules ("İlk siparişinize özel",
 * "Hafta sonu", "Havale/EFT ile ödemede"), the same text for every visitor. That keeps the shop front
 * identical for all visitors, so a page cache cannot serve one visitor's price to another. The cart
 * decides what really applies.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Campaign\Schedule;
use Erusoft\CampaignEngine\Engine\Condition\RuleTree;
use Erusoft\CampaignEngine\Presentation\Texts;

final class Audience {

	/** Conditions that depend on the visitor or on the moment within the season. */
	public const VISITOR_CONDITIONS = array( 'customer.logged_in', 'customer.role', 'customer.order_count', 'customer.first_order', 'customer.total_spent', 'customer.days_since_last_order', 'cart.payment_method', 'date.weekday', 'time.window' );

	/** Conditions on the cart as a whole: a product page cannot know them, the cart decides. */
	public const CART_CONDITIONS = array( 'cart.subtotal', 'cart.eligible_subtotal', 'cart.quantity', 'cart.eligible_quantity', 'cart.coupon', 'cart.shipping_method', 'cart.shipping_country' );

	/**
	 * @param int|null $per_customer the campaign's per-customer usage limit
	 */
	public static function isConditional( Campaign $campaign, ?int $per_customer = null ): bool {
		if ( null !== $per_customer || ! $campaign->schedule->recurrence->isNone() ) {
			return true;
		}

		foreach ( RuleTree::leaves( $campaign->rules ) as $leaf ) {
			if ( in_array( $leaf['condition'] ?? '', self::VISITOR_CONDITIONS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The campaign with every visitor- and cart-dependent part treated as met: those leaves pass (an OR
	 * group holding one passes), the repeating window is dropped. Used only to ask "could this campaign
	 * apply to this product, given the right visitor and cart?", never to price anything.
	 */
	public static function relaxed( Campaign $campaign ): Campaign {
		$rules    = self::relax( $campaign->rules );
		$schedule = $campaign->schedule;

		return new Campaign(
			$campaign->id,
			$campaign->label,
			$campaign->actions,
			null === $rules ? null : $rules,
			$campaign->targets,
			new Schedule( $schedule->starts_ts, $schedule->ends_ts, null, $schedule->timezone, $schedule->flash ),
			$campaign->priority,
			false,
			$campaign->basis,
			$campaign->max_discount_minor,
			$campaign->version,
			$campaign->meta
		);
	}

	/**
	 * @param array<string,mixed>|null $node
	 * @return array<string,mixed>|null null = always passes
	 */
	private static function relax( ?array $node ): ?array {
		if ( null === $node || array() === $node ) {
			return null;
		}

		if ( isset( $node['condition'] ) ) {
			return in_array( $node['condition'], self::VISITOR_CONDITIONS, true ) || in_array( $node['condition'], self::CART_CONDITIONS, true ) ? null : $node;
		}

		$or       = 'or' === strtolower( (string) ( $node['op'] ?? 'and' ) );
		$children = array();

		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			$relaxed = is_array( $child ) ? self::relax( $child ) : null;

			if ( null === $relaxed ) {
				if ( $or ) {
					return null; // One branch always passes, so the group does.
				}

				continue;
			}

			$children[] = $relaxed;
		}

		return array() === $children ? null : array(
			'op'       => $or ? 'or' : 'and',
			'children' => $children,
		);
	}

	/**
	 * The condition note of a campaign, from its rules, repeating window and per-customer limit;
	 * "Koşullara bağlı" when none of them has its own wording. Never empty; the same for every visitor.
	 */
	public static function note( Campaign $campaign, ?int $per_customer = null ): string {
		$parts = array();
		$rules = self::phrase( $campaign->rules );

		if ( '' !== $rules ) {
			$parts[] = $rules;
		}

		$window = self::window( $campaign );

		if ( '' !== $window ) {
			$parts[] = $window;
		}

		if ( null !== $per_customer ) {
			/* translators: %d: uses per customer */
			$parts[] = sprintf( _n( 'müşteri başına %d kez', 'müşteri başına %d kez', $per_customer, 'campaign-engine-for-woocommerce' ), $per_customer );
		}

		$text = implode( ', ', $parts );

		// Never an unstated condition: a set or tier, or a condition without its own wording.
		return '' === $text ? __( 'Koşullara bağlı', 'campaign-engine-for-woocommerce' ) : Texts::ucfirst( $text );
	}

	/** @param array<string,mixed>|null $node */
	private static function phrase( ?array $node ): string {
		if ( null === $node || array() === $node ) {
			return '';
		}

		if ( isset( $node['condition'] ) ) {
			return self::leaf( $node );
		}

		$parts = array();

		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			$text = is_array( $child ) ? self::phrase( $child ) : '';

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		$or = 'or' === strtolower( (string) ( $node['op'] ?? 'and' ) );

		return implode( $or ? __( ' ya da ', 'campaign-engine-for-woocommerce' ) : ', ', array_unique( $parts ) );
	}

	/** @param array<string,mixed> $leaf */
	private static function leaf( array $leaf ): string {
		$cmp   = (string) ( $leaf['cmp'] ?? '' );
		$value = $leaf['value'] ?? true;
		$flag  = ! in_array( $value, array( false, 'false', '0', 0, 'no' ), true );

		switch ( (string) $leaf['condition'] ) {
			case 'customer.first_order':
				return $flag ? __( 'ilk siparişinize özel', 'campaign-engine-for-woocommerce' ) : __( 'daha önce sipariş vermiş müşterilere', 'campaign-engine-for-woocommerce' );
			case 'customer.logged_in':
				return $flag ? __( 'üyelere özel', 'campaign-engine-for-woocommerce' ) : __( 'üye girişi yapmadan alışverişte', 'campaign-engine-for-woocommerce' );
			case 'customer.role':
				$names = array();
				foreach ( (array) $value as $role ) {
					$all     = wp_roles()->get_names();
					$names[] = isset( $all[ $role ] ) ? translate_user_role( $all[ $role ] ) : (string) $role;
				}
				/* translators: %s: role names */
				return sprintf( __( '%s müşteri grubuna özel', 'campaign-engine-for-woocommerce' ), implode( ', ', $names ) );
			case 'customer.order_count':
				if ( '==' === $cmp && is_numeric( $value ) ) {
					/* translators: %d: order number */
					return sprintf( __( '%d. siparişinize özel', 'campaign-engine-for-woocommerce' ), (int) $value + 1 );
				}
				if ( in_array( $cmp, array( '>=', '>' ), true ) && is_numeric( $value ) ) {
					/* translators: %d: paid orders */
					return sprintf( __( 'en az %d siparişi olan müşterilere', 'campaign-engine-for-woocommerce' ), (int) $value + ( '>' === $cmp ? 1 : 0 ) );
				}
				return __( 'sipariş geçmişine bağlı', 'campaign-engine-for-woocommerce' );
			case 'customer.total_spent':
				if ( in_array( $cmp, array( '>=', '>' ), true ) && is_numeric( $value ) ) {
					/* translators: %s: amount */
					return sprintf( __( 'toplam %s ve üzeri alışveriş yapmış müşterilere', 'campaign-engine-for-woocommerce' ), Texts::money( (int) $value ) );
				}
				return __( 'alışveriş geçmişine bağlı', 'campaign-engine-for-woocommerce' );
			case 'customer.days_since_last_order':
				return __( 'son siparişin tarihine bağlı', 'campaign-engine-for-woocommerce' );
			case 'cart.payment_method':
				$titles   = array();
				$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
				foreach ( (array) $value as $id ) {
					$titles[] = isset( $gateways[ $id ] ) ? wp_strip_all_tags( (string) $gateways[ $id ]->get_title() ) : (string) $id;
				}
				return 'not_in' === $cmp
					/* translators: %s: payment methods */
					? sprintf( __( '%s dışındaki ödeme yöntemleriyle', 'campaign-engine-for-woocommerce' ), implode( ', ', $titles ) )
					/* translators: %s: payment methods */
					: sprintf( __( '%s ile ödemede', 'campaign-engine-for-woocommerce' ), implode( ' / ', $titles ) );
			case 'date.weekday':
				return self::days( (array) $value, 'not_in' === $cmp );
			case 'time.window':
				/* translators: 1: from, 2: to */
				return sprintf( __( 'saat %1$s–%2$s arası', 'campaign-engine-for-woocommerce' ), (string) ( $leaf['from'] ?? '' ), (string) ( $leaf['to'] ?? '' ) );
			case 'cart.subtotal':
			case 'cart.eligible_subtotal':
				if ( in_array( $cmp, array( '>=', '>' ), true ) && is_numeric( $value ) ) {
					/* translators: %s: amount */
					return sprintf( '>=' === $cmp ? __( '%s ve üzeri alışverişte', 'campaign-engine-for-woocommerce' ) : __( '%s üzeri alışverişte', 'campaign-engine-for-woocommerce' ), Texts::money( (int) $value ) );
				}
				return __( 'sepet koşuluna bağlı', 'campaign-engine-for-woocommerce' );
			case 'cart.quantity':
			case 'cart.eligible_quantity':
				if ( in_array( $cmp, array( '>=', '>' ), true ) && is_numeric( $value ) ) {
					/* translators: %d: units */
					return sprintf( __( '%d adet ve üzeri alışverişte', 'campaign-engine-for-woocommerce' ), (int) $value + ( '>' === $cmp ? 1 : 0 ) );
				}
				return __( 'sepet koşuluna bağlı', 'campaign-engine-for-woocommerce' );
			case 'cart.coupon':
				return __( 'kuponla birlikte', 'campaign-engine-for-woocommerce' );
			case 'cart.shipping_method':
				return __( 'kargo seçimine bağlı', 'campaign-engine-for-woocommerce' );
			case 'cart.shipping_country':
				return __( 'teslimat ülkesine bağlı', 'campaign-engine-for-woocommerce' );
		}

		return '';
	}

	/** Day codes as text ("Hafta sonu", "Cuma, Cumartesi günleri"). */
	private static function days( array $codes, bool $except ): string {
		$names = array(
			'MO' => __( 'Pazartesi', 'campaign-engine-for-woocommerce' ),
			'TU' => __( 'Salı', 'campaign-engine-for-woocommerce' ),
			'WE' => __( 'Çarşamba', 'campaign-engine-for-woocommerce' ),
			'TH' => __( 'Perşembe', 'campaign-engine-for-woocommerce' ),
			'FR' => __( 'Cuma', 'campaign-engine-for-woocommerce' ),
			'SA' => __( 'Cumartesi', 'campaign-engine-for-woocommerce' ),
			'SU' => __( 'Pazar', 'campaign-engine-for-woocommerce' ),
		);
		$codes = \Erusoft\CampaignEngine\Engine\Campaign\Recurrence::dayCodes( array_values( $codes ) );

		if ( ! $except && array( 'SA', 'SU' ) === $codes ) {
			return __( 'hafta sonu', 'campaign-engine-for-woocommerce' );
		}

		if ( ! $except && array( 'MO', 'TU', 'WE', 'TH', 'FR' ) === $codes ) {
			return __( 'hafta içi', 'campaign-engine-for-woocommerce' );
		}

		$list = implode( ', ', array_map( static fn( string $c ): string => $names[ $c ], $codes ) );

		/* translators: %s: day names */
		return $except ? sprintf( __( '%s dışındaki günlerde', 'campaign-engine-for-woocommerce' ), $list ) : sprintf( __( '%s günleri', 'campaign-engine-for-woocommerce' ), $list );
	}

	/** The repeating window of the schedule as text, '' without one. */
	private static function window( Campaign $campaign ): string {
		$recurrence = $campaign->schedule->recurrence;

		if ( $recurrence->isNone() || ! $recurrence->isValid() ) {
			return '';
		}

		$row = $recurrence->toArray();

		switch ( $recurrence->freq() ) {
			case 'daily':
				/* translators: 1: from, 2: to */
				return sprintf( __( 'her gün %1$s–%2$s', 'campaign-engine-for-woocommerce' ), (string) $row['start'], (string) $row['end'] );
			case 'weekly':
				$days = self::days( (array) $row['days'], false );
				return '00:00' === $row['start'] && '23:59' === $row['end'] ? $days : $days . ' ' . $row['start'] . '–' . $row['end'];
			case 'weekly_span':
				$span = self::days( array( (string) $row['from_day'] ), false ) . ' ' . $row['from'] . ' – ' . self::days( array( (string) $row['to_day'] ), false ) . ' ' . $row['to'];
				return str_replace( __( ' günleri', 'campaign-engine-for-woocommerce' ), '', $span );
			case 'monthly':
				/* translators: %s: days of the month */
				return sprintf( __( 'ayın %s. günleri', 'campaign-engine-for-woocommerce' ), implode( ', ', array_map( 'strval', (array) $row['days'] ) ) );
		}

		return '';
	}
}
