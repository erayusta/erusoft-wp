<?php
/**
 * Shared shape of value conditions: a subclass produces the left-hand value, the base compares it.
 *
 * A null left value means "unknown" (no publish date, no previous order…) and never matches, whatever
 * the operator. Text values are compared case-insensitively when the subclass says so.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition;

use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

abstract class BaseCondition implements ConditionInterface {

	/**
	 * The value the condition reads from the context; null when unknown.
	 *
	 * @return mixed
	 */
	abstract protected function left( Scope $scope, ?Item $item );

	/** Operator used when the node has none. */
	protected function defaultOperator(): string {
		return '==';
	}

	/** Whether text on both sides is lower-cased before comparing. */
	protected function caseInsensitive(): bool {
		return false;
	}

	public function matches( Scope $scope, ?Item $item, array $args ): bool {
		if ( 'item' === $this->scope() && null === $item ) {
			return false;
		}

		$left = $this->left( $scope, $item );

		if ( null === $left ) {
			return false;
		}

		$operator = isset( $args['cmp'] ) && is_string( $args['cmp'] ) && '' !== $args['cmp'] ? $args['cmp'] : $this->defaultOperator();
		$right    = $args['value'] ?? null;

		if ( $this->caseInsensitive() ) {
			$left  = self::lower( $left );
			$right = self::lower( $right );
		}

		return Comparator::compare( $left, $operator, $right );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function lower( $value ) {
		if ( is_string( $value ) ) {
			return strtolower( $value );
		}

		if ( is_array( $value ) ) {
			return array_map( static fn( $v ) => is_string( $v ) ? strtolower( $v ) : $v, $value );
		}

		return $value;
	}
}
