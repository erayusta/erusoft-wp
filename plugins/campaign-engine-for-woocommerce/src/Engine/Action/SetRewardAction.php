<?php
/**
 * Set rewards: one action for "buy N" style campaigns.
 *
 *   ['type' => 'set_reward', 'set_size' => 3, 'reward_count' => 1, 'reward' => 'free']
 *        "3 al 2 öde": in every 3 units, the cheapest one is free
 *   ['type' => 'set_reward', 'set_size' => 2, 'reward_count' => 1, 'reward' => 'percent', 'value' => '50']
 *        "2. üründe %50"
 *   ['type' => 'set_reward', 'set_size' => 6, 'reward_count' => 0, 'reward' => 'percent', 'value' => '15']
 *        every full set of 6 gets 15 % on all its units (a case deal)
 *   … + 'reward_products' => [ids] or 'reward_terms' => [ids]
 *        paired bundle: units outside that scope trigger, units inside it are rewarded
 *
 * Other arguments: 'value' (percent string or fixed minor units), 'target' cheapest|most_expensive
 * (default cheapest), 'pool' product|mixed (default product: sets are built per product; mixed: any
 * eligible products fill a set together), 'repeat' every|once (default every).
 *
 * Unknown or missing money-deciding arguments throw ArgumentError (traced as invalid_args); a broken
 * row must not become the most generous reading of itself. Unit selection lives in UnitAllocation; this class only decides how much
 * a rewarded unit gets.
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

final class SetRewardAction extends BaseAction {

	public function id(): string {
		return 'set_reward';
	}

	public function level(): string {
		return 'item';
	}

	/**
	 * The validated configuration of a set reward row. Shared with the offer finder so both read a
	 * row the same way. Throws ArgumentError when the row is unusable: a set below 2 units, a reward
	 * count that covers the whole set, a missing or unknown reward, an invalid value or reward id.
	 *
	 * @param array<string,mixed> $args
	 * @return array{set_size:int,reward_count:int,reward:string,value:string|int,target:string,pool:string,repeat:string,reward_products:int[],reward_terms:int[]}
	 * @throws ArgumentError
	 */
	public static function config( array $args ): array {
		$set_size = Args::positiveInt( $args['set_size'] ?? null, 'set_size' );
		$count    = array_key_exists( 'reward_count', $args ) ? Args::minor( $args['reward_count'], 'reward_count' ) : 1;
		$reward   = Args::choice( $args, 'reward', array( 'free', 'percent', 'fixed' ), null );
		$target   = Args::choice( $args, 'target', array( UnitAllocation::TARGET_CHEAPEST, UnitAllocation::TARGET_MOST_EXPENSIVE ), UnitAllocation::TARGET_CHEAPEST );
		$pool     = Args::choice( $args, 'pool', array( UnitAllocation::POOL_PRODUCT, UnitAllocation::POOL_MIXED ), UnitAllocation::POOL_PRODUCT );
		$repeat   = Args::choice( $args, 'repeat', array( UnitAllocation::REPEAT_EVERY, UnitAllocation::REPEAT_ONCE ), UnitAllocation::REPEAT_EVERY );

		if ( $set_size < 2 ) {
			throw new ArgumentError( 'set_size', 'out_of_range' );
		}

		if ( $count > 0 && $count >= $set_size ) {
			throw new ArgumentError( 'reward_count', 'out_of_range' );
		}

		$value = '';

		if ( 'percent' === $reward ) {
			$value = Args::percent( $args['value'] ?? null, 'value' );
		} elseif ( 'fixed' === $reward ) {
			$value = Args::positiveInt( $args['value'] ?? null, 'value' );
		}

		return array(
			'set_size'        => $set_size,
			'reward_count'    => $count,
			'reward'          => $reward,
			'value'           => $value,
			'target'          => $target,
			'pool'            => $pool,
			'repeat'          => $repeat,
			'reward_products' => Args::idList( $args['reward_products'] ?? null, 'reward_products' ),
			'reward_terms'    => Args::idList( $args['reward_terms'] ?? null, 'reward_terms' ),
		);
	}

	/**
	 * Nominal discount for one rewarded unit priced $base.
	 *
	 * @param array{reward:string,value:string|int} $config
	 */
	public static function unitReward( array $config, int $base ): int {
		switch ( $config['reward'] ) {
			case 'free':
				return $base;
			case 'percent':
				return min( $base, Money::percent( $base, (string) $config['value'] ) );
			case 'fixed':
				return min( $base, (int) $config['value'] );
			default:
				return 0;
		}
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		$config = self::config( $args );

		/** @var array<string,Item> $items */
		$items = array();
		/** @var array<string,int> $bases */
		$bases = array();

		foreach ( $scope->match->items as $item ) {
			$base = PriceBasis::base( $item, $campaign->basis );

			if ( null !== $base && $base > 0 ) {
				$items[ $item->key ] = $item;
				$bases[ $item->key ] = $base;
			}
		}

		if ( array() === $items ) {
			return array();
		}

		$discount = static function ( $key, int $base ) use ( $items, $config ): int {
			$nominal = self::unitReward( $config, $base );

			return $nominal > 0 ? PriceBasis::effectivePerUnit( $items[ (string) $key ], $base, $nominal ) : 0;
		};

		$paired = array() !== $config['reward_products'] || array() !== $config['reward_terms'];

		if ( $paired ) {
			$per_set  = max( 1, $config['reward_count'] );
			$triggers = array();
			$rewards  = array();

			foreach ( UnitAllocation::units( array_values( $items ), $bases ) as $unit ) {
				if ( self::inRewardScope( $items[ $unit['key'] ], $config ) ) {
					$rewards[] = $unit;
				} else {
					$triggers[] = $unit;
				}
			}

			$result = UnitAllocation::allocatePaired( $triggers, UnitAllocation::sortUnits( $rewards, $config['target'] ), $config['set_size'] - $per_set, $per_set, $config['repeat'], $discount );
		} else {
			$result = UnitAllocation::allocateSets( array_values( $items ), $bases, $config, $discount );
		}

		$out = array();

		foreach ( $result['lines'] as $key => $total ) {
			$item = $items[ (string) $key ];

			$out[] = $this->adjustment(
				$campaign,
				$item->key,
				$total,
				array(
					'per_unit'     => intdiv( $total, max( 1, $item->qty ) ),
					'reward_units' => $result['units'],
					'set_size'     => $config['set_size'],
				)
			);
		}

		return $out;
	}

	/**
	 * @param array{reward_products:int[],reward_terms:int[]} $config
	 */
	private static function inRewardScope( Item $item, array $config ): bool {
		if ( in_array( $item->product_id, $config['reward_products'], true ) || ( 0 !== $item->variation_id && in_array( $item->variation_id, $config['reward_products'], true ) ) ) {
			return true;
		}

		return array() !== array_intersect( $item->termIds(), $config['reward_terms'] );
	}
}
