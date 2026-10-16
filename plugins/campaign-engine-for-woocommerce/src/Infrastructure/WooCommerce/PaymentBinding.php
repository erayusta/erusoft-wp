<?php
/**
 * An order priced by a campaign that depends on the payment method (snapshot `payment_bound`) can only
 * be paid with that gateway later on:
 *  - classic order-pay page: only that gateway is offered (`woocommerce_available_payment_gateways`),
 *    and a different gateway posted anyway stops the payment (`woocommerce_before_pay_action` adds an
 *    error notice, which WooCommerce checks before processing the payment);
 *  - Store API pay route (POST /wc/store/v1/checkout/{id}): a validation error before payment.
 * When the bound gateway is not available (disabled, or restricted for the customer), both places say
 * so by name and ask the customer to contact the store, instead of a silent "no payment methods".
 *
 * Lifting the binding:
 *  - filter `eru_ce_payment_bound` (string $gateway_id, WC_Order $order): return false or '' to lift;
 *  - order screen box "Kampanya ödeme bağı" → "Ödeme bağını kaldır" (capability edit_shop_orders,
 *    nonce): the priced total is kept as it is (the merchant's decision) and an order note records who
 *    lifted it.
 * Orders without such a campaign are not affected.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use WC_Order;
use WP_Error;
use WP_Post;

final class PaymentBinding {

	public const UNBOUND_META = '_eru_ce_payment_unbound';

	public static function hooks(): void {
		add_filter( 'woocommerce_available_payment_gateways', array( self::class, 'gateways' ), 100 );
		add_filter( 'woocommerce_no_available_payment_methods_message', array( self::class, 'noMethodsMessage' ), 100 );
		add_action( 'woocommerce_before_pay_action', array( self::class, 'beforePay' ), 10, 1 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( self::class, 'validate' ), 5, 2 );
		add_action( 'add_meta_boxes', array( self::class, 'addMetabox' ), 30, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( self::class, 'onOrderSave' ), 30, 1 );
	}

	/** The gateway an order must be paid with, or '' (no binding, lifted, or filtered away). */
	public static function boundTo( WC_Order $order ): string {
		$bound = (string) ( OrderWriter::snapshot( $order )['payment_bound'] ?? '' );

		if ( '' === $bound || '' !== (string) $order->get_meta( self::UNBOUND_META ) ) {
			return '';
		}

		/**
		 * Filters the gateway a campaign-priced order must be paid with. Return false or '' to lift it.
		 *
		 * @param string   $bound Gateway id.
		 * @param WC_Order $order Order.
		 */
		$filtered = apply_filters( 'eru_ce_payment_bound', $bound, $order );

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * @param array<string,\WC_Payment_Gateway> $gateways
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public static function gateways( $gateways ) {
		$order = self::payPageOrder();
		$bound = $order ? self::boundTo( $order ) : '';

		if ( ! is_array( $gateways ) || '' === $bound ) {
			return $gateways;
		}

		return array_intersect_key( $gateways, array( $bound => true ) );
	}

	/** The order-pay page's "no payment methods" text names the missing bound gateway. */
	public static function noMethodsMessage( $message ) {
		$order = self::payPageOrder();
		$bound = $order ? self::boundTo( $order ) : '';

		return '' !== $bound && ! self::isAvailable( $bound ) ? esc_html( self::unavailableMessage( $bound ) ) : $message;
	}

	public static function beforePay( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$bound  = self::boundTo( $order );
		$posted = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the order-pay nonce before this action.

		if ( '' !== $bound && $posted !== $bound ) {
			wc_add_notice( self::isAvailable( $bound ) ? self::message( $bound ) : self::unavailableMessage( $bound ), 'error' );
		}
	}

	/** Store API: existing orders only (the session's draft is handled by the block-checkout re-check). */
	public static function validate( $order, $errors ): void {
		if ( ! $order instanceof WC_Order || ! $errors instanceof WP_Error || ReservationGate::isSessionDraft( $order ) ) {
			return;
		}

		$bound = self::boundTo( $order );

		if ( '' !== $bound && (string) $order->get_payment_method() !== $bound ) {
			$errors->add( 'eru_ce_payment_bound', self::isAvailable( $bound ) ? self::message( $bound ) : self::unavailableMessage( $bound ) );
		}
	}

	/** Lifts the binding: the total stays as priced, an order note records it. */
	public static function unbind( WC_Order $order ): bool {
		$bound = self::boundTo( $order );

		if ( '' === $bound ) {
			return false;
		}

		$user = wp_get_current_user();
		$order->update_meta_data( self::UNBOUND_META, time() );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment method title, 2: user display name */
				__( 'Kampanya ödeme bağı kaldırıldı (%1$s); sipariş tutarı değiştirilmedi. Kaldıran: %2$s.', 'campaign-engine-for-woocommerce' ),
				self::title( $bound ),
				$user && $user->exists() ? $user->display_name : '-'
			)
		);

		return true;
	}

	/** @param mixed $screen_or_post_type */
	public static function addMetabox( $screen_or_post_type, $post_or_order = null ): void {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;

		if ( ! $order instanceof WC_Order || '' === self::boundTo( $order ) ) {
			return;
		}

		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box(
			'eru-ce-payment-bound',
			__( 'Kampanya ödeme bağı', 'campaign-engine-for-woocommerce' ),
			static function ( $object ) {
				$order = $object instanceof WP_Post ? wc_get_order( $object->ID ) : $object;

				if ( $order instanceof WC_Order ) {
					self::renderMetabox( $order );
				}
			},
			$screen,
			'side',
			'default'
		);
	}

	public static function renderMetabox( WC_Order $order ): void {
		$bound = self::boundTo( $order );

		if ( '' === $bound ) {
			return;
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: payment method title */
				__( 'Bu siparişin kampanya indirimi %s ile ödemeye bağlı; başka bir yöntemle ödenemez.', 'campaign-engine-for-woocommerce' ),
				self::title( $bound )
			)
		) . '</p>';
		echo '<p class="description">' . esc_html__( 'Bağı kaldırırsanız sipariş tutarı olduğu gibi kalır ve müşteri başka bir yöntemle ödeyebilir.', 'campaign-engine-for-woocommerce' ) . '</p>';
		wp_nonce_field( 'eru_ce_unbind_' . $order->get_id(), 'eru_ce_unbind_nonce' );
		echo '<button type="submit" class="button" name="eru_ce_unbind" value="1">' . esc_html__( 'Ödeme bağını kaldır', 'campaign-engine-for-woocommerce' ) . '</button>';
	}

	/** Order save: the "Ödeme bağını kaldır" button. */
	public static function onOrderSave( $order_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		if ( empty( $_POST['eru_ce_unbind'] ) || ! isset( $_POST['eru_ce_unbind_nonce'] ) ) {
			return;
		}

		$order_id = (int) $order_id;
		$nonce    = sanitize_text_field( wp_unslash( $_POST['eru_ce_unbind_nonce'] ) );
		// phpcs:enable

		if ( ! current_user_can( 'edit_shop_orders' ) || ! wp_verify_nonce( $nonce, 'eru_ce_unbind_' . $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			self::unbind( $order );
		}
	}

	private static function payPageOrder(): ?WC_Order {
		global $wp;

		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;

		return $order instanceof WC_Order ? $order : null;
	}

	private static function isAvailable( string $gateway_id ): bool {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $gateway_id ] ?? null;

		return $gateway && 'yes' === $gateway->enabled && $gateway->is_available();
	}

	private static function title( string $gateway_id ): string {
		$gateways = WC()->payment_gateways()->payment_gateways();

		return isset( $gateways[ $gateway_id ] ) ? wp_strip_all_tags( (string) $gateways[ $gateway_id ]->get_title() ) : $gateway_id;
	}

	private static function message( string $bound ): string {
		return sprintf(
			/* translators: %s: payment method title */
			__( 'Bu sipariş %s ile ödeme kampanyasıyla fiyatlandı. Siparişi aynı ödeme yöntemiyle tamamlayın.', 'campaign-engine-for-woocommerce' ),
			self::title( $bound )
		);
	}

	private static function unavailableMessage( string $bound ): string {
		$title = self::title( $bound );

		return sprintf(
			/* translators: 1: payment method title, 2: payment method title */
			__( 'Bu sipariş %1$s ile ödeme kampanyasıyla fiyatlandı; %2$s şu an kullanılamıyor, lütfen mağazayla iletişime geçin.', 'campaign-engine-for-woocommerce' ),
			$title,
			$title
		);
	}
}
