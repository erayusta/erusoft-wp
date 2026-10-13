<?php
/**
 * Carrier catalogue, per-carrier settings and rate calculation.
 *
 * @package turkish-shipping-carriers-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_TSC_Carriers {

	const OPTION          = 'eru_tsc_carriers';
	const SETTINGS_OPTION = 'eru_tsc_settings';
	const PLACEHOLDER     = '{takip_no}';

	/**
	 * Built-in carriers. Names are the carriers' own trade names (integration names only).
	 * Tracking URL templates point to each carrier's public tracking page; carriers change
	 * these pages from time to time, so every template is editable in the settings.
	 * `custom1`/`custom2` are free slots for any other carrier.
	 */
	public static function catalogue(): array {
		$list = array(
			'yurtici'          => array( 'name' => 'Yurtiçi Kargo', 'tracking_url' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code={takip_no}' ),
			'aras'             => array( 'name' => 'Aras Kargo', 'tracking_url' => 'https://araskargo.com.tr/trmnet/takip?kargo_takip_no={takip_no}' ),
			'mng'              => array( 'name' => 'MNG Kargo', 'tracking_url' => 'https://www.mngkargo.com.tr/gonderi-takip?code={takip_no}' ),
			'ptt'              => array( 'name' => 'PTT Kargo', 'tracking_url' => 'https://gonderitakip.ptt.gov.tr/Track/Verify?q={takip_no}' ),
			'surat'            => array( 'name' => 'Sürat Kargo', 'tracking_url' => 'https://www.suratkargo.com.tr/KargoTakip/?kargotakipno={takip_no}' ),
			'hepsijet'         => array( 'name' => 'HepsiJET', 'tracking_url' => 'https://www.hepsijet.com/gonderi-takip/{takip_no}' ),
			'trendyol_express' => array( 'name' => 'Trendyol Express', 'tracking_url' => '' ),
			'sendeo'           => array( 'name' => 'Sendeo', 'tracking_url' => 'https://www.sendeo.com.tr/gonderi-takip?code={takip_no}' ),
			'custom1'          => array( 'name' => '', 'tracking_url' => '' ),
			'custom2'          => array( 'name' => '', 'tracking_url' => '' ),
		);
		return (array) apply_filters( 'eru_tsc_catalogue', $list );
	}

	/** Defaults for one carrier: disabled, so nothing is offered until the merchant enables a carrier. */
	public static function carrier_defaults( string $id ): array {
		$cat = self::catalogue()[ $id ] ?? array( 'name' => '', 'tracking_url' => '' );
		return array(
			'enabled'      => 0,
			'name'         => (string) $cat['name'],
			'mode'         => 'flat',
			'cost'         => '0',
			'per_desi'     => '0',
			'free_over'    => '0',
			'tracking_url' => (string) $cat['tracking_url'],
		);
	}

	/** All carriers with saved settings merged over defaults, in catalogue order. */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( array_keys( self::catalogue() ) as $id ) {
			$row          = is_array( $saved[ $id ] ?? null ) ? $saved[ $id ] : array();
			$out[ $id ]   = array_merge( self::carrier_defaults( $id ), array_intersect_key( $row, self::carrier_defaults( $id ) ) );
			$out[ $id ]['id'] = $id;
		}
		return $out;
	}

	/** Enabled carriers that have a name. */
	public static function enabled(): array {
		return array_filter( self::all(), fn( $c ) => ! empty( $c['enabled'] ) && '' !== trim( (string) $c['name'] ) );
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/** Display name of a carrier id ('' for unknown ids). */
	public static function name( string $id ): string {
		$c = self::get( $id );
		return $c ? (string) $c['name'] : '';
	}

	public static function settings(): array {
		$s = get_option( self::SETTINGS_OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'notify_customer' => empty( $s['notify_customer'] ) ? 0 : 1,
			'show_in_emails'  => array_key_exists( 'show_in_emails', $s ) ? ( empty( $s['show_in_emails'] ) ? 0 : 1 ) : 1,
		);
	}

	/* ---- sanitising ---- */

	public static function sanitize_carriers( $in ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( array_keys( self::catalogue() ) as $id ) {
			$row        = is_array( $in[ $id ] ?? null ) ? $in[ $id ] : array();
			$out[ $id ] = array(
				'enabled'      => empty( $row['enabled'] ) ? 0 : 1,
				'name'         => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'mode'         => 'desi' === ( $row['mode'] ?? '' ) ? 'desi' : 'flat',
				'cost'         => self::money( $row['cost'] ?? '0' ),
				'per_desi'     => self::money( $row['per_desi'] ?? '0' ),
				'free_over'    => self::money( $row['free_over'] ?? '0' ),
				'tracking_url' => self::sanitize_template( (string) ( $row['tracking_url'] ?? '' ) ),
			);
		}
		return $out;
	}

	/** Non-negative decimal as string ("120", "12.5"); Turkish decimal comma accepted. */
	public static function money( $v ): string {
		$v = str_replace( ',', '.', trim( (string) $v ) );
		$v = (float) wc_format_decimal( $v );
		return (string) max( 0, $v );
	}

	/**
	 * Tracking URL template: an http(s) URL; `{takip_no}` is kept (esc_url_raw would strip the braces).
	 */
	public static function sanitize_template( string $tpl ): string {
		$tpl = trim( $tpl );
		if ( '' === $tpl ) {
			return '';
		}
		$marker = 'ERUTSCNUMBERMARK';
		$url    = esc_url_raw( str_replace( self::PLACEHOLDER, $marker, $tpl ), array( 'http', 'https' ) );
		return str_replace( $marker, self::PLACEHOLDER, $url );
	}

	/* ---- tracking URL ---- */

	public static function tracking_url( string $carrier_id, string $number ): string {
		$number = trim( $number );
		$c      = self::get( $carrier_id );
		$tpl    = $c ? (string) $c['tracking_url'] : '';
		$url    = ( '' === $tpl || '' === $number ) ? '' : str_replace( self::PLACEHOLDER, rawurlencode( $number ), $tpl );
		return (string) apply_filters( 'eru_tsc_tracking_url', $url, $carrier_id, $number );
	}

	/* ---- rates ---- */

	/**
	 * Package desi: per line max(weight in kg, L×W×H in cm / 3000) × quantity.
	 * Lines without weight and dimensions add 0.
	 */
	public static function package_desi( array $package ): float {
		$total = 0.0;
		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$qty    = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$weight = (float) $product->get_weight();
			$weight = $weight > 0 ? (float) wc_get_weight( $weight, 'kg' ) : 0.0;
			$l      = (float) $product->get_length();
			$w      = (float) $product->get_width();
			$h      = (float) $product->get_height();
			$vol    = ( $l > 0 && $w > 0 && $h > 0 ) ? ( wc_get_dimension( $l, 'cm' ) * wc_get_dimension( $w, 'cm' ) * wc_get_dimension( $h, 'cm' ) ) / 3000 : 0.0;
			$total += max( $weight, $vol ) * $qty;
		}
		return (float) apply_filters( 'eru_tsc_package_desi', $total, $package );
	}

	/** Goods amount used for the free-shipping threshold: line totals after discounts, excluding tax. */
	public static function package_amount( array $package ): float {
		$amount = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;
		return (float) apply_filters( 'eru_tsc_free_shipping_amount', $amount, $package );
	}

	/** Shipping cost of one carrier for a package. */
	public static function cost( array $carrier, array $package ): float {
		$free_over = (float) $carrier['free_over'];
		if ( $free_over > 0 && self::package_amount( $package ) >= $free_over ) {
			$cost = 0.0;
		} elseif ( 'desi' === $carrier['mode'] ) {
			$cost = (float) $carrier['cost'] + (float) $carrier['per_desi'] * ceil( round( self::package_desi( $package ), 2 ) );
		} else {
			$cost = (float) $carrier['cost'];
		}
		return (float) apply_filters( 'eru_tsc_rate_cost', max( 0.0, $cost ), $carrier, $package );
	}
}
