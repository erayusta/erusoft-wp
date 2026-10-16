<?php
/**
 * Tiered discount by quantity or by subtotal.
 *
 *   ['type' => 'tiered', 'measure' => 'quantity'|'subtotal', 'mode' => 'all_units'|'incremental',
 *    'group' => 'product'|'pool', 'tiers' => [ ['min' => 3, 'max' => 5, 'type' => 'percent', 'value' => '5'], … ]]
 *
 * `measure` is what the tiers count: units, or the group's subtotal in minor units. `group` decides
 * what is measured together: each product (two cart lines of one product add up) or every eligible
 * line as one pool. A tier covers min…max inclusive (no max = no upper bound); the first covering tier
 * wins, so a measure exactly on a boundary belongs to the tier that starts there.
 *
 * Tier types:
 *  - `percent`  a percentage off each unit the tier applies to;
 *  - `fixed`    by default ONE amount (minor units) for the qualifying group, spread exactly over the
 *               group's lines (UnitAllocation) and capped at the group's total — "spend 1.000, get
 *               75 off" gives 75 once, not 75 per unit. With 'per' => 'unit' (quantity measure only)
 *               it is an amount off each unit instead; a per-unit fixed tier on a subtotal measure is
 *               refused, since a subtotal has no unit to count.
 * In `incremental` mode a group-fixed tier gives its amount once, shared by the units it covers.
 *
 * A malformed tier row makes the action throw ArgumentError (traced as invalid_args): running with
 * fewer tiers than configured would be a different campaign.
 *
 * `all_units` gives the covering tier's discount to every unit of the group. `incremental` numbers the
 * units from the cheapest up and gives unit n the discount of the tier covering n ("units 1–4 full
 * price, from the 5th on 12 % off"); it only exists for quantity, a subtotal has no "5th unit", so a
 * subtotal measure always runs as `all_units`. Numbering by price makes the result depend on the cart's
 * content, not on the order products were added, and puts the deeper tiers on the dearer units.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Adjustment\UnitAllocation;
use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;
use Erusoft\CampaignEngine\Engine\Money;

final class TieredAction extends BaseAction {

	public function id(): string {
		return 'tiered';
	}

	public function level(): string {
		return 'item';
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		$measure = Args::choice( $args, 'measure', array( 'quantity', 'subtotal' ), 'quantity' );
		$mode    = Args::choice( $args, 'mode', array( 'all_units', 'incremental' ), 'all_units' );
		$group   = Args::choice( $args, 'group', array( 'product', 'pool' ), 'product' );
		$tiers   = self::tiers( $args['tiers'] ?? null, $measure );

		if ( 'subtotal' === $measure ) {
			$mode = 'all_units';
		}

		/** @var array<string,Item[]> $groups */
		$groups = array();
		/** @var array<string,int> $bases */
		$bases = array();

		foreach ( $scope->match->items as $item ) {
			$base = PriceBasis::base( $item, $campaign->basis );

			if ( null === $base || $base <= 0 || $item->qty <= 0 ) {
				continue;
			}

			$groups[ 'pool' === $group ? 'pool' : $item->productKey() ][] = $item;
			$bases[ $item->key ] = $base;
		}

		$out = array();

		foreach ( $groups as $group_items ) {
			$lines = 'incremental' === $mode
				? self::incremental( $group_items, $bases, $tiers )
				: self::allUnits( $group_items, $bases, $tiers, self::measure( $group_items, $bases, $measure ) );

			foreach ( $group_items as $item ) {
				$total = $lines[ $item->key ] ?? 0;

				if ( $total > 0 ) {
					$out[] = $this->adjustment( $campaign, $item->key, $total, array( 'per_unit' => intdiv( $total, $item->qty ) ) );
				}
			}
		}

		return $out;
	}

	/**
	 * The first tier covering the measure, or null.
	 *
	 * @param array<int,array{min:int,max:?int,type:string,value:string,per:string}> $tiers Normalised.
	 * @return array{min:int,max:?int,type:string,value:string,per:string}|null
	 */
	public static function tierFor( array $tiers, int $measure ): ?array {
		foreach ( $tiers as $tier ) {
			if ( $measure >= $tier['min'] && ( null === $tier['max'] || $measure <= $tier['max'] ) ) {
				return $tier;
			}
		}

		return null;
	}

	/**
	 * Strict tier list for the action: sorted by `min`; any malformed row throws
	 * ArgumentError( 'tiers', 'invalid' ), an empty list ArgumentError( 'tiers', 'missing' ).
	 *
	 * @param mixed $raw
	 * @return array<int,array{min:int,max:?int,type:string,value:string,per:string}>
	 * @throws ArgumentError
	 */
	public static function tiers( $raw, string $measure = 'quantity' ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			throw new ArgumentError( 'tiers', 'missing' );
		}

		$out = array();

		foreach ( $raw as $tier ) {
			try {
				$out[] = self::tierRow( $tier, $measure );
			} catch ( ArgumentError $e ) {
				throw new ArgumentError( 'tiers', 'invalid' );
			}
		}

		usort( $out, static fn( array $a, array $b ): int => $a['min'] <=> $b['min'] );

		return $out;
	}

	/**
	 * Lenient tier list for previews only (the action and the offer finder use the strict `tiers()`,
	 * which refuses the whole list): malformed rows are skipped, never repaired, so a
	 * misread row cannot turn into a tier that covers everything.
	 *
	 * @param array<int|string,mixed> $tiers
	 * @return array<int,array{min:int,max:?int,type:string,value:string,per:string}>
	 */
	public static function normaliseTiers( array $tiers, string $measure = 'quantity' ): array {
		$out = array();

		foreach ( $tiers as $tier ) {
			try {
				$out[] = self::tierRow( $tier, $measure );
			} catch ( ArgumentError $e ) {
				continue;
			}
		}

		usort( $out, static fn( array $a, array $b ): int => $a['min'] <=> $b['min'] );

		return $out;
	}

	/**
	 * Per-unit discount of a percent tier, or of a per-unit fixed tier, on a unit priced $base.
	 *
	 * @param array{type:string,value:string,per:string} $tier
	 */
	public static function tierAmount( array $tier, int $base ): int {
		if ( 'fixed' === $tier['type'] ) {
			return min( $base, (int) $tier['value'] );
		}

		return min( $base, Money::percent( $base, $tier['value'] ) );
	}

	/** Whether a tier is one amount for the group (a fixed tier not marked per unit). */
	public static function isGroupAmount( array $tier ): bool {
		return 'fixed' === $tier['type'] && 'group' === $tier['per'];
	}

	/**
	 * One validated tier row. `min` is a whole number of at least 1, `max` (optional) is not below
	 * `min`, `type` is percent or fixed, `value` is a valid percentage or a positive minor-unit
	 * amount, and `per` (fixed only) is group or unit — unit only on a quantity measure.
	 *
	 * @param mixed $tier
	 * @return array{min:int,max:?int,type:string,value:string,per:string}
	 * @throws ArgumentError
	 */
	private static function tierRow( $tier, string $measure ): array {
		if ( ! is_array( $tier ) ) {
			throw new ArgumentError( 'tiers', 'invalid' );
		}

		$min = Args::positiveInt( $tier['min'] ?? null, 'min' );
		$max = null;

		if ( isset( $tier['max'] ) && '' !== $tier['max'] ) {
			$max = Args::positiveInt( $tier['max'], 'max' );

			if ( $max < $min ) {
				throw new ArgumentError( 'max', 'out_of_range' );
			}
		}

		$type  = Args::choice( $tier, 'type', array( 'percent', 'fixed' ), 'percent' );
		$per   = 'fixed' === $type ? Args::choice( $tier, 'per', array( 'group', 'unit' ), 'group' ) : 'unit';
		$value = 'fixed' === $type ? (string) Args::positiveInt( $tier['value'] ?? null, 'value' ) : Args::percent( $tier['value'] ?? null, 'value' );

		if ( 'fixed' === $type && 'unit' === $per && 'subtotal' === $measure ) {
			throw new ArgumentError( 'per', 'unknown_choice' );
		}

		return array(
			'min'   => $min,
			'max'   => $max,
			'type'  => $type,
			'value' => $value,
			'per'   => $per,
		);
	}

	/**
	 * @param Item[]            $items
	 * @param array<string,int> $bases
	 */
	private static function measure( array $items, array $bases, string $measure ): int {
		$total = 0;

		foreach ( $items as $item ) {
			$total += 'subtotal' === $measure ? $bases[ $item->key ] * $item->qty : $item->qty;
		}

		return $total;
	}

	/**
	 * @param Item[]                                                                $items
	 * @param array<string,int>                                                     $bases
	 * @param array<int,array{min:int,max:?int,type:string,value:string,per:string}> $tiers
	 * @return array<string,int>
	 */
	private static function allUnits( array $items, array $bases, array $tiers, int $measure ): array {
		$tier = self::tierFor( $tiers, $measure );

		if ( null === $tier ) {
			return array();
		}

		if ( self::isGroupAmount( $tier ) ) {
			$basis = array(
				'items'    => array(),
				'bases'    => array(),
				'subtotal' => 0,
			);

			foreach ( $items as $item ) {
				$basis['items'][ $item->key ] = $item;
				$basis['bases'][ $item->key ] = $bases[ $item->key ];
				$basis['subtotal']           += $bases[ $item->key ] * $item->qty;
			}

			return CartSpread::lines( min( (int) $tier['value'], $basis['subtotal'] ), $basis );
		}

		$out = array();

		foreach ( $items as $item ) {
			$base     = $bases[ $item->key ];
			$per_unit = PriceBasis::effectivePerUnit( $item, $base, self::tierAmount( $tier, $base ) );

			if ( $per_unit > 0 ) {
				$out[ $item->key ] = $per_unit * $item->qty;
			}
		}

		return $out;
	}

	/**
	 * @param Item[]                                                                $items
	 * @param array<string,int>                                                     $bases
	 * @param array<int,array{min:int,max:?int,type:string,value:string,per:string}> $tiers
	 * @return array<string,int>
	 */
	private static function incremental( array $items, array $bases, array $tiers ): array {
		$units = array();

		foreach ( $items as $position => $item ) {
			for ( $i = 0; $i < $item->qty; $i++ ) {
				$units[] = array( $bases[ $item->key ], $position, $item );
			}
		}

		usort(
			$units,
			static fn( array $a, array $b ): int => 0 !== ( $a[0] <=> $b[0] ) ? $a[0] <=> $b[0] : $a[1] <=> $b[1]
		);

		$out     = array();
		$by_tier = array();

		foreach ( $units as $index => $unit ) {
			$tier = self::tierFor( $tiers, $index + 1 );

			if ( null === $tier ) {
				continue;
			}

			if ( self::isGroupAmount( $tier ) ) {
				$by_tier[ $tier['min'] ]['tier']    = $tier;
				$by_tier[ $tier['min'] ]['units'][] = $unit;
				continue;
			}

			$amount = PriceBasis::effectivePerUnit( $unit[2], $unit[0], self::tierAmount( $tier, $unit[0] ) );

			if ( $amount > 0 ) {
				$out[ $unit[2]->key ] = ( $out[ $unit[2]->key ] ?? 0 ) + $amount;
			}
		}

		// A group-fixed tier: its amount once, shared over the units it covers, capped at their total.
		foreach ( $by_tier as $bucket ) {
			$lines = array();
			$total = 0;

			foreach ( $bucket['units'] as $i => $unit ) {
				$lines[ $i ] = array(
					'qty'        => 1,
					'unit_minor' => $unit[0],
				);
				$total      += $unit[0];
			}

			foreach ( UnitAllocation::spread( min( (int) $bucket['tier']['value'], $total ), $lines ) as $i => $share ) {
				$unit   = $bucket['units'][ $i ];
				$amount = PriceBasis::effectivePerUnit( $unit[2], $unit[0], $share );

				if ( $amount > 0 ) {
					$out[ $unit[2]->key ] = ( $out[ $unit[2]->key ] ?? 0 ) + $amount;
				}
			}
		}

		return $out;
	}
}
