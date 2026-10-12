<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ortak: gönderi türü, ayarlar ve yardımcılar. */
class Eru_Bcb {

	public const POST_TYPE = 'eru_bcb_banner';
	public const OPTION    = 'eru_bcb_options';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Marka Bannerları', 'brand-coupon-banners-for-woocommerce' ),
					'singular_name'      => __( 'Marka Bannerı', 'brand-coupon-banners-for-woocommerce' ),
					'menu_name'          => __( 'Marka Bannerları', 'brand-coupon-banners-for-woocommerce' ),
					'add_new'            => __( 'Yeni ekle', 'brand-coupon-banners-for-woocommerce' ),
					'add_new_item'       => __( 'Yeni banner ekle', 'brand-coupon-banners-for-woocommerce' ),
					'edit_item'          => __( 'Banner düzenle', 'brand-coupon-banners-for-woocommerce' ),
					'new_item'           => __( 'Yeni banner', 'brand-coupon-banners-for-woocommerce' ),
					'search_items'       => __( 'Banner ara', 'brand-coupon-banners-for-woocommerce' ),
					'not_found'          => __( 'Banner bulunamadı', 'brand-coupon-banners-for-woocommerce' ),
					'not_found_in_trash' => __( 'Çöp kutusunda banner yok', 'brand-coupon-banners-for-woocommerce' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-tickets-alt',
				'capability_type' => 'post',
				'supports'        => array( 'title' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	public static function defaults(): array {
		return array(
			'taxonomy'      => taxonomy_exists( 'product_brand' ) ? 'product_brand' : '',
			'show_archive'  => 'yes',
			'show_single'   => 'yes',
			'bg'            => '#1d2327',
			'color'         => '#ffffff',
			'create_coupon' => 'yes',
		);
	}

	public static function options(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** Marka olarak seçilebilecek ürün sınıflandırmaları (slug => nesne). */
	public static function selectable_taxonomies(): array {
		$out = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $slug => $tax ) {
			if ( $tax->show_ui && ! in_array( $slug, array( 'product_type', 'product_visibility', 'product_shipping_class', 'product_cat', 'product_tag' ), true ) ) {
				$out[ $slug ] = $tax;
			}
		}
		return $out;
	}

	/** Ayarlarda seçili marka sınıflandırması; geçerli değilse ''. */
	public static function brand_taxonomy(): string {
		$tax = (string) self::options()['taxonomy'];
		return '' !== $tax && taxonomy_exists( $tax ) ? $tax : '';
	}

	/** Kayıtlı alan değerleri (öntanımlılarla). */
	public static function banner_meta( int $post_id ): array {
		$g    = fn( string $k ) => get_post_meta( $post_id, '_eru_bcb_' . $k, true );
		$cats = $g( 'cats' );
		$brs  = $g( 'brands' );
		return array(
			'code'     => (string) $g( 'code' ),
			'type'     => (string) ( $g( 'type' ) ?: 'percent' ),
			'amount'   => (string) $g( 'amount' ),
			'start'    => (string) $g( 'start' ),
			'end'      => (string) $g( 'end' ),
			'cats'     => is_array( $cats ) ? array_map( 'intval', $cats ) : array(),
			'brands'   => is_array( $brs ) ? array_map( 'intval', $brs ) : array(),
			'active'   => '1' === (string) $g( 'active' ),
			'desktop'  => (int) $g( 'desktop' ),
			'mobile'   => (int) $g( 'mobile' ),
			'link'     => (string) $g( 'link' ),
			'position' => (int) $g( 'position' ),
			'text'     => (string) $g( 'text' ),
			'bg'       => (string) $g( 'bg' ),
			'color'    => (string) $g( 'color' ),
		);
	}

	/** datetime-local değerini site saat diliminde zaman damgasına çevirir; geçersizse null. */
	public static function parse_time( string $value ): ?int {
		if ( '' === $value ) {
			return null;
		}
		$dt = date_create_immutable( $value, wp_timezone() );
		return $dt ? $dt->getTimestamp() : null;
	}
}
