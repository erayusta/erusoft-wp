<?php
/**
 * Builds the engine Context from a WooCommerce cart: lines with their base prices (as the cart applier
 * read them), terms, attributes and stock; the customer with their order history; coupons, the chosen
 * payment and shipping methods, the shipping country; "now" in the site's time zone.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use Erusoft\CampaignEngine\Engine\Context\CartInfo;
use Erusoft\CampaignEngine\Engine\Context\Context;
use Erusoft\CampaignEngine\Engine\Context\Customer;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository;
use WC_Cart;
use WC_Product;

final class ContextFactory {

	/**
	 * @param array<string,array{product:WC_Product,qty:int,base_minor:int,excluded:?string,item:array}> $lines
	 */
	public static function fromCart( WC_Cart $cart, array $lines ): Context {
		$decimals = wc_get_price_decimals();
		$items    = array();
		$subtotal = 0;

		foreach ( $lines as $key => $line ) {
			$items[]   = self::item( (string) $key, $line, $decimals );
			$subtotal += $line['base_minor'] * $line['qty'];
		}

		$session  = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		$customer = function_exists( 'WC' ) ? WC()->customer : null;
		$methods  = $session ? (array) $session->get( 'chosen_shipping_methods', array() ) : array();

		$info = CartInfo::fromArray(
			array(
				'subtotal_minor'   => $subtotal,
				'coupons'          => array_values( array_map( 'strval', $cart->get_applied_coupons() ) ),
				'payment_method'   => $session ? (string) $session->get( 'chosen_payment_method', '' ) : '',
				'shipping_methods' => array_values( array_filter( array_map( 'strval', $methods ) ) ),
				'shipping_country' => $customer ? (string) $customer->get_shipping_country() : '',
				'currency'         => get_woocommerce_currency(),
				'decimals'         => $decimals,
			)
		);

		return new Context( $items, self::customer( get_current_user_id(), self::email() ), $info, Clock::now() );
	}

	/**
	 * A context over products that are not in a cart (admin preview, CLI, product pages): each line's
	 * base is the product's current price as WooCommerce returns it. A price with more decimals than the
	 * currency is kept out, as in the cart.
	 *
	 * @param array<int,array{0:WC_Product,1:int}> $products product and quantity per line
	 * @param array<string,mixed>                  $cart     CartInfo fields to add (payment_method…)
	 */
	public static function fromProducts( array $products, Customer $customer, array $cart = array() ): Context {
		$decimals = wc_get_price_decimals();
		$items    = array();
		$subtotal = 0;
		$index    = 0;

		foreach ( $products as $row ) {
			$product = $row[0] ?? null;
			$qty     = max( 0, (int) ( $row[1] ?? 0 ) );

			if ( ! $product instanceof WC_Product || $qty <= 0 ) {
				continue;
			}

			$price    = $product->get_price();
			$minor    = is_numeric( $price ) ? Money::of( (string) $price, $decimals ) : 0;
			$excluded = ! is_numeric( $price ) || (float) $price < 0 || abs( (float) $price * ( 10 ** $decimals ) - $minor ) > 0.000001 ? 'precision' : null;
			$key      = 'line' . ( ++$index );
			$line     = array(
				'product'    => $product,
				'qty'        => $qty,
				'base_minor' => max( 0, $minor ),
				'excluded'   => $excluded,
				'item'       => array(
					'product_id'   => $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id(),
					'variation_id' => $product->get_parent_id() > 0 ? $product->get_id() : 0,
					'quantity'     => $qty,
					'data'         => $product,
				),
			);

			$items[]   = self::item( $key, $line, $decimals );
			$subtotal += $line['base_minor'] * $qty;
		}

		$info = CartInfo::fromArray(
			array_merge(
				array(
					'subtotal_minor' => $subtotal,
					'currency'       => get_woocommerce_currency(),
					'decimals'       => $decimals,
				),
				$cart
			)
		);

		return new Context( $items, $customer, $info, Clock::now() );
	}

	/** The engine's view of a customer: account, roles and order history. */
	public static function customer( int $user_id, string $email ): Customer {
		$roles = array();

		if ( $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$roles = $user ? array_values( (array) $user->roles ) : array();
		}

		$figures = MetricsRepository::figures( $user_id, $email );

		return Customer::fromArray(
			array(
				'id'                => $user_id,
				'logged_in'         => $user_id > 0,
				'roles'             => $roles,
				'order_count'       => $figures['order_count'],
				'total_spent_minor' => $figures['total_spent_minor'],
				'first_order_ts'    => $figures['first_order_ts'],
				'last_order_ts'     => $figures['last_order_ts'],
			)
		);
	}

	/** The shopper's e-mail as far as the store knows it (account or typed billing e-mail). */
	public static function email(): string {
		$customer = function_exists( 'WC' ) ? WC()->customer : null;
		$email    = $customer ? (string) $customer->get_billing_email() : '';

		if ( '' === $email && is_user_logged_in() ) {
			$email = (string) wp_get_current_user()->user_email;
		}

		return strtolower( trim( $email ) );
	}

	/** @param array{product:WC_Product,qty:int,base_minor:int,excluded:?string,item:array} $line */
	private static function item( string $key, array $line, int $decimals ): Item {
		$product   = $line['product'];
		$parent_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
		$regular   = (string) $product->get_regular_price();
		$brands    = taxonomy_exists( 'product_brand' ) ? wc_get_product_term_ids( $parent_id, 'product_brand' ) : array();
		$parent    = get_post( $parent_id );
		$excluded  = null !== $line['excluded'];

		/**
		 * Filters whether a cart line is kept out of every campaign (gift cards, deposits…).
		 *
		 * @param bool       $excluded Default false.
		 * @param WC_Product $product  Line product.
		 * @param array      $item     Cart item.
		 */
		$excluded = $excluded || (bool) apply_filters( 'eru_ce_item_excluded', false, $product, $line['item'] );

		return Item::fromArray(
			array(
				'key'           => $key,
				'product_id'    => $parent_id,
				'variation_id'  => $product->get_parent_id() > 0 ? $product->get_id() : 0,
				'qty'           => $line['qty'],
				'unit_minor'    => $line['base_minor'],
				'regular_minor' => '' === $regular ? null : Money::of( $regular, $decimals ),
				'on_sale'       => $product->is_on_sale(),
				'category_ids'  => wc_get_product_term_ids( $parent_id, 'product_cat' ),
				'tag_ids'       => wc_get_product_term_ids( $parent_id, 'product_tag' ),
				'brand_ids'     => $brands,
				'attributes'    => self::attributes( $product, $parent_id ),
				'sku'           => (string) $product->get_sku(),
				'stock_status'  => (string) $product->get_stock_status(),
				'stock_qty'     => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
				'published_ts'  => $parent ? (int) get_post_time( 'U', true, $parent ) : null,
				'excluded'      => $excluded,
			)
		);
	}

	/** @return array<string,string[]> attribute name => value slugs */
	private static function attributes( WC_Product $product, int $parent_id ): array {
		$out = array();

		if ( $product->get_parent_id() > 0 ) {
			foreach ( (array) $product->get_attributes() as $name => $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$out[ (string) $name ][] = sanitize_title( $value );
				}
			}
		}

		$source = $product->get_parent_id() > 0 ? wc_get_product( $parent_id ) : $product;

		if ( ! $source instanceof WC_Product ) {
			return $out;
		}

		foreach ( $source->get_attributes() as $name => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || isset( $out[ (string) $name ] ) ) {
				continue;
			}

			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $parent_id, $attribute->get_name(), array( 'fields' => 'slugs' ) )
				: array_map( 'sanitize_title', $attribute->get_options() );

			$out[ (string) $name ] = array_values( array_map( 'strval', (array) $values ) );
		}

		return $out;
	}
}
