<?php
/**
 * Primary category logic and product-edit picker.
 *
 * @package primary-category-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PC {

	const META = '_eru_pc_primary';
	const TAX  = 'product_cat';

	public static function init() {
		add_filter( 'wc_product_post_type_link_product_cat', array( __CLASS__, 'permalink_term' ), 10, 3 );
		add_filter( 'woocommerce_breadcrumb_main_term', array( __CLASS__, 'breadcrumb_term' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_box' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
	}

	/**
	 * @return WP_Term|null
	 */
	public static function get_primary( $product_id, $taxonomy = self::TAX ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return null;
		}
		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = (int) wp_get_post_parent_id( $product_id );
		}
		$assigned = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $assigned ) || ! $assigned ) {
			return null;
		}
		$assigned   = array_map( 'intval', $assigned );
		$candidates = array( (int) get_post_meta( $product_id, self::META, true ) );
		if ( self::TAX === $taxonomy ) {
			// Read-only fallback to SEO plugins' primary term meta.
			$candidates[] = (int) get_post_meta( $product_id, '_yoast_wpseo_primary_product_cat', true );
			$candidates[] = (int) get_post_meta( $product_id, 'rank_math_primary_product_cat', true );
		}
		$chosen = 0;
		foreach ( $candidates as $id ) {
			if ( $id && in_array( $id, $assigned, true ) ) {
				$chosen = $id;
				break;
			}
		}
		$term = $chosen ? get_term( $chosen, $taxonomy ) : null;
		$term = ( $term instanceof WP_Term ) ? $term : null;
		/**
		 * Filters the primary term of a product.
		 *
		 * @param WP_Term|null $term       Term or null.
		 * @param int          $product_id Product ID.
		 * @param string       $taxonomy   Taxonomy.
		 */
		return apply_filters( 'eru_pc_primary_term', $term, $product_id, $taxonomy );
	}

	public static function permalink_term( $term, $terms, $post ) {
		$primary = self::get_primary( (int) $post->ID );
		return $primary ? $primary : $term;
	}

	public static function breadcrumb_term( $term, $terms ) {
		global $post;
		if ( ! $post || 'product' !== $post->post_type ) {
			return $term;
		}
		$primary = self::get_primary( (int) $post->ID );
		return $primary ? $primary : $term;
	}

	/* ---- Admin ---- */

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
			return;
		}
		wp_enqueue_script( 'eru-pc-admin', ERU_PC_URL . 'assets/admin.js', array( 'jquery' ), ERU_PC_VERSION, true );
	}

	public static function add_box() {
		add_meta_box( 'eru-pc', __( 'Birincil kategori', 'primary-category-for-woocommerce' ), array( __CLASS__, 'render_box' ), 'product', 'side', 'default' );
	}

	public static function render_box( $post ) {
		$assigned = wp_get_post_terms( $post->ID, self::TAX );
		$assigned = is_wp_error( $assigned ) ? array() : $assigned;
		$current  = (int) get_post_meta( $post->ID, self::META, true );
		wp_nonce_field( 'eru_pc_save', 'eru_pc_nonce' );
		echo '<select name="eru_pc_primary" id="eru-pc-select" style="width:100%" data-current="' . (int) $current . '">';
		echo '<option value="0">' . esc_html__( '— otomatik —', 'primary-category-for-woocommerce' ) . '</option>';
		foreach ( $assigned as $t ) {
			printf( '<option value="%d"%s>%s</option>', (int) $t->term_id, selected( $current, $t->term_id, false ), esc_html( $t->name ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Listede yalnızca ürüne atanmış kategoriler görünür. Kaydedince adres ve kırıntıda kullanılır.', 'primary-category-for-woocommerce' ) . '</p>';
	}

	/**
	 * Saves the picker value; the posted category must be one the product is assigned to.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save( $product ) {
		$id = $product->get_id();
		if ( ! $id || ! isset( $_POST['eru_pc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_pc_nonce'] ) ), 'eru_pc_save' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$want = isset( $_POST['eru_pc_primary'] ) ? absint( $_POST['eru_pc_primary'] ) : 0;
		if ( ! $want ) {
			delete_post_meta( $id, self::META );
			return;
		}
		// During a real save the posted category checkboxes are the truth; WooCommerce applies them after this hook.
		$posted = isset( $_POST['tax_input'][ self::TAX ] ) ? array_map( 'absint', (array) wp_unslash( $_POST['tax_input'][ self::TAX ] ) ) : null;
		$ids    = null !== $posted ? $posted : array_map( 'intval', (array) wp_get_post_terms( $id, self::TAX, array( 'fields' => 'ids' ) ) );
		if ( in_array( $want, $ids, true ) ) {
			update_post_meta( $id, self::META, $want );
		}
	}
}
