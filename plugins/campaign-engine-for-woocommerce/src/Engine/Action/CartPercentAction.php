<?php
/**
 * Percentage of the eligible subtotal, spread onto the lines: ['type' => 'cart_percent', 'percent' => '8'].
 *
 * A threshold ("above X") is a condition (`cart.subtotal` or `cart.eligible_subtotal`), not part of
 * the action.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Scope;
use Erusoft\CampaignEngine\Engine\Money;

final class CartPercentAction extends BaseAction {

	public function id(): string {
		return 'cart_percent';
	}

	public function level(): string {
		return 'item';
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		$percent = Args::percent( $args['percent'] ?? null, 'percent' );
		$basis = CartSpread::basis( $scope, $campaign->basis );
		$out   = array();

		foreach ( CartSpread::lines( Money::percent( $basis['subtotal'], $percent ), $basis ) as $key => $amount ) {
			$out[] = $this->adjustment( $campaign, (string) $key, $amount, array( 'cart_level' => 'percent' ) );
		}

		return $out;
	}
}
