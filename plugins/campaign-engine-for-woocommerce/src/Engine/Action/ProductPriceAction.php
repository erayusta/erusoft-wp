<?php
/**
 * Campaign price: ['type' => 'product_price', 'price' => 19990] (minor units, above zero). Every
 * eligible unit priced above it is brought down to it; cheaper units are left alone. A price of zero
 * is refused: giving products away is a gift rule, not a price.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class ProductPriceAction extends BaseAction {

	public function id(): string {
		return 'product_price';
	}

	public function level(): string {
		return 'item';
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		$target = Args::positiveInt( $args['price'] ?? null, 'price' );
		$out = array();

		foreach ( $scope->match->items as $item ) {
			$base = PriceBasis::base( $item, $campaign->basis );

			if ( null === $base || $base <= $target ) {
				continue;
			}

			$per_unit = PriceBasis::effectivePerUnit( $item, $base, $base - $target );

			if ( $per_unit > 0 ) {
				$out[] = $this->adjustment( $campaign, $item->key, $per_unit * $item->qty, array( 'per_unit' => $per_unit ) );
			}
		}

		return $out;
	}
}
