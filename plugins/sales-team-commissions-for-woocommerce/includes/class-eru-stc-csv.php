<?php
/**
 * CSV writing for exports. Every cell that a spreadsheet could read as a formula (starting with = + - @,
 * a tab or a carriage return) is prefixed with an apostrophe; plain numbers such as "-4.00" stay numbers.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_CSV {

	/**
	 * @param mixed $value Cell value.
	 * @return string Neutralised cell.
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		if ( '' === $value || 1 === preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return $value;
		}
		return in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}

	/**
	 * CSV text (UTF-8 with BOM so spreadsheet programs read Turkish characters).
	 *
	 * @param string[] $header Column titles.
	 * @param array[]  $rows   Rows.
	 * @return string
	 */
	public static function build( array $header, array $rows ) {
		$fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $fh, array_map( array( __CLASS__, 'cell' ), $header ), ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'cell' ), array_values( $row ) ), ',', '"', '' );
		}
		rewind( $fh );
		$out = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/**
	 * Sends a CSV download.
	 *
	 * @param string $filename File name.
	 * @param string $csv      Content.
	 */
	public static function send( $filename, $csv ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells neutralised.
	}
}
