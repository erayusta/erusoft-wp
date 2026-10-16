<?php
/**
 * Cart-wide facts: subtotal, coupons, chosen payment and shipping, and the currency's decimals.
 *
 * `subtotal_minor` is the cart subtotal as the store shows it before campaigns (the WooCommerce layer
 * decides whether tax is included). `shipping_methods` holds the chosen rate ids, one per package,
 * such as "flat_rate:4".
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Context;

use Erusoft\CampaignEngine\Engine\Args;

final class CartInfo {

	/**
	 * @param string[] $coupons          Applied coupon codes.
	 * @param string[] $shipping_methods Chosen shipping rate ids.
	 */
	public function __construct(
		public readonly int $subtotal_minor = 0,
		public readonly array $coupons = array(),
		public readonly string $payment_method = '',
		public readonly array $shipping_methods = array(),
		public readonly string $shipping_country = '',
		public readonly string $currency = 'TRY',
		public readonly int $decimals = 2
	) {}

	/**
	 * @param array<string,mixed> $row Keys are the constructor's parameter names.
	 */
	public static function fromArray( array $row ): self {
		return new self(
			max( 0, (int) ( $row['subtotal_minor'] ?? 0 ) ),
			Args::strings( $row['coupons'] ?? array() ),
			(string) ( $row['payment_method'] ?? '' ),
			Args::strings( $row['shipping_methods'] ?? array() ),
			strtoupper( (string) ( $row['shipping_country'] ?? '' ) ),
			(string) ( $row['currency'] ?? 'TRY' ),
			max( 0, min( 4, (int) ( $row['decimals'] ?? 2 ) ) )
		);
	}
}
