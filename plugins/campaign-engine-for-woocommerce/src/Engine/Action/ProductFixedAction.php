<?php
/**
 * Fixed amount off every eligible unit: ['type' => 'product_fixed', 'amount' => 2500] (minor units),
 * capped at the unit price.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class ProductFixedAction extends BaseAction {

	public function id(): string {
		return 'product_fixed';
	}

	public function level(): string {
		return 'item';
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		$amount = Args::positiveInt( $args['amount'] ?? null, 'amount' );
		$out = array();

		foreach ( $scope->match->items as $item ) {
			$base = PriceBasis::base( $item, $campaign->basis );

			if ( null === $base || $base <= 0 ) {
				continue;
			}

			$per_unit = PriceBasis::effectivePerUnit( $item, $base, min( $base, $amount ) );

			if ( $per_unit > 0 ) {
				$out[] = $this->adjustment( $campaign, $item->key, $per_unit * $item->qty, array( 'per_unit' => $per_unit ) );
			}
		}

		return $out;
	}
}
