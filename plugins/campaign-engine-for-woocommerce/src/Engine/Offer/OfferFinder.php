<?php
/**
 * Finds near misses: campaigns the cart does not get yet but would get with a little more.
 *
 * Three sources, tried in this order for each active, not yet applied campaign:
 *   1. a threshold in its rules — `cart.subtotal`, `cart.eligible_subtotal`, `cart.quantity` or
 *      `cart.eligible_quantity` with `>=` or `>`, at the root or under AND groups only (a leaf under OR
 *      is not required, so promising it would be a promise the campaign may not keep);
 *   2. a set reward whose first set is not complete yet;
 *   3. a tiered action whose first tier is not reached yet.
 * An offer is made only when everything else already holds: the finder relaxes the single missing
 * threshold and runs the full match; if any other condition fails (role, day, first order…) there is
 * no offer. Paired bundles and carts with more than one unmet threshold give no offer either.
 *
 * Ranking: offers with a known gain first, by gain per effort (compared by cross-multiplication, no
 * floats), then smaller effort, then lower campaign id; offers without a known gain after them by
 * effort. At most `limit` offers (default 2) are returned.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Offer;

use Erusoft\CampaignEngine\Engine\Action\PriceBasis;
use Erusoft\CampaignEngine\Engine\Action\SetRewardAction;
use Erusoft\CampaignEngine\Engine\Action\TieredAction;
use Erusoft\CampaignEngine\Engine\Adjustment\UnitAllocation;
use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Condition\ConditionRegistry;
use Erusoft\CampaignEngine\Engine\Context\Context;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Matcher;
use Erusoft\CampaignEngine\Engine\Money;

final class OfferFinder {

	public const LIMIT = 2;

	public const AMOUNT_CONDITIONS   = array( 'cart.subtotal', 'cart.eligible_subtotal' );
	public const QUANTITY_CONDITIONS = array( 'cart.quantity', 'cart.eligible_quantity' );

	private Matcher $matcher;

	public function __construct( ConditionRegistry $conditions ) {
		$this->matcher = new Matcher( $conditions );
	}

	/**
	 * @param Campaign[] $campaigns
	 * @param int[]      $applied_ids Campaigns the cart already gets (from the evaluation result).
	 * @param int        $shipping_cost_minor Current shipping cost, the gain of a free-shipping offer.
	 * @return Offer[]
	 */
	public function find( Context $context, array $campaigns, array $applied_ids = array(), int $shipping_cost_minor = 0, int $limit = self::LIMIT ): array {
		$applied = array_fill_keys( array_map( 'intval', $applied_ids ), true );
		$offers  = array();

		foreach ( $campaigns as $campaign ) {
			if ( ! $campaign instanceof Campaign || isset( $applied[ $campaign->id ] ) || ! $campaign->schedule->isActiveAt( $context->now ) ) {
				continue;
			}

			if ( array() === $this->matcher->scoped( $campaign, $context ) ) {
				continue;
			}

			try {
				$offer = $this->thresholdOffer( $context, $campaign, $shipping_cost_minor ) ?? $this->actionOffer( $context, $campaign );
			} catch ( ArgumentError $e ) {
				$offer = null; // A campaign with broken arguments cannot apply, so it is not offered either.
			}

			if ( null !== $offer ) {
				$offers[] = $offer;
			}
		}

		return self::rank( $offers, $limit );
	}

	/**
	 * @param Offer[] $offers
	 * @return Offer[]
	 */
	public static function rank( array $offers, int $limit = self::LIMIT ): array {
		$known   = array();
		$unknown = array();

		foreach ( $offers as $offer ) {
			if ( $offer->value_known && $offer->value_minor > 0 && $offer->effort_minor > 0 ) {
				$known[] = $offer;
			} else {
				$unknown[] = $offer;
			}
		}

		usort(
			$known,
			static function ( Offer $a, Offer $b ): int {
				$left  = $a->value_minor * $b->effort_minor;
				$right = $b->value_minor * $a->effort_minor;

				if ( $left !== $right ) {
					return $right <=> $left;
				}

				return array( $a->effort_minor, $a->campaign_id ) <=> array( $b->effort_minor, $b->campaign_id );
			}
		);

		usort( $unknown, static fn( Offer $a, Offer $b ): int => array( $a->effort_minor, $a->campaign_id ) <=> array( $b->effort_minor, $b->campaign_id ) );

		return array_slice( array_merge( $known, $unknown ), 0, max( 1, $limit ) );
	}

	/** Source 1: one unmet amount/quantity threshold, everything else holding. */
	private function thresholdOffer( Context $context, Campaign $campaign, int $shipping_cost_minor ): ?Offer {
		$unmet = array();

		foreach ( self::requiredThresholds( $campaign->rules ) as $threshold ) {
			$relaxed = self::relax( $campaign->rules, $threshold['path'] );
			$match   = $this->matcher->match( $campaign, $context, $relaxed, true );

			if ( Matcher::OK !== $match['status'] ) {
				continue;
			}

			$current = self::measure( $threshold['condition'], $context, $match['eligible'] );

			if ( $current < $threshold['target'] ) {
				$unmet[] = $threshold + array(
					'current'  => $current,
					'eligible' => $match['eligible'],
				);
			}
		}

		if ( 1 !== count( $unmet ) ) {
			return null;
		}

		$t      = $unmet[0];
		$amount = in_array( $t['condition'], self::AMOUNT_CONDITIONS, true );

		if ( $t['current'] <= 0 ) {
			return null;
		}

		$value  = $amount ? self::valueAtAmount( $campaign, $t['target'], $shipping_cost_minor ) : array( 0, false );
		$effort = $amount ? $t['target'] - $t['current'] : ( $t['target'] - $t['current'] ) * self::cheapest( $t['eligible'] );

		return new Offer( $campaign->id, $amount ? Offer::KIND_AMOUNT : Offer::KIND_QUANTITY, $t['current'], $t['target'], $effort, $value[0], $value[1], self::rewardOf( $campaign ), null, $campaign->label );
	}

	/** Sources 2 and 3: the campaign's own rules already hold, only its first set or tier is missing. */
	private function actionOffer( Context $context, Campaign $campaign ): ?Offer {
		$match = $this->matcher->match( $campaign, $context );

		if ( Matcher::OK !== $match['status'] ) {
			return null;
		}

		foreach ( $campaign->actions as $row ) {
			$type = $row['type'] ?? '';

			if ( 'set_reward' === $type ) {
				return self::setOffer( $campaign, $row, $match['eligible'] );
			}

			if ( 'tiered' === $type ) {
				return self::tierOffer( $campaign, $row, $match['eligible'] );
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param Item[]              $eligible
	 */
	private static function setOffer( Campaign $campaign, array $row, array $eligible ): ?Offer {
		$config = SetRewardAction::config( $row );

		if ( array() !== $config['reward_products'] || array() !== $config['reward_terms'] ) {
			return null;
		}

		$items = self::priced( $campaign, $eligible );

		if ( array() === $items ) {
			return null;
		}

		$single = 1 === count( array_unique( array_map( static fn( array $p ): string => $p[0]->productKey(), $items ) ) );

		if ( UnitAllocation::POOL_PRODUCT === $config['pool'] && ! $single ) {
			return null;
		}

		$current = array_sum( array_map( static fn( array $p ): int => $p[0]->qty, $items ) );

		if ( $current >= $config['set_size'] ) {
			return null;
		}

		$value  = 0;
		$known  = false;
		$reward = array(
			'type'  => $config['reward'],
			'value' => (string) $config['value'],
		);

		if ( $single ) {
			$item  = $items[0][0];
			$base  = $items[0][1];
			$units = 0 === $config['reward_count'] ? $config['set_size'] : $config['reward_count'];
			$value = PriceBasis::effectivePerUnit( $item, $base, SetRewardAction::unitReward( $config, $base ) ) * $units;
			$known = $value > 0;
		}

		$missing = $config['set_size'] - $current;

		return new Offer( $campaign->id, Offer::KIND_QUANTITY, $current, $config['set_size'], $missing * self::cheapest( $eligible ), $value, $known, $reward, $single ? $items[0][0]->key : null, $campaign->label );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param Item[]              $eligible
	 */
	private static function tierOffer( Campaign $campaign, array $row, array $eligible ): ?Offer {
		$measure = Args::choice( $row, 'measure', array( 'quantity', 'subtotal' ), 'quantity' );
		$mode    = Args::choice( $row, 'mode', array( 'all_units', 'incremental' ), 'all_units' );
		$group   = Args::choice( $row, 'group', array( 'product', 'pool' ), 'product' );
		$tiers   = TieredAction::tiers( $row['tiers'] ?? null, $measure );
		$items   = self::priced( $campaign, $eligible );

		if ( 'subtotal' === $measure ) {
			$mode = 'all_units';
		}

		if ( array() === $items ) {
			return null;
		}

		$single = 1 === count( array_unique( array_map( static fn( array $p ): string => $p[0]->productKey(), $items ) ) );

		if ( 'product' === $group && ! $single ) {
			return null;
		}

		$current = 0;

		foreach ( $items as $pair ) {
			$current += 'subtotal' === $measure ? $pair[1] * $pair[0]->qty : $pair[0]->qty;
		}

		if ( null !== TieredAction::tierFor( $tiers, $current ) ) {
			return null;
		}

		$next = null;

		foreach ( $tiers as $tier ) {
			if ( $tier['min'] > $current ) {
				$next = $tier;
				break;
			}
		}

		if ( null === $next || $current <= 0 ) {
			return null;
		}

		$value = 0;

		if ( 'subtotal' === $measure ) {
			// At the threshold the group subtotal equals `min`.
			$value = TieredAction::isGroupAmount( $next ) ? min( (int) $next['value'], $next['min'] ) : Money::percent( $next['min'], $next['value'] );
		} elseif ( $single ) {
			$item  = $items[0][0];
			$base  = $items[0][1];
			$units = 'incremental' === $mode ? 1 : $next['min'];

			if ( TieredAction::isGroupAmount( $next ) ) {
				$value = min( (int) $next['value'], $units * PriceBasis::effectivePerUnit( $item, $base, $base ) );
			} else {
				$value = PriceBasis::effectivePerUnit( $item, $base, TieredAction::tierAmount( $next, $base ) ) * $units;
			}
		}

		$missing = $next['min'] - $current;
		$amount  = 'subtotal' === $measure;

		return new Offer(
			$campaign->id,
			$amount ? Offer::KIND_AMOUNT : Offer::KIND_QUANTITY,
			$current,
			$next['min'],
			$amount ? $missing : $missing * self::cheapest( $eligible ),
			$value,
			$value > 0,
			array(
				'type'  => $next['type'],
				'value' => $next['value'],
			),
			$single ? $items[0][0]->key : null,
			$campaign->label
		);
	}

	/**
	 * Threshold leaves reachable through the root and AND groups, with their path in the tree.
	 *
	 * @param array<string,mixed>|null $node
	 * @param int[]                    $path
	 * @return array<int,array{condition:string,target:int,path:int[]}>
	 */
	private static function requiredThresholds( ?array $node, array $path = array() ): array {
		if ( null === $node || array() === $node ) {
			return array();
		}

		if ( isset( $node['condition'] ) ) {
			$id = is_string( $node['condition'] ) ? $node['condition'] : '';

			if ( ! in_array( $id, array_merge( self::AMOUNT_CONDITIONS, self::QUANTITY_CONDITIONS ), true ) ) {
				return array();
			}

			$cmp = $node['cmp'] ?? '';

			try {
				$value = Args::minor( $node['value'] ?? null, 'value' );
			} catch ( ArgumentError $e ) {
				return array(); // An unreadable threshold never matches, so it is not promised either.
			}

			if ( '>=' !== $cmp && '>' !== $cmp ) {
				return array();
			}

			$target = $value + ( '>' === $cmp ? 1 : 0 );

			return $target > 0 ? array(
				array(
					'condition' => $id,
					'target'    => $target,
					'path'      => $path,
				),
			) : array();
		}

		if ( 'and' !== strtolower( is_string( $node['op'] ?? null ) ? $node['op'] : '' ) || ! is_array( $node['children'] ?? null ) ) {
			return array();
		}

		$out = array();

		foreach ( $node['children'] as $index => $child ) {
			if ( is_array( $child ) ) {
				$out = array_merge( $out, self::requiredThresholds( $child, array_merge( $path, array( $index ) ) ) );
			}
		}

		return $out;
	}

	/**
	 * A copy of the tree with the leaf at $path replaced by an always-true empty group.
	 *
	 * @param array<string,mixed>|null $node
	 * @param int[]                    $path
	 * @return array<string,mixed>|null
	 */
	private static function relax( ?array $node, array $path ): ?array {
		if ( null === $node ) {
			return null;
		}

		if ( array() === $path ) {
			return array(
				'op'       => 'and',
				'children' => array(),
			);
		}

		$index                      = array_shift( $path );
		$node['children'][ $index ] = self::relax( $node['children'][ $index ], $path );

		return $node;
	}

	/**
	 * @param Item[] $eligible
	 */
	private static function measure( string $condition, Context $context, array $eligible ): int {
		switch ( $condition ) {
			case 'cart.subtotal':
				return $context->cart->subtotal_minor;
			case 'cart.quantity':
				return $context->totalQuantity();
			case 'cart.eligible_quantity':
				return array_sum( array_map( static fn( Item $i ): int => $i->qty, $eligible ) );
			default:
				return array_sum( array_map( static fn( Item $i ): int => $i->lineSubtotalMinor(), $eligible ) );
		}
	}

	/**
	 * Expected gain of a campaign once an amount threshold is reached; [amount, known].
	 *
	 * @return array{0:int,1:bool}
	 */
	private static function valueAtAmount( Campaign $campaign, int $target, int $shipping_cost_minor ): array {
		foreach ( $campaign->actions as $row ) {
			$type = $row['type'] ?? '';

			if ( 'cart_percent' === $type ) {
				return array( Money::percent( $target, Args::percent( $row['percent'] ?? null ) ), true );
			}

			if ( 'cart_fixed' === $type ) {
				return array( min( $target, Args::positiveInt( $row['amount'] ?? null, 'amount' ) ), true );
			}

			if ( 'free_shipping' === $type ) {
				return array( max( 0, $shipping_cost_minor ), $shipping_cost_minor > 0 );
			}

			if ( 'future_reward' === $type ) {
				$kind   = Args::choice( $row, 'reward', array( 'fixed', 'percent' ), 'fixed' );
				$amount = 'percent' === $kind ? Money::percent( $target, Args::percent( $row['value'] ?? null, 'value' ) ) : Args::positiveInt( $row['value'] ?? null, 'value' );

				return array( $amount, $amount > 0 );
			}
		}

		return array( 0, false );
	}

	/**
	 * The reward of the campaign's first action, for the offer sentence.
	 *
	 * @return array{type:string,value:string}
	 */
	private static function rewardOf( Campaign $campaign ): array {
		$row  = $campaign->actions[0] ?? array();
		$type = $row['type'] ?? '';

		switch ( $type ) {
			case 'cart_percent':
			case 'product_percent':
				return array(
					'type'  => 'percent',
					'value' => Args::percent( $row['percent'] ?? null ),
				);
			case 'cart_fixed':
			case 'product_fixed':
				return array(
					'type'  => 'fixed',
					'value' => (string) Args::positiveInt( $row['amount'] ?? null, 'amount' ),
				);
			case 'product_price':
				return array(
					'type'  => 'price',
					'value' => (string) Args::positiveInt( $row['price'] ?? null, 'price' ),
				);
			case 'free_shipping':
				return array(
					'type'  => 'free_shipping',
					'value' => '',
				);
			case 'future_reward':
				return array(
					'type'  => 'reward_coupon',
					'value' => is_scalar( $row['value'] ?? null ) ? (string) $row['value'] : '',
				);
			case 'set_reward':
				$config = SetRewardAction::config( $row );
				return array(
					'type'  => $config['reward'],
					'value' => (string) $config['value'],
				);
			default:
				return array(
					'type'  => is_string( $type ) ? $type : '',
					'value' => '',
				);
		}
	}

	/**
	 * Eligible lines with their price at the campaign's basis; skipped lines left out.
	 *
	 * @param Item[] $eligible
	 * @return array<int,array{0:Item,1:int}>
	 */
	private static function priced( Campaign $campaign, array $eligible ): array {
		$out = array();

		foreach ( $eligible as $item ) {
			$base = PriceBasis::base( $item, $campaign->basis );

			if ( null !== $base && $base > 0 && $item->qty > 0 ) {
				$out[] = array( $item, $base );
			}
		}

		return $out;
	}

	/**
	 * The cheapest current unit price among the lines (0 when none).
	 *
	 * @param Item[] $items
	 */
	private static function cheapest( array $items ): int {
		$cheapest = 0;

		foreach ( $items as $item ) {
			if ( $item->unit_minor > 0 && ( 0 === $cheapest || $item->unit_minor < $cheapest ) ) {
				$cheapest = $item->unit_minor;
			}
		}

		return $cheapest;
	}
}
