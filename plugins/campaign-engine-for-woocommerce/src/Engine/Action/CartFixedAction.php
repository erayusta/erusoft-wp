<?php
/**
 * Fixed amount off the eligible lines: ['type' => 'cart_fixed', 'amount' => 7500] (minor units).
 *
 * The amount is capped at the eligible subtotal; nothing carries over to another order and no line
 * goes below zero.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class CartFixedAction extends BaseAction {

	public function id(): string {
		return 'cart_fixed';
	}

	public function level(): string {
		return 'item';
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		$amount = Args::positiveInt( $args['amount'] ?? null, 'amount' );
		$basis = CartSpread::basis( $scope, $campaign->basis );
		$out   = array();

		foreach ( CartSpread::lines( min( $amount, $basis['subtotal'] ), $basis ) as $key => $share ) {
			$out[] = $this->adjustment( $campaign, (string) $key, $share, array( 'cart_level' => 'fixed' ) );
		}

		return $out;
	}
}
