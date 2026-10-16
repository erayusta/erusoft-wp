<?php
/**
 * Percentage off every eligible unit: ['type' => 'product_percent', 'percent' => '15'].
 *
 * The per-unit discount is rounded up to the next minor unit (Money::percent).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Scope;
use Erusoft\CampaignEngine\Engine\Money;

final class ProductPercentAction extends BaseAction {

	public function id(): string {
		return 'product_percent';
	}

	public function level(): string {
		return 'item';
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		$percent = Args::percent( $args['percent'] ?? null, 'percent' );
		$out = array();

		foreach ( $scope->match->items as $item ) {
			$base = PriceBasis::base( $item, $campaign->basis );

			if ( null === $base || $base <= 0 ) {
				continue;
			}

			$per_unit = PriceBasis::effectivePerUnit( $item, $base, min( $base, Money::percent( $base, $percent ) ) );

			if ( $per_unit > 0 ) {
				$out[] = $this->adjustment( $campaign, $item->key, $per_unit * $item->qty, array( 'per_unit' => $per_unit ) );
			}
		}

		return $out;
	}
}
