<?php
/** Checkout selection, validation, saving, display, optional "ready" status. Classic (shortcode) checkout. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CCL_Checkout {

	const READY = 'wc-eru-ccl-ready';

	public static function init(): void {
		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_select' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_choice' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'on_validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'on_create_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_order' ), 5, 1 );

		// Display: admin order, e-mails, thank-you page and My Account order view.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_box' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_info' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'frontend_info' ) );

		// Optional status.
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid_statuses' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_action' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_action' ) );
		add_action( 'woocommerce_order_status_eru-ccl-ready', array( __CLASS__, 'send_ready_email' ), 10, 2 );
	}

	/* ---- selection ---- */

	private static function chosen_rate_for_index( $index ): string {
		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
		return (string) ( $chosen[ $index ] ?? '' );
	}

	public static function render_select( $method, $index ): void {
		if ( ! $method instanceof WC_Shipping_Rate || 'eru_ccl' !== $method->get_method_id() || ! ( defined( 'WOOCOMMERCE_CHECKOUT' ) || is_checkout() ) ) {
			return;
		}
		if ( self::chosen_rate_for_index( $index ) !== $method->get_id() ) {
			return;
		}
		$locations = Eru_CCL_Locations::all();
		$selected  = WC()->session ? (string) WC()->session->get( 'eru_ccl_location' ) : '';
		if ( ! isset( $locations[ $selected ] ) && 1 === count( $locations ) ) {
			$selected = (string) key( $locations );
		}
		echo '<div class="eru-ccl-select" style="margin:6px 0 10px"><label for="eru_ccl_location"><strong>' . esc_html__( 'Teslim noktası', 'click-collect-for-woocommerce' ) . '</strong> <abbr class="required" title="required">*</abbr></label>';
		echo '<select name="eru_ccl_location" id="eru_ccl_location" style="width:100%"><option value="">' . esc_html__( 'Seçin…', 'click-collect-for-woocommerce' ) . '</option>';
		foreach ( $locations as $loc ) {
			echo '<option value="' . esc_attr( $loc['id'] ) . '"' . selected( $selected, $loc['id'], false ) . '>' . esc_html( $loc['name'] . ( '' !== $loc['address'] ? ' — ' . $loc['address'] : '' ) ) . '</option>';
		}
		echo '</select></div>';
	}

	public static function remember_choice( $post_data ): void {
		parse_str( (string) $post_data, $data );
		if ( WC()->session && isset( $data['eru_ccl_location'] ) ) {
			WC()->session->set( 'eru_ccl_location', sanitize_text_field( (string) $data['eru_ccl_location'] ) );
		}
	}

	/* ---- validation / saving ---- */

	private static function posted_location(): string {
		return isset( $_POST['eru_ccl_location'] ) ? sanitize_text_field( wp_unslash( $_POST['eru_ccl_location'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the checkout nonce before these hooks run.
	}

	/** @param string[] $chosen_rates Chosen shipping rate ids (e.g. "eru_ccl:3"). */
	public static function validate( array $chosen_rates, string $location_id, $errors ): void {
		$pickup = false;
		foreach ( $chosen_rates as $rate ) {
			if ( 0 === strpos( (string) $rate, 'eru_ccl' ) ) {
				$pickup = true;
			}
		}
		if ( ! $pickup ) {
			return;
		}
		$all = Eru_CCL_Locations::all();
		if ( '' === $location_id && 1 === count( $all ) ) {
			return;
		}
		if ( ! isset( $all[ $location_id ] ) ) {
			$msg = __( 'Lütfen geçerli bir teslim noktası seçin.', 'click-collect-for-woocommerce' );
			if ( $errors instanceof WP_Error ) {
				$errors->add( 'eru_ccl_location', $msg );
			} else {
				wc_add_notice( $msg, 'error' );
			}
		}
	}

	public static function on_validate( $data, $errors ): void {
		self::validate( (array) ( $data['shipping_method'] ?? array() ), self::posted_location(), $errors );
	}

	public static function on_create_order( $order, $data ): void {
		if ( $order instanceof WC_Order && Eru_CCL_Locations::is_pickup_order( $order ) ) {
			Eru_CCL_Locations::save_to_order( $order, self::posted_location() );
		}
	}

	/**
	 * Block (Store API) checkout cannot show the location picker. With a single configured location it is
	 * assigned; otherwise the request is aborted so no pickup order exists without a location.
	 */
	public static function on_store_api_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! Eru_CCL_Locations::is_pickup_order( $order ) || $order->get_meta( Eru_CCL_Locations::META_LOCATION ) ) {
			return;
		}
		if ( Eru_CCL_Locations::save_to_order( $order, '' ) ) {
			$order->save();
			return;
		}
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'eru_ccl_location', esc_html__( 'Teslim noktası seçilemedi. Lütfen klasik ödeme sayfasını kullanın veya başka bir teslimat yöntemi seçin.', 'click-collect-for-woocommerce' ), 400 );
		}
	}

	/* ---- display ---- */

	public static function admin_box( $order ): void {
		if ( $order instanceof WC_Order ) {
			$l = Eru_CCL_Locations::order_location( $order );
			if ( $l ) {
				echo '<div class="address"><p><strong>' . esc_html__( 'Teslim noktası', 'click-collect-for-woocommerce' ) . ':</strong><br>' . esc_html( $l['name'] );
				if ( '' !== $l['address'] ) {
					echo '<br>' . esc_html( $l['address'] );
				}
				if ( '' !== $l['hours'] ) {
					echo '<br>' . esc_html( $l['hours'] );
				}
				echo '</p></div>';
			}
		}
	}

	public static function email_info( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( $plain_text ) {
			echo esc_html( Eru_CCL_Locations::order_info_text( $order ) );
		} else {
			echo wp_kses_post( Eru_CCL_Locations::order_info_html( $order ) );
		}
	}

	public static function frontend_info( $order ): void {
		if ( $order instanceof WC_Order ) {
			echo wp_kses_post( Eru_CCL_Locations::order_info_html( $order ) );
		}
	}

	/* ---- optional order status ---- */

	private static function status_enabled(): bool {
		return (bool) Eru_CCL_Locations::settings()['ready_status'];
	}

	public static function register_status(): void {
		if ( self::status_enabled() ) {
			register_post_status(
				self::READY,
				array(
					'label'                     => __( 'Teslim almaya hazır', 'click-collect-for-woocommerce' ),
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of orders */
					'label_count'               => _n_noop( 'Teslim almaya hazır <span class="count">(%s)</span>', 'Teslim almaya hazır <span class="count">(%s)</span>', 'click-collect-for-woocommerce' ),
				)
			);
		}
	}

	public static function add_status( $statuses ) {
		if ( self::status_enabled() ) {
			$statuses[ self::READY ] = __( 'Teslim almaya hazır', 'click-collect-for-woocommerce' );
		}
		return $statuses;
	}

	public static function paid_statuses( $statuses ) {
		if ( self::status_enabled() ) {
			$statuses[] = 'eru-ccl-ready';
		}
		return $statuses;
	}

	public static function bulk_action( $actions ) {
		if ( self::status_enabled() ) {
			$actions['mark_eru-ccl-ready'] = __( 'Durumu "Teslim almaya hazır" yap', 'click-collect-for-woocommerce' );
		}
		return $actions;
	}

	public static function send_ready_email( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! Eru_CCL_Locations::settings()['ready_email'] || ! is_email( $order->get_billing_email() ) ) {
			return;
		}
		$mailer  = WC()->mailer();
		$heading = __( 'Siparişiniz teslim almaya hazır', 'click-collect-for-woocommerce' );
		$body    = '<p>' . esc_html( sprintf( /* translators: %s: order number */ __( '#%s numaralı siparişiniz teslim noktasında sizi bekliyor.', 'click-collect-for-woocommerce' ), $order->get_order_number() ) ) . '</p>' . Eru_CCL_Locations::order_info_html( $order );
		$mailer->send( $order->get_billing_email(), $heading, $mailer->wrap_message( $heading, $body ) );
	}
}
