<?php
/** Limit evaluation and enforcement (add to cart, cart update, cart/checkout validation). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PL_Limits {

	/** @var array<string,array<int,int>> Request cache of purchase history: key => product_id => qty. */
	private static array $history = array();

	public static function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'on_add' ), 20, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'on_update' ), 20, 4 );
		// woocommerce_add_to_cart_validation runs only in the form, AJAX and session-restore paths; a direct
		// WC_Cart::add_to_cart() call (other plugins, custom code) skips it. Every add is re-checked here and reverted when over a limit.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'after_add' ), 20, 3 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'on_store_api_add' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'on_cart_check' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'on_checkout' ), 10, 2 );
		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'quantity_args' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_cache' ) );
	}

	public static function flush_cache(): void {
		self::$history = array();
	}

	/* ---------------------------------------------------------------- constraints */

	/**
	 * Constraints that apply to the given product ids.
	 *
	 * @param int[] $product_ids Parent product ids present in the evaluation.
	 * @return array<int,array<string,mixed>> Each: name, match (callable int=>bool), min, max, customer_max, period_days.
	 */
	private static function constraints( array $product_ids ): array {
		$list = array();
		foreach ( array_unique( $product_ids ) as $pid ) {
			$min = (int) get_post_meta( $pid, '_eru_pl_min', true );
			$max = (int) get_post_meta( $pid, '_eru_pl_max', true );
			$cm  = (int) get_post_meta( $pid, '_eru_pl_customer_max', true );
			if ( $min || $max || $cm ) {
				$list[] = array(
					'name'         => get_the_title( $pid ),
					'match'        => static fn( int $id ): bool => $id === $pid,
					'min'          => $min,
					'max'          => $max,
					'customer_max' => $cm,
					'period_days'  => (int) get_post_meta( $pid, '_eru_pl_period_days', true ),
				);
			}
		}
		$settings = Eru_PL_Settings::get();
		foreach ( (array) $settings['category_rules'] as $rule ) {
			$term = get_term( (int) $rule['category'], 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$cat   = (int) $rule['category'];
			$list[] = array(
				'name'         => $term->name,
				'match'        => static function ( int $id ) use ( $cat ): bool {
					return has_term( $cat, 'product_cat', $id ) || self::in_child_category( $id, $cat );
				},
				'min'          => (int) $rule['min'],
				'max'          => (int) $rule['max'],
				'customer_max' => (int) $rule['customer_max'],
				'period_days'  => (int) $rule['period_days'],
			);
		}
		if ( $settings['order_min'] || $settings['order_max'] ) {
			$list[] = array(
				'name'         => __( 'Sepet', 'purchase-limits-for-woocommerce' ),
				'match'        => static fn( int $id ): bool => true,
				'min'          => (int) $settings['order_min'],
				'max'          => (int) $settings['order_max'],
				'customer_max' => 0,
				'period_days'  => 0,
			);
		}
		/**
		 * Filters the list of constraints (add your own, or drop some).
		 *
		 * @param array $list        Constraints.
		 * @param int[] $product_ids Product ids in the evaluation.
		 */
		return (array) apply_filters( 'eru_pl_constraints', $list, $product_ids );
	}

	private static function in_child_category( int $product_id, int $cat ): bool {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term_id ) {
			if ( in_array( $cat, array_map( 'intval', get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------- evaluation */

	/**
	 * @param array<int,int> $qtys      product_id => quantity.
	 * @param bool           $check_min Whether minimums are enforced (not while adding to cart).
	 * @param string         $email     Billing e-mail for guests (checkout).
	 * @return string[] Messages.
	 */
	public static function violations( array $qtys, bool $check_min, string $email = '' ): array {
		$messages = array();
		foreach ( self::constraints( array_keys( $qtys ) ) as $c ) {
			$total = 0;
			foreach ( $qtys as $pid => $qty ) {
				if ( ( $c['match'] )( (int) $pid ) ) {
					$total += (int) $qty;
				}
			}
			if ( $total <= 0 ) {
				continue;
			}
			if ( $c['max'] && $total > $c['max'] ) {
				$messages[] = self::message( 'msg_max', $c, array( '{limit}' => $c['max'] ) );
			}
			if ( $check_min && $c['min'] && $total < $c['min'] ) {
				$messages[] = self::message( 'msg_min', $c, array( '{limit}' => $c['min'] ) );
			}
			if ( $c['customer_max'] ) {
				$bought = self::bought( $c['match'], (int) $c['period_days'], $email );
				if ( null !== $bought && $bought + $total > $c['customer_max'] ) {
					$period     = $c['period_days'] > 0
						/* translators: %d: number of days */
						? sprintf( _n( 'son %d gün içinde', 'son %d gün içinde', $c['period_days'], 'purchase-limits-for-woocommerce' ), $c['period_days'] )
						: __( 'toplamda', 'purchase-limits-for-woocommerce' );
					$messages[] = self::message( 'msg_customer', $c, array( '{limit}' => $c['customer_max'], '{bought}' => $bought, '{period}' => $period ) );
				}
			}
		}
		return array_values( array_unique( $messages ) );
	}

	private static function message( string $key, array $c, array $vars ): string {
		$settings = Eru_PL_Settings::get();
		$template = '' !== (string) $settings[ $key ] ? (string) $settings[ $key ] : Eru_PL_Settings::default_message( $key );
		$vars['{name}'] = $c['name'];
		return strtr( $template, array_map( 'strval', $vars ) );
	}

	/**
	 * Quantity of matching products the customer has already bought in the period.
	 * Returns null when the customer cannot be identified (guest without e-mail yet).
	 */
	private static function bought( callable $match, int $period_days, string $email ): ?int {
		$user_id = get_current_user_id();
		$args    = array(
			'limit'  => -1,
			'return' => 'objects',
			'status' => (array) apply_filters( 'eru_pl_counted_statuses', array( 'wc-processing', 'wc-on-hold', 'wc-completed' ) ),
		);
		if ( $user_id ) {
			$args['customer_id'] = $user_id;
			$who                 = 'u' . $user_id;
		} elseif ( is_email( $email ) ) {
			$args['billing_email'] = $email;
			$who                   = 'e' . strtolower( $email );
		} else {
			return null;
		}
		if ( $period_days > 0 ) {
			$args['date_created'] = '>' . ( time() - $period_days * DAY_IN_SECONDS );
		}
		$key = $who . '|' . $period_days;
		if ( ! isset( self::$history[ $key ] ) ) {
			$sum = array();
			foreach ( wc_get_orders( $args ) as $order ) {
				foreach ( $order->get_items() as $item ) {
					$pid         = (int) $item->get_product_id();
					$sum[ $pid ] = ( $sum[ $pid ] ?? 0 ) + (int) $item->get_quantity();
				}
			}
			self::$history[ $key ] = $sum;
		}
		$total = 0;
		foreach ( self::$history[ $key ] as $pid => $qty ) {
			if ( $match( (int) $pid ) ) {
				$total += $qty;
			}
		}
		return $total;
	}

	/** @return array<int,int> product_id => qty over the current cart. */
	private static function cart_qtys( ?string $skip_key = null ): array {
		$qtys = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				if ( $key === $skip_key ) {
					continue;
				}
				$pid          = (int) $item['product_id'];
				$qtys[ $pid ] = ( $qtys[ $pid ] ?? 0 ) + (int) $item['quantity'];
			}
		}
		return $qtys;
	}

	/* ---------------------------------------------------------------- hooks */

	public static function on_add( $passed, $product_id, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}
		$product_id          = (int) $product_id;
		$qtys                = self::cart_qtys();
		$qtys[ $product_id ] = ( $qtys[ $product_id ] ?? 0 ) + (int) $quantity;
		return self::reject( self::violations( $qtys, false ) );
	}

	public static function on_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}
		$qtys = self::cart_qtys( (string) $cart_item_key );
		$pid  = (int) ( $values['product_id'] ?? 0 );
		$qtys[ $pid ] = ( $qtys[ $pid ] ?? 0 ) + (int) $quantity;
		return self::reject( self::violations( $qtys, false ) );
	}

	public static function after_add( $cart_key, $product_id, $quantity ): void {
		$messages = self::violations( self::cart_qtys(), false );
		if ( ! $messages ) {
			return;
		}
		$cart = WC()->cart;
		$item = $cart->get_cart_item( (string) $cart_key );
		if ( $item ) {
			$cart->set_quantity( (string) $cart_key, max( 0, (int) $item['quantity'] - (int) $quantity ) );
		}
		self::reject( $messages );
	}

	/** Store API (block cart): block the add with a route error. */
	public static function on_store_api_add( $product, $request ): void {
		if ( ! $product instanceof WC_Product || ! class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			return;
		}
		$pid          = $product->get_parent_id() ?: $product->get_id();
		$qtys         = self::cart_qtys();
		$qtys[ $pid ] = ( $qtys[ $pid ] ?? 0 ) + max( 1, (int) ( $request['quantity'] ?? 1 ) );
		$messages     = self::violations( $qtys, false );
		if ( $messages ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'eru_pl_limit', esc_html( $messages[0] ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** @param string[] $messages */
	private static function reject( array $messages ): bool {
		foreach ( $messages as $message ) {
			wc_add_notice( esc_html( $message ), 'error' );
		}
		return ! $messages;
	}

	public static function on_cart_check(): void {
		foreach ( self::violations( self::cart_qtys(), true ) as $message ) {
			wc_add_notice( esc_html( $message ), 'error' );
		}
	}

	public static function on_checkout( $data, $errors ): void {
		$email = is_array( $data ) ? (string) ( $data['billing_email'] ?? '' ) : '';
		foreach ( self::violations( self::cart_qtys(), true, $email ) as $message ) {
			$errors->add( 'eru_pl_limit', esc_html( $message ) );
		}
	}

	public static function quantity_args( $args, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $args;
		}
		$pid = $product->get_parent_id() ?: $product->get_id();
		$min = (int) get_post_meta( $pid, '_eru_pl_min', true );
		$max = (int) get_post_meta( $pid, '_eru_pl_max', true );
		if ( $max > 0 ) {
			$args['max_value'] = ( ! empty( $args['max_value'] ) && $args['max_value'] > 0 ) ? min( (int) $args['max_value'], $max ) : $max;
		}
		if ( $min > 0 ) {
			$args['min_value'] = max( (int) ( $args['min_value'] ?? 0 ), $min );
			if ( isset( $args['input_value'] ) && $args['input_value'] < $min ) {
				$args['input_value'] = $min;
			}
		}
		return $args;
	}
}
