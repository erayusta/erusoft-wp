<?php
/**
 * Applies the engine's line discounts to a WooCommerce cart.
 *
 * How the discount reaches the price
 *   The product objects are never written to. In `woocommerce_before_calculate_totals` (at the price
 *   priority, see below) every line's price is read once with this class's filter in "probe" mode:
 *   that read is the base the engine works on: whatever WooCommerce, role-price or currency plugins
 *   return. The engine runs, and the net unit price of each discounted line is kept in a WeakMap keyed
 *   by the line's product object. The `woocommerce_product_get_price` /
 *   `woocommerce_product_variation_get_price` filters, at the same priority, then return that net
 *   price whenever the price arriving at them is still the probed one. Recalculating runs the same
 *   probe on the same untouched base, so three passes give three identical totals (no compounding),
 *   and nothing of this plugin goes into the cart item data (the cart hash and session stay stable).
 *
 * The documented priority
 *   `eru_ce_price_priority` (default 9999). Price changes made before it (in a price filter or in
 *   `woocommerce_before_calculate_totals`) are the base the campaign rides on. A price filter after it
 *   that changes the price makes the line ineligible (its discount is withdrawn, never claimed).
 *
 * Read-back
 *   Right after registering a line, its price is read back as WooCommerce will read it; a mismatch
 *   (someone after us replaced the price) withdraws that line and the cart is evaluated again without
 *   it. In `woocommerce_after_calculate_totals` every line is checked once more (an object swapped or
 *   re-priced after our pass); a mismatch triggers one corrective recalculation with those lines kept
 *   out. So the discount the order snapshot records is always the discount WooCommerce charged.
 *
 * Exact money
 *   A line discount that does not divide by the quantity is carried by a unit price with extra
 *   decimals (UnitPrice), so line totals equal the engine's to the minor unit, with taxes on or off.
 *
 * Several carts
 *   State is kept per WC_Cart object (WeakMap), and every product object belongs to one cart. A clone
 *   of the main cart (recurring carts) or a `new WC_Cart` filled with `set_cart_contents()` starts with
 *   the main cart's product objects; on its first pass it swaps them for its own copies, so its
 *   evaluation (often over fewer lines) never changes the price the main cart's objects return. The
 *   main cart always keeps its objects. Lines withdrawn by the read-back are kept out only for the one
 *   corrective recalculation; the next calculation tries them again.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use Erusoft\CampaignEngine\Engine\Adjustment\Adjustment;
use Erusoft\CampaignEngine\Engine\Adjustment\AdjustmentSet;
use Erusoft\CampaignEngine\Engine\EvaluationResult;
use Erusoft\CampaignEngine\Engine\Evaluator;
use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Engine\UsageLimits;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use WC_Cart;
use WC_Product;
use WeakMap;

final class CartApplier {

	public const DEFAULT_PRIORITY = 9999;
	public const SIGNATURE_KEY    = 'eru_ce_signature';
	private const EPSILON         = 0.0000001;

	/** @var WeakMap<WC_Product,array{at:float,net:string}>|null */
	private static ?WeakMap $products = null;
	/** @var WeakMap<WC_Cart,array<string,mixed>>|null */
	private static ?WeakMap $carts = null;
	/** @var WeakMap<WC_Product,\WeakReference>|null the cart each product object belongs to */
	private static ?WeakMap $owners = null;
	/** @var WeakMap<WC_Cart,bool>|null carts in a corrective recalculation */
	private static ?WeakMap $correcting = null;
	/** @var WC_Cart[] carts being calculated, innermost last */
	private static array $stack = array();
	private static bool $busy = false;
	private static bool $probing = false;
	private static ?WC_Product $probe_target = null;
	private static ?float $probe_value = null;
	private static ?int $priority = null;

	public static function pricePriority(): int {
		if ( null === self::$priority ) {
			/**
			 * Filters the priority of the campaign price filters and of the cart pass. Price changes made
			 * at a lower priority are the campaign's base; a later price change withdraws the discount.
			 *
			 * @param int $priority Default 9999.
			 */
			self::$priority = (int) apply_filters( 'eru_ce_price_priority', self::DEFAULT_PRIORITY );
		}

		return self::$priority;
	}

	public static function hooks(): void {
		self::$products   = new WeakMap();
		self::$carts      = new WeakMap();
		self::$owners     = new WeakMap();
		self::$correcting = new WeakMap();
		$priority         = self::pricePriority();

		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'apply' ), $priority );
		add_action( 'woocommerce_after_calculate_totals', array( self::class, 'verify' ), $priority );
		add_filter( 'woocommerce_product_get_price', array( self::class, 'filterPrice' ), $priority, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( self::class, 'filterPrice' ), $priority, 2 );
		add_action( 'woocommerce_cart_emptied', array( self::class, 'onEmptied' ) );
		add_action( 'woocommerce_before_mini_cart', array( self::class, 'beforeMiniCart' ) );
	}

	/** The evaluation behind the cart's current totals, or null when no campaign ran on it. */
	public static function resultFor( ?WC_Cart $cart ): ?EvaluationResult {
		$state = self::stateFor( $cart );

		return $state['result'] ?? null;
	}

	/**
	 * The cart's state: result, lines (key => base_minor, qty, discount_minor, net), fingerprint.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function stateFor( ?WC_Cart $cart ): ?array {
		if ( null === $cart || null === self::$carts || ! isset( self::$carts[ $cart ] ) ) {
			return null;
		}

		return self::$carts[ $cart ];
	}

	/** The cart being calculated right now (shipping packages are built inside the calculation). */
	public static function current(): ?WC_Cart {
		$top = end( self::$stack );

		if ( $top instanceof WC_Cart ) {
			return $top;
		}

		return function_exists( 'WC' ) && WC()->cart instanceof WC_Cart ? WC()->cart : null;
	}

	/** Drops the cached evaluation so the next calculation runs the engine again (checkout). */
	public static function forget( ?WC_Cart $cart ): void {
		if ( null !== $cart && null !== self::$carts && isset( self::$carts[ $cart ] ) ) {
			$state                = self::$carts[ $cart ];
			$state['fingerprint'] = '';
			self::$carts[ $cart ] = $state;
		}
	}

	/**
	 * woocommerce_cart_emptied does not say which cart was emptied: forget the state of every known cart
	 * that is empty now (an emptied clone never touches the main cart's state).
	 */
	public static function onEmptied(): void {
		if ( null === self::$carts ) {
			return;
		}

		$empty = array();

		foreach ( self::$carts as $cart => $state ) {
			if ( array() === $cart->get_cart_contents() ) {
				$empty[] = $cart;
			}
		}

		foreach ( $empty as $cart ) {
			unset( self::$carts[ $cart ] );
		}
	}

	/** Classic mini cart on a request that has not calculated the cart yet. */
	public static function beforeMiniCart(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( $cart instanceof WC_Cart && ! $cart->is_empty() && null === self::stateFor( $cart ) && CampaignRepository::hasActive() ) {
			$cart->calculate_totals();
		}
	}

	/**
	 * Price filter: the net unit price of a discounted line, while the price arriving here is still the
	 * base the engine saw. In probe mode it only records the arriving price.
	 *
	 * @param mixed      $price
	 * @param WC_Product $product
	 * @return mixed
	 */
	public static function filterPrice( $price, $product ) {
		if ( self::$probing ) {
			if ( $product === self::$probe_target ) {
				self::$probe_value = is_numeric( $price ) ? (float) $price : null;
			}

			return $price;
		}

		if ( null === self::$products || ! $product instanceof WC_Product || ! isset( self::$products[ $product ] ) ) {
			return $price;
		}

		$entry = self::$products[ $product ];

		if ( ! is_numeric( $price ) || abs( (float) $price - $entry['at'] ) > self::EPSILON ) {
			return $price;
		}

		return $entry['net'];
	}

	/** woocommerce_before_calculate_totals */
	public static function apply( $cart ): void {
		if ( ! $cart instanceof WC_Cart || self::$busy ) {
			return;
		}

		if ( end( self::$stack ) !== $cart ) {
			self::$stack[] = $cart;
		}

		if ( ! CampaignRepository::hasActive() ) {
			self::clear( $cart );

			return;
		}

		self::$busy = true;

		try {
			self::ownObjects( $cart );

			$state = self::$carts[ $cart ] ?? array();
			// Lines withdrawn after the totals stay out only for the corrective recalculation.
			$forced          = empty( self::$correcting[ $cart ] ) ? array() : (array) ( $state['forced'] ?? array() );
			$state['forced'] = array();
			$lines           = self::probe( $cart, $forced );
			$result          = self::evaluate( $cart, $lines, $state );
			$mismatch        = self::register( $lines, $result );

			if ( array() !== $mismatch ) {
				// Someone after our priority replaces these prices: keep them out and evaluate again.
				foreach ( $mismatch as $key ) {
					$lines[ $key ]['excluded'] = 'readback';
				}

				$state['fingerprint'] = '';
				$result               = self::evaluate( $cart, $lines, $state );
				$still                = self::register( $lines, $result );

				if ( array() !== $still ) {
					$result = self::without( $result, $still );

					foreach ( $still as $key ) {
						unset( self::$products[ $lines[ $key ]['product'] ] );
					}
				}
			}

			$state['result']      = $result;
			$state['lines']       = self::publicLines( $lines, $result );
			self::$carts[ $cart ] = $state;
			self::rememberInSession( $cart, $result );
		} finally {
			self::$busy = false;
		}
	}

	/** woocommerce_after_calculate_totals: the price WooCommerce used must be the one we expect. */
	public static function verify( $cart ): void {
		if ( ! $cart instanceof WC_Cart || self::$busy ) {
			return;
		}

		if ( end( self::$stack ) === $cart ) {
			array_pop( self::$stack );
		}

		if ( ! isset( self::$carts[ $cart ] ) ) {
			return;
		}

		$state    = self::$carts[ $cart ];
		$mismatch = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			$line    = $state['lines'][ (string) $key ] ?? null;
			$product = $item['data'] ?? null;

			if ( null === $line || 'no_price' === $line['excluded'] || ! $product instanceof WC_Product ) {
				continue;
			}

			$seen = $product->get_price();

			if ( ! is_numeric( $seen ) || abs( (float) $seen - (float) $line['net'] ) > self::EPSILON ) {
				$mismatch[] = (string) $key;
			}
		}

		if ( array() === $mismatch ) {
			return;
		}

		if ( empty( self::$correcting[ $cart ] ) ) {
			// One corrective pass with those lines kept out; its totals replace these.
			$state['forced']      = (array) ( $state['forced'] ?? array() ) + array_fill_keys( $mismatch, true );
			$state['fingerprint'] = '';
			self::$carts[ $cart ] = $state;
			self::$correcting[ $cart ] = true;

			try {
				$cart->calculate_totals();
			} finally {
				unset( self::$correcting[ $cart ] );
			}

			return;
		}

		// Still different inside the corrective pass: claim nothing for those lines.
		$state['result'] = self::without( $state['result'], $mismatch );

		foreach ( $mismatch as $key ) {
			$state['lines'][ $key ]['discount_minor'] = 0;
			$state['lines'][ $key ]['excluded']       = 'readback';
		}

		self::$carts[ $cart ] = $state;
	}

	/**
	 * Gives a cart other than the main one its own product objects when it shares them with the main cart
	 * or with another cart, so each cart's net prices live on objects only it uses. The main cart keeps
	 * (and takes back) its objects.
	 */
	private static function ownObjects( WC_Cart $cart ): void {
		$main    = function_exists( 'WC' ) && WC()->cart instanceof WC_Cart ? WC()->cart : null;
		$is_main = $main === $cart;
		$in_main = new WeakMap();

		if ( ! $is_main && null !== $main ) {
			foreach ( $main->get_cart_contents() as $item ) {
				if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
					$in_main[ $item['data'] ] = true;
				}
			}
		}

		$contents = $cart->get_cart_contents();
		$changed  = false;

		foreach ( $contents as $key => $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$owner = isset( self::$owners[ $product ] ) ? self::$owners[ $product ]->get() : null;

			if ( ! $is_main && ( isset( $in_main[ $product ] ) || ( null !== $owner && $owner !== $cart ) ) ) {
				$product                 = clone $product;
				$contents[ $key ]['data'] = $product;
				$changed                 = true;
			}

			self::$owners[ $product ] = \WeakReference::create( $cart );
		}

		if ( $changed ) {
			$cart->set_cart_contents( $contents );
		}
	}

	/**
	 * Reads every line's base price (probe) and decides which lines may be discounted.
	 *
	 * @param array<string,bool> $forced keys kept out after a failed read-back
	 * @return array<string,array<string,mixed>>
	 */
	private static function probe( WC_Cart $cart, array $forced ): array {
		$decimals = wc_get_price_decimals();
		$lines    = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$key = (string) $key;
			unset( self::$products[ $product ] );

			self::$probing      = true;
			self::$probe_target = $product;
			self::$probe_value  = null;

			try {
				$seen = $product->get_price();
			} finally {
				self::$probing      = false;
				self::$probe_target = null;
			}

			$at       = self::$probe_value;
			$excluded = null;
			$minor    = is_numeric( $seen ) ? Money::of( (string) $seen, $decimals ) : 0;

			if ( ! is_numeric( $seen ) || (float) $seen < 0 ) {
				$excluded = 'no_price';
			} elseif ( null === $at ) {
				$excluded = 'no_filter';
			} elseif ( abs( $at - (float) $seen ) > self::EPSILON ) {
				$excluded = 'transformed';
			} elseif ( abs( (float) $seen * ( 10 ** $decimals ) - $minor ) > 0.000001 ) {
				$excluded = 'precision';
			} elseif ( isset( $forced[ $key ] ) ) {
				$excluded = 'readback';
			}

			$lines[ $key ] = array(
				'product'    => $product,
				'item'       => $item,
				'qty'        => max( 0, (int) $item['quantity'] ),
				'base_minor' => max( 0, $minor ),
				'at'         => (float) $at,
				'seen'       => (string) $seen,
				'excluded'   => $excluded,
			);
		}

		return $lines;
	}

	/**
	 * Runs the engine, or reuses the last result when nothing that matters changed in this request.
	 *
	 * @param array<string,array<string,mixed>> $lines
	 * @param array<string,mixed>               $state by reference: fingerprint is updated
	 */
	private static function evaluate( WC_Cart $cart, array $lines, array &$state ): EvaluationResult {
		$context     = ContextFactory::fromCart( $cart, $lines );
		$fingerprint = md5(
			(string) wp_json_encode(
				array(
					array_map( static fn( $i ) => array( $i->key, $i->product_id, $i->variation_id, $i->qty, $i->unit_minor, $i->excluded ), $context->items ),
					$context->cart,
					$context->customer,
					CampaignRepository::version(),
					UsageRepository::writes(),
					$context->now->format( 'Y-m-d H:i' ),
				)
			)
		);

		if ( isset( $state['result'] ) && ( $state['fingerprint'] ?? '' ) === $fingerprint ) {
			return $state['result'];
		}

		$limits = CampaignRepository::limits();
		$usage  = UsageLimits::none();

		if ( array() !== $limits ) {
			$email = ContextFactory::email();
			$usage = new UsageLimits( UsageRepository::remaining( $limits, get_current_user_id(), UsageRepository::emailHash( $email ), self::ownTokens() ) );
		}

		$result               = Evaluator::withDefaults( Options::engineSettings() )->evaluate( $context, CampaignRepository::active(), $usage );
		$state['fingerprint'] = $fingerprint;

		return $result;
	}

	/**
	 * Registers the net prices of discounted lines and reads them back.
	 *
	 * @param array<string,array<string,mixed>> $lines
	 * @return string[] keys whose read-back differs
	 */
	private static function register( array $lines, EvaluationResult $result ): array {
		$decimals = wc_get_price_decimals();
		$mismatch = array();

		foreach ( $lines as $key => $line ) {
			$product  = $line['product'];
			$discount = null === $line['excluded'] ? $result->adjustments->lineDiscount( (string) $key ) : 0;

			unset( self::$products[ $product ] );

			if ( $discount <= 0 || $line['qty'] <= 0 ) {
				continue;
			}

			$total = $line['base_minor'] * $line['qty'];
			$net   = UnitPrice::forLine( $total - min( $discount, $total ), $line['qty'], $decimals );

			self::$products[ $product ] = array(
				'at'  => $line['at'],
				'net' => $net,
			);

			$back = $product->get_price();

			if ( ! is_numeric( $back ) || abs( (float) $back - (float) $net ) > self::EPSILON ) {
				$mismatch[] = (string) $key;
				unset( self::$products[ $product ] );
			}
		}

		return $mismatch;
	}

	/**
	 * Lines as exposed to the order writer and the checks: base, quantity, discount, expected unit price.
	 *
	 * @param array<string,array<string,mixed>> $lines
	 * @return array<string,array{product_id:int,base_minor:int,qty:int,discount_minor:int,net:string,excluded:?string}>
	 */
	private static function publicLines( array $lines, EvaluationResult $result ): array {
		$decimals = wc_get_price_decimals();
		$out      = array();

		foreach ( $lines as $key => $line ) {
			$discount = null === $line['excluded'] ? min( $result->adjustments->lineDiscount( (string) $key ), $line['base_minor'] * $line['qty'] ) : 0;
			$net      = $discount > 0 ? UnitPrice::forLine( $line['base_minor'] * $line['qty'] - $discount, $line['qty'], $decimals ) : $line['seen'];

			$out[ (string) $key ] = array(
				'product_id'     => (int) $line['product']->get_id(),
				'base_minor'     => (int) $line['base_minor'],
				'qty'            => (int) $line['qty'],
				'discount_minor' => (int) $discount,
				'net'            => (string) $net,
				'excluded'       => $line['excluded'] ?? null,
			);
		}

		return $out;
	}

	/** The result without the item adjustments of $keys (their campaigns keep the rest). */
	private static function without( EvaluationResult $result, array $keys ): EvaluationResult {
		$keys = array_fill_keys( array_map( 'strval', $keys ), true );
		$kept = array_filter(
			$result->adjustments->all(),
			static fn( Adjustment $a ): bool => Adjustment::LEVEL_ITEM !== $a->level || ! isset( $keys[ $a->item_key ] )
		);

		return new EvaluationResult( new AdjustmentSet( $kept ), $result->trace, $result->evaluatedCandidates() );
	}

	private static function clear( WC_Cart $cart ): void {
		if ( null === self::$carts || ! isset( self::$carts[ $cart ] ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				unset( self::$products[ $item['data'] ] );
			}
		}

		unset( self::$carts[ $cart ] );
		self::rememberInSession( $cart, null );
	}

	/**
	 * Fingerprint of a campaign outcome: applied campaigns, line discounts, free shipping ('' = none).
	 * The checkout guards compare it before and after a fresh evaluation; the order snapshot keeps it.
	 */
	public static function signature( ?EvaluationResult $result ): string {
		if ( null === $result || $result->adjustments->isEmpty() ) {
			return '';
		}

		$ids   = $result->appliedCampaignIds();
		$fs    = $result->adjustments->freeShippingCampaignIds();
		$lines = $result->adjustments->lineDiscounts();
		sort( $ids );
		sort( $fs );
		ksort( $lines );

		return md5( (string) wp_json_encode( array( $ids, $lines, $fs ) ) );
	}

	/**
	 * Keeps the campaign outcome of the main cart in the session (only when it changes), for the
	 * checkout guard. Nothing is written for carts without campaigns.
	 */
	private static function rememberInSession( WC_Cart $cart, ?EvaluationResult $result ): void {
		$session = function_exists( 'WC' ) ? WC()->session : null;

		if ( ! $session || ! function_exists( 'WC' ) || WC()->cart !== $cart ) {
			return;
		}

		$signature = self::signature( $result );

		if ( (string) $session->get( self::SIGNATURE_KEY, '' ) !== $signature ) {
			$session->set( self::SIGNATURE_KEY, '' === $signature ? null : $signature );
		}
	}

	/**
	 * Tokens of the customer's own orders in flight (classic: order awaiting payment; block checkout: the
	 * Store API draft order), whose reservations must not count against them.
	 *
	 * @return string[]
	 */
	private static function ownTokens(): array {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		$tokens  = array();

		if ( ! $session ) {
			return $tokens;
		}

		foreach ( array( 'order_awaiting_payment', 'store_api_draft_order' ) as $key ) {
			$order_id = absint( $session->get( $key, 0 ) );
			$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
			$token    = $order ? (string) $order->get_meta( ReservationGate::TOKEN_META ) : '';

			if ( '' !== $token ) {
				$tokens[] = $token;
			}
		}

		return $tokens;
	}
}
