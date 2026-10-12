<?php
/** Sipariş listesi rozeti, sipariş detay kutusu ve yeniden hesaplama (HPOS + klasik). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_Ors_Admin {

	const NONCE_ACTION = 'eru_ors_recalc';

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );

		// Sipariş listesi sütunu: HPOS ve klasik.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_classic' ), 25, 1 );

		add_action( 'wp_ajax_eru_ors_recalc', array( __CLASS__, 'ajax_recalc' ) );
	}

	private static function order_screen_id(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	public static function enqueue( $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( self::order_screen_id(), 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'eru-ors', ERU_ORS_URL . 'assets/admin/order-risk-score.css', array(), ERU_ORS_VERSION );
		wp_enqueue_script( 'eru-ors', ERU_ORS_URL . 'assets/admin/order-risk-score.js', array( 'jquery' ), ERU_ORS_VERSION, true );
		wp_localize_script(
			'eru-ors',
			'ERU_ORS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'calculating' => __( 'Hesaplanıyor…', 'order-risk-score-for-woocommerce' ),
					'updated'     => __( 'Skor güncellendi.', 'order-risk-score-for-woocommerce' ),
					'error'       => __( 'Hata.', 'order-risk-score-for-woocommerce' ),
					'serverError' => __( 'Sunucu hatası.', 'order-risk-score-for-woocommerce' ),
					'risk'        => __( '%s risk', 'order-risk-score-for-woocommerce' ),
					'noSignals'   => __( 'Tetiklenen risk sinyali yok.', 'order-risk-score-for-woocommerce' ),
					'calculated'  => __( 'Hesaplandı: %s', 'order-risk-score-for-woocommerce' ),
				),
			)
		);
	}

	public static function add_metabox(): void {
		add_meta_box(
			'eru_ors_metabox',
			__( 'Sipariş Risk Skoru', 'order-risk-score-for-woocommerce' ),
			array( __CLASS__, 'render_metabox' ),
			array_unique( array( self::order_screen_id(), 'shop_order' ) ),
			'side',
			'default'
		);
	}

	public static function render_metabox( $post_or_order ): void {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Sipariş okunamadı.', 'order-risk-score-for-woocommerce' ) . '</p>';
			return;
		}

		$score   = (int) $order->get_meta( '_eru_ors_score' );
		$band    = (string) $order->get_meta( '_eru_ors_band' );
		$signals = (array) $order->get_meta( '_eru_ors_signals' );
		$at      = (string) $order->get_meta( '_eru_ors_calculated_at' );
		$labels  = wp_list_pluck( Eru_Ors_Scorer::signals(), 'label' );
		if ( '' === $band ) {
			$band = Eru_Ors_Scorer::band_for_score( $score );
		}
		?>
		<div class="eru-ors-box" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<div class="eru-ors-headline">
				<span class="eru-ors-badge eru-ors-<?php echo esc_attr( $band ); ?>"><?php echo (int) $score; ?>/100</span>
				<span class="eru-ors-band-label"><?php echo esc_html( sprintf( /* translators: %s: band name */ __( '%s risk', 'order-risk-score-for-woocommerce' ), Eru_Ors_Scorer::band_label( $band ) ) ); ?></span>
			</div>
			<?php if ( empty( $signals ) ) : ?>
				<p><em><?php esc_html_e( 'Tetiklenen risk sinyali yok.', 'order-risk-score-for-woocommerce' ); ?></em></p>
			<?php else : ?>
				<ul class="eru-ors-signals">
					<?php foreach ( $signals as $key => $weight ) : ?>
						<li><span class="eru-ors-signal-label"><?php echo esc_html( $labels[ $key ] ?? $key ); ?></span> <span class="eru-ors-signal-weight">+<?php echo (int) $weight; ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $at ) : ?>
				<p class="eru-ors-at"><small><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Hesaplandı: %s', 'order-risk-score-for-woocommerce' ), $at ) ); ?></small></p>
			<?php endif; ?>
			<p><button type="button" class="button" data-eru-ors-action="recalc"><?php esc_html_e( 'Yeniden hesapla', 'order-risk-score-for-woocommerce' ); ?></button></p>
			<div class="eru-ors-result"></div>
		</div>
		<?php
	}

	public static function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key || 'status' === $key ) {
				$new['eru_ors'] = __( 'Risk', 'order-risk-score-for-woocommerce' );
			}
		}
		if ( ! isset( $new['eru_ors'] ) ) {
			$new['eru_ors'] = __( 'Risk', 'order-risk-score-for-woocommerce' );
		}
		return $new;
	}

	public static function render_column_hpos( $column, $order ): void {
		if ( 'eru_ors' !== $column ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		echo wp_kses_post( self::badge( $order ) );
	}

	public static function render_column_classic( $column ): void {
		if ( 'eru_ors' !== $column ) {
			return;
		}
		global $post;
		echo wp_kses_post( self::badge( wc_get_order( $post->ID ) ) );
	}

	private static function badge( $order ): string {
		if ( ! $order instanceof WC_Order || '' === $order->get_meta( '_eru_ors_calculated_at' ) ) {
			return '<span class="eru-ors-badge eru-ors-na" title="' . esc_attr__( 'Henüz hesaplanmadı', 'order-risk-score-for-woocommerce' ) . '">&mdash;</span>';
		}
		$score = (int) $order->get_meta( '_eru_ors_score' );
		$band  = (string) $order->get_meta( '_eru_ors_band' );
		if ( '' === $band ) {
			$band = Eru_Ors_Scorer::band_for_score( $score );
		}
		return sprintf( '<span class="eru-ors-badge eru-ors-%s">%d</span>', esc_attr( $band ), $score );
	}

	public static function ajax_recalc(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( ! $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetki yok.', 'order-risk-score-for-woocommerce' ) ), 403 );
		}
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Sipariş bulunamadı.', 'order-risk-score-for-woocommerce' ) ), 404 );
		}

		$result                   = Eru_Ors_Scorer::score_order( $order, true );
		$labels                   = wp_list_pluck( Eru_Ors_Scorer::signals(), 'label' );
		$result['band_label']     = Eru_Ors_Scorer::band_label( $result['band'] );
		$result['signals_pretty'] = array();
		foreach ( $result['signals'] as $k => $v ) {
			$result['signals_pretty'][] = array( 'key' => $k, 'label' => $labels[ $k ] ?? $k, 'weight' => (int) $v );
		}
		$result['calculated_at'] = (string) $order->get_meta( '_eru_ors_calculated_at' );
		wp_send_json_success( $result );
	}
}
