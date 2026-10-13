<?php
/**
 * Pure text helpers (no WordPress calls): phone normalising, placeholders, SMS segment count, code generation.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Text {

	const CODE_PLACEHOLDER = '{teslimat_kodu}';

	// GSM 03.38 basic set; the extension set counts as 2 characters. Turkish ı/ş/ğ/İ switch the SMS to UCS-2 (70 per part).
	const GSM_BASIC     = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	const GSM_EXTENSION = '^{}\\[~]|€';

	/**
	 * Turkish mobile number as 905XXXXXXXXX; null when it is not a Turkish mobile number.
	 * Accepts 0090…, +90…, 90…, 0… and 5… forms with any separators.
	 */
	public static function phone( $raw ): ?string {
		$d = preg_replace( '/\D+/', '', (string) $raw );
		if ( '' === $d ) {
			return null;
		}
		if ( str_starts_with( $d, '0090' ) ) {
			$d = substr( $d, 4 );
		} elseif ( str_starts_with( $d, '90' ) && 12 === strlen( $d ) ) {
			$d = substr( $d, 2 );
		} elseif ( str_starts_with( $d, '0' ) ) {
			$d = substr( $d, 1 );
		}
		if ( 10 !== strlen( $d ) || '5' !== $d[0] ) {
			return null;
		}
		return '90' . $d;
	}

	/** Display form with the middle digits hidden: 90555***0000. */
	public static function mask_phone( string $phone ): string {
		return strlen( $phone ) >= 9 ? substr( $phone, 0, 5 ) . '***' . substr( $phone, -4 ) : $phone;
	}

	/** Replace {key} placeholders; unknown placeholders are left as they are. */
	public static function fill( $text, array $vars ): string {
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . $k . '}' ] = (string) $v;
		}
		return strtr( (string) $text, $map );
	}

	/** Log / screen form: the code placeholder is shown as asterisks. */
	public static function masked( $text ): string {
		return str_replace( self::CODE_PLACEHOLDER, '******', (string) $text );
	}

	/**
	 * Estimated SMS parts. Any non-GSM character → UCS-2 (70 / 67 per part), otherwise GSM (160 / 153).
	 *
	 * @return array{chars:int,segments:int,unicode:bool}
	 */
	public static function segments( $text ): array {
		$chars   = preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$unicode = false;
		$gsm_len = 0;
		foreach ( $chars as $c ) {
			if ( false !== mb_strpos( self::GSM_BASIC, $c ) ) {
				$gsm_len++;
			} elseif ( false !== mb_strpos( self::GSM_EXTENSION, $c ) ) {
				$gsm_len += 2;
			} else {
				$unicode = true;
			}
		}
		if ( $unicode ) {
			$n   = count( $chars );
			$seg = $n <= 70 ? 1 : (int) ceil( $n / 67 );
		} else {
			$n   = $gsm_len;
			$seg = $n <= 160 ? 1 : (int) ceil( $n / 153 );
		}
		return array(
			'chars'    => $n,
			'segments' => 0 === $n ? 0 : $seg,
			'unicode'  => $unicode,
		);
	}

	/** 6-digit delivery code; leading zeros kept. */
	public static function new_code(): string {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}
}
