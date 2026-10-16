<?php
/**
 * Action lookup by type. `withDefaults()` holds every built-in action.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

final class ActionRegistry {

	/** @var array<string,ActionInterface> */
	private array $actions = array();

	/** A registry holding the built-in actions. */
	public static function withDefaults(): self {
		$registry = new self();

		$defaults = array(
			new ProductPercentAction(),
			new ProductFixedAction(),
			new ProductPriceAction(),
			new CartPercentAction(),
			new CartFixedAction(),
			new TieredAction(),
			new SetRewardAction(),
			new FreeShippingAction(),
			new FutureRewardAction(),
		);

		foreach ( $defaults as $action ) {
			$registry->add( $action );
		}

		return $registry;
	}

	/** Adds or replaces an action under its type. */
	public function add( ActionInterface $action ): void {
		$this->actions[ $action->id() ] = $action;
	}

	public function get( string $id ): ?ActionInterface {
		return $this->actions[ $id ] ?? null;
	}

	/** @return array<string,ActionInterface> */
	public function all(): array {
		return $this->actions;
	}
}
