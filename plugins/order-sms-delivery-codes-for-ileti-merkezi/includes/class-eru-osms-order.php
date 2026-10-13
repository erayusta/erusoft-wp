<?php
/**
 * Read-only order helpers: shipping methods, phone, template variables, request context.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Order {

	/** Shipping method ids of the order (e.g. flat_rate, local_pickup). */
	public static function methods( WC_Order $o ): array {
		$out = array();
		foreach ( $o->get_shipping_methods() as $s ) {
			$out[] = (string) $s->get_method_id();
		}
		return array_values( array_unique( $out ) );
	}

	/** Billing phone; if it is not a mobile number, the shipping phone; otherwise null. */
	public static function phone( WC_Order $o ): ?string {
		$p = Eru_OSMS_Text::phone( $o->get_billing_phone() );
		return null !== $p ? $p : Eru_OSMS_Text::phone( (string) $o->get_shipping_phone() );
	}

	/** Should this order get SMS at all? (e.g. return false for marketplace orders) */
	public static function eligible( WC_Order $o ): bool {
		return (bool) apply_filters( 'eru_osms_order_eligible', true, $o );
	}

	/**
	 * Template variables. {teslimat_kodu} is deliberately absent: the worker inserts it when sending.
	 * Tracking data comes from Turkish Shipping Carriers when that plugin is active, or from the
	 * `eru_osms_tracking` filter.
	 */
	public static function variables( WC_Order $o ): array {
		$s        = Eru_OSMS_Settings::get();
		$tracking = function_exists( 'eru_tsc_get_tracking' ) ? eru_tsc_get_tracking( $o ) : array();
		$tracking = (array) apply_filters( 'eru_osms_tracking', $tracking, $o );
		$vars     = array(
			'ad'             => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
			'siparis_no'     => (string) $o->get_order_number(),
			'toplam'         => html_entity_decode( wp_strip_all_tags( wc_price( $o->get_total(), array( 'currency' => $o->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
			'magaza'         => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'kargo_firmasi'  => (string) ( $tracking['carrier_name'] ?? '' ),
			'kargo_takip'    => (string) ( $tracking['number'] ?? '' ),
			'takip_linki'    => (string) ( $tracking['url'] ?? '' ),
			'destek_tel'     => (string) $s['support_phone'],
			'destek_eposta'  => (string) $s['support_email'],
		);
		/**
		 * Placeholders filled from customer input: cleaned (control characters, links) and truncated.
		 *
		 * @param string[] $keys Variable names.
		 */
		foreach ( (array) apply_filters( 'eru_osms_customer_variables', array( 'ad' ) ) as $k ) {
			if ( isset( $vars[ $k ] ) ) {
				$vars[ $k ] = self::clean_customer_text( (string) $vars[ $k ] );
			}
		}
		return (array) apply_filters( 'eru_osms_variables', $vars, $o );
	}

	/**
	 * Customer-entered text going into an SMS: no control characters, no links or domain names,
	 * at most 40 characters (filter `eru_osms_customer_variable_length`).
	 */
	public static function clean_customer_text( string $text ): string {
		$text = (string) preg_replace( '/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text );
		$text = (string) preg_replace( '~(?:[a-z][a-z0-9+.-]*://|www\.)\S*~iu', ' ', $text );
		$text = (string) preg_replace( '~\b[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.(?:com|net|org|info|biz|io|co|me|tv|xyz|app|site|online|link|ly|gl|to|cc|tk|ru|top|club|shop|store|live|click|tr)\b\S*~iu', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$max  = max( 1, (int) apply_filters( 'eru_osms_customer_variable_length', 40 ) );
		return trim( mb_substr( $text, 0, $max ) );
	}

	/** Placeholder names offered in the admin (for help text). */
	public static function variable_names(): array {
		return array( 'ad', 'siparis_no', 'toplam', 'magaza', 'kargo_firmasi', 'kargo_takip', 'takip_linki', 'destek_tel', 'destek_eposta', 'teslimat_kodu' );
	}

	/** Context of the current status change (input of Eru_OSMS_Decision::codeless_source). */
	public static function context(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- context detection only.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$system = wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( wp_doing_ajax() && 'as_async_request_queue_runner' === $action );
		$header = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		$param  = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		// phpcs:enable
		return array(
			'rest'        => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'cookie_rest' => Eru_OSMS_Decision::is_cookie_rest( $header, $param ),
			'system'      => $system,
			'user_id'     => get_current_user_id(),
		);
	}

	public static function user_name( int $uid ): string {
		$u = $uid ? get_userdata( $uid ) : null;
		return $u ? (string) $u->display_name : __( 'sistem', 'order-sms-delivery-codes-for-ileti-merkezi' );
	}
}
