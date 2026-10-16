<?php
/**
 * Evaluates a nested AND/OR tree of conditions.
 *
 * A node is either a group or a leaf:
 *   ['op' => 'and'|'or', 'children' => [ node, … ]]
 *   ['condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 60000]   (the leaf is its own args)
 *
 * An empty tree or an empty group passes. An unknown operator or condition id fails (a rule the engine
 * cannot read must not grant a discount).
 *
 * Two passes. A campaign first runs `evaluate()` once per line with that line as $item: item
 * conditions judge the line, cart conditions judge the whole cart, and the lines that pass become the
 * campaign's eligible lines. `evaluateCart()` then runs once over a scope built from those eligible
 * lines, so values such as `cart.eligible_quantity` count only them; in this pass item leaves count as
 * satisfied, because each eligible line has already satisfied the whole tree in the first pass.
 * Calling `evaluateCart()` on lines that did not go through the first pass would wrongly let an item
 * leaf satisfy an OR group.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition;

use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class RuleTree {

	/** Nesting deeper than this is refused (fails), so a hostile tree cannot exhaust the stack. */
	public const MAX_DEPTH = 8;

	/** First pass: one line against the whole tree. */
	public static function evaluate( ?array $node, ConditionRegistry $registry, Scope $scope, ?Item $item ): bool {
		return self::walk( $node, $registry, $scope, $item, false, 0 );
	}

	/** Second pass: the cart, over the lines that passed the first pass. */
	public static function evaluateCart( ?array $node, ConditionRegistry $registry, Scope $scope ): bool {
		return self::walk( $node, $registry, $scope, null, true, 0 );
	}

	/**
	 * Every leaf node of the tree, in order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function leaves( ?array $node ): array {
		if ( null === $node || array() === $node ) {
			return array();
		}

		if ( isset( $node['condition'] ) ) {
			return array( $node );
		}

		$out = array();

		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$out = array_merge( $out, self::leaves( $child ) );
			}
		}

		return $out;
	}

	private static function walk( ?array $node, ConditionRegistry $registry, Scope $scope, ?Item $item, bool $item_leaves_pass, int $depth ): bool {
		if ( null === $node || array() === $node ) {
			return true;
		}

		if ( $depth > self::MAX_DEPTH ) {
			return false;
		}

		if ( isset( $node['condition'] ) ) {
			$condition = $registry->get( is_string( $node['condition'] ) ? $node['condition'] : '' );

			if ( null === $condition ) {
				return false;
			}

			if ( $item_leaves_pass && 'item' === $condition->scope() ) {
				return true;
			}

			return $condition->matches( $scope, $item, $node );
		}

		$op       = is_string( $node['op'] ?? null ) ? strtolower( $node['op'] ) : '';
		$children = is_array( $node['children'] ?? null ) ? $node['children'] : null;

		if ( null === $children || ! in_array( $op, array( 'and', 'or' ), true ) ) {
			return false;
		}

		if ( array() === $children ) {
			return true;
		}

		foreach ( $children as $child ) {
			$passed = is_array( $child ) && self::walk( $child, $registry, $scope, $item, $item_leaves_pass, $depth + 1 );

			if ( 'or' === $op && $passed ) {
				return true;
			}

			if ( 'and' === $op && ! $passed ) {
				return false;
			}
		}

		return 'and' === $op;
	}
}
