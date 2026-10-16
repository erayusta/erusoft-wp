<?php
/**
 * Warnings go to the WooCommerce logger (source "eru-stc"), never to error_log. Only real anomalies are
 * logged (a cycle in the hierarchy, a failed write), not routine runs.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Log {

	/**
	 * @param string $message Message (no personal data).
	 * @param array  $context Extra context (ids only).
	 */
	public static function warning( $message, array $context = array() ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array_merge( $context, array( 'source' => 'eru-stc' ) ) );
		}
	}
}
