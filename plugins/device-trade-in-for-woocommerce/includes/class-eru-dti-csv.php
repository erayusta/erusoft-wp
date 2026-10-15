<?php
/**
 * CSV import/export for the device table.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses Turkish and plain amounts, imports rows and exports with formula neutralising.
 */
class Eru_DTI_CSV {

	const HEADERS = array( 'level1', 'level2', 'level3', 'base_value', 'active' );

	/**
	 * Parses "1.234,56", "1234.56", "1.234", "8500,5" ... into a float.
	 *
	 * @param string $s Raw cell.
	 * @return float|null Null when it is not a non-negative amount.
	 */
	public static function parse_amount( $s ) {
		$s = trim( str_ireplace( array( 'TL', '₺', "\xC2\xA0", ' ' ), '', (string) $s ) );
		if ( '' === $s || ! preg_match( '/^\d[\d.,]*$/', $s ) ) {
			return null;
		}
		$dots   = substr_count( $s, '.' );
		$commas = substr_count( $s, ',' );
		if ( $dots && $commas ) {
			$dec = strrpos( $s, ',' ) > strrpos( $s, '.' ) ? ',' : '.';
			$thou = ',' === $dec ? '.' : ',';
			if ( substr_count( $s, $dec ) > 1 ) {
				return null;
			}
			$s = str_replace( $thou, '', $s );
			$s = str_replace( $dec, '.', $s );
		} elseif ( $commas ) {
			if ( $commas > 1 ) {
				$s = str_replace( ',', '', $s );
			} elseif ( preg_match( '/^\d{1,3},\d{3}$/', $s ) ) {
				$s = str_replace( ',', '', $s ); // "1,234" reads as thousands.
			} else {
				$s = str_replace( ',', '.', $s );
			}
		} elseif ( $dots ) {
			if ( $dots > 1 || preg_match( '/^\d{1,3}\.\d{3}$/', $s ) ) {
				$s = str_replace( '.', '', $s ); // "1.234" is thousands in Turkish.
			}
		}
		return is_numeric( $s ) ? (float) $s : null;
	}

	/** @param mixed $v Cell. @return string Cell safe for spreadsheets. */
	public static function export_cell( $v ) {
		$v = (string) $v;
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/** @param string $v Cell. @return int|null 1, 0, or null when unrecognised. */
	private static function parse_bool( $v ) {
		$v = mb_strtolower( trim( (string) $v ) );
		if ( '' === $v || in_array( $v, array( '1', 'yes', 'evet', 'true', 'e', 'aktif' ), true ) ) {
			return 1;
		}
		if ( in_array( $v, array( '0', 'no', 'hayır', 'hayir', 'false', 'h', 'pasif' ), true ) ) {
			return 0;
		}
		return null;
	}

	/**
	 * Imports CSV text. Rows are matched on level1+level2+level3 and updated or added.
	 *
	 * @param string $text CSV contents.
	 * @return array{added:int,updated:int,errors:string[]}
	 */
	public static function import( $text ) {
		$res  = array( 'added' => 0, 'updated' => 0, 'errors' => array() );
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$first = strtok( $text, "\n" );
		$delim = ( false !== $first && substr_count( $first, ';' ) > substr_count( $first, ',' ) ) ? ';' : ',';
		$lines = explode( "\n", $text );
		$map   = null;
		foreach ( $lines as $i => $line ) {
			$n = $i + 1;
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line, $delim, '"', '' );
			if ( null === $map ) {
				$map = array();
				foreach ( $cells as $ci => $name ) {
					$map[ strtolower( trim( $name ) ) ] = $ci;
				}
				foreach ( array( 'level1', 'level2', 'base_value' ) as $req ) {
					if ( ! isset( $map[ $req ] ) ) {
						/* translators: %s: column name. */
						$res['errors'][] = sprintf( __( 'Satır %1$d: "%2$s" sütunu eksik.', 'device-trade-in-for-woocommerce' ), $n, $req );
						return $res;
					}
				}
				continue;
			}
			$get = fn( $k ) => isset( $map[ $k ], $cells[ $map[ $k ] ] ) ? trim( $cells[ $map[ $k ] ] ) : '';
			$l1  = sanitize_text_field( $get( 'level1' ) );
			$l2  = sanitize_text_field( $get( 'level2' ) );
			$l3  = sanitize_text_field( $get( 'level3' ) );
			if ( '' === $l1 || '' === $l2 ) {
				/* translators: %d: line number. */
				$res['errors'][] = sprintf( __( 'Satır %d: marka ve model boş olamaz.', 'device-trade-in-for-woocommerce' ), $n );
				continue;
			}
			$amount = self::parse_amount( $get( 'base_value' ) );
			if ( null === $amount ) {
				/* translators: 1: line number, 2: raw value. */
				$res['errors'][] = sprintf( __( 'Satır %1$d: geçersiz tutar "%2$s".', 'device-trade-in-for-woocommerce' ), $n, mb_substr( $get( 'base_value' ), 0, 30 ) );
				continue;
			}
			$active = self::parse_bool( $get( 'active' ) );
			if ( null === $active ) {
				/* translators: %d: line number. */
				$res['errors'][] = sprintf( __( 'Satır %d: "active" değeri anlaşılamadı.', 'device-trade-in-for-woocommerce' ), $n );
				continue;
			}
			$existing = Eru_DTI_DB::device_find( $l1, $l2, $l3 );
			if ( $existing ) {
				Eru_DTI_DB::device_update( (int) $existing['id'], array( 'base_value' => $amount, 'active' => $active ) );
				++$res['updated'];
			} else {
				Eru_DTI_DB::device_insert( array( 'level1' => $l1, 'level2' => $l2, 'level3' => $l3, 'base_value' => $amount, 'active' => $active ) );
				++$res['added'];
			}
		}
		return $res;
	}

	/** @return string CSV text with a UTF-8 BOM. */
	public static function export() {
		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, self::HEADERS, ',', '"', '' );
		foreach ( Eru_DTI_DB::devices_all() as $d ) {
			fputcsv(
				$fh,
				array(
					self::export_cell( $d['level1'] ),
					self::export_cell( $d['level2'] ),
					self::export_cell( $d['level3'] ),
					number_format( (float) $d['base_value'], 2, '.', '' ),
					(int) $d['active'],
				),
				',',
				'"',
				''
			);
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh );
		return "\xEF\xBB\xBF" . $out;
	}
}
