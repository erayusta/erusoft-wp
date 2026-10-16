<?php
/**
 * Order screen box "Pazaryeri" for marketplace orders (HPOS and classic edit screens).
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows the marketplace, order and package numbers (earlier packages too), statuses, cargo, the acknowledgement
 * or packing state with its attempts and error, review and replacement links, and a link to the stored raw payload
 * (only while it is retained, only with manage_woocommerce). The identity number is never shown.
 *
 * Buttons ("Yeniden çek", "Pazaryerine bildir") are plain buttons: admin.js posts a separate small form to
 * admin-post.php with the order's nonce, so the order form itself is never submitted elsewhere. Both only enqueue
 * an Action Scheduler job.
 */
class Eru_MPO_Metabox {

	/** Hooks. */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'item_meta_label' ), 10, 2 );
	}

	/** @return array<string,string> Line meta key => label (WooCommerce shows "_" keys on the order screen; e-mails hide them). */
	public static function item_labels() {
		return array(
			'_eru_mpo_sku'                     => __( 'Pazaryeri stok kodu', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_barcode'                 => __( 'Pazaryeri barkodu', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_remote_sku'              => __( 'Pazaryeri ürün kodu', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_hb_sku'                  => __( 'Hepsiburada SKU', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_remote_line_id'          => __( 'Pazaryeri satır no', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_commission_rate'         => __( 'Komisyon oranı (%)', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_commission_amount'       => __( 'Komisyon tutarı', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_discount'                => __( 'Satır indirimi', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_ty_seller_discount'      => __( 'Satıcı indirimi', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_ty_marketplace_discount' => __( 'Trendyol indirimi', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_hb_merchant_discount'    => __( 'Satıcı indirimi', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_hb_marketplace_discount' => __( 'Hepsiburada indirimi', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_unmatched'               => __( 'Ürünle eşleşmedi', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_line_status'             => __( 'Pazaryeri satır durumu', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_original_price'          => __( 'Pazaryerindeki birim fiyat', 'marketplace-orders-for-woocommerce' ),
			'_eru_mpo_customized'              => __( 'Kişiselleştirme', 'marketplace-orders-for-woocommerce' ),
		);
	}

	/**
	 * woocommerce_order_item_display_meta_key.
	 *
	 * @param string $key  Display key.
	 * @param object $meta Meta (key, value).
	 * @return string
	 */
	public static function item_meta_label( $key, $meta = null ) {
		$raw = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : (string) $key;
		return 0 === strpos( $raw, '_eru_mpo_' ) ? ( self::item_labels()[ $raw ] ?? $key ) : $key;
	}

	/**
	 * @param string           $screen_or_type Screen id or post type.
	 * @param WP_Post|WC_Order $object         Post or order.
	 */
	public static function register( $screen_or_type, $object = null ) {
		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
		if ( ! $order || ! Eru_MPO_Stock::is_marketplace_order( $order ) ) {
			return;
		}
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'eru-mpo-order', __( 'Pazaryeri', 'marketplace-orders-for-woocommerce' ), array( __CLASS__, 'render_box' ), $screen, 'side', 'high' );
	}

	/** @param WP_Post|WC_Order $object Post or order. */
	public static function render_box( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID ?? 0 );
		if ( $order ) {
			self::render( $order );
		}
	}

	/** @param int $id Order. @return string Link to its edit screen. */
	private static function order_link( $id ) {
		$url = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int) $id ) : admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
		return '<a href="' . esc_url( $url ) . '">#' . (int) $id . '</a>';
	}

	/** @param mixed $v Meta value (array, JSON or scalar). @return array */
	private static function as_list( $v ) {
		if ( is_string( $v ) && '' !== $v && ( '[' === $v[0] || '{' === $v[0] ) ) {
			$d = json_decode( $v, true );
			$v = is_array( $d ) ? $d : $v;
		}
		return array_values( array_filter( array_map( 'strval', is_array( $v ) ? $v : ( '' === (string) $v ? array() : array( $v ) ) ), 'strlen' ) );
	}

	/** @param WC_Order $order Order. */
	public static function render( $order ) {
		if ( ! current_user_can( 'edit_shop_orders', $order->get_id() ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$mp   = (string) $order->get_meta( '_eru_mpo_marketplace' );
		$a    = Eru_MPO_Registry::get( $mp );
		$ref  = (string) $order->get_meta( '_eru_mpo_remote_ref' );
		$rows = array();
		$add  = function ( $label, $value, $html = false ) use ( &$rows ) {
			if ( '' !== (string) $value ) {
				$rows[] = array( $label, $value, $html );
			}
		};
		$add( __( 'Pazaryeri', 'marketplace-orders-for-woocommerce' ), $a ? $a->label() : $mp );
		$add( __( 'Sipariş no', 'marketplace-orders-for-woocommerce' ), (string) $order->get_meta( '_eru_mpo_order_number' ) );
		$add( __( 'Paket', 'marketplace-orders-for-woocommerce' ), (string) $order->get_meta( '_eru_mpo_package_id' ) ?: $ref );
		$earlier = array_diff( self::as_list( $order->get_meta( '_eru_mpo_packages' ) ), array( $ref ) );
		$add( __( 'Önceki paketler', 'marketplace-orders-for-woocommerce' ), implode( ', ', $earlier ) );
		$labels = Eru_MPO_Statuses::labels();
		$cur    = Eru_MPO_Statuses::current( $order );
		$remote = (string) $order->get_meta( '_eru_mpo_remote_status' );
		$add( __( 'Durum', 'marketplace-orders-for-woocommerce' ), trim( ( $labels[ $cur ] ?? $cur ) . ( '' !== $remote ? ' (' . $remote . ')' : '' ) ) );
		$cargo = trim( (string) $order->get_meta( '_eru_mpo_cargo_provider' ) . ' ' . (string) $order->get_meta( '_eru_mpo_cargo_tracking' ) );
		$link  = (string) $order->get_meta( '_eru_mpo_cargo_link' );
		if ( '' !== $cargo && wp_http_validate_url( $link ) ) {
			$add( __( 'Kargo', 'marketplace-orders-for-woocommerce' ), '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $cargo ) . '</a>', true );
		} else {
			$add( __( 'Kargo', 'marketplace-orders-for-woocommerce' ), $cargo );
		}
		$hb_packages = self::as_list( $order->get_meta( '_eru_mpo_hb_packages' ) );
		$add( __( 'Hepsiburada paketleri', 'marketplace-orders-for-woocommerce' ), implode( ', ', $hb_packages ) );
		$mode = $a ? Eru_MPO_Ack::mode( $mp ) : 'never';
		if ( $a ) {
			$p      = Eru_MPO_Ack::profile( $a );
			$state  = (string) $order->get_meta( $p['state'] );
			$states = array(
				'queued'  => __( 'Kuyrukta', 'marketplace-orders-for-woocommerce' ),
				'retry'   => __( 'Yeniden denenecek', 'marketplace-orders-for-woocommerce' ),
				'done'    => __( 'Yapıldı', 'marketplace-orders-for-woocommerce' ),
				'packed'  => __( 'Paketlendi', 'marketplace-orders-for-woocommerce' ),
				'failed'  => __( 'Başarısız', 'marketplace-orders-for-woocommerce' ),
				'skipped' => __( 'Atlandı (sipariş kapalı)', 'marketplace-orders-for-woocommerce' ),
			);
			$line   = '' !== $state ? ( $states[ $state ] ?? $state ) : ( 'never' === $mode ? __( 'Kapalı', 'marketplace-orders-for-woocommerce' ) : __( 'Henüz yok', 'marketplace-orders-for-woocommerce' ) );
			$tries  = (int) $order->get_meta( $p['tries'] );
			if ( $tries ) {
				/* translators: %d: attempts */
				$line .= ' · ' . sprintf( _n( '%d deneme', '%d deneme', $tries, 'marketplace-orders-for-woocommerce' ), $tries );
			}
			$at = (string) $order->get_meta( $p['at'] );
			if ( '' !== $at ) {
				$line .= ' · ' . get_date_from_gmt( $at, 'd.m.Y H:i' );
			}
			$add( $p['label'], $line );
			$add( __( 'Son hata', 'marketplace-orders-for-woocommerce' ), Eru_MPO_Logger::redact( (string) $order->get_meta( $p['error'] ) ) );
			if ( 'yes' === (string) $order->get_meta( '_eru_mpo_hb_pack_noted' ) && 'packed' !== $state ) {
				$add( __( 'Paketleme', 'marketplace-orders-for-woocommerce' ), __( 'Belirsiz: siparişin bir paketi var ama satırları görünmüyor; Hepsiburada panelinden kontrol edin.', 'marketplace-orders-for-woocommerce' ) );
			}
		}
		$by = array_map( 'intval', self::as_list( $order->get_meta( '_eru_mpo_replaced_by' ) ) );
		if ( $by ) {
			$add( __( 'Yerine geçen', 'marketplace-orders-for-woocommerce' ), implode( ', ', array_map( array( __CLASS__, 'order_link' ), $by ) ), true );
		}
		$rep = array_map( 'intval', self::as_list( $order->get_meta( '_eru_mpo_replaces' ) ) );
		if ( $rep ) {
			$add( __( 'Yerine geçtiği', 'marketplace-orders-for-woocommerce' ), implode( ', ', array_map( array( __CLASS__, 'order_link' ), $rep ) ), true );
		}
		$orig = (string) $order->get_meta( '_eru_mpo_original_total' );
		if ( '' !== $orig ) {
			$add( __( 'Önceki toplam', 'marketplace-orders-for-woocommerce' ), wp_strip_all_tags( wc_price( (float) $orig, array( 'currency' => $order->get_currency() ) ) ) );
		}
		$noted = self::as_list( $order->get_meta( '_eru_mpo_noted_lines' ) );
		if ( $noted ) {
			/* translators: %d: line count */
			$add( __( 'Sonradan gelen satırlar', 'marketplace-orders-for-woocommerce' ), sprintf( __( '%d satır not edildi (siparişe eklenmedi)', 'marketplace-orders-for-woocommerce' ), count( $noted ) ) );
		}
		$imported = (string) $order->get_meta( '_eru_mpo_imported_at' );
		$add( __( 'Aktarıldı', 'marketplace-orders-for-woocommerce' ), '' !== $imported ? get_date_from_gmt( $imported, 'd.m.Y H:i' ) : '' );
		$add( __( 'Taşındı', 'marketplace-orders-for-woocommerce' ), '' !== (string) $order->get_meta( '_eru_mpo_migrated' ) ? __( 'Eski eklentiden', 'marketplace-orders-for-woocommerce' ) : '' );

		echo '<div class="eru-mpo-metabox">';
		if ( 'yes' === (string) $order->get_meta( '_eru_mpo_review' ) ) {
			echo '<p class="eru-mpo-review">' . esc_html__( 'İncelenmeli: sipariş notlarına bakın.', 'marketplace-orders-for-woocommerce' ) . '</p>';
		}
		echo '<dl>';
		foreach ( $rows as $r ) {
			echo '<dt>' . esc_html( $r[0] ) . '</dt><dd>' . ( $r[2] ? wp_kses( $r[1], array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) : esc_html( (string) $r[1] ) ) . '</dd>';
		}
		echo '</dl>';
		if ( '' !== $ref && current_user_can( 'manage_woocommerce' ) && null !== Eru_MPO_Raw::get( $mp, $ref ) ) {
			echo '<p><a href="' . esc_url( Eru_MPO_Admin_Pages::raw_url( $mp, $ref ) ) . '">' . esc_html__( 'Ham veriyi göster', 'marketplace-orders-for-woocommerce' ) . '</a></p>';
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$common = ' data-order="' . (int) $order->get_id() . '" data-nonce="' . esc_attr( wp_create_nonce( 'eru_mpo_order_' . $order->get_id() ) ) . '" data-url="' . esc_url( admin_url( 'admin-post.php' ) ) . '"';
			$single = $a && method_exists( $a, 'fetch_order' );
			echo '<p class="eru-mpo-buttons"><button type="button" class="button" data-eru-mpo-do="eru_mpo_refetch"' . $common . '>' . esc_html( $single ? __( 'Yeniden çek', 'marketplace-orders-for-woocommerce' ) : __( 'Pazaryerini şimdi çek', 'marketplace-orders-for-woocommerce' ) ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $common is escaped above.
			if ( 'never' !== $mode ) {
				echo ' <button type="button" class="button" data-eru-mpo-do="eru_mpo_ack"' . $common . '>' . esc_html__( 'Pazaryerine bildir', 'marketplace-orders-for-woocommerce' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</p><p class="description">' . esc_html( $single ? __( 'Düğmeler işi arka plana kuyruğa alır. "Yeniden çek" yalnızca bu siparişi pazaryerinden okur.', 'marketplace-orders-for-woocommerce' ) : __( 'Düğmeler işi arka plana kuyruğa alır. Bu pazaryeri tek sipariş sorgusu sunmadığı için "Pazaryerini şimdi çek" pazaryerinin tüm sipariş ve yaşam döngüsü çekimini çalıştırır (tarama pencereleri içinde).', 'marketplace-orders-for-woocommerce' ) ) . '</p>';
		}
		echo '</div>';
	}
}
