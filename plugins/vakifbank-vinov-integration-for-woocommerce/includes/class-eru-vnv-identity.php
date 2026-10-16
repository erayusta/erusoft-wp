<?php
/**
 * Identity number and phone helpers: checksum, keyed hashes, masks.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The identity number (TCKN) is never stored. The WooCommerce session keeps an HMAC of it (key derived from
 * the site's auth salt) and the order keeps a mask with the first two and last two digits.
 */
class Eru_VNV_Identity {

	/**
	 * Checksum of a Turkish identity number: 11 digits, first not 0, 10th and 11th digits computed.
	 *
	 * @param string $tckn Candidate.
	 * @return bool
	 */
	public static function valid_tckn( $tckn ) {
		$tckn = (string) $tckn;
		if ( ! preg_match( '/^[1-9]\d{10}$/', $tckn ) ) {
			return false;
		}
		$d    = array_map( 'intval', str_split( $tckn ) );
		$odd  = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
		$even = $d[1] + $d[3] + $d[5] + $d[7];
		$d10  = ( ( $odd * 7 - $even ) % 10 + 10 ) % 10;
		$d11  = array_sum( array_slice( $d, 0, 10 ) ) % 10;
		return $d10 === $d[9] && $d11 === $d[10];
	}

	/** @param mixed $raw Posted value. @return string Digits only (max 11). */
	public static function clean_tckn( $raw ) {
		return substr( preg_replace( '/\D+/', '', is_scalar( $raw ) ? (string) $raw : '' ), 0, 11 );
	}

	/** @return string HMAC key (never stored). */
	private static function key() {
		return hash_hmac( 'sha256', 'eru_vnv_identity', wp_salt( 'auth' ) );
	}

	/** @param string $tckn Identity number. @return string HMAC-SHA256 (hex). */
	public static function hmac( $tckn ) {
		return hash_hmac( 'sha256', 'tckn|' . (string) $tckn, self::key() );
	}

	/** @param string $tckn Identity number. @return string "12*******34". */
	public static function mask( $tckn ) {
		$tckn = (string) $tckn;
		return strlen( $tckn ) < 5 ? str_repeat( '*', strlen( $tckn ) ) : substr( $tckn, 0, 2 ) . str_repeat( '*', strlen( $tckn ) - 4 ) . substr( $tckn, -2 );
	}

	/**
	 * Turkish mobile number as ten digits ("5XXXXXXXXX"); accepts 0 / 90 / +90 prefixes and any grouping.
	 *
	 * @param mixed $raw Posted value.
	 * @return string '' when invalid.
	 */
	public static function normalize_phone( $raw ) {
		$d = preg_replace( '/\D+/', '', is_scalar( $raw ) ? (string) $raw : '' );
		if ( 12 === strlen( $d ) && 0 === strpos( $d, '90' ) ) {
			$d = substr( $d, 2 );
		} elseif ( 11 === strlen( $d ) && '0' === $d[0] ) {
			$d = substr( $d, 1 );
		}
		return preg_match( '/^5\d{9}$/', $d ) ? $d : '';
	}

	/** @param string $phone Normalised phone. @return string HMAC-SHA256 (hex). */
	public static function phone_hash( $phone ) {
		return hash_hmac( 'sha256', 'phone|' . (string) $phone, self::key() );
	}
}
