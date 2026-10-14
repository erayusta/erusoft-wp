<?php
/**
 * Server side: validation, cart item data, pricing, per-line fees, cart/order display, order again.
 *
 * @package product-add-on-services-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart and order integration. The server is the only authority for choices and prices.
 *
 * Cart item keys: `eru_aos` (list of chosen options, snapshot of the definition at add time),
 * Request-only pricing state (base price read once, price this plugin set, final price after all plugins)
 * is kept outside the cart item, keyed by cart item key, so it is never saved to the session and never
 * changes the cart hash. It is shared by every WC_Cart holding the same key (cloned recurring carts share
 * the product objects too), reset when an item is added or restored from the session and when the cart
 * is emptied, and it survives other plugins replacing the item's product object with a clone.
 */
class Eru_AOS_Cart {

	/**
	 * Pricing state: cart item key => array( 'base' => float, 'applied' => ?float, 'seen' => ?float ).
	 *
	 * @var array
	 */
	private static $state = array();

	private static function get_state( $cart, $key ) {
		return self::$state[ (string) $key ] ?? null;
	}

	private static function set_state( $cart, $key, $st ) {
		if ( '' === (string) $key ) {
			return;
		}
		self::$state[ (string) $key ] = $st;
	}

	public static function reset_state() {
		// A cloned cart being emptied must not wipe the main cart's state.
		if ( ! WC()->cart instanceof WC_Cart || WC()->cart->is_empty() ) {
			self::$state = array();
		}
	}

	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'capture' ), 20, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 20, 3 );
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'reset_state' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_calculate_totals' ), 20 );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'after_calculate_totals' ), 999 );
		add_action( 'woocommerce_before_mini_cart', array( __CLASS__, 'mini_cart' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fees' ) );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 4 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( __CLASS__, 'order_again' ), 10, 2 );

		// Simple products with a required group cannot be added from a product list: link to the product page instead.
		add_filter( 'woocommerce_product_add_to_cart_url', array( __CLASS__, 'loop_url' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'loop_text' ), 20, 2 );
		add_filter( 'woocommerce_product_supports', array( __CLASS__, 'loop_supports' ), 20, 3 );
	}

	/* ---------------------------------------------------------------- choices */

	/**
	 * Raw choices posted for a product, as group ID => option ID | option ID list.
	 * Read only when the request adds this very product: `add-to-cart`, `product_id` or `variation_id` is the
	 * product or one of its variations (classic form, theme AJAX forms that post the variation ID).
	 *
	 * @param int $product_id Product (parent) ID.
	 * @return array|null Null when the request does not add this product.
	 */
	public static function posted( $product_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- WooCommerce add-to-cart has no nonce; every value is validated against the product's groups.
		$pid   = (int) $product_id;
		$match = false;
		foreach ( array( 'add-to-cart', 'product_id', 'variation_id' ) as $field ) {
			$target = absint( $_REQUEST[ $field ] ?? 0 );
			if ( ! $target ) {
				continue;
			}
			if ( $target === $pid ) {
				$match = true;
				break;
			}
			$p = wc_get_product( $target );
			if ( $p && $p->is_type( 'variation' ) && $p->get_parent_id() === $pid ) {
				$match = true;
				break;
			}
		}
		if ( ! $match ) {
			return null;
		}
		if ( ! isset( $_POST['eru_aos'] ) || ! is_array( $_POST['eru_aos'] ) ) {
			return array();
		}
		$raw = wp_unslash( $_POST['eru_aos'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		// phpcs:enable
		$out = array();
		foreach ( $raw as $gid => $val ) {
			$out[ absint( $gid ) ] = is_array( $val ) ? array_map( fn( $v ) => sanitize_key( (string) $v ), array_values( $val ) ) : sanitize_key( (string) $val );
		}
		return $out;
	}

	/**
	 * Turns a stored choice list back into the posted shape.
	 *
	 * @param array $choices Choice snapshots.
	 * @return array
	 */
	public static function to_posted( array $choices ) {
		$out = array();
		foreach ( $choices as $c ) {
			if ( isset( $c['group_id'], $c['option_id'] ) ) {
				$out[ (int) $c['group_id'] ][] = sanitize_key( $c['option_id'] );
			}
		}
		return $out;
	}

	/**
	 * Validates raw choices against the groups of a product and returns the choice snapshots.
	 *
	 * @param int   $product_id Product (parent) ID.
	 * @param array $posted     Group ID => option ID | option IDs.
	 * @return array|WP_Error
	 */
	public static function parse( $product_id, array $posted ) {
		$groups = array();
		foreach ( Eru_AOS_Groups::for_product( $product_id ) as $g ) {
			$groups[ (int) $g['id'] ] = $g;
		}
		foreach ( array_keys( $posted ) as $gid ) {
			if ( ! isset( $groups[ (int) $gid ] ) ) {
				return new WP_Error( 'eru_aos_foreign', __( 'Seçilen ek hizmet bu ürün için geçerli değil.', 'product-add-on-services-for-woocommerce' ) );
			}
		}

		$choices = array();
		foreach ( $groups as $gid => $g ) {
			$val = $posted[ $gid ] ?? array();
			if ( 'single' === $g['type'] && is_array( $val ) ) {
				// Stored choices come back as a list; a posted radio is a string.
				if ( count( $val ) > 1 ) {
					return new WP_Error( 'eru_aos_invalid', __( 'Seçilen ek hizmet bu ürün için geçerli değil.', 'product-add-on-services-for-woocommerce' ) );
				}
				$val = $val ? reset( $val ) : '';
			}
			$ids = array_values( array_unique( array_filter( array_map( 'strval', (array) $val ), 'strlen' ) ) );

			$by_id = array_column( $g['options'], null, 'id' );
			foreach ( $ids as $oid ) {
				if ( ! isset( $by_id[ $oid ] ) ) {
					return new WP_Error( 'eru_aos_invalid', __( 'Seçilen ek hizmet bu ürün için geçerli değil.', 'product-add-on-services-for-woocommerce' ) );
				}
			}

			$n = count( $ids );
			if ( 0 === $n && $g['required'] ) {
				/* translators: %s: service group name */
				return new WP_Error( 'eru_aos_required', sprintf( __( '"%s" için bir seçim yapın.', 'product-add-on-services-for-woocommerce' ), $g['title'] ) );
			}
			if ( $n > 0 && $g['min'] && $n < $g['min'] ) {
				/* translators: 1: service group name, 2: minimum number of choices */
				return new WP_Error( 'eru_aos_min', sprintf( __( '"%1$s" için en az %2$d seçim yapın.', 'product-add-on-services-for-woocommerce' ), $g['title'], $g['min'] ) );
			}
			if ( $g['max'] && $n > $g['max'] ) {
				/* translators: 1: service group name, 2: maximum number of choices */
				return new WP_Error( 'eru_aos_max', sprintf( __( '"%1$s" için en fazla %2$d seçim yapabilirsiniz.', 'product-add-on-services-for-woocommerce' ), $g['title'], $g['max'] ) );
			}

			foreach ( $g['options'] as $o ) {
				if ( in_array( $o['id'], $ids, true ) ) {
					$choices[] = array(
						'group_id'   => $gid,
						'group'      => $g['title'],
						'option_id'  => $o['id'],
						'label'      => $o['label'],
						'price_type' => $o['price_type'],
						'amount'     => $o['amount'],
						'charge'     => $o['charge'],
					);
				}
			}
		}
		return $choices;
	}

	/* ------------------------------------------------------------- add to cart */

	public static function validate( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! $passed ) {
			return $passed;
		}
		$provided = is_array( $cart_item_data ) && isset( $cart_item_data['eru_aos'] );
		if ( ! Eru_AOS_Groups::for_product( $product_id ) ) {
			// Add-ons handed in by code (order again, other plugins) for a product that has none now.
			if ( $provided && (array) $cart_item_data['eru_aos'] ) {
				wc_add_notice( __( 'Seçilen ek hizmet bu ürün için geçerli değil.', 'product-add-on-services-for-woocommerce' ), 'error' );
				return false;
			}
			return $passed;
		}
		if ( $provided ) {
			$posted = self::to_posted( (array) $cart_item_data['eru_aos'] );
		} else {
			$posted = self::posted( $product_id );
			if ( null === $posted && self::is_grouped_child( $product_id ) ) {
				// The grouped product form has no add-on fields: children are added without add-ons.
				return $passed;
			}
			$posted = (array) $posted;
		}
		$result = self::parse( $product_id, $posted );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Whether the request is a grouped product form that contains this product as a child.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private static function is_grouped_child( $product_id ) {
		$target = absint( $_REQUEST['add-to-cart'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
		$parent = $target ? wc_get_product( $target ) : null;
		return $parent && $parent->is_type( 'grouped' ) && in_array( (int) $product_id, array_map( 'intval', $parent->get_children() ), true );
	}

	/**
	 * Stores the chosen options on the cart item. Different choices make different cart lines.
	 *
	 * @throws Exception When the posted choices are invalid (add_to_cart() turns it into a notice).
	 */
	public static function add_cart_item_data( $data, $product_id, $variation_id ) {
		if ( isset( $data['eru_aos'] ) ) {
			return $data;
		}
		$posted = self::posted( $product_id );
		if ( null === $posted || ! Eru_AOS_Groups::for_product( $product_id ) ) {
			return $data;
		}
		$choices = self::parse( $product_id, $posted );
		if ( is_wp_error( $choices ) ) {
			throw new Exception( esc_html( $choices->get_error_message() ) );
		}
		if ( $choices ) {
			$data['eru_aos'] = $choices;
		}
		return $data;
	}

	/* ----------------------------------------------------------------- pricing */

	/**
	 * Amount of one chosen option for one unit of a product.
	 *
	 * @param array      $choice  Choice snapshot.
	 * @param WC_Product $product Cart product.
	 * @param float      $base    Product price before add-ons.
	 * @return float
	 */
	public static function amount( array $choice, WC_Product $product, $base ) {
		$amount = (float) $choice['amount'];
		if ( 'percent' === $choice['price_type'] ) {
			$pct_base = $base;
			if ( 'regular' === get_option( 'eru_aos_percent_base', 'current' ) && '' !== (string) $product->get_regular_price( 'edit' ) ) {
				$pct_base = (float) $product->get_regular_price( 'edit' );
			}
			$amount = $pct_base * $amount / 100;
		}
		/**
		 * Filters the amount of one add-on option (per unit, or per line for per_line options).
		 *
		 * @param float      $amount  Computed amount.
		 * @param array      $choice  Choice snapshot (group, label, price_type, amount, charge...).
		 * @param WC_Product $product Cart product (variation for variable products).
		 * @param float      $base    Product price before add-ons.
		 */
		$amount = (float) apply_filters( 'eru_aos_option_amount', $amount, $choice, $product, (float) $base );
		return round( max( 0, $amount ), wc_get_price_decimals() );
	}

	/**
	 * Reads the product price before add-ons once, when the item is added or restored from the session.
	 * The price itself is only changed in woocommerce_before_calculate_totals, so other price plugins
	 * never see an add-on price on their first run.
	 *
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function capture( $item, $key = '' ) {
		if ( ! empty( $item['eru_aos'] ) && isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
			self::set_state(
				WC()->cart,
				$key ? $key : ( $item['key'] ?? '' ),
				array(
					'base'    => (float) $item['data']->get_price( 'edit' ),
					'applied' => null,
					'seen'    => null,
				)
			);
		}
		return $item;
	}

	/**
	 * Product price before add-ons for a cart item, or null when not known yet.
	 *
	 * @param array $item Cart item.
	 * @return float|null
	 */
	public static function base_for( array $item, $cart = null ) {
		$st = self::get_state( $cart ? $cart : WC()->cart, $item['key'] ?? '' );
		return $st ? (float) $st['base'] : null;
	}

	/**
	 * Sets the unit price of a cart item to base + per-unit add-ons. Idempotent: the base stays as captured
	 * unless the price met here is neither the base, the price this plugin set nor the final price of the
	 * previous calculation – i.e. an earlier-priority plugin changed it during this run, in which case its
	 * price becomes the base. Only the computed option amounts (`unit`) are written to the cart item; they
	 * are the same after a session load and after a calculation, so the cart hash stays stable.
	 *
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function apply_item( $item, $key = '', $cart = null ) {
		if ( empty( $item['eru_aos'] ) || empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
			return $item;
		}
		$product = $item['data'];
		$cart    = $cart ? $cart : WC()->cart;
		$key     = $key ? $key : ( $item['key'] ?? '' );
		$current = (float) $product->get_price( 'edit' );
		$st      = self::get_state( $cart, $key );
		if ( null === $st ) {
			$st = array(
				'base'    => $current,
				'applied' => null,
				'seen'    => null,
			);
		} else {
			$match = false;
			foreach ( array( $st['base'], $st['applied'], $st['seen'] ) as $k ) {
				$match = $match || ( null !== $k && abs( $current - (float) $k ) < 0.000001 );
			}
			if ( ! $match ) {
				$st['base'] = $current;
			}
		}
		$base = (float) $st['base'];
		$add  = 0.0;
		foreach ( $item['eru_aos'] as $k => $c ) {
			$unit                          = self::amount( $c, $product, $base );
			$item['eru_aos'][ $k ]['unit'] = $unit;
			if ( 'per_unit' === $c['charge'] ) {
				$add += $unit;
			}
		}
		$price = $base + $add;
		$product->set_price( $price );
		$st['applied'] = $price;
		self::set_state( $cart, $key, $st );
		return $item;
	}

	public static function from_session( $item, $values = array(), $key = '' ) {
		// Sessions written by an earlier build may still carry request-only keys.
		unset( $item['_eru_aos_base'], $item['_eru_aos_applied'], $item['_eru_aos_seen'] );
		return self::capture( $item, $key );
	}

	public static function before_calculate_totals( $cart ) {
		$contents = $cart->get_cart_contents();
		$changed  = false;
		foreach ( $contents as $key => $item ) {
			if ( ! empty( $item['eru_aos'] ) ) {
				$contents[ $key ] = self::apply_item( $item, $key, $cart );
				$changed          = true;
			}
		}
		if ( $changed ) {
			$cart->set_cart_contents( $contents );
		}
	}

	/**
	 * Remembers the final unit price (after every price plugin) so the next run can tell it apart.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function after_calculate_totals( $cart ) {
		foreach ( $cart->get_cart() as $key => $item ) {
			$st = ! empty( $item['eru_aos'] ) && isset( $item['data'] ) && $item['data'] instanceof WC_Product ? self::get_state( $cart, $key ) : null;
			if ( $st ) {
				$st['seen'] = (float) $item['data']->get_price( 'edit' );
				self::set_state( $cart, $key, $st );
			}
		}
	}

	/**
	 * The classic mini cart prints product prices; make sure they include add-ons on requests that did not
	 * calculate the cart yet.
	 */
	public static function mini_cart() {
		if ( did_action( 'woocommerce_after_calculate_totals' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['eru_aos'] ) ) {
				WC()->cart->calculate_totals();
				return;
			}
		}
	}

	/**
	 * Per-line options: one fee per cart line and option, charged once regardless of quantity and taxed like
	 * the product. Amounts are entered like product prices; with "prices include tax" the fee is converted to
	 * net with the shop base rates, the same way WooCommerce treats the product price.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function add_fees( $cart ) {
		foreach ( $cart->get_cart() as $cart_key => $item ) {
			if ( empty( $item['eru_aos'] ) || empty( $item['data'] ) ) {
				continue;
			}
			$product = $item['data'];
			$st      = self::get_state( $cart, $cart_key );
			$base    = $st ? (float) $st['base'] : (float) $product->get_price( 'edit' );
			foreach ( $item['eru_aos'] as $c ) {
				if ( 'per_line' !== $c['charge'] ) {
					continue;
				}
				$amount = isset( $c['unit'] ) ? (float) $c['unit'] : self::amount( $c, $product, $base );
				if ( $amount <= 0 ) {
					continue;
				}
				$taxable = $product->is_taxable();
				$class   = $product->get_tax_class();
				if ( $taxable && wc_tax_enabled() && wc_prices_include_tax() ) {
					$amount -= array_sum( WC_Tax::calc_tax( $amount, WC_Tax::get_base_tax_rates( $class ), true ) );
				}
				$fee = array(
					'id'        => 'eru-aos-' . md5( $cart_key . '|' . $c['group_id'] . '|' . $c['option_id'] ),
					/* translators: 1: service group, 2: option, 3: product name */
					'name'      => sprintf( __( '%1$s: %2$s (%3$s)', 'product-add-on-services-for-woocommerce' ), $c['group'], $c['label'], $product->get_name() ),
					'amount'    => $amount,
					'taxable'   => $taxable,
					'tax_class' => $class,
				);
				// The ID is deterministic: "fee_exists" means this very charge is already in the cart
				// (e.g. calculate_fees() called again after calculate_totals()), so it is not added twice.
				$cart->fees_api()->add_fee( $fee );
			}
		}
	}

	/* ----------------------------------------------------------------- display */

	/**
	 * Plain-text price for cart, checkout, order and e-mail meta (blocks show plain text).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code (orders).
	 * @return string
	 */
	public static function price_text( $amount, $currency = '' ) {
		$args = $currency ? array( 'currency' => $currency ) : array();
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * "Label (+price)" with a per-line marker.
	 *
	 * @param array  $c        Choice.
	 * @param float  $unit     Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function choice_text( array $c, $unit, $currency = '' ) {
		if ( (float) $unit <= 0 ) {
			return $c['label'];
		}
		$price = '+' . self::price_text( $unit, $currency );
		if ( 'per_line' === $c['charge'] ) {
			/* translators: %s: price */
			$price = sprintf( __( '%s, tek sefer', 'product-add-on-services-for-woocommerce' ), $price );
		}
		return sprintf( '%1$s (%2$s)', $c['label'], $price );
	}

	public static function item_data( $data, $item ) {
		if ( empty( $item['eru_aos'] ) ) {
			return $data;
		}
		foreach ( $item['eru_aos'] as $c ) {
			$data[] = array(
				'key'   => $c['group'],
				'value' => self::choice_text( $c, (float) ( $c['unit'] ?? 0 ) ),
			);
		}
		return $data;
	}

	public static function order_line_item( $order_item, $cart_item_key, $values, $order = null ) {
		if ( empty( $values['eru_aos'] ) ) {
			return;
		}
		$currency = $order instanceof WC_Order ? $order->get_currency() : '';
		$keep     = array();
		foreach ( $values['eru_aos'] as $c ) {
			$unit = (float) ( $c['unit'] ?? 0 );
			$order_item->add_meta_data( __( 'Ek hizmet', 'product-add-on-services-for-woocommerce' ), $c['group'] . ' – ' . self::choice_text( $c, $unit, $currency ), false );
			$keep[] = array_intersect_key( $c, array_flip( array( 'group_id', 'group', 'option_id', 'label', 'price_type', 'amount', 'charge', 'unit' ) ) );
		}
		$order_item->add_meta_data( '_eru_aos_choices', $keep, true );
	}

	/**
	 * Order again: rebuild the choices from the current group definitions only, never from the stored
	 * snapshot (its prices may be stale). Options and groups that no longer apply are dropped with a
	 * notice; if a required group can no longer be satisfied, validation rejects the line with a notice.
	 */
	public static function order_again( $data, $order_item ) {
		$stored = $order_item->get_meta( '_eru_aos_choices' );
		if ( ! is_array( $stored ) || ! $stored ) {
			return $data;
		}
		$groups = array();
		foreach ( Eru_AOS_Groups::for_product( $order_item->get_product_id() ) as $g ) {
			$groups[ (int) $g['id'] ] = $g;
		}
		$keep    = array();
		$dropped = false;
		foreach ( self::to_posted( $stored ) as $gid => $oids ) {
			$g     = $groups[ $gid ] ?? null;
			$valid = $g ? array_values( array_intersect( $oids, array_column( $g['options'], 'id' ) ) ) : array();
			if ( count( $valid ) < count( $oids ) ) {
				$dropped = true;
			}
			if ( $valid ) {
				$keep[ $gid ] = $valid;
			}
		}
		if ( $dropped ) {
			wc_add_notice( __( 'Bazı ek hizmetler artık sunulmadığı için sepete eklenmedi.', 'product-add-on-services-for-woocommerce' ), 'notice' );
		}
		unset( $data['eru_aos'] );
		$choices = self::parse( $order_item->get_product_id(), $keep );
		if ( ! is_wp_error( $choices ) && $choices ) {
			$data['eru_aos'] = $choices;
		}
		// On a WP_Error (a required group or a minimum can no longer be met) nothing is set: validate()
		// re-parses an empty choice list, adds the notice and the line is skipped.
		return $data;
	}

	/* -------------------------------------------------------------- loop button */

	private static function needs_choice( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) ) {
			return false;
		}
		foreach ( Eru_AOS_Groups::for_product( $product ) as $g ) {
			if ( $g['required'] ) {
				return true;
			}
		}
		return false;
	}

	public static function loop_url( $url, $product ) {
		return self::needs_choice( $product ) ? $product->get_permalink() : $url;
	}

	public static function loop_text( $text, $product ) {
		return self::needs_choice( $product ) ? __( 'Seçenekleri seç', 'product-add-on-services-for-woocommerce' ) : $text;
	}

	public static function loop_supports( $supports, $feature, $product ) {
		return 'ajax_add_to_cart' === $feature && $supports && self::needs_choice( $product ) ? false : $supports;
	}
}
