<?php
/**
 * Checks a campaign definition with the same readers the engine uses (Args, Campaign::fromArray, the
 * strict tier and set readers), so a campaign that saves is one the engine can run, and a malformed
 * value is refused with its field and reason instead of being widened at run time.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Campaign;

use Erusoft\CampaignEngine\Engine\Action\ActionRegistry;
use Erusoft\CampaignEngine\Engine\Action\SetRewardAction;
use Erusoft\CampaignEngine\Engine\Action\TieredAction;
use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Condition\ConditionRegistry;
use Erusoft\CampaignEngine\Engine\Condition\RuleTree;

final class DefinitionValidator {

	/**
	 * @param array<string,mixed> $def
	 * @throws ArgumentError
	 */
	public static function check( array $def ): void {
		$actions = $def['actions'] ?? null;

		if ( ! is_array( $actions ) || array() === $actions ) {
			throw new ArgumentError( 'actions', 'missing' );
		}

		$registry = ActionRegistry::withDefaults();

		foreach ( $actions as $row ) {
			if ( ! is_array( $row ) ) {
				throw new ArgumentError( 'actions', 'invalid' );
			}

			$type = is_string( $row['type'] ?? null ) ? $row['type'] : '';

			if ( null === $registry->get( $type ) ) {
				throw new ArgumentError( 'actions.type', 'unknown_choice' );
			}

			self::action( $type, $row );
		}

		if ( isset( $def['basis'] ) && ! in_array( $def['basis'], Campaign::BASES, true ) ) {
			throw new ArgumentError( 'basis', 'unknown_choice' );
		}

		if ( isset( $def['priority'] ) && ( ! is_numeric( $def['priority'] ) || (int) $def['priority'] != $def['priority'] || abs( (int) $def['priority'] ) > 100000 ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
			throw new ArgumentError( 'priority', 'invalid' );
		}

		foreach ( array( 'usage_limit', 'usage_limit_per_customer' ) as $field ) {
			if ( isset( $def[ $field ] ) && '' !== $def[ $field ] ) {
				Args::positiveInt( $def[ $field ], $field );
			}
		}

		if ( isset( $def['rules'] ) && null !== $def['rules'] ) {
			if ( ! is_array( $def['rules'] ) ) {
				throw new ArgumentError( 'rules', 'invalid' );
			}
			self::rules( $def['rules'], ConditionRegistry::withDefaults(), 0 );
		}

		if ( isset( $def['schedule'] ) && null !== $def['schedule'] && ! is_array( $def['schedule'] ) ) {
			throw new ArgumentError( 'schedule', 'invalid' );
		}

		// Caps, targets and the schedule shape go through the engine's own constructor.
		Campaign::fromArray( array_merge( $def, array( 'id' => 1 ) ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @throws ArgumentError
	 */
	private static function action( string $type, array $row ): void {
		switch ( $type ) {
			case 'product_percent':
			case 'cart_percent':
				Args::percent( $row['percent'] ?? null, 'percent' );
				break;
			case 'product_fixed':
			case 'cart_fixed':
				Args::positiveInt( $row['amount'] ?? null, 'amount' );
				break;
			case 'product_price':
				Args::positiveInt( $row['price'] ?? null, 'price' );
				break;
			case 'tiered':
				$measure = Args::choice( $row, 'measure', array( 'quantity', 'subtotal' ), 'quantity' );
				Args::choice( $row, 'mode', array( 'all_units', 'incremental' ), 'all_units' );
				Args::choice( $row, 'group', array( 'product', 'pool' ), 'product' );
				TieredAction::tiers( $row['tiers'] ?? null, $measure );
				break;
			case 'set_reward':
				SetRewardAction::config( $row );
				break;
			case 'future_reward':
				$reward = Args::choice( $row, 'reward', array( 'fixed', 'percent' ), 'fixed' );
				if ( 'fixed' === $reward ) {
					Args::positiveInt( $row['value'] ?? null, 'value' );
				} else {
					Args::percent( $row['value'] ?? null, 'value' );
				}
				if ( isset( $row['min_cart'] ) && '' !== $row['min_cart'] ) {
					Args::minor( $row['min_cart'], 'min_cart' );
				}
				if ( isset( $row['valid_days'] ) && '' !== $row['valid_days'] ) {
					Args::positiveInt( $row['valid_days'], 'valid_days' );
				}
				Args::idList( $row['category_ids'] ?? null, 'category_ids' );
				break;
			case 'free_shipping':
			default:
				break;
		}
	}

	/**
	 * @param array<string,mixed> $node
	 * @throws ArgumentError
	 */
	private static function rules( array $node, ConditionRegistry $registry, int $depth ): void {
		if ( array() === $node ) {
			return;
		}

		// Same reading as RuleTree::walk(): a deeper tree, a bad operator or an unknown condition would
		// silently fail every cart, so it is refused when saving.
		if ( $depth > RuleTree::MAX_DEPTH ) {
			throw new ArgumentError( 'rules', 'out_of_range' );
		}

		if ( isset( $node['condition'] ) ) {
			if ( null === $registry->get( is_string( $node['condition'] ) ? $node['condition'] : '' ) ) {
				throw new ArgumentError( 'rules.condition', 'unknown_choice' );
			}

			return;
		}

		$op = is_string( $node['op'] ?? null ) ? strtolower( $node['op'] ) : '';

		if ( ! in_array( $op, array( 'and', 'or' ), true ) || ! is_array( $node['children'] ?? null ) ) {
			throw new ArgumentError( 'rules', 'invalid' );
		}

		foreach ( $node['children'] as $child ) {
			if ( ! is_array( $child ) ) {
				throw new ArgumentError( 'rules', 'invalid' );
			}

			self::rules( $child, $registry, $depth + 1 );
		}
	}
}
