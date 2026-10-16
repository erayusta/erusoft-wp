<?php
/**
 * Writes the snapshot of the applied campaigns into the order (`_eru_ce_applied`), at checkout, from
 * the same evaluation that priced the cart.
 *
 * Order meta:
 *   v, currency, decimals, prices_include_tax, evaluated_at,
 *   campaigns: [{id, version, label, discount_minor, free_shipping, reward_minor}],
 *   total_discount_minor (= Σ campaigns discount_minor = Σ line discounts),
 *   free_shipping: [campaign ids],
 *   rewards: [{campaign_id, version, label, amount_minor, min_cart_minor, valid_days, category_ids}]
 *            (only campaigns the engine applied: a campaign that lost every line under best/first
 *            stacking is not applied and earns nothing).
 * Line item meta (every line of an order that has a campaign):
 *   {base_unit_minor, qty, discount_minor, campaigns: [{id, version, label, discount_minor}]}
 *
 * Σ (base_unit_minor × qty) − Σ line subtotals = total_discount_minor (incl. tax when prices include tax).
 * `payment_bound`: the gateway the order was priced with, when an applied campaign has a
 * `cart.payment_method` condition ('' otherwise); paying such an order later with another gateway is
 * refused (PaymentBinding). `signature`: the campaign outcome, for the block-checkout re-check.
 *
 * Classic checkout writes in `woocommerce_checkout_create_order`, the block checkout in
 * `woocommerce_store_api_checkout_update_order_from_request`, but only for the session's own draft
 * order: the pay-for-order route fires the same hook for an existing order, whose snapshot must never
 * be rewritten from whatever the cart holds now. A resumed classic order is rewritten.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use Erusoft\CampaignEngine\Engine\Adjustment\Adjustment;
use Erusoft\CampaignEngine\Engine\Condition\RuleTree;
use Erusoft\CampaignEngine\Engine\EvaluationResult;
use WC_Order;
use WC_Order_Item_Product;

final class OrderWriter {

	public const META = '_eru_ce_applied';

	public static function hooks(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'lineItem' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'writeOrder' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'writeDraftOrder' ), 10, 2 );
	}

	/** @return array<string,mixed> the order's snapshot, or [] */
	public static function snapshot( WC_Order $order ): array {
		$snap = $order->get_meta( self::META );

		return is_array( $snap ) ? $snap : array();
	}

	/** Whether an applied campaign's rules look at the payment method. */
	private static function dependsOnPaymentMethod( EvaluationResult $result, array $applied ): bool {
		foreach ( $result->evaluatedCandidates() as $candidate ) {
			if ( ! isset( $applied[ $candidate->campaign->id ] ) ) {
				continue;
			}

			foreach ( RuleTree::leaves( $candidate->campaign->rules ) as $leaf ) {
				if ( 'cart.payment_method' === ( $leaf['condition'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** @return int[] campaign ids of the snapshot */
	public static function campaignIds( array $snapshot ): array {
		return array_values( array_map( static fn( $c ): int => (int) ( $c['id'] ?? 0 ), (array) ( $snapshot['campaigns'] ?? array() ) ) );
	}

	/** @return array<int,int> campaign id => discount */
	public static function discounts( array $snapshot ): array {
		$out = array();

		foreach ( (array) ( $snapshot['campaigns'] ?? array() ) as $c ) {
			$out[ (int) ( $c['id'] ?? 0 ) ] = (int) ( $c['discount_minor'] ?? 0 );
		}

		return $out;
	}

	public static function lineItem( $item, $cart_item_key, $values, $order ): void {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$cart  = function_exists( 'WC' ) ? WC()->cart : null;
		$state = CartApplier::stateFor( $cart );
		$line  = $state['lines'][ (string) $cart_item_key ] ?? null;

		if ( null === $line || ! ( $state['result'] ?? null ) instanceof EvaluationResult || $state['result']->adjustments->isEmpty() ) {
			$item->delete_meta_data( self::META );

			return;
		}

		$campaigns = array();

		foreach ( $state['result']->adjustments->forItem( (string) $cart_item_key ) as $adjustment ) {
			if ( $adjustment->discount_minor <= 0 ) {
				continue;
			}

			$campaigns[] = array(
				'id'             => $adjustment->campaign_id,
				'version'        => $adjustment->campaign_version,
				'label'          => $adjustment->label,
				'discount_minor' => $adjustment->discount_minor,
			);
		}

		$item->update_meta_data(
			self::META,
			array(
				'base_unit_minor' => $line['base_minor'],
				'qty'             => $line['qty'],
				'discount_minor'  => $line['discount_minor'],
				'campaigns'       => $line['discount_minor'] > 0 ? $campaigns : array(),
			)
		);
	}

	/**
	 * Block checkout: the order placed by the cart checkout route gets its snapshot from the cart, also when
	 * WooCommerce reuses the session's pending or failed order on a retry. The pay-for-order route
	 * (`/checkout/{id}`) never rewrites an order's snapshot.
	 *
	 * @param \WP_REST_Request|null $request
	 */
	public static function writeDraftOrder( $order, $request = null ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		ReservationGate::noteRequest( $order, $request );

		if ( ReservationGate::isPayRoute( $request ) ) {
			return;
		}

		if ( ReservationGate::isCartRoute( $request ) || ReservationGate::isSessionDraft( $order ) ) {
			self::writeOrder( $order );
		}
	}

	/** Order-level snapshot from the current cart's evaluation. */
	public static function writeOrder( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$cart   = function_exists( 'WC' ) ? WC()->cart : null;
		$state  = CartApplier::stateFor( $cart );
		$result = $state['result'] ?? null;

		if ( ! $result instanceof EvaluationResult || $result->adjustments->isEmpty() ) {
			$order->delete_meta_data( self::META );

			return;
		}

		$order->update_meta_data( self::META, self::build( $result, (array) $state['lines'], (string) $order->get_payment_method() ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $lines
	 * @return array<string,mixed>
	 */
	public static function build( EvaluationResult $result, array $lines, string $payment_method = '' ): array {
		$applied   = array_fill_keys( $result->appliedCampaignIds(), true );
		$line_disc = array();

		// Line discounts as charged (a line withdrawn after read-back counts 0).
		foreach ( $result->adjustments->forLevel( Adjustment::LEVEL_ITEM ) as $a ) {
			if ( isset( $lines[ $a->item_key ] ) && $lines[ $a->item_key ]['discount_minor'] > 0 ) {
				$line_disc[ $a->campaign_id ] = ( $line_disc[ $a->campaign_id ] ?? 0 ) + $a->discount_minor;
			}
		}

		$free      = array_values( array_intersect( $result->adjustments->freeShippingCampaignIds(), array_keys( $applied ) ) );
		$rewards   = array();
		$reward_by = array();

		foreach ( $result->adjustments->rewards() as $a ) {
			if ( ! isset( $applied[ $a->campaign_id ] ) ) {
				continue;
			}

			$amount = (int) ( $a->meta['amount_minor'] ?? 0 );

			if ( $amount <= 0 ) {
				continue;
			}

			$rewards[]                    = array(
				'campaign_id'    => $a->campaign_id,
				'version'        => $a->campaign_version,
				'label'          => $a->label,
				'amount_minor'   => $amount,
				'min_cart_minor' => (int) ( $a->meta['min_cart_minor'] ?? 0 ),
				'valid_days'     => (int) ( $a->meta['valid_days'] ?? 0 ),
				'category_ids'   => array_values( array_map( 'intval', (array) ( $a->meta['category_ids'] ?? array() ) ) ),
			);
			$reward_by[ $a->campaign_id ] = ( $reward_by[ $a->campaign_id ] ?? 0 ) + $amount;
		}

		$campaigns = array();
		$labels    = array();
		$versions  = array();

		foreach ( $result->adjustments->all() as $a ) {
			$labels[ $a->campaign_id ]   = $a->label;
			$versions[ $a->campaign_id ] = $a->campaign_version;
		}

		foreach ( array_keys( $applied ) as $id ) {
			$campaigns[] = array(
				'id'             => (int) $id,
				'version'        => (int) ( $versions[ $id ] ?? 1 ),
				'label'          => (string) ( $labels[ $id ] ?? '' ),
				'discount_minor' => (int) ( $line_disc[ $id ] ?? 0 ),
				'free_shipping'  => in_array( $id, $free, true ),
				'reward_minor'   => (int) ( $reward_by[ $id ] ?? 0 ),
			);
		}

		return array(
			'v'                    => 1,
			'currency'             => get_woocommerce_currency(),
			'decimals'             => wc_get_price_decimals(),
			'prices_include_tax'   => wc_prices_include_tax(),
			'evaluated_at'         => time(),
			'campaigns'            => $campaigns,
			'total_discount_minor' => array_sum( $line_disc ),
			'free_shipping'        => $free,
			'rewards'              => $rewards,
			'signature'            => CartApplier::signature( $result ),
			'payment_bound'        => self::dependsOnPaymentMethod( $result, $applied ) ? $payment_method : '',
		);
	}
}
