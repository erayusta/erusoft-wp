<?php
/**
 * Encryption of payout details and the IBAN / TCKN validators and masks.
 *
 * The key is the wp-config constant ERU_STC_ENCRYPTION_KEY: base64 of exactly 32 random bytes. Values are
 * sealed with libsodium's secretbox (XSalsa20-Poly1305) and a fresh random nonce, and stored as
 * "v1:" . base64( nonce . box ). Without a valid key nothing is encrypted or decrypted: the TCKN and IBAN
 * fields are hidden, saves are refused and stored values read "anahtar yok". Full values never leave this
 * class except to the payout-details owner's own save and to the explicit full-IBAN export.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Crypto {

	const CONSTANT = 'ERU_STC_ENCRYPTION_KEY';
	const PREFIX   = 'v1:';

	/**
	 * Raw key from a constant value.
	 *
	 * @param mixed $value Base64 text.
	 * @return string|null 32 raw bytes, or null when the value is not base64 of exactly 32 bytes.
	 */
	public static function parse_key( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$raw = base64_decode( trim( $value ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return ( is_string( $raw ) && 32 === strlen( $raw ) ) ? $raw : null;
	}

	/** @return string|null The key, or null when it is missing or invalid. */
	private static function key() {
		return defined( self::CONSTANT ) ? self::parse_key( constant( self::CONSTANT ) ) : null;
	}

	/** @return string ok | missing | invalid | no_sodium. */
	public static function state() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'no_sodium';
		}
		if ( ! defined( self::CONSTANT ) ) {
			return 'missing';
		}
		return null === self::key() ? 'invalid' : 'ok';
	}

	/** @return bool Whether values can be encrypted and decrypted. */
	public static function available() {
		return 'ok' === self::state();
	}

	/**
	 * @param string $plain Plain text.
	 * @return string|WP_Error Stored form ("v1:" + base64).
	 */
	public static function encrypt( $plain ) {
		$key = self::available() ? self::key() : null;
		if ( null === $key ) {
			return new WP_Error( 'eru_stc_no_key', __( 'Şifreleme anahtarı tanımlı değil; ödeme bilgileri kaydedilemez.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string|null $stored Stored form.
	 * @return string|null Plain text, or null (no key, not ours, tampered or another key).
	 */
	public static function decrypt( $stored ) {
		$key = self::available() ? self::key() : null;
		if ( null === $key || ! is_string( $stored ) || 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$n   = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( ! is_string( $raw ) || strlen( $raw ) <= $n + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, $n ), substr( $raw, 0, $n ), $key );
		} catch ( SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/** @param string $iban Raw input. @return string Upper case, no spaces or dashes. */
	public static function normalize_iban( $iban ) {
		return strtoupper( (string) preg_replace( '/[\s\-]+/', '', (string) $iban ) );
	}

	/**
	 * A Turkish IBAN: "TR", 24 digits, ISO 13616 check digits (mod 97 = 1).
	 *
	 * @param string $iban Raw or normalised.
	 * @return bool
	 */
	public static function valid_iban( $iban ) {
		$iban = self::normalize_iban( $iban );
		if ( 1 !== preg_match( '/^TR\d{24}$/', $iban ) ) {
			return false;
		}
		$moved = substr( $iban, 4 ) . '2927' . substr( $iban, 2, 2 ); // T = 29, R = 27.
		$mod   = 0;
		foreach ( str_split( $moved ) as $c ) {
			$mod = ( $mod * 10 + (int) $c ) % 97;
		}
		return 1 === $mod;
	}

	/**
	 * A T.C. kimlik number: 11 digits, first not 0, both check digits right.
	 *
	 * @param string $tckn Value.
	 * @return bool
	 */
	public static function valid_tckn( $tckn ) {
		$tckn = trim( (string) $tckn );
		if ( 1 !== preg_match( '/^[1-9]\d{10}$/', $tckn ) ) {
			return false;
		}
		$d   = array_map( 'intval', str_split( $tckn ) );
		$d10 = ( ( ( $d[0] + $d[2] + $d[4] + $d[6] + $d[8] ) * 7 - ( $d[1] + $d[3] + $d[5] + $d[7] ) ) % 10 + 10 ) % 10;
		$d11 = array_sum( array_slice( $d, 0, 10 ) ) % 10;
		return $d[9] === $d10 && $d[10] === $d11;
	}

	/**
	 * IBAN mask: country code and the last four characters, everything else starred, in groups of four
	 * ("TR** **** **** **** **** **12 34").
	 *
	 * @param string $iban Value.
	 * @return string '' for an empty value; a value shorter than 10 characters is fully starred.
	 */
	public static function mask_iban( $iban ) {
		$iban = self::normalize_iban( $iban );
		$len  = strlen( $iban );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len < 10 ) {
			return str_repeat( '*', 4 );
		}
		$masked = substr( $iban, 0, 2 ) . str_repeat( '*', $len - 6 ) . substr( $iban, -4 );
		return trim( chunk_split( $masked, 4, ' ' ) );
	}

	/**
	 * TCKN mask: the first two and last two digits ("12*******34").
	 *
	 * @param string $tckn Value.
	 * @return string
	 */
	public static function mask_tckn( $tckn ) {
		$tckn = preg_replace( '/\s+/', '', (string) $tckn );
		$len  = strlen( $tckn );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len < 8 ) {
			return str_repeat( '*', 11 );
		}
		return substr( $tckn, 0, 2 ) . str_repeat( '*', $len - 4 ) . substr( $tckn, -2 );
	}

	/**
	 * Name mask: the first letter of each word ("D*** T*******").
	 *
	 * @param string $name Value.
	 * @return string
	 */
	public static function mask_name( $name ) {
		$out = array();
		foreach ( preg_split( '/\s+/u', trim( (string) $name ), -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$out[] = mb_substr( $word, 0, 1 ) . str_repeat( '*', max( 1, mb_strlen( $word ) - 1 ) );
		}
		return implode( ' ', $out );
	}

	/**
	 * E-mail mask: the first two characters of the local part and the domain ("de***@example.com").
	 *
	 * @param string $email Value.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return '' === $email ? '' : '***';
		}
		return mb_substr( substr( $email, 0, $at ), 0, 2 ) . '***' . substr( $email, $at );
	}
}
