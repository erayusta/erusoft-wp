<?php
/** Consent notice + capture (checkout page only) and recovery marking. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_ACCL_Capture {

	const NONCE = 'eru_accl_capture';

	public static function init(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render_consent' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_eru_accl_capture', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_eru_accl_capture', array( __CLASS__, 'ajax' ) );

		// Recovery: any path that creates or updates an order.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_id' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order_id' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );

		add_action( 'eru_accl_purge', array( __CLASS__, 'cron_purge' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( 'Eru_ACCL_DB', 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( 'Eru_ACCL_DB', 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_text' ) );
	}

	public static function ensure_schedule(): void {
		// Kept scheduled even when capture is disabled, so rows already stored are still purged.
		if ( ! wp_next_scheduled( 'eru_accl_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'eru_accl_purge' );
		}
	}

	public static function cron_purge(): void {
		Eru_ACCL_DB::purge( max( 1, (int) Eru_ACCL_DB::settings()['retention_days'] ) );
	}

	public static function privacy_policy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Abandoned Checkout Call List',
				'<p>' . esc_html__( 'Ödeme sayfasında onayınızla e-posta, telefon, ad ve sepet içeriğiniz, siparişi tamamlamamanız halinde sizinle iletişime geçilebilmesi için geçici olarak saklanır ve belirlenen süre sonunda silinir.', 'abandoned-checkout-call-list-for-woocommerce' ) . '</p>'
			);
		}
	}

	/** Consent text: setting, or a default that links the store's privacy page. Empty = not ready. */
	public static function consent_html(): string {
		$text = trim( (string) Eru_ACCL_DB::settings()['consent_text'] );
		if ( '' !== $text ) {
			return wp_kses_post( $text );
		}
		$url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( '' === $url ) {
			return '';
		}
		return sprintf(
			/* translators: %s: privacy policy link */
			esc_html__( 'Siparişi tamamlamazsanız size yardımcı olabilmemiz için e-posta, telefon ve sepet bilgilerinizi geçici olarak kaydetmemize izin veriyorum. Ayrıntılar: %s', 'abandoned-checkout-call-list-for-woocommerce' ),
			'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Gizlilik Politikası', 'abandoned-checkout-call-list-for-woocommerce' ) . '</a>'
		);
	}

	public static function is_ready(): bool {
		return (bool) Eru_ACCL_DB::settings()['enabled'] && '' !== self::consent_html();
	}

	private static function on_checkout_form_page(): bool {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && ! is_wc_endpoint_url( 'order-pay' );
	}

	public static function render_consent(): void {
		if ( ! self::is_ready() || ! self::on_checkout_form_page() ) {
			return;
		}
		$s = Eru_ACCL_DB::settings();
		echo '<p class="form-row eru-accl-consent" id="eru-accl-consent" style="clear:both;font-size:.9em">';
		if ( $s['require_checkbox'] ) {
			echo '<label><input type="checkbox" id="eru_accl_consent" value="1"> ' . self::consent_html() . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts / kses.
		} else {
			echo self::consent_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</p>';
	}

	public static function enqueue(): void {
		if ( ! self::is_ready() || ! self::on_checkout_form_page() ) {
			return;
		}
		wp_enqueue_script( 'eru-accl-capture', ERU_ACCL_URL . 'assets/capture.js', array(), ERU_ACCL_VERSION, true );
		wp_localize_script(
			'eru-accl-capture',
			'ERU_ACCL',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE ),
				'requireCheckbox' => (bool) Eru_ACCL_DB::settings()['require_checkbox'],
			)
		);
	}

	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * IP used only to rate-limit capture requests (never stored).
		 * Behind a proxy/CDN return the trusted forwarded address.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'eru_accl_client_ip', $ip );
	}

	public static function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::is_ready() ) {
			wp_send_json_error( 'disabled', 403 );
		}
		if ( empty( $_POST['consent'] ) || '1' !== (string) wp_unslash( $_POST['consent'] ) ) {
			wp_send_json_error( 'no-consent', 400 );
		}
		$rl = 'eru_accl_rl_' . md5( self::client_ip() );
		if ( get_transient( $rl ) ) {
			wp_send_json_error( 'rate-limited', 429 );
		}
		set_transient( $rl, 1, 2 );

		$cart = array();
		$total = 0.0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $line ) {
				$product = $line['data'] ?? null;
				$cart[]  = array(
					'product_id'   => (int) $line['product_id'],
					'variation_id' => (int) $line['variation_id'],
					'name'         => $product ? wp_strip_all_tags( $product->get_name() ) : '',
					'qty'          => (int) $line['quantity'],
				);
			}
			$total = (float) WC()->cart->get_cart_contents_total();
		}
		$id = self::save(
			isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
			isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
			$cart,
			$total,
			true
		);
		wp_send_json_success( array( 'saved' => $id > 0 ) );
	}

	/**
	 * Capture handler. Stores nothing unless the feature is enabled and consent was given.
	 *
	 * @return int Lead id or 0.
	 */
	public static function save( string $email, string $phone, string $first_name, array $cart, float $total, bool $consent ): int {
		if ( ! $consent || ! Eru_ACCL_DB::settings()['enabled'] ) {
			return 0;
		}
		return Eru_ACCL_DB::upsert( $email, $phone, $first_name, $cart, $total );
	}

	public static function on_order( $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		// Pending (awaiting payment) counts; failed and cancelled attempts are not a recovery.
		if ( ! $order || in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft', 'trash', 'failed', 'cancelled' ), true ) ) {
			return;
		}
		$email = (string) $order->get_billing_email();
		$phone = (string) $order->get_billing_phone();
		if ( '' === $email && '' === $phone ) {
			return;
		}
		Eru_ACCL_DB::mark_ordered( $email, $phone, $order->get_id() );
	}

	/** Recovery on a status change to processing, on-hold or completed (e.g. orders created in the admin). */
	public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
		if ( in_array( $to, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			self::on_order( $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id ) );
		}
	}

	public static function on_order_id( $order_id ): void {
		self::on_order( wc_get_order( (int) $order_id ) );
	}
}
