<?php
/**
 * Condition lookup by id. `withDefaults()` holds every built-in condition; the WooCommerce layer may
 * add its own before handing the registry to the evaluator.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition;

final class ConditionRegistry {

	/** @var array<string,ConditionInterface> */
	private array $conditions = array();

	/** A registry holding the built-in conditions. */
	public static function withDefaults(): self {
		$registry = new self();

		$defaults = array(
			new Cart\SubtotalCondition(),
			new Cart\EligibleSubtotalCondition(),
			new Cart\QuantityCondition(),
			new Cart\EligibleQuantityCondition(),
			new Cart\CouponCondition(),
			new Cart\PaymentMethodCondition(),
			new Cart\ShippingMethodCondition(),
			new Cart\ShippingCountryCondition(),
			new Item\ProductCondition(),
			new Item\QuantityCondition(),
			new Item\CategoryCondition(),
			new Item\TagCondition(),
			new Item\BrandCondition(),
			new Item\TermCondition(),
			new Item\AttributeCondition(),
			new Item\SkuCondition(),
			new Item\OnSaleCondition(),
			new Item\PriceCondition(),
			new Item\StockStatusCondition(),
			new Item\StockQuantityCondition(),
			new Item\AgeDaysCondition(),
			new Customer\LoggedInCondition(),
			new Customer\RoleCondition(),
			new Customer\OrderCountCondition(),
			new Customer\FirstOrderCondition(),
			new Customer\TotalSpentCondition(),
			new Customer\DaysSinceLastOrderCondition(),
			new DateTime\DateRangeCondition(),
			new DateTime\WeekdayCondition(),
			new DateTime\TimeWindowCondition(),
		);

		foreach ( $defaults as $condition ) {
			$registry->add( $condition );
		}

		return $registry;
	}

	/** Adds or replaces a condition under its id. */
	public function add( ConditionInterface $condition ): void {
		$this->conditions[ $condition->id() ] = $condition;
	}

	public function get( string $id ): ?ConditionInterface {
		return $this->conditions[ $id ] ?? null;
	}

	/** @return array<string,ConditionInterface> */
	public function all(): array {
		return $this->conditions;
	}
}
