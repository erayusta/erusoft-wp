<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Mağaza kapanış mantığı. */
class Eru_Scs {

	public static function init(): void {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 99 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 99 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_add_to_cart' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_close_page' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_close_rest' ), 10, 3 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
	}

	public static function default_message(): string {
		return __( 'Mağazamız şu anda kapalı. Yeni sipariş alınmıyor; lütfen daha sonra tekrar ziyaret edin.', 'store-closure-switch-for-woocommerce' );
	}

	/** Yeniden açılış tarihinin site saat dilimindeki zaman damgası; tarih yoksa 0. */
	public static function reopen_timestamp(): int {
		$date = (string) get_option( 'eru_scs_reopen', '' );
		if ( '' === $date ) {
			return 0;
		}
		$dt = DateTime::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/** Mağaza şu an kapalı mı? (Yalnızca ayar durumu; yönetici muafiyeti ayrıca bakılır.) */
	public static function is_closed(): bool {
		$closed = 'yes' === get_option( 'eru_scs_enabled', 'no' );
		if ( $closed ) {
			$reopen = self::reopen_timestamp();
			if ( $reopen > 0 && time() >= $reopen ) {
				$closed = false;
			}
		}
		/**
		 * Mağazanın kapalı olup olmadığını değiştirir.
		 *
		 * @param bool $closed Kapalı mı.
		 */
		return (bool) apply_filters( 'eru_scs_is_closed', $closed );
	}

	/** Yönetici ve mağaza yöneticileri kapanıştan etkilenmez. */
	public static function bypass(): bool {
		$bypass = is_user_logged_in() && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
		/**
		 * Kapanıştan muaf kullanıcıları belirler.
		 *
		 * @param bool $bypass Muaf mı.
		 */
		return (bool) apply_filters( 'eru_scs_bypass', $bypass );
	}

	/** Kapanış şu anda bu istek için geçerli mi? */
	public static function applies(): bool {
		return self::is_closed() && ! self::bypass();
	}

	public static function message(): string {
		$message = trim( (string) get_option( 'eru_scs_message', '' ) );
		return '' !== $message ? $message : self::default_message();
	}

	/** Retry-After saniyesi: yeniden açılış tarihi varsa ona kadar, yoksa 24 saat. */
	public static function retry_after(): int {
		$reopen = self::reopen_timestamp();
		$secs   = $reopen > 0 ? $reopen - time() : DAY_IN_SECONDS;
		return (int) apply_filters( 'eru_scs_retry_after', max( 60, $secs ) );
	}

	private static function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		status_header( 503 );
		header( 'Retry-After: ' . self::retry_after() );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
	}

	public static function filter_purchasable( $purchasable ) {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $purchasable; // Zamanlanmış görevler ve WP-CLI etkilenmez.
		}
		return self::applies() ? false : $purchasable;
	}

	public static function block_add_to_cart( $passed ) {
		if ( ! self::applies() ) {
			return $passed;
		}
		wc_add_notice( self::message(), 'error' );
		return false;
	}

	/** Ön yüz istekleri: sayfayı 503 ile değiştirir. */
	public static function maybe_close_page(): void {
		if ( ! self::applies() || is_admin() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$whole_site = 'site' === get_option( 'eru_scs_scope', 'purchase' );
		$checkout   = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) );

		if ( ! $whole_site && ! $checkout ) {
			return;
		}

		self::send_headers();
		wp_die(
			'<h1>' . esc_html__( 'Mağazamız kapalı', 'store-closure-switch-for-woocommerce' ) . '</h1><p>' . nl2br( esc_html( self::message() ) ) . '</p>' . self::reopen_line(),
			esc_html__( 'Mağazamız kapalı', 'store-closure-switch-for-woocommerce' ),
			array( 'response' => 503 )
		);
	}

	private static function reopen_line(): string {
		$reopen = self::reopen_timestamp();
		if ( $reopen <= 0 ) {
			return '';
		}
		/* translators: %s: reopening date. */
		return '<p>' . esc_html( sprintf( __( 'Yeniden açılış: %s', 'store-closure-switch-for-woocommerce' ), wp_date( get_option( 'date_format' ), $reopen ) ) ) . '</p>';
	}

	/** WooCommerce Store API: sepete ekleme/değiştirme ve ödeme yazma uçlarını kapatır. */
	public static function maybe_close_rest( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || 'GET' === $request->get_method() || ! self::applies() ) {
			return $result;
		}
		if ( ! preg_match( '#^/wc/store(/v\d+)?/(checkout|cart/(add-item|items|update-item|remove-item|update-customer|apply-coupon|remove-coupon|select-shipping-rate|extensions)|batch)#', $request->get_route() ) ) {
			return $result;
		}
		self::send_headers();
		return new WP_Error( 'eru_scs_closed', self::message(), array( 'status' => 503 ) );
	}

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! self::is_closed() || ! self::bypass() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'eru-scs-closed',
				'title' => esc_html__( 'Mağaza kapalı', 'store-closure-switch-for-woocommerce' ),
				'href'  => admin_url( 'admin.php?page=wc-settings&tab=eru_scs' ),
				'meta'  => array( 'class' => 'eru-scs-closed' ),
			)
		);
	}
}
