<?php
/**
 * Bundled address data: il → ilçe (data/il-ilce.json) and, per ilçe, mahalle + cadde/sokak
 * (data/ilce/<ilçe id>.txt). Read lazily — only the requested ilçe file is opened.
 *
 * District file format, one line per mahalle:
 *   <mahalle id> TAB <mahalle name> TAB <street>|<street>|…
 * Street suffix codes (keep files small): ~ " Sk.", @ " Cd.", = " Küme Evler", $ " Sokağı", % " Blv.".
 *
 * @package turkish-address-fields-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_TAF_Data {

	const SUFFIX = array(
		'~' => ' Sk.',
		'@' => ' Cd.',
		'=' => ' Küme Evler',
		'$' => ' Sokağı',
		'%' => ' Blv.',
	);

	private static ?array $provinces = null;
	private static array $districts  = array();

	public static function dir(): string {
		return trailingslashit( (string) apply_filters( 'eru_taf_data_dir', ERU_TAF_PATH . 'data/' ) );
	}

	/** "TR06" → "06"; '' when it is not a Turkish province code. */
	public static function plate( string $state ): string {
		return preg_match( '/^TR(\d{2})$/', strtoupper( trim( $state ) ), $m ) ? $m[1] : '';
	}

	/** plate => ['name' => İl, 'districts' => [[id, İlçe], …]] */
	public static function provinces(): array {
		if ( null === self::$provinces ) {
			$raw             = is_readable( self::dir() . 'il-ilce.json' ) ? (string) file_get_contents( self::dir() . 'il-ilce.json' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.
			$json            = json_decode( $raw, true );
			self::$provinces = is_array( $json ) ? $json : array();
		}
		return self::$provinces;
	}

	/** @return array<int, array{0:int,1:string}> */
	public static function districts( string $plate ): array {
		$p = self::provinces()[ $plate ] ?? null;
		return is_array( $p ) && isset( $p['districts'] ) ? (array) $p['districts'] : array();
	}

	public static function province_name( string $plate ): string {
		return (string) ( self::provinces()[ $plate ]['name'] ?? '' );
	}

	/** District id by name within a province (0 if not found). */
	public static function district_id( string $plate, string $name ): int {
		foreach ( self::districts( $plate ) as $d ) {
			if ( (string) $d[1] === $name ) {
				return (int) $d[0];
			}
		}
		return 0;
	}

	/** Does this district id belong to any province? (AJAX input check) */
	public static function district_exists( int $id ): bool {
		return $id > 0 && is_readable( self::dir() . 'ilce/' . $id . '.txt' );
	}

	/** mahalle id => [name, encoded streets] for one district. */
	private static function lines( int $district_id ): array {
		if ( isset( self::$districts[ $district_id ] ) ) {
			return self::$districts[ $district_id ];
		}
		$out  = array();
		$file = self::dir() . 'ilce/' . $district_id . '.txt';
		if ( $district_id > 0 && is_readable( $file ) ) {
			foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.
				$parts = explode( "\t", $line );
				if ( count( $parts ) < 2 || ! ctype_digit( $parts[0] ) ) {
					continue;
				}
				$out[ (int) $parts[0] ] = array( $parts[1], $parts[2] ?? '' );
			}
		}
		// Keep at most a few districts in memory per request.
		if ( count( self::$districts ) > 8 ) {
			self::$districts = array();
		}
		self::$districts[ $district_id ] = $out;
		return $out;
	}

	/** @return array<int, array{0:int,1:string}> */
	public static function neighborhoods( int $district_id ): array {
		$out = array();
		foreach ( self::lines( $district_id ) as $id => $row ) {
			$out[] = array( $id, $row[0] );
		}
		return $out;
	}

	public static function neighborhood_id( int $district_id, string $name ): int {
		foreach ( self::lines( $district_id ) as $id => $row ) {
			if ( $row[0] === $name ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/** @return string[] */
	public static function streets( int $district_id, int $neighborhood_id ): array {
		$row = self::lines( $district_id )[ $neighborhood_id ] ?? null;
		if ( ! $row || '' === $row[1] ) {
			return array();
		}
		$out = array();
		foreach ( explode( '|', $row[1] ) as $s ) {
			$last  = substr( $s, -1 );
			$out[] = isset( self::SUFFIX[ $last ] ) ? substr( $s, 0, -1 ) . self::SUFFIX[ $last ] : $s;
		}
		return $out;
	}
}
