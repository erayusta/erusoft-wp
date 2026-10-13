<?php
/**
 * Admin screen: pick a term, drag its products into order.
 *
 * @package brand-product-sorting-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BPS_Admin {

	const PAGE = 'eru-bps';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_eru_bps_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_eru_bps_clear', array( __CLASS__, 'ajax_clear' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=product', __( 'Ürün Sıralama', 'brand-product-sorting-for-woocommerce' ), __( 'Ürün Sıralama', 'brand-product-sorting-for-woocommerce' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'eru-bps-admin', ERU_BPS_URL . 'assets/admin.css', array(), ERU_BPS_VERSION );
		wp_enqueue_script( 'eru-bps-admin', ERU_BPS_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), ERU_BPS_VERSION, true );
		wp_localize_script(
			'eru-bps-admin',
			'eruBps',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'eru_bps' ),
				'saved'  => __( 'Sıralama kaydedildi.', 'brand-product-sorting-for-woocommerce' ),
				'reset'  => __( 'Elle sıralama kaldırıldı.', 'brand-product-sorting-for-woocommerce' ),
				'failed' => __( 'İşlem başarısız oldu.', 'brand-product-sorting-for-woocommerce' ),
				'ask'    => __( 'Bu arşivdeki elle sıralama kaldırılsın mı?', 'brand-product-sorting-for-woocommerce' ),
			)
		);
	}

	/**
	 * Products of a term: the stored order first, then the rest in the shop's default order.
	 *
	 * @return WC_Product[]
	 */
	public static function term_products( $term ) {
		$limit = (int) apply_filters( 'eru_bps_max_products', 2000 );
		$ids   = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => max( 1, $limit ),
				'fields'         => 'ids',
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => array( $term->term_id ),
					),
				),
			)
		);
		$ids    = array_map( 'intval', $ids );
		$stored = array_values( array_intersect( Eru_BPS::get_order( $term->term_id ), $ids ) );
		$ids    = array_merge( $stored, array_values( array_diff( $ids, $stored ) ) );
		return array_values( array_filter( array_map( 'wc_get_product', $ids ) ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'brand-product-sorting-for-woocommerce' ) );
		}
		$taxes = Eru_BPS::taxonomies();
		$default_tax = (string) apply_filters( 'eru_bps_default_taxonomy', 'product_cat' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view selection.
		$tax = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : $default_tax;
		$tax = in_array( $tax, $taxes, true ) ? $tax : ( in_array( $default_tax, $taxes, true ) ? $default_tax : (string) reset( $taxes ) );
		$tid = isset( $_GET['term'] ) ? absint( $_GET['term'] ) : 0;
		// phpcs:enable
		$terms = $tax ? get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) : array();
		$terms = is_wp_error( $terms ) ? array() : $terms;
		$term  = $tid ? get_term( $tid, $tax ) : null;
		$term  = ( $term && ! is_wp_error( $term ) ) ? $term : null;
		?>
		<div class="wrap eru-bps">
			<h1><?php esc_html_e( 'Ürün Sıralama', 'brand-product-sorting-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Bir marka veya kategori seçin, ürünleri sürükleyip bırakın. Sıralama yalnızca o arşivde ve mağazanın varsayılan sıralamasında uygulanır; ziyaretçi fiyat gibi başka bir sıralama seçerse dokunulmaz.', 'brand-product-sorting-for-woocommerce' ); ?></p>
			<form method="get" class="eru-bps-pick">
				<input type="hidden" name="post_type" value="product">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<label><?php esc_html_e( 'Sınıflandırma', 'brand-product-sorting-for-woocommerce' ); ?>
					<select name="taxonomy" onchange="this.form.elements.term.value='';this.form.submit()">
						<?php foreach ( $taxes as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tax, $t ); ?>><?php echo esc_html( get_taxonomy( $t )->labels->singular_name . ' (' . $t . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Terim', 'brand-product-sorting-for-woocommerce' ); ?>
					<select name="term" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( '— seçin —', 'brand-product-sorting-for-woocommerce' ); ?></option>
						<?php foreach ( $terms as $t ) : ?>
							<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $tid, $t->term_id ); ?>><?php echo esc_html( $t->name . ' (' . $t->count . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<noscript><button class="button"><?php esc_html_e( 'Göster', 'brand-product-sorting-for-woocommerce' ); ?></button></noscript>
			</form>
			<?php if ( $term ) : ?>
				<?php $products = self::term_products( $term ); ?>
				<p class="eru-bps-bar">
					<button type="button" class="button button-primary" id="eru-bps-save" data-term="<?php echo (int) $term->term_id; ?>"><?php esc_html_e( 'Sıralamayı kaydet', 'brand-product-sorting-for-woocommerce' ); ?></button>
					<button type="button" class="button" id="eru-bps-clear" data-term="<?php echo (int) $term->term_id; ?>"><?php esc_html_e( 'Elle sıralamayı kaldır', 'brand-product-sorting-for-woocommerce' ); ?></button>
					<span id="eru-bps-msg" role="status"></span>
				</p>
				<?php if ( ! $products ) : ?>
					<p><?php esc_html_e( 'Bu terimde ürün yok.', 'brand-product-sorting-for-woocommerce' ); ?></p>
				<?php else : ?>
					<ul id="eru-bps-list">
						<?php foreach ( $products as $p ) : ?>
							<li data-id="<?php echo (int) $p->get_id(); ?>">
								<span class="eru-bps-handle dashicons dashicons-menu" aria-hidden="true"></span>
								<span class="eru-bps-thumb"><?php echo wp_kses_post( $p->get_image( array( 40, 40 ) ) ); ?></span>
								<span class="eru-bps-title"><?php echo esc_html( $p->get_name() ); ?></span>
								<span class="eru-bps-meta">#<?php echo (int) $p->get_id(); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function guard() {
		check_ajax_referer( 'eru_bps', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkiniz yok.', 'brand-product-sorting-for-woocommerce' ) ), 403 );
		}
	}

	public static function ajax_save() {
		self::guard();
		$term_id = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$ids     = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		if ( ! Eru_BPS::save_order( $term_id, $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Terim geçersiz.', 'brand-product-sorting-for-woocommerce' ) ), 400 );
		}
		wp_send_json_success();
	}

	public static function ajax_clear() {
		self::guard();
		$term_id = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$term    = $term_id ? get_term( $term_id ) : null;
		if ( ! $term || is_wp_error( $term ) || ! in_array( $term->taxonomy, Eru_BPS::taxonomies(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Terim geçersiz.', 'brand-product-sorting-for-woocommerce' ) ), 400 );
		}
		Eru_BPS::clear_order( $term_id );
		wp_send_json_success();
	}
}
