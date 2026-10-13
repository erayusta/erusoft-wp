<?php
/**
 * Order screen box "Teslimat kodu ve SMS", order list badge and the box's AJAX endpoints (verify / resend).
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Metabox {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_eru_osms_verify', array( __CLASS__, 'ajax_verify' ) );
		add_action( 'wp_ajax_eru_osms_resend', array( __CLASS__, 'ajax_resend' ) );

		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 10, 2 );
	}

	public static function status_labels(): array {
		return array(
			'queued'  => __( 'Kuyrukta', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'sent'    => __( 'Gönderildi', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'error'   => __( 'Hata', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'test'    => __( 'Test (gönderilmedi)', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'skipped' => __( 'Atlandı', 'order-sms-delivery-codes-for-ileti-merkezi' ),
		);
	}

	private static function screen_id(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	public static function add_box(): void {
		add_meta_box( 'eru-osms-delivery', __( 'Teslimat kodu ve SMS', 'order-sms-delivery-codes-for-ileti-merkezi' ), array( __CLASS__, 'render' ), self::screen_id(), 'side', 'high' );
	}

	private static function current_order(): ?WC_Order {
		// phpcs:disable WordPress.Security.NonceVerification
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 );
		// phpcs:enable
		$o = $id ? wc_get_order( $id ) : null;
		return $o instanceof WC_Order ? $o : null;
	}

	public static function assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ids    = array( 'woocommerce_page_wc-orders', 'admin_page_wc-orders', 'edit-shop_order', 'shop_order', 'woocommerce_page_eru-osms', self::screen_id() );
		if ( ! $screen || ! in_array( $screen->id, $ids, true ) ) {
			return;
		}
		wp_enqueue_style( 'eru-osms-admin', ERU_OSMS_URL . 'assets/admin.css', array(), ERU_OSMS_VERSION );
		wp_enqueue_script( 'eru-osms-admin', ERU_OSMS_URL . 'assets/admin.js', array( 'jquery' ), ERU_OSMS_VERSION, true );

		$data = array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'locked'  => array(),
			'orderId' => 0,
			'nonce'   => '',
			'i18n'    => array(
				'enterCode'   => __( 'Müşteriden aldığınız 6 haneli kodu girin.', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'enterName'   => __( 'Teslim alan kişinin adını soyadını yazın.', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'pickRel'     => __( 'Teslim alanın yakınlığını seçin.', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'verifying'   => __( 'Doğrulanıyor…', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'failed'      => __( 'Doğrulanamadı.', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'network'     => __( 'Bağlantı hatası, tekrar deneyin.', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'confirm'     => __( 'Eski kod geçersiz olacak; onaylamak için tekrar tıklayın', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'sending'     => __( 'Gönderiliyor…', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'waitingCode' => __( 'kod bekleniyor', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'chars'       => __( 'karakter', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'parts'       => __( 'SMS', 'order-sms-delivery-codes-for-ileti-merkezi' ),
				'unicode'     => __( 'Türkçe karakter var (70 karakterlik parçalar)', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			),
		);
		$o = self::current_order();
		if ( $o ) {
			$data['orderId'] = $o->get_id();
			$data['nonce']   = wp_create_nonce( 'eru_osms_delivery_' . $o->get_id() );
			if ( Eru_OSMS_Lock::active() && Eru_OSMS_Decision::is_locked( Eru_OSMS_Delivery::get( $o->get_id() ) ) ) {
				foreach ( array_keys( wc_get_order_statuses() ) as $slug ) {
					if ( Eru_OSMS_Delivery::blocks( $o->get_id(), $slug ) ) {
						$data['locked'][] = $slug;
					}
				}
			}
		}
		wp_localize_script( 'eru-osms-admin', 'eruOsms', $data );
	}

	public static function render( $post_or_order ): void {
		$o = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $o ) {
			return;
		}
		$row = Eru_OSMS_Delivery::get( $o->get_id() );
		echo '<div class="eru-osms-box">';
		if ( ! $row ) {
			echo '<p class="eru-osms-muted">' . esc_html__( 'Bu sipariş teslimat kodu kapsamında değil.', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</p>';
		} elseif ( 'pending' === $row['result'] ) {
			self::render_pending( $o, $row );
		} elseif ( 'with_code' === $row['result'] ) {
			self::render_with_code( $row );
		} else {
			self::render_without_code( $row );
		}
		self::render_history( $o );
		echo '</div>';
	}

	private static function render_pending( WC_Order $o, array $row ): void {
		echo '<div class="eru-osms-strip eru-osms-strip--pending">' . esc_html__( 'Kod bekleniyor', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</div>';
		if ( 'error' === $row['sms_status'] || 'skipped' === $row['sms_status'] ) {
			$last  = null;
			foreach ( Eru_OSMS_Log::for_order( $o->get_id() ) as $l ) {
				if ( (int) $l['has_code'] ) {
					$last = $l;
					break;
				}
			}
			$why = $last && '' !== $last['error'] ? $last['error'] : __( 'bilinmiyor', 'order-sms-delivery-codes-for-ileti-merkezi' );
			/* translators: %s: reason */
			echo '<p class="eru-osms-warn">' . esc_html( sprintf( __( 'Kod SMS\'i gönderilemedi: %s', 'order-sms-delivery-codes-for-ileti-merkezi' ), $why ) ) . '</p>';
		}
		if ( ! Eru_OSMS_Lock::active() ) {
			echo '<p class="eru-osms-warn eru-osms-warn--soft">' . esc_html__( 'Ana şalter kapalı: kilit devre dışı; durum değiştirilebilir ama teslim "kodsuz" kaydedilir.', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</p>';
		}
		if ( ! empty( $row['locked'] ) ) {
			echo '<p class="eru-osms-warn">' . esc_html__( 'Kod hatalı denemeler nedeniyle kilitli. Yeni kod gönderin.', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</p>';
		}
		echo '<p class="eru-osms-muted">' . esc_html__( 'Doğrulanınca:', 'order-sms-delivery-codes-for-ileti-merkezi' ) . ' <strong>' . esc_html( wc_get_order_status_name( $row['target_status'] ) ) . '</strong></p>';

		echo '<div class="eru-osms-form">';
		echo '<label>' . esc_html__( 'Teslimat kodu', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '<input type="text" class="eru-osms-code" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="______"></label>';
		echo '<label>' . esc_html__( 'Teslim alan (ad soyad)', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '<input type="text" class="eru-osms-name" autocomplete="off"></label>';
		echo '<label>' . esc_html__( 'Yakınlık', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '<select class="eru-osms-relation"><option value="">' . esc_html__( 'Seçin', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</option>';
		foreach ( Eru_OSMS_Delivery::relations() as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Not', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '<textarea class="eru-osms-note" rows="2"></textarea></label>';
		echo '<button type="button" class="button button-primary eru-osms-verify">' . esc_html__( 'Doğrula ve teslim et', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</button>';
		echo '<p class="eru-osms-msg" role="status" aria-live="polite"></p>';
		echo '<button type="button" class="button-link eru-osms-resend">' . esc_html__( 'Kodu tekrar gönder', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</button>';
		echo '</div>';
	}

	private static function render_with_code( array $row ): void {
		echo '<div class="eru-osms-strip eru-osms-strip--ok">' . esc_html__( 'Kodla teslim edildi', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</div><dl class="eru-osms-record">';
		$rel = Eru_OSMS_Delivery::relations()[ $row['recipient_relation'] ] ?? '';
		self::dl( __( 'Teslim eden', 'order-sms-delivery-codes-for-ileti-merkezi' ), Eru_OSMS_Order::user_name( (int) $row['delivered_by'] ) );
		self::dl( __( 'Teslim alan', 'order-sms-delivery-codes-for-ileti-merkezi' ), trim( $row['recipient_name'] . ( $rel ? ' (' . $rel . ')' : '' ) ) );
		self::dl( __( 'Zaman', 'order-sms-delivery-codes-for-ileti-merkezi' ), self::date( $row['delivered_at'] ) );
		if ( '' !== (string) $row['note'] ) {
			self::dl( __( 'Not', 'order-sms-delivery-codes-for-ileti-merkezi' ), $row['note'] );
		}
		echo '</dl>';
	}

	private static function render_without_code( array $row ): void {
		echo '<div class="eru-osms-strip eru-osms-strip--bad">' . esc_html__( 'Kodsuz teslim', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</div><dl class="eru-osms-record">';
		self::dl( __( 'Kaynak', 'order-sms-delivery-codes-for-ileti-merkezi' ), Eru_OSMS_Delivery::source_label( (string) $row['codeless_source'], (int) $row['codeless_user_id'] ) );
		if ( '' !== (string) $row['previous_status'] ) {
			self::dl( __( 'Önceki durum', 'order-sms-delivery-codes-for-ileti-merkezi' ), wc_get_order_status_name( $row['previous_status'] ) );
		}
		self::dl( __( 'Zaman', 'order-sms-delivery-codes-for-ileti-merkezi' ), self::date( $row['delivered_at'] ) );
		echo '</dl>';
	}

	private static function render_history( WC_Order $o ): void {
		$logs = Eru_OSMS_Log::for_order( $o->get_id() );
		if ( ! $logs ) {
			return;
		}
		$labels = self::status_labels();
		echo '<h4 class="eru-osms-heading">' . esc_html__( 'SMS geçmişi', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</h4><ol class="eru-osms-history">';
		foreach ( $logs as $l ) {
			$rule = Eru_OSMS_Settings::rule( (string) $l['rule_id'] );
			$name = $rule && '' !== $rule['name'] ? $rule['name'] : $l['rule_id'];
			if ( 'resend' === $l['source'] ) {
				/* translators: %s: user name */
				$name .= ' · ' . sprintf( __( 'tekrar (%s)', 'order-sms-delivery-codes-for-ileti-merkezi' ), Eru_OSMS_Order::user_name( (int) $l['user_id'] ) );
			}
			echo '<li><div class="eru-osms-history-top"><span>' . esc_html( self::date( $l['created_at'] ) ) . '</span><span class="eru-osms-badge eru-osms-badge--' . esc_attr( $l['status'] ) . '">' . esc_html( $labels[ $l['status'] ] ?? $l['status'] ) . '</span></div>';
			echo '<div class="eru-osms-history-name">' . esc_html( $name ) . '</div>';
			echo '<div class="eru-osms-history-text">' . esc_html( Eru_OSMS_Text::masked( $l['text'] ) ) . '</div>';
			if ( '' !== (string) $l['error'] && 'sent' !== $l['status'] ) {
				echo '<div class="eru-osms-history-error">' . esc_html( $l['error'] ) . '</div>';
			}
			echo '</li>';
		}
		echo '</ol>';
	}

	private static function dl( string $k, string $v ): void {
		echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>';
	}

	private static function date( $mysql ): string {
		return $mysql ? (string) mysql2date( 'd.m.Y H:i', $mysql ) : '—';
	}

	/* ---- AJAX ---- */

	private static function ajax_order(): WC_Order {
		$id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified right below.
		if ( ! $id || ! check_ajax_referer( 'eru_osms_delivery_' . $id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Oturum doğrulanamadı. Sayfayı yenileyin.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ), 403 );
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bu işlem için yetkiniz yok.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ), 403 );
		}
		$o = wc_get_order( $id );
		if ( ! $o instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Sipariş bulunamadı.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ), 404 );
		}
		return $o;
	}

	public static function ajax_verify(): void {
		$o = self::ajax_order();
		// phpcs:disable WordPress.Security.NonceVerification -- ajax_order() verified the nonce.
		$r = Eru_OSMS_Delivery::verify(
			$o,
			isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			isset( $_POST['relation'] ) ? sanitize_key( wp_unslash( $_POST['relation'] ) ) : '',
			isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''
		);
		// phpcs:enable
		if ( is_wp_error( $r ) ) {
			$data = $r->get_error_data();
			wp_send_json_error(
				array(
					'message' => $r->get_error_message(),
					'left'    => is_array( $data ) && isset( $data['left'] ) ? (int) $data['left'] : null,
				)
			);
		}
		wp_send_json_success( array( 'message' => __( 'Teslimat doğrulandı, sipariş güncellendi.', 'order-sms-delivery-codes-for-ileti-merkezi' ) ) );
	}

	public static function ajax_resend(): void {
		$o = self::ajax_order();
		$r = Eru_OSMS_Delivery::resend( $o );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$msg = Eru_OSMS_Settings::test_allows( Eru_OSMS_Order::phone( $o ) )
			? __( 'Yeni kod kuyruğa alındı, birkaç saniye içinde müşteriye gider.', 'order-sms-delivery-codes-for-ileti-merkezi' )
			: __( 'Test modu: yeni kod kaydedildi ama SMS gönderilmeyecek.', 'order-sms-delivery-codes-for-ileti-merkezi' );
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/* ---- list badge ---- */

	public static function add_column( $cols ): array {
		$new = array();
		foreach ( (array) $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$new['eru_osms_delivery'] = __( 'Teslimat kodu', 'order-sms-delivery-codes-for-ileti-merkezi' );
			}
		}
		if ( ! isset( $new['eru_osms_delivery'] ) ) {
			$new['eru_osms_delivery'] = __( 'Teslimat kodu', 'order-sms-delivery-codes-for-ileti-merkezi' );
		}
		return $new;
	}

	public static function column_hpos( $col, $order ): void {
		if ( 'eru_osms_delivery' === $col && $order instanceof WC_Order ) {
			self::badge( $order->get_id() );
		}
	}

	public static function column_legacy( $col, $post_id ): void {
		if ( 'eru_osms_delivery' === $col ) {
			self::badge( (int) $post_id );
		}
	}

	private static function badge( int $order_id ): void {
		$row = Eru_OSMS_Delivery::get( $order_id );
		if ( ! $row ) {
			return;
		}
		$labels = array(
			'pending'      => __( 'Kod bekliyor', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'with_code'    => __( 'Kodla', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'without_code' => __( 'Kodsuz', 'order-sms-delivery-codes-for-ileti-merkezi' ),
		);
		$r = isset( $labels[ $row['result'] ] ) ? $row['result'] : 'pending';
		echo '<span class="eru-osms-badge eru-osms-badge--' . esc_attr( $r ) . '">' . esc_html( $labels[ $r ] ) . '</span>';
	}
}
