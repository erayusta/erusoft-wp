<?php
/** Settings storage. Option `eru_wib_settings` (non-autoloaded); disabled by default. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WIB_Settings {

	const OPTION = 'eru_wib_settings';

	public static function defaults(): array {
		return array(
			'enabled'      => 0,
			'title'        => __( 'Toplu alım için teklif isteyin', 'wholesale-inquiry-box-for-woocommerce' ),
			'text'         => __( 'Yüksek adetli alımlar için size özel fiyat teklifi hazırlayalım.', 'wholesale-inquiry-box-for-woocommerce' ),
			'button'       => __( 'Teklif iste', 'wholesale-inquiry-box-for-woocommerce' ),
			'min_qty'      => 10,
			'email'        => '',
			'category_ids' => array(),
			'whatsapp'     => '',
			'wa_template'  => __( 'Merhaba, {urun} ({sku}) ürünü için toplu alım teklifi almak istiyorum. {link}', 'wholesale-inquiry-box-for-woocommerce' ),
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** Recipient: the configured address, or the site admin e-mail. */
	public static function recipient(): string {
		$email = sanitize_email( (string) self::get()['email'] );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	public static function sanitize( $in ): array {
		$in = is_array( $in ) ? $in : array();
		$d  = self::defaults();
		$s  = fn( $k ) => isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';

		$cats = array();
		foreach ( (array) ( $in['category_ids'] ?? array() ) as $id ) {
			if ( is_scalar( $id ) && (int) $id > 0 && term_exists( (int) $id, 'product_cat' ) ) {
				$cats[] = (int) $id;
			}
		}
		$wa = preg_replace( '/\D/', '', $s( 'whatsapp' ) );

		return array(
			'enabled'      => ! empty( $in['enabled'] ) ? 1 : 0,
			'title'        => '' !== trim( $s( 'title' ) ) ? sanitize_text_field( $s( 'title' ) ) : $d['title'],
			'text'         => sanitize_textarea_field( $s( 'text' ) ),
			'button'       => '' !== trim( $s( 'button' ) ) ? sanitize_text_field( $s( 'button' ) ) : $d['button'],
			'min_qty'      => max( 1, (int) $s( 'min_qty' ) ),
			'email'        => sanitize_email( $s( 'email' ) ),
			'category_ids' => array_values( array_unique( $cats ) ),
			'whatsapp'     => (string) $wa,
			'wa_template'  => '' !== trim( $s( 'wa_template' ) ) ? sanitize_textarea_field( $s( 'wa_template' ) ) : $d['wa_template'],
		);
	}
}
