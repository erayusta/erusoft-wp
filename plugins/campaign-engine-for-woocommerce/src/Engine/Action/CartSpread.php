<?php
/**
 * Puts a cart-level amount onto the eligible lines.
 *
 * Cart discounts are never a separate negative line: a discount that belongs to no product has no tax
 * rate, cannot be refunded with the product and cannot be reported per product. The amount is shared
 * over the eligible lines in proportion to their totals at the campaign's price basis (see
 * UnitAllocation::spread), then each share is converted to what the customer really gets on that line
 * (see PriceBasis::effectiveLineShare). Lines skipped by the basis take no share and do not count
 * towards the subtotal.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Adjustment\UnitAllocation;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class CartSpread {

	/**
	 * The eligible lines at the campaign's basis.
	 *
	 * @return array{items:array<string,Item>,bases:array<string,int>,subtotal:int}
	 */
	public static function basis( Scope $scope, string $basis ): array {
		$items    = array();
		$bases    = array();
		$subtotal = 0;

		foreach ( $scope->match->items as $item ) {
			$base = PriceBasis::base( $item, $basis );

			if ( null === $base || $base <= 0 || $item->qty <= 0 ) {
				continue;
			}

			$items[ $item->key ] = $item;
			$bases[ $item->key ] = $base;
			$subtotal           += $base * $item->qty;
		}

		return array(
			'items'    => $items,
			'bases'    => $bases,
			'subtotal' => $subtotal,
		);
	}

	/**
	 * Shares $total over the lines and returns the effective line discounts (zero lines left out).
	 *
	 * @param array{items:array<string,Item>,bases:array<string,int>,subtotal:int} $basis
	 * @return array<string,int>
	 */
	public static function lines( int $total, array $basis ): array {
		if ( $total <= 0 || array() === $basis['items'] ) {
			return array();
		}

		$lines = array();

		foreach ( $basis['items'] as $key => $item ) {
			$lines[ $key ] = array(
				'qty'        => $item->qty,
				'unit_minor' => $basis['bases'][ $key ],
				'cap_minor'  => $item->lineSubtotalMinor(),
			);
		}

		$out = array();

		foreach ( UnitAllocation::spread( $total, $lines ) as $key => $share ) {
			$key       = (string) $key;
			$effective = PriceBasis::effectiveLineShare( $basis['items'][ $key ], $basis['bases'][ $key ], $share );

			if ( $effective > 0 ) {
				$out[ $key ] = $effective;
			}
		}

		return $out;
	}
}
