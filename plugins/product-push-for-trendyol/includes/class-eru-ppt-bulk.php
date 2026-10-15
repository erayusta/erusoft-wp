<?php
/**
 * Product list: Trendyol status column, bulk actions "Trendyol'a gönder" / "Trendyol: doğrula", notices.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bulk handler checks manage_woocommerce and the list nonce itself. Pushes are queued in Action
 * Scheduler (no HTTP in the page request). Validation reads category data from the cache only; a cache miss
 * queues a background refresh and is reported as "yükleniyor". Results are shown once.
 */
class Eru_PPT_Bulk {

	const MAX_VALIDATE = 100;
	const ARGS         = array( 'eru_ppt_denied', 'eru_ppt_off', 'eru_ppt_queued', 'eru_ppt_validated' );

	/** Hooks (admin). */
	public static function init() {
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle' ), 10, 3 );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'column' ), 30 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'cell' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_eru_ppt_dismiss', array( __CLASS__, 'dismiss' ) );
		add_filter( 'removable_query_args', fn( $args ) => array_merge( $args, self::ARGS ) );
	}

	/** @param array $actions Actions. @return array */
	public static function actions( $actions ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$actions['eru_ppt_push']     = __( 'Trendyol\'a gönder', 'product-push-for-trendyol' );
			$actions['eru_ppt_validate'] = __( 'Trendyol: doğrula', 'product-push-for-trendyol' );
		}
		return $actions;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Post ids.
	 * @return string
	 */
	public static function handle( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'eru_ppt_push', 'eru_ppt_validate' ), true ) ) {
			return $redirect;
		}
		$redirect = remove_query_arg( self::ARGS, $redirect );
		$nonce    = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $nonce, 'bulk-posts' ) ) {
			return add_query_arg( 'eru_ppt_denied', 1, $redirect );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		if ( 'eru_ppt_validate' === $action ) {
			$rows = array();
			foreach ( array_slice( $ids, 0, self::MAX_VALIDATE ) as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p ) {
					continue;
				}
				$b      = Eru_PPT_Builder::build( $p, false );
				$rows[] = array(
					'id'     => $id,
					'name'   => $p->get_name(),
					'items'  => count( $b['items'] ),
					'errors' => array_slice( $b['errors'], 0, 20 ),
					'warn'   => array_slice( $b['warnings'], 0, 20 ),
				);
			}
			set_transient( 'eru_ppt_bulk_' . get_current_user_id(), array( 'rows' => $rows ), 10 * MINUTE_IN_SECONDS );
			return add_query_arg( 'eru_ppt_validated', count( $rows ), $redirect );
		}
		if ( ! Eru_PPT_Settings::is_enabled() ) {
			return add_query_arg( 'eru_ppt_off', 1, $redirect );
		}
		return add_query_arg( 'eru_ppt_queued', Eru_PPT_Pusher::enqueue( $ids ), $redirect );
	}

	/** @param array $cols Columns. @return array */
	public static function column( $cols ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $cols;
		}
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'price' === $k ) {
				$out['eru_ppt'] = __( 'Trendyol', 'product-push-for-trendyol' );
			}
		}
		if ( ! isset( $out['eru_ppt'] ) ) {
			$out['eru_ppt'] = __( 'Trendyol', 'product-push-for-trendyol' );
		}
		return $out;
	}

	/** @return array<string,string> Status labels. */
	public static function labels() {
		return array(
			'approved'     => __( 'Onaylı', 'product-push-for-trendyol' ),
			'unapproved'   => __( 'Onay bekliyor', 'product-push-for-trendyol' ),
			'pending'      => __( 'Sonuç bekleniyor', 'product-push-for-trendyol' ),
			'sending'      => __( 'Gönderiliyor', 'product-push-for-trendyol' ),
			'success'      => __( 'Başarılı', 'product-push-for-trendyol' ),
			'failed'       => __( 'Hata', 'product-push-for-trendyol' ),
			'invalid'      => __( 'Doğrulama hatası', 'product-push-for-trendyol' ),
			'needs_review' => __( 'İnceleme gerekli', 'product-push-for-trendyol' ),
			'unknown'      => __( 'Belirsiz', 'product-push-for-trendyol' ),
			''             => __( 'Gönderilmedi', 'product-push-for-trendyol' ),
		);
	}

	/**
	 * State of one object for display: the last operation when it needs attention, else the remote state.
	 *
	 * @param int $oid Object.
	 * @return string Label key.
	 */
	public static function state( $oid ) {
		$status = (string) get_post_meta( $oid, '_eru_ppt_status', true );
		$remote = (string) get_post_meta( $oid, '_eru_ppt_remote', true );
		if ( in_array( $status, array( 'failed', 'invalid', 'needs_review', 'pending', 'sending' ), true ) ) {
			return $status;
		}
		return $remote;
	}

	/** @param string $col Column. @param int $post_id Product. */
	public static function cell( $col, $post_id ) {
		if ( 'eru_ppt' !== $col ) {
			return;
		}
		$p = wc_get_product( $post_id );
		if ( ! $p ) {
			return;
		}
		$labels = self::labels();
		if ( 'invalid' === get_post_meta( $post_id, '_eru_ppt_status', true ) && $p->is_type( 'variable' ) ) {
			echo '<mark class="eru-ppt-badge is-invalid">' . esc_html( $labels['invalid'] ) . '</mark>';
			return;
		}
		$ids    = $p->is_type( 'variable' ) ? $p->get_children() : array( $post_id );
		$counts = array();
		foreach ( $ids as $oid ) {
			$s            = self::state( $oid );
			$counts[ $s ] = ( $counts[ $s ] ?? 0 ) + 1;
		}
		if ( ! $counts ) {
			echo '<span class="eru-ppt-badge">' . esc_html( $labels[''] ) . '</span>';
			return;
		}
		$order = array( 'needs_review', 'failed', 'invalid', 'unknown', 'sending', 'pending', 'unapproved', 'approved', '' );
		uksort( $counts, fn( $a, $b ) => array_search( (string) $a, $order, true ) <=> array_search( (string) $b, $order, true ) );
		$total = count( $ids );
		foreach ( $counts as $s => $n ) {
			$s    = (string) $s;
			$text = $labels[ $s ] ?? $s;
			if ( $total > 1 ) {
				$text = $n . '/' . $total . ' ' . $text;
			}
			echo '<mark class="eru-ppt-badge is-' . esc_attr( '' === $s ? 'none' : $s ) . '">' . esc_html( $text ) . '</mark> ';
		}
	}

	/** Notices: bulk results and the batch summary. */
	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$here   = $screen && ( 'edit-product' === $screen->id || 'product' === $screen->id || false !== strpos( (string) $screen->id, 'eru-ppt' ) );
		if ( ! $here ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['eru_ppt_denied'] ) ) {
			echo '<div class="notice notice-error eru-keep"><p>' . esc_html__( 'Trendyol: bu işlem için yetkiniz yok veya oturum süresi doldu.', 'product-push-for-trendyol' ) . '</p></div>';
		}
		if ( isset( $_GET['eru_ppt_off'] ) ) {
			echo '<div class="notice notice-warning eru-keep"><p>' . esc_html__( 'Trendyol: entegrasyon kapalı veya API bilgileri eksik; hiçbir ürün gönderilmedi.', 'product-push-for-trendyol' ) . '</p></div>';
		}
		if ( isset( $_GET['eru_ppt_queued'] ) ) {
			/* translators: %d: products */
			echo '<div class="notice notice-success eru-keep"><p>' . esc_html( sprintf( __( 'Trendyol: %d ürün arka planda gönderim kuyruğuna alındı. Sonuçlar Trendyol sütununda ve ürün ekranında görünür.', 'product-push-for-trendyol' ), absint( $_GET['eru_ppt_queued'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['eru_ppt_validated'] ) ) {
			self::validation_notice();
		}
		// phpcs:enable
		$n = get_option( Eru_PPT_Poller::NOTICE );
		if ( is_array( $n ) && ( ! empty( $n['ok'] ) || ! empty( $n['failed'] ) ) ) {
			$cls = empty( $n['failed'] ) ? 'notice-success' : 'notice-warning';
			echo '<div class="notice ' . esc_attr( $cls ) . ' eru-keep eru-ppt-batch-notice"><p><strong>' . esc_html__( 'Trendyol batch sonuçları', 'product-push-for-trendyol' ) . '</strong> — ';
			/* translators: 1: batches, 2: successful items, 3: failed items */
			echo esc_html( sprintf( __( '%1$d batch tamamlandı: %2$d öğe başarılı, %3$d öğe hatalı.', 'product-push-for-trendyol' ), (int) $n['batches'], (int) $n['ok'], (int) $n['failed'] ) );
			echo '</p>';
			if ( ! empty( $n['products'] ) ) {
				echo '<p>' . esc_html__( 'Hatalı ürünler:', 'product-push-for-trendyol' ) . ' ';
				$links = array();
				foreach ( (array) $n['products'] as $pid ) {
					$links[] = '<a href="' . esc_url( (string) get_edit_post_link( (int) $pid ) ) . '">' . esc_html( get_the_title( (int) $pid ) ) . '</a>';
				}
				echo wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => true ) ) ) . '</p>';
			}
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eru_ppt_dismiss' ), 'eru_ppt_dismiss' ) ) . '">' . esc_html__( 'Okundu', 'product-push-for-trendyol' ) . '</a></p></div>';
		}
	}

	/** Validation result table (once). */
	private static function validation_notice() {
		$key = 'eru_ppt_bulk_' . get_current_user_id();
		$res = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $res ) || empty( $res['rows'] ) ) {
			return;
		}
		$bad = array_filter( $res['rows'], fn( $r ) => ! empty( $r['errors'] ) );
		$cls = $bad ? 'notice-warning' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $cls ) . ' eru-keep"><p><strong>' . esc_html__( 'Trendyol doğrulama', 'product-push-for-trendyol' ) . '</strong> — ';
		/* translators: 1: checked, 2: with errors */
		echo esc_html( sprintf( __( '%1$d ürün kontrol edildi, %2$d üründe eksik var. Hiçbir şey gönderilmedi.', 'product-push-for-trendyol' ), count( $res['rows'] ), count( $bad ) ) ) . '</p><ul class="eru-ppt-list">';
		foreach ( $res['rows'] as $r ) {
			echo '<li><a href="' . esc_url( (string) get_edit_post_link( (int) $r['id'] ) ) . '">' . esc_html( $r['name'] ) . '</a>: ';
			if ( empty( $r['errors'] ) ) {
				/* translators: %d: items */
				echo esc_html( sprintf( __( 'hazır (%d öğe)', 'product-push-for-trendyol' ), (int) $r['items'] ) );
				if ( ! empty( $r['warn'] ) ) {
					echo '<ul class="eru-ppt-warn">';
					foreach ( (array) $r['warn'] as $w ) {
						echo '<li>' . esc_html( (string) $w ) . '</li>';
					}
					echo '</ul>';
				}
			} else {
				echo '<ul>';
				foreach ( $r['errors'] as $e ) {
					echo '<li>' . esc_html( $e ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/** Dismisses the batch summary. */
	public static function dismiss() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'product-push-for-trendyol' ), 403 );
		}
		check_admin_referer( 'eru_ppt_dismiss' );
		delete_option( Eru_PPT_Poller::NOTICE );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=product' ) );
		exit;
	}
}
