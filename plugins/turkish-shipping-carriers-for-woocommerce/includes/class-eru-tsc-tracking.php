<?php
/**
 * Tracking number on the order: admin box, saving, e-mail / My Account display.
 *
 * Order meta: `_eru_tsc_carrier` (carrier id), `_eru_tsc_tracking_number`.
 *
 * @package turkish-shipping-carriers-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_TSC_Tracking {

	const META_CARRIER = '_eru_tsc_carrier';
	const META_NUMBER  = '_eru_tsc_tracking_number';

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_box' ), 50 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_info' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'frontend_info' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_item_meta' ) );
	}

	/** The carrier id on the shipping line is internal; the line title already names the carrier. */
	public static function hide_item_meta( $keys ): array {
		$keys   = (array) $keys;
		$keys[] = '_eru_tsc_carrier';
		return $keys;
	}

	/** Carrier id chosen at checkout (shipping rate meta), '' if the order did not use this method. */
	public static function checkout_carrier( WC_Order $order ): string {
		foreach ( $order->get_shipping_methods() as $item ) {
			$id = (string) $item->get_meta( '_eru_tsc_carrier' );
			if ( '' !== $id ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * @param WC_Order|int $order
	 * @return array{carrier:string,carrier_name:string,number:string,url:string}
	 */
	public static function get( $order ): array {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return array( 'carrier' => '', 'carrier_name' => '', 'number' => '', 'url' => '' );
		}
		$carrier = (string) $order->get_meta( self::META_CARRIER );
		if ( '' === $carrier ) {
			$carrier = self::checkout_carrier( $order );
		}
		$number = (string) $order->get_meta( self::META_NUMBER );
		return array(
			'carrier'      => $carrier,
			'carrier_name' => Eru_TSC_Carriers::name( $carrier ),
			'number'       => $number,
			'url'          => Eru_TSC_Carriers::tracking_url( $carrier, $number ),
		);
	}

	/**
	 * Store carrier + tracking number on the order (does not save the order).
	 * Returns true when the tracking number changed to a non-empty value.
	 */
	public static function set( WC_Order $order, string $carrier, string $number ): bool {
		$carrier = sanitize_key( $carrier );
		if ( '' !== $carrier && null === Eru_TSC_Carriers::get( $carrier ) ) {
			$carrier = '';
		}
		$number = substr( preg_replace( '/[^A-Za-z0-9\-_.\/ ]/', '', sanitize_text_field( $number ) ), 0, 64 );
		$number = trim( $number );
		$old    = (string) $order->get_meta( self::META_NUMBER );
		$order->update_meta_data( self::META_CARRIER, $carrier );
		$order->update_meta_data( self::META_NUMBER, $number );
		return '' !== $number && $old !== $number;
	}

	/**
	 * Public save path for other plugins (e.g. a carrier integration that receives tracking numbers in the background):
	 * stores carrier + number, saves the order and, when the number is new, runs the same side effects as a manual
	 * entry (optional customer note e-mail and the `eru_tsc_tracking_saved` action).
	 *
	 * @since 1.0.1
	 * @param WC_Order    $order   Order.
	 * @param string      $carrier Carrier id (e.g. `yurtici`), '' for none.
	 * @param string|null $number  Tracking number; null keeps the stored one (carrier only).
	 * @return bool True when the tracking number changed to a new non-empty value.
	 */
	public static function save_tracking( WC_Order $order, string $carrier, ?string $number = null ): bool {
		if ( null === $number ) {
			$number = (string) $order->get_meta( self::META_NUMBER );
		}
		$changed = self::set( $order, $carrier, $number );
		$order->save();
		if ( $changed ) {
			self::after_new_number( $order );
		}
		return $changed;
	}

	/* ---- admin box ---- */

	private static function screen_id(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	public static function add_box(): void {
		add_meta_box( 'eru-tsc-tracking', __( 'Kargo takip', 'turkish-shipping-carriers-for-woocommerce' ), array( __CLASS__, 'render_box' ), self::screen_id(), 'side', 'high' );
	}

	public static function render_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$t = self::get( $order );
		wp_nonce_field( 'eru_tsc_tracking', 'eru_tsc_tracking_nonce' );
		// The values this screen was opened with: a field the admin did not touch must not overwrite a tracking number
		// another plugin stored in the background meanwhile.
		echo '<input type="hidden" name="eru_tsc_carrier_loaded" value="' . esc_attr( (string) $order->get_meta( self::META_CARRIER ) ) . '">';
		echo '<input type="hidden" name="eru_tsc_tracking_loaded" value="' . esc_attr( $t['number'] ) . '">';
		echo '<p><label for="eru_tsc_carrier">' . esc_html__( 'Kargo firması', 'turkish-shipping-carriers-for-woocommerce' ) . '</label><br><select name="eru_tsc_carrier" id="eru_tsc_carrier" style="width:100%"><option value="">' . esc_html__( '— Seçin —', 'turkish-shipping-carriers-for-woocommerce' ) . '</option>';
		foreach ( Eru_TSC_Carriers::all() as $id => $c ) {
			if ( '' === trim( (string) $c['name'] ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $t['carrier'], $id, false ) . '>' . esc_html( $c['name'] ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="eru_tsc_tracking_number">' . esc_html__( 'Takip numarası', 'turkish-shipping-carriers-for-woocommerce' ) . '</label><br><input type="text" name="eru_tsc_tracking_number" id="eru_tsc_tracking_number" value="' . esc_attr( $t['number'] ) . '" style="width:100%" autocomplete="off"></p>';
		if ( '' !== $t['url'] ) {
			echo '<p><a href="' . esc_url( $t['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Takip sayfasını aç', 'turkish-shipping-carriers-for-woocommerce' ) . '</a></p>';
		}
		if ( Eru_TSC_Carriers::settings()['notify_customer'] ) {
			echo '<p class="description">' . esc_html__( 'Yeni bir takip numarası kaydedildiğinde müşteriye takip bağlantısını içeren bir sipariş notu e-postası gider.', 'turkish-shipping-carriers-for-woocommerce' ) . '</p>';
		}
	}

	public static function save_box( $order_id ): void {
		if ( ! isset( $_POST['eru_tsc_tracking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_tsc_tracking_nonce'] ) ), 'eru_tsc_tracking' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$carrier = isset( $_POST['eru_tsc_carrier'] ) ? sanitize_key( wp_unslash( $_POST['eru_tsc_carrier'] ) ) : '';
		$number  = isset( $_POST['eru_tsc_tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['eru_tsc_tracking_number'] ) ) : '';
		// A field still holding the value the screen was opened with was not edited: keep what is stored now.
		if ( isset( $_POST['eru_tsc_carrier_loaded'] ) && sanitize_key( wp_unslash( $_POST['eru_tsc_carrier_loaded'] ) ) === $carrier ) {
			$carrier = (string) $order->get_meta( self::META_CARRIER );
		}
		if ( isset( $_POST['eru_tsc_tracking_loaded'] ) && sanitize_text_field( wp_unslash( $_POST['eru_tsc_tracking_loaded'] ) ) === $number ) {
			$number = (string) $order->get_meta( self::META_NUMBER );
		}
		$changed = self::set( $order, $carrier, $number );
		$order->save();
		if ( $changed ) {
			self::after_new_number( $order );
		}
	}

	/** Customer note (optional) + action for other plugins (e.g. SMS). */
	public static function after_new_number( WC_Order $order ): void {
		$t = self::get( $order );
		if ( Eru_TSC_Carriers::settings()['notify_customer'] ) {
			/* translators: 1: carrier name, 2: tracking number */
			$note = sprintf( __( 'Siparişiniz kargoya verildi. %1$s takip numarası: %2$s', 'turkish-shipping-carriers-for-woocommerce' ), $t['carrier_name'] ? $t['carrier_name'] : __( 'Kargo', 'turkish-shipping-carriers-for-woocommerce' ), $t['number'] );
			if ( '' !== $t['url'] ) {
				$note .= ' — ' . $t['url'];
			}
			$order->add_order_note( $note, 1 );
		}
		do_action( 'eru_tsc_tracking_saved', $order, $t );
	}

	/* ---- display ---- */

	/** Tracking block HTML ('' when there is no tracking number). */
	public static function html( WC_Order $order ): string {
		$t = self::get( $order );
		if ( '' === $t['number'] ) {
			return '';
		}
		$label = '' !== $t['carrier_name'] ? $t['carrier_name'] . ': ' : '';
		$html  = '<section class="eru-tsc-tracking"><h2>' . esc_html__( 'Kargo takip', 'turkish-shipping-carriers-for-woocommerce' ) . '</h2><p>' . esc_html( $label . $t['number'] );
		if ( '' !== $t['url'] ) {
			$html .= '<br><a href="' . esc_url( $t['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Kargonuzu takip edin', 'turkish-shipping-carriers-for-woocommerce' ) . '</a>';
		}
		return $html . '</p></section>';
	}

	public static function email_info( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof WC_Order || ! Eru_TSC_Carriers::settings()['show_in_emails'] ) {
			return;
		}
		$t = self::get( $order );
		if ( '' === $t['number'] ) {
			return;
		}
		if ( $plain_text ) {
			// Plain-text e-mail: no HTML escaping (entities would show literally); tags and control characters stripped.
			$line = __( 'Kargo takip', 'turkish-shipping-carriers-for-woocommerce' ) . ': ' . trim( $t['carrier_name'] . ' ' . $t['number'] );
			echo "\n" . preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/', '', wp_strip_all_tags( $line ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text e-mail body.
			if ( '' !== $t['url'] ) {
				echo esc_url_raw( $t['url'] ) . "\n";
			}
			return;
		}
		echo self::html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
	}

	public static function frontend_info( $order ): void {
		if ( $order instanceof WC_Order ) {
			echo self::html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}
}
