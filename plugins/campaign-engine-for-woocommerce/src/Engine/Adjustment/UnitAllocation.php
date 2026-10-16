<?php
/**
 * Puts discounts on lines and units, in whole minor units, without losing or inventing a kuruş.
 *
 * Two jobs:
 *
 * 1. Spreading an amount (`spread`, `perUnit`). A cart-level discount is shared over lines in
 *    proportion to their totals (largest remainder), capped at what each line can take; whatever a
 *    capped line cannot take moves to lines that still have room. The shares always add up to the
 *    amount (or to the cart's room, when the amount is larger), no share is negative and no line gets
 *    more than its own total. Inside a line, `perUnit` splits the line discount over its units; the
 *    units differ by at most one minor unit and the first units carry the extra ones.
 *
 * 2. Set allocation for "buy N" style rewards (`units` → `pools` → `sortUnits` → `sets` →
 *    `rewardUnits`, wrapped by `allocateSets` and `allocatePaired`). Lines are opened into single
 *    units, grouped into pools (one per product, or one mixed pool), ordered by price, cut into full
 *    sets from the cheapest unit up, and the first `reward_count` units of each set are rewarded. How
 *    much a rewarded unit gets is decided by the caller's callback, so pricing policy stays in the
 *    action and this class stays pure arithmetic.
 *
 * A unit is array{key:string, price:int, pool:string}: the cart line it came from, its unit price in
 * minor units and its pool key (product and variation id).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Adjustment;

use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Money;

final class UnitAllocation {

	public const POOL_PRODUCT = 'product';
	public const POOL_MIXED   = 'mixed';

	public const TARGET_CHEAPEST       = 'cheapest';
	public const TARGET_MOST_EXPENSIVE = 'most_expensive';

	public const REPEAT_EVERY = 'every';
	public const REPEAT_ONCE  = 'once';

	/**
	 * Shares an amount over lines in proportion to their totals.
	 *
	 * @param int                                                          $discount Amount to share, minor units.
	 * @param array<string|int,array{qty:int,unit_minor:int,cap_minor?:int}> $lines  Line key => quantity, unit price
	 *                                                                               and optional extra cap.
	 * @return array<string|int,int> Line key => share, every input key present, in input order.
	 */
	public static function spread( int $discount, array $lines ): array {
		$weights = array();
		$caps    = array();
		$alloc   = array();

		foreach ( $lines as $key => $line ) {
			$total           = max( 0, (int) ( $line['qty'] ?? 0 ) ) * max( 0, (int) ( $line['unit_minor'] ?? 0 ) );
			$cap             = isset( $line['cap_minor'] ) ? min( $total, max( 0, (int) $line['cap_minor'] ) ) : $total;
			$weights[ $key ] = $total;
			$caps[ $key ]    = $cap;
			$alloc[ $key ]   = 0;
		}

		$remaining = min( max( 0, $discount ), array_sum( $caps ) );
		$active    = array_filter( $caps, static fn( int $cap ): bool => $cap > 0 );

		while ( $remaining > 0 && array() !== $active ) {
			$shares = Money::distribute( $remaining, array_intersect_key( $weights, $active ) );
			$left   = 0;

			foreach ( $shares as $key => $share ) {
				$room          = $caps[ $key ] - $alloc[ $key ];
				$give          = min( $share, $room );
				$alloc[ $key ] += $give;
				$left         += $share - $give;

				if ( $alloc[ $key ] >= $caps[ $key ] ) {
					unset( $active[ $key ] );
				}
			}

			$remaining = $left;
		}

		return $alloc;
	}

	/**
	 * Shares an amount over Item lines (convenience over `spread`).
	 *
	 * @param Item[]            $items
	 * @param array<string,int> $caps Optional per-line caps, keyed by item key.
	 * @return array<string,int>
	 */
	public static function spreadItems( int $discount, array $items, array $caps = array() ): array {
		$lines = array();

		foreach ( $items as $item ) {
			$line = array(
				'qty'        => $item->qty,
				'unit_minor' => $item->unit_minor,
			);

			if ( isset( $caps[ $item->key ] ) ) {
				$line['cap_minor'] = $caps[ $item->key ];
			}

			$lines[ $item->key ] = $line;
		}

		return self::spread( $discount, $lines );
	}

	/**
	 * Splits a line discount over its units: equal shares, the remainder one minor unit at a time on
	 * the first units. The result adds up to the line discount.
	 *
	 * @return int[] One share per unit; empty for a quantity below 1.
	 */
	public static function perUnit( int $line_discount, int $qty ): array {
		if ( $qty < 1 ) {
			return array();
		}

		$line_discount = max( 0, $line_discount );
		$base          = intdiv( $line_discount, $qty );
		$extra         = $line_discount % $qty;
		$out           = array();

		for ( $i = 0; $i < $qty; $i++ ) {
			$out[] = $base + ( $i < $extra ? 1 : 0 );
		}

		return $out;
	}

	/**
	 * Opens lines into units. Lines without a positive price in $prices take no part.
	 *
	 * @param Item[]            $items
	 * @param array<string,int> $prices item key => unit price used for the allocation.
	 * @return array<int,array{key:string,price:int,pool:string}>
	 */
	public static function units( array $items, array $prices ): array {
		$units = array();

		foreach ( $items as $item ) {
			$price = (int) ( $prices[ $item->key ] ?? 0 );

			if ( $price <= 0 || $item->qty <= 0 ) {
				continue;
			}

			for ( $i = 0; $i < $item->qty; $i++ ) {
				$units[] = array(
					'key'   => $item->key,
					'price' => $price,
					'pool'  => $item->productKey(),
				);
			}
		}

		return $units;
	}

	/**
	 * Groups units into pools. In product mode two cart lines of the same product share a pool, so a
	 * customer who added 2 and then 1 still completes a set of 3.
	 *
	 * @param array<int,array{key:string,price:int,pool:string}> $units
	 * @return array<string,array<int,array{key:string,price:int,pool:string}>>
	 */
	public static function pools( array $units, string $mode ): array {
		if ( self::POOL_MIXED === $mode ) {
			return array() === $units ? array() : array( self::POOL_MIXED => $units );
		}

		$pools = array();

		foreach ( $units as $unit ) {
			$pools[ $unit['pool'] ][] = $unit;
		}

		return $pools;
	}

	/**
	 * Orders a pool for rewarding: cheapest first, or most expensive first. Equal prices keep their
	 * cart order, then their line key, so the outcome never depends on how the sort is implemented.
	 *
	 * @param array<int,array{key:string,price:int,pool:string}> $units
	 * @return array<int,array{key:string,price:int,pool:string}>
	 */
	public static function sortUnits( array $units, string $target ): array {
		$indexed = array();

		foreach ( array_values( $units ) as $position => $unit ) {
			$indexed[] = array( $position, $unit );
		}

		$direction = self::TARGET_MOST_EXPENSIVE === $target ? -1 : 1;

		usort(
			$indexed,
			static function ( array $a, array $b ) use ( $direction ): int {
				$by_price = ( $a[1]['price'] <=> $b[1]['price'] ) * $direction;

				return 0 !== $by_price ? $by_price : $a[0] <=> $b[0];
			}
		);

		return array_map( static fn( array $pair ): array => $pair[1], $indexed );
	}

	/**
	 * Cuts an ordered pool into full sets. Units left over do not form a set and pay full price.
	 *
	 * @param array<int,array{key:string,price:int,pool:string}> $units
	 * @return array<int,array<int,array{key:string,price:int,pool:string}>>
	 */
	public static function sets( array $units, int $set_size, string $repeat ): array {
		if ( $set_size < 1 ) {
			return array();
		}

		$count = intdiv( count( $units ), $set_size );

		if ( self::REPEAT_ONCE === $repeat ) {
			$count = min( 1, $count );
		}

		$sets = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$sets[] = array_slice( $units, $i * $set_size, $set_size );
		}

		return $sets;
	}

	/**
	 * The rewarded units of one ordered set: its first `reward_count` units, or all of them when the
	 * count is 0 (a whole-set discount).
	 *
	 * @param array<int,array{key:string,price:int,pool:string}> $set
	 * @return array<int,array{key:string,price:int,pool:string}>
	 */
	public static function rewardUnits( array $set, int $reward_count ): array {
		if ( $reward_count <= 0 || $reward_count >= count( $set ) ) {
			return $set;
		}

		return array_slice( $set, 0, $reward_count );
	}

	/**
	 * Set allocation over one group of lines.
	 *
	 * Refused (empty result) when the set has fewer than 2 units, or when every unit of a set would be
	 * rewarded by count (`reward_count >= set_size`); such a set is a plain discount, not a set reward.
	 *
	 * @param Item[]            $items
	 * @param array<string,int> $prices item key => unit price used for the allocation.
	 * @param array{pool:string,set_size:int,reward_count:int,target:string,repeat:string} $config
	 * @param callable          $discount fn( string $item_key, int $unit_price ): int — discount for one rewarded unit.
	 * @return array{lines:array<string,int>,units:int} Line key => discount, and how many units were rewarded.
	 */
	public static function allocateSets( array $items, array $prices, array $config, callable $discount ): array {
		$set_size = (int) ( $config['set_size'] ?? 0 );
		$count    = max( 0, (int) ( $config['reward_count'] ?? 0 ) );
		$empty    = array(
			'lines' => array(),
			'units' => 0,
		);

		if ( $set_size < 2 || ( $count > 0 && $count >= $set_size ) ) {
			return $empty;
		}

		$lines    = array();
		$rewarded = 0;

		foreach ( self::pools( self::units( $items, $prices ), (string) ( $config['pool'] ?? self::POOL_PRODUCT ) ) as $pool ) {
			$ordered = self::sortUnits( $pool, (string) ( $config['target'] ?? self::TARGET_CHEAPEST ) );

			foreach ( self::sets( $ordered, $set_size, (string) ( $config['repeat'] ?? self::REPEAT_EVERY ) ) as $set ) {
				foreach ( self::rewardUnits( $set, $count ) as $unit ) {
					$amount = (int) $discount( $unit['key'], $unit['price'] );

					if ( $amount <= 0 ) {
						continue;
					}

					$lines[ $unit['key'] ] = ( $lines[ $unit['key'] ] ?? 0 ) + $amount;
					++$rewarded;
				}
			}
		}

		return array(
			'lines' => $lines,
			'units' => $rewarded,
		);
	}

	/**
	 * Paired allocation: trigger units come from one group, rewarded units from another (a bundle such
	 * as "two of these, one of those free"). The number of sets is the smaller of what each side can
	 * fill; trigger units are only counted, never discounted.
	 *
	 * @param array<int,array{key:string,price:int,pool:string}> $triggers
	 * @param array<int,array{key:string,price:int,pool:string}> $rewards Already ordered by target.
	 * @param callable $discount fn( string $item_key, int $unit_price ): int
	 * @return array{lines:array<string,int>,units:int}
	 */
	public static function allocatePaired( array $triggers, array $rewards, int $triggers_per_set, int $rewards_per_set, string $repeat, callable $discount ): array {
		$lines    = array();
		$rewarded = 0;

		if ( $triggers_per_set < 1 || $rewards_per_set < 1 ) {
			return array(
				'lines' => $lines,
				'units' => 0,
			);
		}

		$sets = min( intdiv( count( $triggers ), $triggers_per_set ), intdiv( count( $rewards ), $rewards_per_set ) );

		if ( self::REPEAT_ONCE === $repeat ) {
			$sets = min( 1, $sets );
		}

		$rewards = array_values( $rewards );

		for ( $i = 0; $i < $sets * $rewards_per_set; $i++ ) {
			$unit   = $rewards[ $i ];
			$amount = (int) $discount( $unit['key'], $unit['price'] );

			if ( $amount <= 0 ) {
				continue;
			}

			$lines[ $unit['key'] ] = ( $lines[ $unit['key'] ] ?? 0 ) + $amount;
			++$rewarded;
		}

		return array(
			'lines' => $lines,
			'units' => $rewarded,
		);
	}
}
