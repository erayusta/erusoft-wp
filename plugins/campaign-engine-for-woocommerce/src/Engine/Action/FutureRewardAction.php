<?php
/**
 * Spend and earn: the cart earns a coupon for a later order.
 *
 *   ['type' => 'future_reward', 'reward' => 'fixed', 'value' => 7500]       a fixed amount (minor units)
 *   ['type' => 'future_reward', 'reward' => 'percent', 'value' => '5']      a share of the eligible subtotal
 *   optional: 'min_cart' (coupon minimum, minor units), 'valid_days', 'category_ids'
 *
 * The action issues nothing. It only marks the cart with a 'reward' adjustment; the WooCommerce layer
 * copies the mark into the order and creates the coupon after payment. Carts are evaluated many times
 * per visit and most never become orders, so issuing here would mint coupons for every page view.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Adjustment\Adjustment;
use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Scope;
use Erusoft\CampaignEngine\Engine\Money;

final class FutureRewardAction extends BaseAction {

	public function id(): string {
		return 'future_reward';
	}

	public function level(): string {
		return Adjustment::LEVEL_REWARD;
	}

	public function build( Scope $scope, Campaign $campaign, array $args ): array {
		$type = Args::choice( $args, 'reward', array( 'fixed', 'percent' ), 'fixed' );

		if ( 'fixed' === $type ) {
			$amount = Args::positiveInt( $args['value'] ?? null, 'value' );
		} else {
			$amount = Money::percent( CartSpread::basis( $scope, $campaign->basis )['subtotal'], Args::percent( $args['value'] ?? null, 'value' ) );
		}

		$min_cart   = $args['min_cart'] ?? null;
		$valid_days = $args['valid_days'] ?? null;
		$categories = Args::idList( $args['category_ids'] ?? null, 'category_ids' );

		if ( $amount <= 0 ) {
			return array();
		}

		return array(
			$this->adjustment(
				$campaign,
				'',
				0,
				array(
					'reward_type'    => 'coupon',
					'amount_minor'   => $amount,
					'min_cart_minor' => null === $min_cart || '' === $min_cart ? 0 : Args::minor( $min_cart, 'min_cart' ),
					'valid_days'     => null === $valid_days || '' === $valid_days ? 0 : Args::positiveInt( $valid_days, 'valid_days' ),
					'category_ids'   => $categories,
				),
				Adjustment::LEVEL_REWARD
			),
		);
	}
}
