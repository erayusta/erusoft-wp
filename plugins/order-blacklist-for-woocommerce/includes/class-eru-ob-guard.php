<?php
/** Matching + enforcement (classic checkout validation and a backstop for every other order path). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OB_Guard {

	const OPTION     = 'eru_ob_settings';
	const LOG_OPTION = 'eru_ob_log';
	const LOG_MAX    = 100;

	public static function init(): void {
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 10, 2 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'backstop' ), 999, 2 );
		add_filter( 'woocommerce_email_enabled_cancelled_order', array( __CLASS__, 'mute_cancelled_mail' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'block_store_api_order' ), 5, 1 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function defaults(): array {
		return array(
			'emails'    => '',
			'phones'    => '',
			'ips'       => '',
			'addresses' => '',
			'message'   => '',
			'auto_ip'   => 0,
			'backstop'  => 1,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function message(): string {
		$m = trim( (string) self::settings()['message'] );
		return '' !== $m ? $m : __( 'Siparişiniz şu anda işleme alınamıyor. Lütfen mağaza ile iletişime geçin.', 'order-blacklist-for-woocommerce' );
	}

	public static function lines( $raw ): array {
		$rows = preg_split( '/\r\n|\r|\n/', (string) $raw );
		return array_values( array_filter( array_map( 'trim', $rows ), static fn( $r ) => '' !== $r ) );
	}

	public static function normalize_phone( $phone ): string {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( $d ) > 10 ? substr( $d, -10 ) : $d;
	}

	/** Client IP. Default REMOTE_ADDR; behind a proxy/CDN hook `eru_ob_client_ip`. */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filter the IP address used for IP blocklist matching.
		 * Behind a reverse proxy or CDN return the trusted forwarded address.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'eru_ob_client_ip', $ip );
	}

	/**
	 * Match a customer against the lists.
	 *
	 * @return array{type:string,rule:string}|null
	 */
	public static function match( string $email, string $phone, string $address_blob, string $ip = '' ): ?array {
		$s     = self::settings();
		$email = strtolower( trim( $email ) );
		if ( '' !== $email ) {
			$domain = substr( strrchr( $email, '@' ) ?: '', 0 );
			foreach ( self::lines( $s['emails'] ) as $rule ) {
				$r = strtolower( $rule );
				if ( $r === $email || ( '@' === $r[0] && $r === $domain ) ) {
					return array( 'type' => 'email', 'rule' => $rule );
				}
			}
		}
		$p = self::normalize_phone( $phone );
		if ( '' !== $p ) {
			foreach ( self::lines( $s['phones'] ) as $rule ) {
				if ( self::normalize_phone( $rule ) === $p ) {
					return array( 'type' => 'phone', 'rule' => $rule );
				}
			}
		}
		$blob = mb_strtolower( $address_blob );
		if ( '' !== trim( $blob ) ) {
			foreach ( self::lines( $s['addresses'] ) as $rule ) {
				if ( false !== mb_strpos( $blob, mb_strtolower( $rule ) ) ) {
					return array( 'type' => 'address', 'rule' => $rule );
				}
			}
		}
		if ( '' !== $ip ) {
			foreach ( self::lines( $s['ips'] ) as $rule ) {
				if ( $rule === $ip ) {
					return array( 'type' => 'ip', 'rule' => $rule );
				}
			}
		}
		return null;
	}

	private static function has_rules(): bool {
		$s = self::settings();
		return '' !== trim( $s['emails'] . $s['phones'] . $s['ips'] . $s['addresses'] );
	}

	public static function validate_checkout( $data, $errors ): void {
		if ( ! self::has_rules() ) {
			return;
		}
		$blob = implode(
			' ',
			array_map(
				'strval',
				array(
					$data['billing_address_1'] ?? '',
					$data['billing_address_2'] ?? '',
					$data['billing_city'] ?? '',
					$data['shipping_address_1'] ?? '',
					$data['shipping_address_2'] ?? '',
					$data['shipping_city'] ?? '',
				)
			)
		);
		$hit  = self::match( (string) ( $data['billing_email'] ?? '' ), (string) ( $data['billing_phone'] ?? '' ), $blob, self::client_ip() );
		if ( ! $hit ) {
			return;
		}
		self::on_hit( $hit, 'checkout', 0 );
		if ( $errors instanceof WP_Error ) {
			$errors->add( 'eru_ob_blocked', self::message() );
		} else {
			wc_add_notice( self::message(), 'error' );
		}
	}

	/**
	 * Block (Store API) checkout: fires before payment. On a match the order is cancelled and a
	 * RouteException aborts the request, so the checkout fails with the message and cannot be
	 * moved on to processing by the "no payment needed" path.
	 */
	public static function block_store_api_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! self::has_rules() ) {
			return;
		}
		$blob = implode( ' ', array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city() ) );
		$hit  = self::match( (string) $order->get_billing_email(), (string) $order->get_billing_phone(), $blob, self::client_ip() );
		if ( ! $hit ) {
			return;
		}
		$order->update_meta_data( '_eru_ob_blocked', gmdate( 'c' ) );
		$order->update_status(
			'cancelled',
			/* translators: 1: list type, 2: matching rule */
			sprintf( __( 'Kara liste eşleşti (%1$s: %2$s) — sipariş otomatik iptal edildi.', 'order-blacklist-for-woocommerce' ), $hit['type'], $hit['rule'] )
		);
		self::on_hit( $hit, 'checkout', $order->get_id() );
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'eru_ob_blocked', esc_html( self::message() ), 400 );
		}
	}

	/** Orders created outside classic checkout validation (admin, block checkout, code): cancel on match. */
	public static function backstop( $order_id, $order = null ): void {
		if ( ! self::settings()['backstop'] || ! self::has_rules() ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft', 'auto-draft' ), true ) || $order->get_meta( '_eru_ob_blocked' ) ) {
			return;
		}
		// Orders made by shop staff in wp-admin are trusted.
		if ( is_admin() && current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$blob = implode( ' ', array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city() ) );
		$hit  = self::match( (string) $order->get_billing_email(), (string) $order->get_billing_phone(), $blob, self::client_ip() );
		if ( ! $hit ) {
			return;
		}
		$order->update_meta_data( '_eru_ob_blocked', gmdate( 'c' ) );
		$order->update_status(
			'cancelled',
			/* translators: 1: list type, 2: matching rule */
			sprintf( __( 'Kara liste eşleşti (%1$s: %2$s) — sipariş otomatik iptal edildi.', 'order-blacklist-for-woocommerce' ), $hit['type'], $hit['rule'] )
		);
		self::on_hit( $hit, 'backstop', $order->get_id() );
	}

	/** A blocked attempt is logged by this plugin; no "cancelled order" admin e-mail for it. */
	public static function mute_cancelled_mail( $enabled, $order = null ) {
		if ( $order instanceof WC_Order && $order->get_meta( '_eru_ob_blocked' ) ) {
			return false;
		}
		return $enabled;
	}

	private static function on_hit( array $hit, string $source, int $order_id ): void {
		$s = self::settings();
		// Opt-in: remember the visitor IP after an e-mail/phone/address hit (may affect shared IPs).
		if ( $s['auto_ip'] && 'ip' !== $hit['type'] ) {
			$ip = self::client_ip();
			if ( '' !== $ip && ! in_array( $ip, self::lines( $s['ips'] ), true ) ) {
				$s['ips'] = trim( $s['ips'] . "\n" . $ip );
				update_option( self::OPTION, $s, false );
			}
		}
		self::log( $hit, $source, $order_id );
	}

	/** Log keeps only the matched rule (from your own list), never the visitor's data. */
	public static function log( array $hit, string $source, int $order_id = 0 ): void {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				't'      => time(),
				'type'   => $hit['type'],
				'rule'   => $hit['rule'],
				'source' => $source,
				'order'  => $order_id,
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/* ---- WordPress privacy tools: the e-mail key ---- */

	public static function register_exporter( $exporters ) {
		$exporters['eru-ob'] = array(
			'exporter_friendly_name' => __( 'Order Blacklist', 'order-blacklist-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export_personal' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['eru-ob'] = array(
			'eraser_friendly_name' => __( 'Order Blacklist', 'order-blacklist-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase_personal' ),
		);
		return $erasers;
	}

	/** Phone numbers (last 10 digits) of orders placed with this e-mail; WP privacy tools only pass the e-mail. */
	private static function phones_for_email( string $email ): array {
		$phones = array();
		foreach ( wc_get_orders( array( 'billing_email' => $email, 'limit' => 50, 'return' => 'objects' ) ) as $o ) {
			$p = self::normalize_phone( $o->get_billing_phone() );
			if ( '' !== $p ) {
				$phones[ $p ] = true;
			}
		}
		return array_keys( $phones );
	}

	public static function export_personal( $email ): array {
		$email = strtolower( trim( (string) $email ) );
		$data  = array();
		foreach ( self::lines( self::settings()['emails'] ) as $rule ) {
			if ( strtolower( $rule ) === $email ) {
				$data[] = array(
					'group_id'    => 'eru-ob',
					'group_label' => __( 'Kara liste', 'order-blacklist-for-woocommerce' ),
					'item_id'     => 'eru-ob-email',
					'data'        => array( array( 'name' => __( 'E-posta kara listede', 'order-blacklist-for-woocommerce' ), 'value' => $rule ) ),
				);
			}
		}
		foreach ( self::phones_for_email( $email ) as $p ) {
			foreach ( self::lines( self::settings()['phones'] ) as $rule ) {
				if ( self::normalize_phone( $rule ) === $p ) {
					$data[] = array(
						'group_id'    => 'eru-ob',
						'group_label' => __( 'Kara liste', 'order-blacklist-for-woocommerce' ),
						'item_id'     => 'eru-ob-phone-' . $p,
						'data'        => array( array( 'name' => __( 'Telefon kara listede', 'order-blacklist-for-woocommerce' ), 'value' => $rule ) ),
					);
				}
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase_personal( $email ): array {
		$email   = strtolower( trim( (string) $email ) );
		$phones  = self::phones_for_email( $email );
		$removed = 0;
		$s       = self::settings();
		$keep    = array();
		foreach ( self::lines( $s['emails'] ) as $rule ) {
			if ( strtolower( $rule ) === $email ) {
				$removed++;
			} else {
				$keep[] = $rule;
			}
		}
		$keep_p = array();
		foreach ( self::lines( $s['phones'] ) as $rule ) {
			if ( in_array( self::normalize_phone( $rule ), $phones, true ) ) {
				$removed++;
			} else {
				$keep_p[] = $rule;
			}
		}
		if ( $removed ) {
			$s['emails'] = implode( "\n", $keep );
			$s['phones'] = implode( "\n", $keep_p );
			update_option( self::OPTION, $s, false );
		}
		$log = get_option( self::LOG_OPTION, array() );
		if ( is_array( $log ) ) {
			$new = array_values(
				array_filter(
					$log,
					static function ( $row ) use ( $email, $phones ) {
						$rule = (string) ( $row['rule'] ?? '' );
						if ( 'phone' === ( $row['type'] ?? '' ) ) {
							return ! in_array( self::normalize_phone( $rule ), $phones, true );
						}
						return strtolower( $rule ) !== $email;
					}
				)
			);
			if ( count( $new ) !== count( $log ) ) {
				$removed += count( $log ) - count( $new );
				update_option( self::LOG_OPTION, $new, false );
			}
		}
		return array( 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
