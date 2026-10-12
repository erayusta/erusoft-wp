<?php
/**
 * Ring-buffer log in a single option.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the last MAX_ENTRIES import messages for the admin "Kayıt" tab.
 */
class Eru_TYR_Logger {

	const OPTION      = 'eru_tyr_log';
	const MAX_ENTRIES = 300;

	public static function log( string $message, string $level = 'info' ): void {
		$entries   = self::all();
		$entries[] = array(
			'time'    => time(),
			'level'   => in_array( $level, array( 'info', 'success', 'warning', 'error' ), true ) ? $level : 'info',
			'message' => wp_strip_all_tags( $message ),
		);
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, - self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $entries, false );
	}

	public static function all(): array {
		return (array) get_option( self::OPTION, array() );
	}
}
