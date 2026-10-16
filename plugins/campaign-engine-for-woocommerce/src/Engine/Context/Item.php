<?php
/**
 * One cart line, frozen at evaluation time.
 *
 * The WooCommerce layer builds these from cart items (or from a single product for list prices and
 * previews). Prices are minor units of the line's current unit price (`unit_minor`, what the customer
 * pays before campaigns) and of the catalogue regular price (`regular_minor`).
 *
 * Taxonomy terms are kept per group so conditions can ask for "category" without being confused by a
 * tag with the same id; `term_ids()` is their union, used by campaign targets.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Context;

use Erusoft\CampaignEngine\Engine\Args;

final class Item {

	/**
	 * @param int[]                   $category_ids Product categories (with their ancestors, when the caller wants inheritance).
	 * @param int[]                   $tag_ids      Product tags.
	 * @param int[]                   $brand_ids    Brand terms.
	 * @param int[]                   $other_term_ids Terms of any other taxonomy the caller chooses to expose.
	 * @param array<string,string[]>  $attributes   Attribute slug => value slugs (variation and product attributes).
	 * @param bool                    $excluded     Never discounted by any campaign (gift cards, fees, add-ons…).
	 */
	public function __construct(
		public readonly string $key,
		public readonly int $product_id,
		public readonly int $qty,
		public readonly int $unit_minor,
		public readonly int $variation_id = 0,
		public readonly ?int $regular_minor = null,
		public readonly ?bool $on_sale = null,
		public readonly array $category_ids = array(),
		public readonly array $tag_ids = array(),
		public readonly array $brand_ids = array(),
		public readonly array $other_term_ids = array(),
		public readonly array $attributes = array(),
		public readonly string $sku = '',
		public readonly string $stock_status = '',
		public readonly ?int $stock_qty = null,
		public readonly ?int $published_ts = null,
		public readonly bool $excluded = false
	) {}

	/**
	 * Builds a line from an associative array (the keys are the constructor's parameter names).
	 *
	 * @param array<string,mixed> $row
	 */
	public static function fromArray( array $row ): self {
		$attributes = array();

		foreach ( (array) ( $row['attributes'] ?? array() ) as $name => $values ) {
			$name = strtolower( trim( (string) $name ) );

			if ( '' !== $name ) {
				$attributes[ $name ] = array_map( 'strtolower', Args::strings( $values ) );
			}
		}

		return new self(
			(string) ( $row['key'] ?? '' ),
			max( 0, (int) ( $row['product_id'] ?? 0 ) ),
			max( 0, (int) ( $row['qty'] ?? 0 ) ),
			max( 0, (int) ( $row['unit_minor'] ?? 0 ) ),
			max( 0, (int) ( $row['variation_id'] ?? 0 ) ),
			isset( $row['regular_minor'] ) ? max( 0, (int) $row['regular_minor'] ) : null,
			isset( $row['on_sale'] ) ? (bool) $row['on_sale'] : null,
			Args::ids( $row['category_ids'] ?? array() ),
			Args::ids( $row['tag_ids'] ?? array() ),
			Args::ids( $row['brand_ids'] ?? array() ),
			Args::ids( $row['other_term_ids'] ?? array() ),
			$attributes,
			(string) ( $row['sku'] ?? '' ),
			(string) ( $row['stock_status'] ?? '' ),
			isset( $row['stock_qty'] ) ? (int) $row['stock_qty'] : null,
			isset( $row['published_ts'] ) ? (int) $row['published_ts'] : null,
			(bool) ( $row['excluded'] ?? false )
		);
	}

	/** Line total before campaigns. */
	public function lineSubtotalMinor(): int {
		return $this->unit_minor * $this->qty;
	}

	/** Regular (catalogue) unit price; the current price when none was given. */
	public function regularMinor(): int {
		return $this->regular_minor ?? $this->unit_minor;
	}

	/** On sale when told so, otherwise when the regular price is above the current one. */
	public function isOnSale(): bool {
		return $this->on_sale ?? ( $this->regularMinor() > $this->unit_minor );
	}

	/**
	 * Every term id of the line, across groups.
	 *
	 * @return int[]
	 */
	public function termIds(): array {
		return array_values( array_unique( array_merge( $this->category_ids, $this->tag_ids, $this->brand_ids, $this->other_term_ids ) ) );
	}

	/** Pool key of the line's product: lines of the same product/variation share it. */
	public function productKey(): string {
		return $this->product_id . ':' . $this->variation_id;
	}
}
