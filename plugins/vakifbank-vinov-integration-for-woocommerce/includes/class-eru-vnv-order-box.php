<?php
/**
 * Order screen box: local instruction details (masked) plus "Durumu sorgula" and "Talimatı iptal et".
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendering the box never calls the bank: it shows what the order and the instruction table hold. The two
 * buttons post over admin-ajax with a per-order nonce and need `edit_shop_orders`; cancelling also needs an
 * explicit confirmation (a second button the first one reveals, checked on the server). Cancel goes by the
 * instruction id only.
 */
class Eru_VNV_Order_Box {

	const CAP = 'edit_shop_orders';

	/** Hooks. */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 10, 2 );
		add_action( 'wp_ajax_eru_vnv_order', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** @param mixed $obj Post or order. @return WC_Order|null */
	private static function order_of( $obj ) {
		if ( $obj instanceof WC_Order ) {
			return $obj;
		}
		$o = $obj instanceof WP_Post ? wc_get_order( $obj->ID ) : null;
		return $o instanceof WC_Order ? $o : null;
	}

	/** @param WC_Order $order Order. @return bool A Vinov order. */
	private static function is_vinov( $order ) {
		return Eru_VNV_Settings::GATEWAY_ID === $order->get_payment_method() || '' !== (string) $order->get_meta( '_eru_vnv_attempt' ) || '' !== (string) $order->get_meta( '_eru_vnv_instruction_id' );
	}

	/**
	 * @param string $screen Screen id.
	 * @param mixed  $obj    Post or order.
	 */
	public static function add( $screen, $obj = null ) {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		if ( ! in_array( $screen, $screens, true ) ) {
			return;
		}
		$order = self::order_of( $obj );
		if ( ! $order || ! self::is_vinov( $order ) ) {
			return;
		}
		add_meta_box( 'eru-vnv-order', __( 'VakıfBank Vinov', 'vakifbank-vinov-integration-for-woocommerce' ), array( __CLASS__, 'render' ), $screen, 'side', 'high' );
	}

	/** @param string $hook Hook. */
	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ids    = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}
		if ( ! $screen || ! in_array( $screen->id, $ids, true ) ) {
			return;
		}
		wp_enqueue_style( 'eru-vnv-admin', ERU_VNV_URL . 'assets/admin.css', array(), ERU_VNV_VERSION );
		wp_enqueue_script( 'eru-vnv-admin', ERU_VNV_URL . 'assets/admin.js', array(), ERU_VNV_VERSION, true );
		wp_localize_script(
			'eru-vnv-admin',
			'eruVnvAdmin',
			array(
				'ajax' => admin_url( 'admin-ajax.php' ),
				'i18n' => array(
					'working' => __( 'Bankaya soruluyor…', 'vakifbank-vinov-integration-for-woocommerce' ),
					'error'   => __( 'Bir hata oluştu. Lütfen tekrar deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ),
					'amount'  => __( 'Tutar', 'vakifbank-vinov-integration-for-woocommerce' ),
					'due'     => __( 'Vade', 'vakifbank-vinov-integration-for-woocommerce' ),
					'state'   => __( 'Durum', 'vakifbank-vinov-integration-for-woocommerce' ),
					'reload'  => __( 'Sayfa yenileniyor…', 'vakifbank-vinov-integration-for-woocommerce' ),
				),
			)
		);
	}

	/** @param string $phone Normalised phone. @return string "5** *** ** 12". */
	public static function mask_phone( $phone ) {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( $d ) >= 4 ? '5** *** ** ' . substr( $d, -2 ) : '';
	}

	/** @param string $gmt MySQL GMT date. @return string Local date/time. */
	private static function when( $gmt ) {
		return $gmt ? wp_date( 'd.m.Y H:i', (int) strtotime( $gmt . ' UTC' ) ) : '—';
	}

	/**
	 * Box content (local data only).
	 *
	 * @param mixed $obj Post or order.
	 */
	public static function render( $obj ) {
		$order = self::order_of( $obj );
		if ( ! $order ) {
			return;
		}
		$id    = $order->get_id();
		$iid   = (string) $order->get_meta( '_eru_vnv_instruction_id' );
		$row   = Eru_VNV_Instructions::by_order( $id );
		$state = (string) $order->get_meta( '_eru_vnv_attempt_state' );
		$env   = 'live' === $order->get_meta( '_eru_vnv_env' ) ? __( 'Canlı', 'vakifbank-vinov-integration-for-woocommerce' ) : __( 'Test', 'vakifbank-vinov-integration-for-woocommerce' );
		$lines = array(
			__( 'Talimat no', 'vakifbank-vinov-integration-for-woocommerce' ) => '' !== $iid ? $iid : '—',
			__( 'Ortam', 'vakifbank-vinov-integration-for-woocommerce' )      => $env,
		);
		if ( '' !== (string) $order->get_meta( '_eru_vnv_amount' ) ) {
			$lines[ __( 'Talimat tutarı', 'vakifbank-vinov-integration-for-woocommerce' ) ] = wp_strip_all_tags( wc_price( (float) $order->get_meta( '_eru_vnv_amount' ), array( 'currency' => $order->get_currency() ) ) );
		}
		$n = (int) $order->get_meta( '_eru_vnv_installments' );
		if ( $n > 0 ) {
			$lines[ __( 'Taksit', 'vakifbank-vinov-integration-for-woocommerce' ) ] = 1 === $n ? __( 'Tek çekim', 'vakifbank-vinov-integration-for-woocommerce' ) : (string) $n;
		}
		if ( $row ) {
			$lines[ __( 'Durum', 'vakifbank-vinov-integration-for-woocommerce' ) ]       = Eru_VNV_Instructions::label( (int) $row->status ) . ( null !== $row->closed_at && 'gave_up' === $row->close_reason ? ' — ' . __( 'takip bırakıldı', 'vakifbank-vinov-integration-for-woocommerce' ) : '' );
			$lines[ __( 'Son kontrol', 'vakifbank-vinov-integration-for-woocommerce' ) ] = self::when( $row->last_checked_at );
			if ( ! empty( $row->review ) ) {
				$lines[ __( 'İncelenmeli', 'vakifbank-vinov-integration-for-woocommerce' ) ] = Eru_VNV_Instructions::review_label( $row->review );
			}
		} elseif ( '' === $iid && '' !== $state ) {
			$lines[ __( 'Durum', 'vakifbank-vinov-integration-for-woocommerce' ) ] = 'failed' === $state ? __( 'Banka reddetti', 'vakifbank-vinov-integration-for-woocommerce' ) : Eru_VNV_Instructions::label( 0 );
		}
		$mask = (string) $order->get_meta( '_eru_vnv_tckn_mask' );
		if ( '' !== $mask ) {
			$lines[ __( 'TC kimlik (maskeli)', 'vakifbank-vinov-integration-for-woocommerce' ) ] = $mask;
		}
		$phone = self::mask_phone( (string) $order->get_meta( '_eru_vnv_phone' ) );
		if ( '' !== $phone ) {
			$lines[ __( 'Telefon (maskeli)', 'vakifbank-vinov-integration-for-woocommerce' ) ] = $phone;
		}
		$can_cancel = '' !== $iid && '' === (string) $order->get_meta( '_eru_vnv_cancelled_at' ) && '' === (string) $order->get_meta( '_eru_vnv_paid_at' ) && ! $order->is_paid() && ( ! $row || 1 === (int) $row->status );
		?>
		<div class="eru-vnv-box" id="eru-vnv-box" data-order="<?php echo esc_attr( (string) $id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'eru_vnv_order_' . $id ) ); ?>">
			<dl class="eru-vnv-dl">
				<?php foreach ( $lines as $k => $v ) : ?>
					<dt><?php echo esc_html( $k ); ?></dt><dd><?php echo esc_html( $v ); ?></dd>
				<?php endforeach; ?>
			</dl>
			<?php if ( current_user_can( self::CAP ) && ( $row || '' !== $iid ) ) : ?>
				<p class="eru-vnv-actions">
					<button type="button" class="button" data-eru-vnv-do="query"><?php esc_html_e( 'Durumu sorgula', 'vakifbank-vinov-integration-for-woocommerce' ); ?></button>
					<?php if ( $can_cancel ) : ?>
						<button type="button" class="button eru-vnv-danger" data-eru-vnv-ask="1"><?php esc_html_e( 'Talimatı iptal et', 'vakifbank-vinov-integration-for-woocommerce' ); ?></button>
					<?php endif; ?>
				</p>
				<?php if ( $can_cancel ) : ?>
					<div class="eru-vnv-confirm" hidden>
						<p><?php echo esc_html( sprintf( /* translators: %s: instruction id */ __( '%s numaralı talimat bankada iptal edilecek ve sipariş iptal durumuna alınacak. Bu işlem geri alınamaz.', 'vakifbank-vinov-integration-for-woocommerce' ), $iid ) ); ?></p>
						<p>
							<button type="button" class="button button-primary eru-vnv-danger" data-eru-vnv-do="cancel" data-confirm="1"><?php esc_html_e( 'Evet, talimatı iptal et', 'vakifbank-vinov-integration-for-woocommerce' ); ?></button>
							<button type="button" class="button-link" data-eru-vnv-ask="0"><?php esc_html_e( 'Vazgeç', 'vakifbank-vinov-integration-for-woocommerce' ); ?></button>
						</p>
					</div>
				<?php endif; ?>
				<div class="eru-vnv-result" aria-live="polite"></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handles one box request (capability, nonce and confirmation are checked here; tests call it directly).
	 *
	 * @param array $post {order_id, do: query|cancel, nonce, confirm?}.
	 * @return array{ok:bool, code:string, message:string, data:array}
	 */
	public static function request( array $post ) {
		$id    = absint( $post['order_id'] ?? 0 );
		$do    = sanitize_key( (string) ( $post['do'] ?? '' ) );
		$nonce = is_scalar( $post['nonce'] ?? null ) ? (string) $post['nonce'] : '';
		$no    = fn( $code, $msg ) => array(
			'ok'      => false,
			'code'    => $code,
			'message' => $msg,
			'data'    => array(),
		);
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'eru_vnv_order_' . $id ) ) {
			return $no( 'nonce', __( 'Oturumunuzun süresi doldu. Sayfayı yenileyip tekrar deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( ! current_user_can( self::CAP ) ) {
			return $no( 'cap', __( 'Bu işlem için yetkiniz yok.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order instanceof WC_Order || ! self::is_vinov( $order ) ) {
			return $no( 'order', __( 'Sipariş bulunamadı.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( 'query' === $do ) {
			return Eru_VNV_Sync::query( $order );
		}
		if ( 'cancel' === $do ) {
			if ( '1' !== (string) ( $post['confirm'] ?? '' ) ) {
				return $no( 'confirm', __( 'İptal için onay gerekli.', 'vakifbank-vinov-integration-for-woocommerce' ) );
			}
			$user = wp_get_current_user();
			/* translators: %s: user display name */
			return Eru_VNV_Sync::cancel( $order, sprintf( __( 'yönetici: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $user && $user->exists() ? $user->display_name : '#' . get_current_user_id() ) );
		}
		return $no( 'do', __( 'Geçersiz işlem.', 'vakifbank-vinov-integration-for-woocommerce' ) );
	}

	/** admin-ajax: eru_vnv_order. */
	public static function ajax() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- request() verifies the per-order nonce.
		$post = array_map( fn( $v ) => is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '', wp_unslash( $_POST ) );
		$r    = self::request(
			array(
				'order_id' => $post['order_id'] ?? '',
				'do'       => $post['do'] ?? '',
				'nonce'    => $post['nonce'] ?? '',
				'confirm'  => $post['confirm'] ?? '',
			)
		);
		nocache_headers();
		if ( $r['ok'] ) {
			wp_send_json_success( array( 'message' => $r['message'], 'code' => $r['code'], 'data' => $r['data'] ) );
		}
		wp_send_json_error( array( 'message' => $r['message'], 'code' => $r['code'] ), in_array( $r['code'], array( 'nonce', 'cap' ), true ) ? 403 : 200 );
	}
}
