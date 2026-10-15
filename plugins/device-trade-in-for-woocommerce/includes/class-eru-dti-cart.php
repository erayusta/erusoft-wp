<?php
/**
 * Cart and checkout: validation, value application, order records.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The posted device data is only a reference; the value is re-resolved from the DB on every calculation.
 */
class Eru_DTI_Cart {

	/** Hooks. */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'resolve_items' ), 5 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_line_discount' ), 99 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_fee' ) );
		add_filter( 'woocommerce_cart_totals_get_fees_from_cart_taxes', array( __CLASS__, 'keep_fee_untaxed' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_remove' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item_meta' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'create_tradein_for_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'create_tradein_for_order' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ) );
	}

	/** @return array Posted trade-in input (raw, unslashed). */
	private static function posted() {
		// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$in = isset( $_POST['eru_dti'] ) && is_array( $_POST['eru_dti'] ) ? wp_unslash( $_POST['eru_dti'] ) : array();
		return $in;
	}

	/**
	 * Validates the posted trade-in for a product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $in         Posted values.
	 * @return array|WP_Error|null Normalised data, error, or null when no trade-in was requested.
	 */
	public static function validate_input( $product_id, array $in ) {
		if ( empty( $in['use'] ) ) {
			return null;
		}
		if ( ! Eru_DTI_Settings::product_enabled( $product_id ) ) {
			return new WP_Error( 'eru_dti_product', __( 'Bu ürün için takas yapılamaz.', 'device-trade-in-for-woocommerce' ) );
		}
		$device_id = absint( $in['device_id'] ?? 0 );
		$cond      = sanitize_key( $in['condition'] ?? '' );
		$r         = Eru_DTI_Pricing::resolve( $device_id, $cond );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'eru_dti_choice', __( 'Takas için cihazı ve durumunu seçin.', 'device-trade-in-for-woocommerce' ) );
		}
		$serial = mb_substr( sanitize_text_field( $in['serial'] ?? '' ), 0, 120 );
		if ( Eru_DTI_Settings::is_on( 'serial_required' ) && '' === $serial ) {
			return new WP_Error( 'eru_dti_serial', __( 'Takas cihazının seri numarasını girin.', 'device-trade-in-for-woocommerce' ) );
		}
		if ( '' !== trim( wp_strip_all_tags( (string) Eru_DTI_Settings::get( 'terms_text' ) ) ) && empty( $in['terms'] ) ) {
			return new WP_Error( 'eru_dti_terms', __( 'Takas koşullarını kabul etmelisiniz.', 'device-trade-in-for-woocommerce' ) );
		}
		return array(
			'device_id' => $device_id,
			'condition' => $cond,
			'serial'    => $serial,
		);
	}

	/** @return bool Whether the cart already holds a trade-in. */
	private static function cart_has_tradein() {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['eru_dti'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param bool $passed Valid so far. @param int $product_id Product. @param int $qty Quantity. @return bool */
	public static function validate( $passed, $product_id, $qty ) {
		$in = self::posted();
		if ( empty( $in['use'] ) ) {
			return $passed;
		}
		$r = self::validate_input( (int) $product_id, $in );
		if ( is_wp_error( $r ) ) {
			wc_add_notice( $r->get_error_message(), 'error' );
			return false;
		}
		if ( self::cart_has_tradein() ) {
			wc_add_notice( __( 'Sepetinizde zaten bir takas var; sipariş başına yalnızca bir cihaz takas edilebilir.', 'device-trade-in-for-woocommerce' ), 'error' );
			return false;
		}
		return $passed;
	}

	/** @param array $data Cart item data. @param int $product_id Product. @return array */
	public static function add_item_data( $data, $product_id ) {
		$in = self::posted();
		$r  = empty( $in['use'] ) ? null : self::validate_input( (int) $product_id, $in );
		if ( is_array( $r ) ) {
			$data['eru_dti'] = $r;
		}
		return $data;
	}

	/** @param array $item Cart item. @param array $values Session values. @return array */
	public static function from_session( $item, $values ) {
		if ( ! empty( $values['eru_dti'] ) ) {
			$item['eru_dti'] = $values['eru_dti'];
		}
		return $item;
	}

	/** @param WC_Product $product Product. @return float Per-unit ex-tax price. */
	private static function unit_ex_tax( $product ) {
		$base = $product->get_meta( '_eru_dti_orig_price', true, 'edit' );
		if ( '' !== $base && null !== $base ) {
			$product = clone $product;
			$product->set_price( $base );
		}
		return (float) wc_get_price_excluding_tax( $product );
	}

	/**
	 * Re-resolves every trade-in line from the database. Stale choices are dropped with a notice.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function resolve_items( $cart ) {
		$seen = false;
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['eru_dti'] ) ) {
				continue;
			}
			if ( $seen ) {
				unset( $cart->cart_contents[ $key ]['eru_dti'] );
				wc_add_notice( __( 'Sipariş başına yalnızca bir cihaz takas edilebilir.', 'device-trade-in-for-woocommerce' ), 'notice' );
				continue;
			}
			$seen    = true;
			$product = $item['data'];
			$pid     = $item['product_id'];
			$r       = Eru_DTI_Settings::product_enabled( $pid ) ? Eru_DTI_Pricing::resolve( $item['eru_dti']['device_id'], $item['eru_dti']['condition'] ) : new WP_Error( 'eru_dti_product', '' );
			if ( is_wp_error( $r ) ) {
				unset( $cart->cart_contents[ $key ]['eru_dti'] );
				wc_add_notice( __( 'Seçtiğiniz takas cihazı artık geçerli değil; takas sepetinizden kaldırıldı.', 'device-trade-in-for-woocommerce' ), 'notice' );
				continue;
			}
			if ( '' === (string) $product->get_meta( '_eru_dti_orig_price', true, 'edit' ) ) {
				$product->add_meta_data( '_eru_dti_orig_price', $product->get_price( 'edit' ), true );
			}
			$value = Eru_DTI_Pricing::cap( $r['value'], self::unit_ex_tax( $product ) );
			$cart->cart_contents[ $key ]['eru_dti']['value']  = $value;
			$cart->cart_contents[ $key ]['eru_dti']['bonus']  = $r['bonus'];
			$cart->cart_contents[ $key ]['eru_dti']['label']  = Eru_DTI_DB::device_label( $r['device'] );
			$cart->cart_contents[ $key ]['eru_dti']['cond']   = $r['condition']['label'];
		}
	}

	/** Line discount mode: lowers the unit price so the tax base shrinks. @param WC_Cart $cart Cart. */
	public static function apply_line_discount( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'];
			$orig    = $product->get_meta( '_eru_dti_orig_price', true, 'edit' );
			if ( '' === (string) $orig ) {
				continue;
			}
			// Always start from the untouched price so repeated calculations never stack.
			$product->set_price( $orig );
			if ( empty( $item['eru_dti']['value'] ) || 'line_discount' !== Eru_DTI_Settings::get( 'discount_mode' ) ) {
				continue;
			}
			$ex    = (float) wc_get_price_excluding_tax( $product );
			$ratio = $ex > 0 ? (float) $orig / $ex : 1.0;
			$qty   = max( 1, (int) $item['quantity'] );
			$new   = max( 0, (float) $orig - (float) $item['eru_dti']['value'] * $ratio / $qty );
			$product->set_price( (string) round( $new, wc_get_price_decimals() + 2 ) );
		}
	}

	/** Fee mode: one negative, non-taxable fee. @param WC_Cart $cart Cart. */
	public static function apply_fee( $cart ) {
		if ( 'fee' !== Eru_DTI_Settings::get( 'discount_mode' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['eru_dti']['value'] ) ) {
				$cart->add_fee( self::fee_label(), -1 * (float) $item['eru_dti']['value'], false );
				return;
			}
		}
	}

	/** @return string Fee label. */
	private static function fee_label() {
		return __( 'Takas indirimi', 'device-trade-in-for-woocommerce' );
	}

	/**
	 * WooCommerce spreads the tax of any negative fee across the items as if it were a discount,
	 * even when the fee is non-taxable. In fee mode the credit must leave the VAT base alone, so its tax share is dropped.
	 *
	 * @param array  $taxes Fee taxes.
	 * @param object $fee   Fee props.
	 * @return array
	 */
	public static function keep_fee_untaxed( $taxes, $fee ) {
		if ( 'fee' === Eru_DTI_Settings::get( 'discount_mode' ) && isset( $fee->object->id ) && sanitize_title( self::fee_label() ) === $fee->object->id ) {
			return array();
		}
		return $taxes;
	}

	/** @param string $key Cart item key. @return string Nonce-protected remove URL. */
	public static function remove_url( $key ) {
		return wp_nonce_url( add_query_arg( 'eru_dti_remove', rawurlencode( $key ), wc_get_cart_url() ), 'eru_dti_remove_' . $key );
	}

	/** Removes the trade-in from a cart line. @param string $key Cart item key. */
	public static function remove_tradein( $key ) {
		$cart = WC()->cart;
		if ( $cart && isset( $cart->cart_contents[ $key ]['eru_dti'] ) ) {
			unset( $cart->cart_contents[ $key ]['eru_dti'] );
			$cart->set_session();
		}
	}

	/** Handles the remove link. */
	public static function handle_remove() {
		if ( empty( $_GET['eru_dti_remove'] ) ) {
			return;
		}
		$key = sanitize_text_field( wp_unslash( $_GET['eru_dti_remove'] ) );
		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'eru_dti_remove_' . $key ) ) {
			self::remove_tradein( $key );
			wc_add_notice( __( 'Takas sepetinizden kaldırıldı.', 'device-trade-in-for-woocommerce' ), 'success' );
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/** @param array $data Item data lines. @param array $item Cart item. @return array */
	public static function item_data( $data, $item ) {
		if ( empty( $item['eru_dti']['label'] ) ) {
			return $data;
		}
		$t      = $item['eru_dti'];
		$data[] = array( 'key' => __( 'Takas cihazı', 'device-trade-in-for-woocommerce' ), 'value' => esc_html( $t['label'] . ' (' . $t['cond'] . ')' ) );
		$data[] = array( 'key' => __( 'Takas değeri', 'device-trade-in-for-woocommerce' ), 'value' => wc_price( $t['value'] ) );
		$key    = '';
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $k => $ci ) {
				if ( ! empty( $ci['eru_dti'] ) && $ci['eru_dti'] === $t ) {
					$key = $k;
				}
			}
		}
		if ( '' !== $key && ( is_cart() || is_checkout() ) ) {
			$data[] = array( 'key' => '', 'value' => '<a class="eru-dti-remove" href="' . esc_url( self::remove_url( $key ) ) . '">' . esc_html__( 'Takası kaldır', 'device-trade-in-for-woocommerce' ) . '</a>' );
		}
		return $data;
	}

	/** @param WC_Order_Item_Product $item Item. @param string $key Cart key. @param array $values Cart item. */
	public static function line_item_meta( $item, $key, $values ) {
		if ( empty( $values['eru_dti']['label'] ) ) {
			return;
		}
		$t = $values['eru_dti'];
		$item->add_meta_data( __( 'Takas cihazı', 'device-trade-in-for-woocommerce' ), $t['label'] . ' (' . $t['cond'] . ')' );
		$item->add_meta_data( __( 'Takas değeri', 'device-trade-in-for-woocommerce' ), html_entity_decode( wp_strip_all_tags( wc_price( $t['value'] ) ) ) );
		$item->add_meta_data( '_eru_dti', $t, true );
	}

	/** Writes the trade-in row once. @param WC_Order $order Order. */
	public static function create_tradein_for_order( $order ) {
		if ( ! $order instanceof WC_Order || $order->get_meta( '_eru_dti_tradein_id' ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$t = $item->get_meta( '_eru_dti' );
			if ( empty( $t['device_id'] ) ) {
				continue;
			}
			$id = Eru_DTI_DB::tradein_insert(
				array(
					'order_id'       => $order->get_id(),
					'device_id'      => $t['device_id'],
					'condition_key'  => $t['condition'],
					'serial'         => $t['serial'] ?? '',
					'value'          => $t['value'] ?? 0,
					'bonus'          => $t['bonus'] ?? 0,
					'status'         => 'beklemede',
					'customer_email' => $order->get_billing_email(),
				)
			);
			// The serial lives only in the trade-in table (erasable); drop it from the order line.
			unset( $t['serial'] );
			$item->update_meta_data( '_eru_dti', $t );
			$item->save();
			$order->update_meta_data( '_eru_dti_tradein_id', $id );
			$order->add_order_note( sprintf( /* translators: %s: device label. */ __( 'Takas kaydı oluşturuldu: %s', 'device-trade-in-for-woocommerce' ), $t['label'] ?? '' ) );
			$order->save();
			return;
		}
	}

	/** Shows the shipping instructions on the order-received page. @param int $order_id Order ID. */
	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_eru_dti_tradein_id' ) ) {
			return;
		}
		$text = Eru_DTI_Overdue::instructions( $order->get_order_number() );
		if ( '' !== $text ) {
			echo '<section class="eru-dti-ship"><h2>' . esc_html__( 'Takas cihazınızı gönderin', 'device-trade-in-for-woocommerce' ) . '</h2><p>' . nl2br( esc_html( $text ) ) . '</p></section>';
		}
	}
}
