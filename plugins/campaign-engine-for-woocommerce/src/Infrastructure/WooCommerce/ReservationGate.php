<?php
/**
 * Usage limits at checkout: every campaign in the order snapshot takes one use for the order's token.
 *
 *  - Classic checkout: reserved in `woocommerce_checkout_create_order` (after the snapshot), before the
 *    order is saved; refused → the order is not created and the customer sees an error with the cart
 *    re-evaluated. `woocommerce_checkout_order_created` links the rows to the new order id and
 *    `woocommerce_checkout_order_exception` gives them back if the order was discarded.
 *  - Block checkout: reserved in `woocommerce_checkout_validate_order_before_payment`; refused → a
 *    validation error stops the payment.
 *  - A resumed order keeps its token: its rows are updated, never duplicated, and uses of campaigns that
 *    no longer apply are given back.
 *
 * The token lives in the order meta `_eru_ce_token`. Paid, failed and cancelled orders are handled by
 * OrderEvents (consume / release); the daily sweep gives back stale holds. In "paid_only" mode nothing
 * is reserved and uses are counted when the order is paid.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use Erusoft\CampaignEngine\Infrastructure\AuditLog;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;
use Erusoft\CampaignEngine\Infrastructure\Jobs;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Exception;
use WC_Order;
use WP_Error;

final class ReservationGate {

	public const TOKEN_META = '_eru_ce_token';

	/** Id of the order the Store API cart checkout (POST /checkout) is placing in this request, or 0. */
	private static int $cart_order = 0;

	public static function hooks(): void {
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'classicReserve' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'attach' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_exception', array( self::class, 'discard' ), 10, 1 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( self::class, 'blockRecheck' ), 10, 2 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( self::class, 'blockReserve' ), 20, 2 );
		add_action( 'woocommerce_before_delete_order', array( self::class, 'onDelete' ), 10, 1 );
		add_action( 'woocommerce_trash_order', array( self::class, 'onDelete' ), 10, 1 );
		add_action( 'before_delete_post', array( self::class, 'onDeletePost' ), 10, 1 );
		add_action( 'wp_trash_post', array( self::class, 'onDeletePost' ), 10, 1 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'endRequest' ), 10, 1 );
	}

	/**
	 * Records which order the Store API request is placing. The cart checkout route (`/checkout`) places
	 * the cart's own order: a new draft, or the session's pending/failed order that WooCommerce reuses when
	 * the cart is unchanged. The pay-for-order route (`/checkout/{id}`) pays an existing order and never
	 * counts as the cart's order.
	 */
	public static function noteRequest( WC_Order $order, $request ): void {
		$route            = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		self::$cart_order = 1 === preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#', $route ) ? (int) $order->get_id() : 0;
	}

	/** Whether this request's Store API call is the cart checkout (route `/checkout`, not `/checkout/{id}`). */
	public static function isCartRoute( $request ): bool {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

		return 1 === preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#', $route );
	}

	/** Whether the request is the pay-for-order route (`/checkout/{id}`). */
	public static function isPayRoute( $request ): bool {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

		return 1 === preg_match( '#^/wc/store(?:/v\d+)?/checkout/\d+/?$#', $route );
	}

	/** The recorded order belongs to one REST request only. */
	public static function endRequest( $response ) {
		self::$cart_order = 0;

		return $response;
	}

	/** The order's token, created (and saved when $save) on first use. */
	public static function token( WC_Order $order, bool $save = false ): string {
		$token = (string) $order->get_meta( self::TOKEN_META );

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			$token = bin2hex( random_bytes( 16 ) );
			$order->update_meta_data( self::TOKEN_META, $token );

			if ( $save && $order->get_id() > 0 ) {
				$order->save_meta_data();
			}
		}

		return $token;
	}

	/**
	 * Classic checkout, before the order is saved.
	 *
	 * @throws Exception when a use is gone; WooCommerce turns it into a checkout error.
	 */
	public static function classicReserve( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$refused = self::reserveFor( $order );

		if ( array() !== $refused ) {
			throw new Exception( esc_html( self::refusalMessage() ) );
		}
	}

	/**
	 * Block checkout: WooCommerce prices the draft order before it applies the posted payment method
	 * (Checkout::process_order → create_or_update_draft_order → update_order_from_request), so a
	 * payment-method campaign may have been decided with the session's previous method. Here the posted
	 * method is in the session: the cart is evaluated again and, when the campaign outcome differs from
	 * the order's snapshot, the checkout is refused; the customer re-submits and the order is priced
	 * with the method actually used. A discount is never kept for a payment method that is not used.
	 */
	public static function blockRecheck( $order, $errors ): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		// The pay-for-order route (POST /checkout/{id}) fires the same hook for an existing order that has
		// nothing to do with the cart: only the session's own draft order is compared with the cart.
		if ( ! $order instanceof WC_Order || ! $errors instanceof WP_Error || ! $cart || ! self::isSessionDraft( $order ) ) {
			return;
		}

		$snapshot = OrderWriter::snapshot( $order );

		if ( array() === $snapshot && ! CampaignRepository::hasActive() ) {
			return;
		}

		CartApplier::forget( $cart );
		$cart->calculate_totals();

		if ( CartApplier::signature( CartApplier::resultFor( $cart ) ) !== (string) ( $snapshot['signature'] ?? '' ) ) {
			$errors->add( 'eru_ce_campaigns_changed', __( 'Sepetinizdeki kampanyalar değişti ve toplam güncellendi. Lütfen yeni tutarı kontrol edip siparişi yeniden verin.', 'campaign-engine-for-woocommerce' ) );
		}
	}

	/**
	 * The order the cart's own checkout is placing, decided by route: the order the Store API cart
	 * checkout (`POST /checkout`) recorded in this request, including a pending or failed order that
	 * WooCommerce reuses on a retry with the same cart. A `checkout-draft` order also counts (the pay
	 * route only pays orders that need payment, never drafts). The session's `store_api_draft_order` id is
	 * not used: WooCommerce never clears it, so on the pay route it can name the order being paid.
	 */
	public static function isSessionDraft( WC_Order $order ): bool {
		return ( self::$cart_order > 0 && self::$cart_order === (int) $order->get_id() ) || 'checkout-draft' === $order->get_status();
	}

	/** Block checkout validation: reserve the order's uses (after the re-check). */
	public static function blockReserve( $order, $errors ): void {
		if ( ! $order instanceof WC_Order || ! $errors instanceof WP_Error || $errors->has_errors() ) {
			return;
		}

		$refused = self::reserveFor( $order );

		if ( array() !== $refused ) {
			$errors->add( 'eru_ce_usage_limit', self::refusalMessage() );

			return;
		}

		$order->save_meta_data();
	}

	/**
	 * Reserves (or confirms) one use per campaign in the snapshot.
	 *
	 * @return int[] refused campaign ids
	 */
	public static function reserveFor( WC_Order $order ): array {
		$snapshot = OrderWriter::snapshot( $order );
		$ids      = OrderWriter::campaignIds( $snapshot );
		$token    = (string) $order->get_meta( self::TOKEN_META );

		if ( '' !== $token ) {
			// Resumed order: give back campaigns that no longer apply.
			$gone = array_diff( UsageRepository::heldBy( $token ), $ids );

			if ( array() !== $gone ) {
				UsageRepository::release( $token, false, $gone );
			}
		}

		if ( array() === $ids || ! Options::reserves() ) {
			return array();
		}

		$token   = self::token( $order );
		$refused = UsageRepository::reserve(
			CampaignRepository::limitsFor( $ids ),
			$token,
			(int) $order->get_customer_id(),
			UsageRepository::emailHash( (string) $order->get_billing_email() ),
			(int) $order->get_id(),
			OrderWriter::discounts( $snapshot )
		);

		if ( array() !== $refused ) {
			AuditLog::record(
				'usage',
				$refused[0],
				'reservation_refused',
				array(
					'campaigns' => $refused,
					'reason'    => UsageRepository::lastRefusalWasBusy() ? 'lock_timeout' : 'limit',
				)
			);

			// The customer's cart still shows the campaign: evaluate again so the next page is right.
			$cart = function_exists( 'WC' ) ? WC()->cart : null;

			if ( $cart ) {
				CartApplier::forget( $cart );
				$cart->calculate_totals();
			}

			return $refused;
		}

		Jobs::ensureDailySweep();

		return array();
	}

	public static function attach( $order ): void {
		if ( $order instanceof WC_Order ) {
			UsageRepository::attach( (string) $order->get_meta( self::TOKEN_META ), (int) $order->get_id() );
		}
	}

	public static function discard( $order ): void {
		if ( $order instanceof WC_Order ) {
			UsageRepository::release( (string) $order->get_meta( self::TOKEN_META ) );
		}
	}

	/** Paid: the order's campaigns are consumed (created when there was no reservation). */
	public static function consume( WC_Order $order ): void {
		$snapshot = OrderWriter::snapshot( $order );
		$ids      = OrderWriter::campaignIds( $snapshot );

		if ( array() === $ids ) {
			return;
		}

		$over = UsageRepository::consume(
			CampaignRepository::limitsFor( $ids ),
			self::token( $order, true ),
			(int) $order->get_id(),
			(int) $order->get_customer_id(),
			UsageRepository::emailHash( (string) $order->get_billing_email() ),
			OrderWriter::discounts( $snapshot )
		);

		foreach ( $over as $id ) {
			AuditLog::record( 'campaign', $id, 'used_over_limit', array( 'order_id' => (int) $order->get_id() ) );
		}
	}

	/** Failed / cancelled: uses go back (a paid-then-cancelled order too). */
	public static function release( WC_Order $order ): void {
		UsageRepository::release( (string) $order->get_meta( self::TOKEN_META ), true );
	}

	public static function onDelete( $order_id ): void {
		UsageRepository::releaseOrder( (int) $order_id );
	}

	/** Orders stored as posts (HPOS off). */
	public static function onDeletePost( $post_id ): void {
		if ( 'shop_order' === get_post_type( (int) $post_id ) ) {
			UsageRepository::releaseOrder( (int) $post_id );
		}
	}

	/** Sweep verdict for an order holding a reservation older than the TTL. */
	public static function verdict( int $order_id ): string {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return 'release';
		}

		$status = $order->get_status();

		if ( in_array( $status, wc_get_is_paid_statuses(), true ) ) {
			return 'consume';
		}

		if ( in_array( $status, array( 'pending', 'failed', 'cancelled', 'checkout-draft', 'trash' ), true ) ) {
			return 'release';
		}

		return 'keep';
	}

	private static function refusalMessage(): string {
		if ( UsageRepository::lastRefusalWasBusy() ) {
			return __( 'Şu anda yoğunluk var, lütfen tekrar deneyin.', 'campaign-engine-for-woocommerce' );
		}

		return __( 'Sepetinizdeki bir kampanyanın kullanım hakkı siz ödeme yaparken doldu. Sepetiniz güncellendi; lütfen yeni tutarı kontrol edip yeniden deneyin.', 'campaign-engine-for-woocommerce' );
	}
}
