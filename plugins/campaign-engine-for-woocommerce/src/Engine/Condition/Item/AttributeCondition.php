<?php
/**
 * Values of one product attribute on the line, e.g.
 * ['condition' => 'item.attribute', 'attribute' => 'pa_renk', 'cmp' => 'in', 'value' => ['lacivert']].
 *
 * Slugs compare case-insensitively. A line without the attribute has no values: `in` fails, `not_in`
 * passes. A node without an attribute name never matches.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\Comparator;
use Erusoft\CampaignEngine\Engine\Condition\ConditionInterface;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class AttributeCondition implements ConditionInterface {

	public function id(): string {
		return 'item.attribute';
	}

	public function group(): string {
		return 'item';
	}

	public function scope(): string {
		return 'item';
	}

	public function matches( Scope $scope, ?Item $item, array $args ): bool {
		$name = is_string( $args['attribute'] ?? null ) ? strtolower( trim( $args['attribute'] ) ) : '';

		if ( null === $item || '' === $name ) {
			return false;
		}

		$operator = is_string( $args['cmp'] ?? null ) && '' !== $args['cmp'] ? $args['cmp'] : 'in';
		$right    = array_map( static fn( $v ) => is_string( $v ) ? strtolower( trim( $v ) ) : $v, (array) ( $args['value'] ?? array() ) );

		return Comparator::compare( $item->attributes[ $name ] ?? array(), $operator, $right );
	}
}
