<?php
/**
 * Product screen: "Trendyol" box (overrides, category, attribute form, status, buttons) and per-variation
 * barcode / list price fields.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saving the product only stores the Trendyol fields; nothing is sent. The buttons call
 * wp_ajax_eru_ppt_product (manage_woocommerce + edit_product + nonce): validate, queue a push, reset.
 */
class Eru_PPT_Metabox {

	/** Hooks. */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'wp_ajax_eru_ppt_product', array( __CLASS__, 'ajax' ) );
	}

	/** Registers the box. */
	public static function box() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			add_meta_box( 'eru-ppt', __( 'Trendyol', 'product-push-for-trendyol' ), array( __CLASS__, 'render' ), 'product', 'normal', 'default' );
		}
	}

	/** @param WP_Post $post Post. */
	public static function render( $post ) {
		$p = wc_get_product( $post->ID );
		if ( ! $p ) {
			return;
		}
		$cat    = Eru_PPT_Categories::resolve( $p );
		$over   = absint( $p->get_meta( '_eru_ppt_category_id' ) );
		$saved  = (array) $p->get_meta( '_eru_ppt_attributes' );
		$simple = $p->is_type( 'simple' );
		wp_nonce_field( 'eru_ppt_meta', 'eru_ppt_meta_nonce' );
		echo '<div class="eru-ppt-box" data-product="' . (int) $p->get_id() . '">';
		if ( ! Eru_PPT_Settings::is_enabled() ) {
			echo '<p class="eru-ppt-note">' . esc_html__( 'Trendyol entegrasyonu kapalı veya API bilgileri eksik: alanlar kaydedilir, gönderim ve kategori araması çalışmaz.', 'product-push-for-trendyol' ) . '</p>';
		}
		if ( ! $simple && ! $p->is_type( 'variable' ) ) {
			echo '<p>' . esc_html__( 'Yalnızca basit ve varyantlı ürünler Trendyol\'a gönderilebilir.', 'product-push-for-trendyol' ) . '</p></div>';
			return;
		}
		echo '<div class="eru-ppt-grid">';
		echo '<p><label for="eru-ppt-title"><strong>' . esc_html__( 'Trendyol başlığı', 'product-push-for-trendyol' ) . '</strong></label><br><input type="text" class="widefat" maxlength="100" id="eru-ppt-title" name="eru_ppt[title]" value="' . esc_attr( (string) $p->get_meta( '_eru_ppt_title' ) ) . '" placeholder="' . esc_attr( Eru_PPT_Builder::title( (string) Eru_PPT_Settings::get( 'title_template' ), $p, $p ) ) . '"><span class="description">' . esc_html__( 'Boşsa başlık şablonu kullanılır (en fazla 100 karakter).', 'product-push-for-trendyol' ) . '</span></p>';
		echo '<p><label for="eru-ppt-desc"><strong>' . esc_html__( 'Trendyol açıklaması', 'product-push-for-trendyol' ) . '</strong></label><br><textarea class="widefat" rows="3" id="eru-ppt-desc" name="eru_ppt[description]">' . esc_textarea( (string) $p->get_meta( '_eru_ppt_description' ) ) . '</textarea><span class="description">' . esc_html__( 'Boşsa ürün açıklaması kullanılır.', 'product-push-for-trendyol' ) . '</span></p>';
		echo '</div>';

		echo '<div class="eru-ppt-grid"><div><strong>' . esc_html__( 'Trendyol kategorisi', 'product-push-for-trendyol' ) . '</strong>';
		Eru_PPT_Admin::picker( 'category', 'eru_ppt[category_id]', 'eru_ppt[category_name]', $over, (string) $p->get_meta( '_eru_ppt_category_name' ), __( 'Kategori ara (en az 2 harf)', 'product-push-for-trendyol' ) );
		if ( ! $over ) {
			echo '<p class="description">' . esc_html( 'map' === $cat['source'] ? sprintf( /* translators: 1: category path, 2: id */ __( 'Seçilmezse kategori eşlemesinden: %1$s (#%2$d)', 'product-push-for-trendyol' ), $cat['name'], $cat['id'] ) : __( 'Kategori eşlemesinde bu ürünün kategorisi yok; burada seçin.', 'product-push-for-trendyol' ) ) . '</p>';
		}
		echo '</div><div><strong>' . esc_html__( 'Marka (bu ürün için)', 'product-push-for-trendyol' ) . '</strong>';
		Eru_PPT_Admin::picker( 'brand', 'eru_ppt[brand_id]', 'eru_ppt[brand_name]', absint( $p->get_meta( '_eru_ppt_brand_id' ) ), (string) $p->get_meta( '_eru_ppt_brand_name' ), __( 'Boşsa varsayılan marka', 'product-push-for-trendyol' ) );
		echo '</div></div>';

		if ( $simple ) {
			echo '<div class="eru-ppt-grid"><p><label for="eru-ppt-barcode"><strong>' . esc_html__( 'Trendyol barkodu', 'product-push-for-trendyol' ) . '</strong></label><br><input type="text" maxlength="40" id="eru-ppt-barcode" name="eru_ppt[barcode]" value="' . esc_attr( (string) $p->get_meta( '_eru_ppt_barcode' ) ) . '" placeholder="' . esc_attr( Eru_PPT_Builder::barcode( $p ) ) . '"></p>';
			echo '<p><label for="eru-ppt-list"><strong>' . esc_html__( 'Liste fiyatı', 'product-push-for-trendyol' ) . '</strong></label><br><input type="text" inputmode="decimal" id="eru-ppt-list" name="eru_ppt[list_price]" value="' . esc_attr( (string) $p->get_meta( '_eru_ppt_list_price' ) ) . '" placeholder="' . esc_attr__( 'normal fiyat × çarpan', 'product-push-for-trendyol' ) . '"></p></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Varyantlı ürün: her varyasyon ayrı bir Trendyol öğesidir (ortak productMainId, ayrı barkod). Barkod ve liste fiyatı varyasyon satırlarında; varyant özellikleri Özellik eşleme tablosundan gelir.', 'product-push-for-trendyol' ) . '</p>';
		}

		echo '<h4>' . esc_html__( 'Kategori özellikleri', 'product-push-for-trendyol' ) . '</h4>';
		echo '<div class="eru-ppt-attrs" data-cat="' . (int) $cat['id'] . '" data-saved="' . esc_attr( (string) wp_json_encode( (object) $saved ) ) . '" data-variable="' . ( $simple ? '0' : '1' ) . '"></div>';

		self::status_table( $p );

		echo '<p class="eru-ppt-actions">';
		echo '<button type="button" class="button eru-ppt-do" data-do="validate">' . esc_html__( 'Doğrula', 'product-push-for-trendyol' ) . '</button> ';
		echo '<button type="button" class="button button-primary eru-ppt-do" data-do="push">' . esc_html__( 'Trendyol\'a gönder', 'product-push-for-trendyol' ) . '</button> ';
		echo '<button type="button" class="button-link eru-ppt-do" data-do="reset">' . esc_html__( 'Durumu sıfırla', 'product-push-for-trendyol' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Önce ürünü kaydedin: düğmeler kayıtlı değerlerle çalışır. Gönderim arka planda yapılır; sonuç birkaç dakika içinde burada görünür.', 'product-push-for-trendyol' ) . '</p>';
		echo '<ul class="eru-ppt-result" aria-live="polite"></ul>';
		echo '</div>';
	}

	/** @param WC_Product $p Product. */
	private static function status_table( $p ) {
		$labels = Eru_PPT_Bulk::labels();
		$objs   = Eru_PPT_Builder::objects( $p );
		$perr   = $p->is_type( 'variable' ) && 'invalid' === $p->get_meta( '_eru_ppt_status' ) ? Eru_PPT_Pusher::arr( $p->get_id(), 'errors' ) : array();
		echo '<h4>' . esc_html__( 'Trendyol durumu', 'product-push-for-trendyol' ) . '</h4>';
		if ( $perr ) {
			echo '<div class="eru-ppt-errors"><strong>' . esc_html__( 'Son gönderimde doğrulama hatası:', 'product-push-for-trendyol' ) . '</strong><ul>';
			foreach ( $perr as $e ) {
				echo '<li>' . esc_html( (string) $e ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '<table class="widefat striped eru-ppt-status"><thead><tr>';
		foreach ( array( __( 'Öğe', 'product-push-for-trendyol' ), __( 'Barkod', 'product-push-for-trendyol' ), __( 'Durum', 'product-push-for-trendyol' ), __( 'Trendyol', 'product-push-for-trendyol' ), __( 'Batch', 'product-push-for-trendyol' ), __( 'Hatalar', 'product-push-for-trendyol' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $objs as $o ) {
			$id     = $o->get_id();
			$status = (string) $o->get_meta( '_eru_ppt_status' );
			$remote = (string) $o->get_meta( '_eru_ppt_remote' );
			$errs   = Eru_PPT_Pusher::arr( $id, 'errors' );
			$name   = $o->is_type( 'variation' ) ? '#' . $id . ' ' . Eru_PPT_Builder::summary( $o ) : __( 'Ürün', 'product-push-for-trendyol' );
			echo '<tr><td>' . esc_html( $name ) . '</td><td><code>' . esc_html( Eru_PPT_Builder::barcode( $o ) ) . '</code></td>';
			echo '<td>' . esc_html( $labels[ $status ] ?? ( '' === $status ? '—' : $status ) ) . '</td>';
			echo '<td>' . esc_html( $labels[ $remote ] ?? $remote ) . '</td>';
			echo '<td><code>' . esc_html( (string) $o->get_meta( '_eru_ppt_batch' ) ) . '</code></td><td>';
			if ( $errs ) {
				echo '<ul class="eru-ppt-errlist">';
				foreach ( $errs as $e ) {
					echo '<li>' . esc_html( (string) $e ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</td></tr>';
		}
		if ( ! $objs ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Yayında varyasyon yok.', 'product-push-for-trendyol' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param WC_Product $product Product being saved (WooCommerce checked its own nonce). */
	public static function save( $product ) {
		$nonce = isset( $_POST['eru_ppt_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['eru_ppt_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'eru_ppt_meta' ) || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field by field below.
		$in = isset( $_POST['eru_ppt'] ) && is_array( $_POST['eru_ppt'] ) ? wp_unslash( $_POST['eru_ppt'] ) : array();
		$set = function ( $k, $v ) use ( $product ) {
			if ( '' === $v || 0 === $v || array() === $v ) {
				$product->delete_meta_data( '_eru_ppt_' . $k );
			} else {
				$product->update_meta_data( '_eru_ppt_' . $k, $v );
			}
		};
		$set( 'title', mb_substr( sanitize_text_field( (string) ( $in['title'] ?? '' ) ), 0, 100 ) );
		$set( 'description', wp_kses_post( (string) ( $in['description'] ?? '' ) ) );
		$cid = absint( $in['category_id'] ?? 0 );
		$set( 'category_id', $cid );
		$set( 'category_name', $cid ? sanitize_text_field( (string) ( $in['category_name'] ?? '' ) ) : '' );
		$bid = absint( $in['brand_id'] ?? 0 );
		$set( 'brand_id', $bid );
		$set( 'brand_name', $bid ? sanitize_text_field( (string) ( $in['brand_name'] ?? '' ) ) : '' );
		if ( $product->is_type( 'simple' ) ) {
			$set( 'barcode', substr( preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) ( $in['barcode'] ?? '' ) ), 0, 40 ) );
			$set( 'list_price', Eru_PPT_Settings::decimal( $in['list_price'] ?? '' ) );
		}
		// The attribute form is only saved when admin.js rendered it (a failed lookup must not wipe values).
		if ( ! empty( $in['attrs_loaded'] ) ) {
			$attrs = array();
			foreach ( (array) ( $in['attr'] ?? array() ) as $aid => $v ) {
				if ( absint( $aid ) && absint( $v ) ) {
					$attrs[ absint( $aid ) ] = array( 'v' => absint( $v ) );
				}
			}
			foreach ( (array) ( $in['attr_custom'] ?? array() ) as $aid => $v ) {
				$v = mb_substr( sanitize_text_field( (string) $v ), 0, 200 );
				if ( absint( $aid ) && '' !== $v ) {
					$attrs[ absint( $aid ) ] = array( 'c' => $v );
				}
			}
			$set( 'attributes', array_slice( $attrs, 0, 100, true ) );
		}
	}

	/**
	 * @param int     $loop      Index.
	 * @param array   $data      Data.
	 * @param WP_Post $variation Variation post.
	 */
	public static function variation_fields( $loop, $data, $variation ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$v = wc_get_product( $variation->ID );
		if ( ! $v ) {
			return;
		}
		$labels = Eru_PPT_Bulk::labels();
		$state  = Eru_PPT_Bulk::state( $v->get_id() );
		echo '<div class="eru-ppt-variation form-row form-row-full"><strong>' . esc_html__( 'Trendyol', 'product-push-for-trendyol' ) . '</strong> — ' . esc_html( $labels[ $state ] ?? $state ) . '<br>';
		echo '<label>' . esc_html__( 'Barkod', 'product-push-for-trendyol' ) . ' <input type="text" maxlength="40" name="eru_ppt_var[' . (int) $loop . '][barcode]" value="' . esc_attr( (string) $v->get_meta( '_eru_ppt_barcode' ) ) . '" placeholder="' . esc_attr( Eru_PPT_Builder::barcode( $v ) ) . '"></label> ';
		echo '<label>' . esc_html__( 'Liste fiyatı', 'product-push-for-trendyol' ) . ' <input type="text" inputmode="decimal" name="eru_ppt_var[' . (int) $loop . '][list_price]" value="' . esc_attr( (string) $v->get_meta( '_eru_ppt_list_price' ) ) . '"></label></div>';
	}

	/**
	 * WooCommerce verified the save-variations nonce and edit_products before this runs.
	 *
	 * @param int $vid Variation.
	 * @param int $i   Index.
	 */
	public static function save_variation( $vid, $i ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_product', $vid ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- see above; sanitised below.
		$row = isset( $_POST['eru_ppt_var'][ $i ] ) && is_array( $_POST['eru_ppt_var'][ $i ] ) ? wp_unslash( $_POST['eru_ppt_var'][ $i ] ) : null;
		if ( null === $row ) {
			return;
		}
		$v = wc_get_product( $vid );
		if ( ! $v ) {
			return;
		}
		$bc = substr( preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) ( $row['barcode'] ?? '' ) ), 0, 40 );
		$lp = Eru_PPT_Settings::decimal( $row['list_price'] ?? '' );
		foreach ( array(
			'barcode'    => $bc,
			'list_price' => $lp,
		) as $k => $val ) {
			if ( '' === $val ) {
				$v->delete_meta_data( '_eru_ppt_' . $k );
			} else {
				$v->update_meta_data( '_eru_ppt_' . $k, $val );
			}
		}
		$v->save_meta_data();
	}

	/** Validate / push (queued) / reset. */
	public static function ajax() {
		if ( ! check_ajax_referer( 'eru_ppt_admin', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'messages' => array( __( 'Yetkiniz yok veya oturum süresi doldu.', 'product-push-for-trendyol' ) ) ), 403 );
		}
		$id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p || ! current_user_can( 'edit_product', $id ) ) {
			wp_send_json_error( array( 'messages' => array( __( 'Ürün bulunamadı.', 'product-push-for-trendyol' ) ) ) );
		}
		if ( 'validate' === $do ) {
			$b = Eru_PPT_Builder::build( $p, false );
			if ( $b['errors'] ) {
				wp_send_json_error( array( 'messages' => $b['errors'] ) );
			}
			/* translators: 1: items, 2: productMainId */
			wp_send_json_success( array( 'messages' => array_merge( array( sprintf( __( 'Hazır: %1$d öğe gönderilecek (productMainId %2$s). Hiçbir şey gönderilmedi.', 'product-push-for-trendyol' ), count( $b['items'] ), $b['main_id'] ) ), $b['warnings'] ) ) );
		}
		if ( 'push' === $do ) {
			if ( ! Eru_PPT_Settings::is_enabled() ) {
				wp_send_json_error( array( 'messages' => array( __( 'Entegrasyon kapalı veya API bilgileri eksik.', 'product-push-for-trendyol' ) ) ) );
			}
			$b = Eru_PPT_Builder::build( $p, false );
			if ( $b['errors'] ) {
				wp_send_json_error( array( 'messages' => $b['errors'] ) );
			}
			Eru_PPT_Pusher::enqueue( array( $id ) );
			wp_send_json_success( array( 'messages' => array( __( 'Gönderim kuyruğa alındı. Sonuç birkaç dakika içinde bu kutuda ve ürün listesinin Trendyol sütununda görünür.', 'product-push-for-trendyol' ) ) ) );
		}
		if ( 'reset' === $do ) {
			$n = Eru_PPT_Pusher::reset( $id );
			if ( $n < 0 ) {
				wp_send_json_error( array( 'messages' => array( __( 'Bir Trendyol gönderimi sürüyor; birazdan tekrar deneyin.', 'product-push-for-trendyol' ) ) ) );
			}
			/* translators: %d: items */
			wp_send_json_success( array( 'messages' => array( sprintf( __( '%d öğenin durumu sıfırlandı; bir sonraki gönderimde önce Trendyol\'da aranacak.', 'product-push-for-trendyol' ), $n ) ), 'reload' => $n > 0 ) );
		}
		wp_send_json_error( array( 'messages' => array( __( 'Bilinmeyen işlem.', 'product-push-for-trendyol' ) ) ) );
	}
}
