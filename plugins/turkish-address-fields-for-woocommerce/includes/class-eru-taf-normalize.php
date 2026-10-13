<?php
/**
 * Pure text helpers (no WordPress calls): Turkish-aware case, address line cleanup,
 * door-number check, Turkish phone normalising.
 *
 * @package turkish-address-fields-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_TAF_Normalize {

	/** Turkish-aware upper case: mb_strtoupper alone turns "i" into "I" instead of "İ". */
	public static function upper( string $s ): string {
		return mb_strtoupper( str_replace( array( 'i', 'ı' ), array( 'İ', 'I' ), $s ), 'UTF-8' );
	}

	/** Turkish-aware lower case: "I" → "ı", "İ" → "i". */
	public static function lower( string $s ): string {
		return mb_strtolower( str_replace( array( 'I', 'İ' ), array( 'ı', 'i' ), $s ), 'UTF-8' );
	}

	/** "BAHRİYE SOKAK" → "Bahriye Sokak" (Turkish-aware). */
	public static function title( string $s ): string {
		$parts = preg_split( '/(\s+)/u', self::lower( trim( $s ) ), -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $s;
		}
		foreach ( $parts as $i => $p ) {
			if ( '' === $p || preg_match( '/^\s+$/u', $p ) ) {
				continue;
			}
			$first       = mb_substr( $p, 0, 1, 'UTF-8' );
			$parts[ $i ] = self::upper( $first ) . mb_substr( $p, 1, null, 'UTF-8' );
		}
		return implode( '', $parts );
	}

	/**
	 * Free-text address line cleanup: single spaces, no stray punctuation at the ends,
	 * "no:13" / "NO .13" → "No: 13", "kat:2" → "Kat: 2", "d:5" / "d.5" / "daire 5" → "Daire: 5"
	 * (a bare "D 5" is left alone: it is usually a block letter, "Blok D 5"),
	 * and all-caps text → title case. Idempotent.
	 */
	public static function line( string $s ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ), " \t,;" );
		if ( '' === $s ) {
			return '';
		}
		$letters = preg_replace( '/[^\p{L}]/u', '', $s );
		if ( mb_strlen( (string) $letters, 'UTF-8' ) > 3 && self::upper( (string) $letters ) === $letters ) {
			$s = self::title( $s );
		}
		$s = (string) preg_replace( '/(?<![\p{L}])no\s*[:.]?\s*(?=\d)/iu', 'No: ', $s );
		$s = (string) preg_replace( '/(?<![\p{L}])kat\s*[:.]?\s*(?=-?\d)/iu', 'Kat: ', $s );
		$s = (string) preg_replace( '/(?<![\p{L}])(?:daire\s*[:.]?|d\s*[:.])\s*(?=\d)/iu', 'Daire: ', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Door / building number present? Any digit counts ("13A", "No: 5", "B Blok 2"). */
	public static function has_door_number( string $s ): bool {
		return 1 === preg_match( '/\d/u', $s );
	}

	/**
	 * Turkish phone as 0XXXXXXXXXX (mobile or landline); null when invalid.
	 * Accepts +90…, 90…, 0… and bare 10-digit forms with any separators.
	 */
	public static function phone( string $raw ): ?string {
		$d = (string) preg_replace( '/\D+/', '', $raw );
		if ( '' === $d ) {
			return null;
		}
		if ( str_starts_with( $d, '90' ) && 12 === strlen( $d ) ) {
			$d = substr( $d, 2 );
		} elseif ( str_starts_with( $d, '0' ) && 11 === strlen( $d ) ) {
			$d = substr( $d, 1 );
		}
		if ( 10 !== strlen( $d ) || '0' === $d[0] ) {
			return null;
		}
		return '0' . $d;
	}
}
