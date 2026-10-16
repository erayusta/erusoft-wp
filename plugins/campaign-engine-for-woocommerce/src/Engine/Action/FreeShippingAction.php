<?php
/**
 * Free shipping: ['type' => 'free_shipping'].
 *
 * The threshold is a condition. The action only says "this campaign makes shipping free", as an
 * adjustment owned by the campaign, so usage limits and order snapshots count it like any other.
 * Which rates become free is the WooCommerce layer's decision.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Adjustment\Adjustment;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class FreeShippingAction extends BaseAction {

	public function id(): string {
		return 'free_shipping';
	}

	public function level(): string {
		return Adjustment::LEVEL_SHIPPING;
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		return array( $this->adjustment( $campaign, '', 0, array( 'free_shipping' => true ), Adjustment::LEVEL_SHIPPING ) );
	}
}
