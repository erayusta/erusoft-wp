<?php
/**
 * Abandoned carts: consent checkbox, capture (only after consent), recovery marking and the restore link.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is stored before the visitor ticks the box (or the account opt-in / consent filter says yes).
 */
class Eru_GRA_Cart {

	const NONCE    = 'eru_gra_capture';
	const RATE_MAX = 20;

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render_checkbox' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'enqueue_product' ) );
		add_action( 'wc_ajax_eru_gra_capture', array( __CLASS__, 'ajax' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_consent' ), 10, 2 );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'capture_user' ) );
		add_action( 'template_redirect', array( __CLASS__, 'restore_request' ), 1 );

		// Recovery: any path that creates or updates an order.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_id' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order_id' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
	}

	/** @return string Rate-limit transient key for the visitor. */
	public static function rate_key() {
		return 'eru_gra_rl_' . md5( self::client_ip() );
	}

	/** Visitor IP, only used to rate-limit (never stored). Behind a proxy return the real address with `eru_gra_client_ip`. */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for rate limits.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'eru_gra_client_ip', $ip );
	}

	/** @return bool Checkout form page (not the order-received or pay pages). */
	private static function on_checkout_form() {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && ! is_wc_endpoint_url( 'order-pay' );
	}

	/** Consent checkbox on the classic checkout. */
	public static function render_checkbox() {
		if ( ! Eru_GRA_Settings::marketing_on() || ! self::on_checkout_form() ) {
			return;
		}
		if ( is_user_logged_in() && 'yes' === get_user_meta( get_current_user_id(), Eru_GRA_Consent::USER_META, true ) ) {
			return;
		}
		echo '<p class="form-row eru-gra-consent" id="eru-gra-consent"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="eru_gra_marketing" name="eru_gra_marketing" value="1"> <span>' . esc_html( Eru_GRA_Settings::get( 'general', 'consent_text' ) ) . '</span></label></p>';
	}

	/** Single product markup rendered anywhere (also through the [product_page] shortcode). */
	public static function enqueue_product() {
		if ( Eru_GRA_Settings::is_on( 'alerts' ) ) {
			self::enqueue( true );
		}
	}

	/** Front-end script and style. @param bool $product Force the product-page assets. */
	public static function enqueue( $product = false ) {
		$checkout = Eru_GRA_Settings::marketing_on() && self::on_checkout_form();
		$product  = $product || ( Eru_GRA_Settings::is_on( 'alerts' ) && function_exists( 'is_product' ) && is_product() );
		if ( ! $checkout && ! $product ) {
			return;
		}
		wp_enqueue_style( 'eru-gra-front', ERU_GRA_URL . 'assets/front.css', array(), ERU_GRA_VERSION );
		wp_enqueue_script( 'eru-gra-front', ERU_GRA_URL . 'assets/front.js', array(), ERU_GRA_VERSION, true );
		wp_localize_script(
			'eru-gra-front',
			'eruGra',
			array(
				'captureUrl' => WC_AJAX::get_endpoint( 'eru_gra_capture' ),
				'alertUrl'   => WC_AJAX::get_endpoint( 'eru_gra_alert' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'checkout'   => $checkout ? 1 : 0,
				'error'      => __( 'İstek gönderilemedi, lütfen tekrar deneyin.', 'growth-automations-for-woocommerce' ),
			)
		);
	}

	/** @return array<int,array{product_id:int,variation_id:int,qty:int}> Ids and quantities only. */
	public static function current_lines() {
		$lines = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $line ) {
				$lines[] = array(
					'product_id'   => (int) $line['product_id'],
					'variation_id' => (int) $line['variation_id'],
					'qty'          => (int) $line['quantity'],
				);
			}
		}
		return $lines;
	}

	/**
	 * Insert or refresh the open row for an address.
	 *
	 * @param string $email   Lower-case e-mail.
	 * @param int    $user_id User ID or 0.
	 * @param string $source  checkout|account|filter.
	 * @return int Row ID or 0.
	 */
	public static function store( $email, $user_id, $source ) {
		global $wpdb;
		$t     = Eru_GRA_DB::carts();
		$lines = self::current_lines();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$open  = $wpdb->get_row( $wpdb->prepare( "SELECT id, cart FROM {$t} WHERE email = %s AND status = 'open' ORDER BY id DESC LIMIT 1", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $lines ) {
			if ( $open ) {
				$wpdb->update( $t, array( 'status' => 'expired' ), array( 'id' => (int) $open['id'] ) );
			}
			return 0;
		}
		$json = wp_json_encode( $lines );
		if ( $open ) {
			if ( $open['cart'] !== $json ) {
				$wpdb->update( $t, array( 'cart' => $json, 'updated_at' => $now, 'user_id' => (int) $user_id, 'consent_source' => $source ), array( 'id' => (int) $open['id'] ) );
			}
			return (int) $open['id'];
		}
		// One mail per cool-down: skip when this address was already mailed recently.
		$cool = max( 0, (int) Eru_GRA_Settings::get( 'cart', 'cooldown' ) );
		if ( $cool > 0 ) {
			$recent = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE email = %s AND status IN ('sent','recovered') AND sent_at > %s LIMIT 1", $email, gmdate( 'Y-m-d H:i:s', time() - $cool * HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $recent ) {
				return 0;
			}
		}
		$wpdb->insert(
			$t,
			array(
				'email'          => $email,
				'user_id'        => (int) $user_id,
				'cart'           => $json,
				'token'          => '',
				'consent_source' => $source,
				'status'         => 'open',
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		Eru_GRA_DB::flag_data();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Guest/logged-in capture request from the checkout checkbox, free of output so it can be tested.
	 *
	 * @param array $post email, consent ("1" when the box is ticked).
	 * @return array{ok:bool,code:string}
	 */
	public static function handle_capture( array $post ) {
		global $wpdb;
		$res = fn( $ok, $code ) => array( 'ok' => $ok, 'code' => $code );
		if ( ! Eru_GRA_Settings::is_on( 'cart' ) ) {
			return $res( false, 'off' );
		}
		$rl = self::rate_key();
		$n  = (int) get_transient( $rl );
		if ( $n >= self::RATE_MAX ) {
			return $res( false, 'rate' );
		}
		set_transient( $rl, $n + 1, 10 * MINUTE_IN_SECONDS );

		$email = Eru_GRA_Consent::norm( (string) ( $post['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return $res( false, 'email' );
		}
		if ( empty( $post['consent'] ) || '1' !== (string) $post['consent'] ) {
			// Unticking withdraws: only this session's own open row is removed.
			$own = ( function_exists( 'WC' ) && WC()->session ) ? (int) WC()->session->get( 'eru_gra_cart_id' ) : 0;
			if ( $own ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_GRA_DB::carts() . " WHERE id = %d AND email = %s AND status = 'open' AND consent_source = 'checkout'", $own, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			return $res( false, 'no-consent' );
		}
		if ( Eru_GRA_Consent::is_suppressed( $email ) ) {
			return $res( false, 'suppressed' );
		}
		if ( ! self::current_lines() ) {
			return $res( false, 'empty' );
		}
		$gk  = 'eru_gra_cgl_' . gmdate( 'YmdH' );
		$cap = max( 1, (int) apply_filters( 'eru_gra_capture_hourly_cap', 300 ) );
		$g   = (int) get_transient( $gk );
		if ( $g >= $cap ) {
			return $res( false, 'busy' );
		}
		set_transient( $gk, $g + 1, HOUR_IN_SECONDS );
		$id = self::store( $email, get_current_user_id(), 'checkout' );
		if ( $id && function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'eru_gra_cart_id', $id );
		}
		return $id ? $res( true, 'stored' ) : $res( false, 'skipped' );
	}

	/** wc-ajax endpoint. */
	public static function ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		// phpcs:disable WordPress.Security.NonceVerification -- verified above.
		$r = self::handle_capture(
			array(
				'email'   => isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
				'consent' => isset( $_POST['consent'] ) ? sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : '0',
			)
		);
		// phpcs:enable
		wp_send_json( $r, 'rate' === $r['code'] ? 429 : 200 );
	}

	/** Logged-in users: capture only with the account opt-in or a consent filter. Cheap when nothing applies. */
	public static function capture_user() {
		static $busy = false;
		if ( $busy || ! is_user_logged_in() || ! Eru_GRA_Settings::is_on( 'cart' ) ) {
			return;
		}
		$busy = true;
		$user = wp_get_current_user();
		$src  = Eru_GRA_Consent::source( $user->user_email, $user->ID, 'capture' );
		if ( '' !== $src ) {
			self::store( Eru_GRA_Consent::norm( $user->user_email ), $user->ID, 'checkout' === $src ? 'checkout' : $src );
		}
		$busy = false;
	}

	/** @param WC_Order $order Order. @param array $data Checkout data. Records the checkbox on the order. */
	public static function save_order_consent( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the checkout nonce before creating the order.
		if ( empty( $_POST['eru_gra_marketing'] ) || ! Eru_GRA_Settings::marketing_on() ) {
			return;
		}
		$order->update_meta_data( '_eru_gra_marketing_consent', 'yes' );
		$order->update_meta_data( '_eru_gra_marketing_consent_at', gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( '_eru_gra_marketing_consent_text', Eru_GRA_Settings::get( 'general', 'consent_text' ) );
		$email = Eru_GRA_Consent::norm( (string) ( $data['billing_email'] ?? $order->get_billing_email() ) );
		// The tick is fresh consent, but anyone can type an address into a checkout. An earlier opt-out is lifted at once
		// only for the signed-in owner of that address; anyone else only triggers a confirmation e-mail to the address,
		// and the opt-out is lifted when its owner confirms (paying the order proves nothing about the address).
		if ( is_user_logged_in() && Eru_GRA_Consent::norm( wp_get_current_user()->user_email ) === $email ) {
			Eru_GRA_Consent::remove_suppression( $email );
		} elseif ( Eru_GRA_Consent::is_suppressed( $email ) ) {
			Eru_GRA_Consent::request_reconsent( $email );
		}
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), Eru_GRA_Consent::USER_META, 'yes' );
		}
	}

	/** @param mixed $order Order or ID. Marks open/sent carts of the same e-mail as recovered. */
	public static function on_order( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft', 'trash', 'failed', 'cancelled' ), true ) ) {
			return;
		}
		$email = Eru_GRA_Consent::norm( (string) $order->get_billing_email() );
		if ( '' === $email ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Eru_GRA_DB::carts() . " SET status = 'recovered', token = '' WHERE email = %s AND status IN ('open','sent')", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param int $order_id Order ID. */
	public static function on_order_id( $order_id ) {
		self::on_order( wc_get_order( (int) $order_id ) );
	}

	/** @param int $order_id ID. @param string $from From. @param string $to To. @param WC_Order|null $order Order. */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( in_array( $to, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			self::on_order( $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id ) );
		}
	}

	/** @param string $token Raw token. @return string Hash stored in the table. */
	public static function token_hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Add the stored lines to the current cart (never empties it) and burn the token.
	 *
	 * @param string $token Raw token from the mail link.
	 * @return array{ok:bool,added:int}
	 */
	public static function restore( $token ) {
		global $wpdb;
		$t   = Eru_GRA_DB::carts();
		$bad = array( 'ok' => false, 'added' => 0 );
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $bad;
		}
		$hash = self::token_hash( $token );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s AND status = 'sent' LIMIT 1", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return $bad;
		}
		$ttl = (int) apply_filters( 'eru_gra_restore_ttl', 30 * DAY_IN_SECONDS );
		if ( $row['sent_at'] && strtotime( $row['sent_at'] . ' UTC' ) < time() - $ttl ) {
			return $bad;
		}
		// Single use: only the request that clears the token proceeds.
		$won = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET token = '' WHERE id = %d AND token = %s", (int) $row['id'], $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( 1 !== (int) $won ) {
			return $bad;
		}
		$added = 0;
		foreach ( (array) json_decode( $row['cart'], true ) as $line ) {
			$pid = (int) ( $line['product_id'] ?? 0 );
			$vid = (int) ( $line['variation_id'] ?? 0 );
			$qty = max( 1, (int) ( $line['qty'] ?? 1 ) );
			$p   = wc_get_product( $vid ? $vid : $pid );
			if ( ! $p || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
				continue;
			}
			if ( WC()->cart->add_to_cart( $pid, $qty, $vid ) ) {
				++$added;
			}
		}
		return array( 'ok' => true, 'added' => $added );
	}

	/**
	 * Restore link handling, free of output so it can be tested. GET only renders a button (mail scanners that
	 * pre-open links must not use up the link); a same-site POST carrying the token restores.
	 * The page never shows cart contents and never logs anyone in.
	 *
	 * @param string $method HTTP method. @param string $token Raw token.
	 * @return array{status:int,html:string,redirect?:string}
	 */
	public static function handle_restore( $method, $token ) {
		$title = esc_html__( 'Sepetinizi geri yükleyin', 'growth-automations-for-woocommerce' );
		$page  = fn( $status, $body ) => array( 'status' => $status, 'html' => '<h1>' . $title . '</h1>' . $body );
		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return $page(
				200,
				'<p>' . esc_html__( 'Kaldığınız yerden devam etmek için düğmeye tıklayın; ürünler mevcut sepetinize eklenir.', 'growth-automations-for-woocommerce' ) . '</p>'
				. '<form method="post" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="eru_gra_restore" value="' . esc_attr( $token ) . '">'
				. '<button type="submit" class="button">' . esc_html__( 'Sepetimi geri yükle', 'growth-automations-for-woocommerce' ) . '</button></form>'
			);
		}
		if ( ! Eru_GRA_Consent::not_cross_origin() ) {
			return $page( 403, '<p>' . esc_html__( 'İstek reddedildi.', 'growth-automations-for-woocommerce' ) . '</p>' );
		}
		$r = self::restore( $token );
		if ( ! $r['ok'] ) {
			return $page( 400, '<p>' . esc_html__( 'Bu bağlantı geçersiz veya daha önce kullanılmış.', 'growth-automations-for-woocommerce' ) . '</p>' );
		}
		$out             = $page( 200, '<p>' . esc_html__( 'Ürünler sepetinize eklendi.', 'growth-automations-for-woocommerce' ) . '</p>' );
		$out['redirect'] = $r['added'] > 0 ? wc_get_cart_url() : home_url( '/' );
		return $out;
	}

	/** Front-end entry for the restore link. */
	public static function restore_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- the single-use token is the credential; POST also needs same-site.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$src    = 'POST' === strtoupper( $method ) ? $_POST : $_GET;
		if ( empty( $src['eru_gra_restore'] ) || ! is_string( $src['eru_gra_restore'] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $src['eru_gra_restore'] ) );
		// phpcs:enable
		nocache_headers();
		$r = self::handle_restore( $method, $token );
		if ( ! empty( $r['redirect'] ) ) {
			wp_safe_redirect( $r['redirect'] );
			exit;
		}
		wp_die( Eru_GRA_Consent::kses( $r['html'] ), esc_html__( 'Sepet', 'growth-automations-for-woocommerce' ), array( 'response' => $r['status'], 'back_link' => false ) );
	}
}
