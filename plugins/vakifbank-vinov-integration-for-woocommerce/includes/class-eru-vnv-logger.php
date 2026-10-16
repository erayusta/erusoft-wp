<?php
/**
 * WooCommerce logger (source "eru-vnv") with redaction.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every message passes redact() before it reaches the WooCommerce logger: stored client ids, secrets and the
 * consent id, bearer tokens, credential/identity/OTP fields, 11-digit runs (identity numbers), IBANs and phone
 * numbers are removed or masked. No context is passed beyond the source, so nothing unredacted reaches the
 * log file. The level setting (off, error, info, debug; default error) limits what is written.
 */
class Eru_VNV_Logger {

	const SOURCE = 'eru-vnv';
	const LEVELS = array(
		'error' => 1,
		'info'  => 2,
		'debug' => 3,
	);

	/** @param string $text Text. @return string Redacted text. */
	public static function redact( $text ) {
		$text = (string) $text;
		foreach ( Eru_VNV_Settings::secrets() as $s ) {
			$text = str_replace( $s, '[gizli]', $text );
		}
		$text = (string) preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/i', '$1 [gizli]', $text );
		$text = (string) preg_replace( '/("?\b(?:password|[a-z_]*secret|[a-z_]*token|api_key|authorization|identitynumber|identitytaxnumber|tckn|iban|otp|otpcode|consentid|client_id)"?\s*(?:=|:)\s*"?)[^&\s",}]+/i', '$1[gizli]', $text );
		$text = (string) preg_replace_callback( '/\bTR\s?\d{2}(?:\s?\d){22}(?!\d)/i', fn( $m ) => substr( $m[0], 0, 2 ) . '** … ' . substr( preg_replace( '/\s+/', '', $m[0] ), -4 ), $text );
		$text = (string) preg_replace( '/(?<!\d)\d{11}(?!\d)/', '[gizli-11]', $text );
		return (string) preg_replace_callback(
			'/(?<![\d])(?:\+?90|0)?[\s.]?\(?5\d{2}\)?[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}(?!\d)/',
			fn( $m ) => '5** *** ** ' . substr( preg_replace( '/\D+/', '', $m[0] ), -2 ),
			$text
		);
	}

	/**
	 * Writes one message when the configured level allows it.
	 *
	 * @param string $level   error | info | debug.
	 * @param string $message Message (redacted here).
	 */
	public static function log( $level, $message ) {
		$level = isset( self::LEVELS[ $level ] ) ? $level : 'info';
		$max   = Eru_VNV_Settings::log_level();
		if ( 'off' === $max || self::LEVELS[ $level ] > self::LEVELS[ $max ] || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, self::redact( $message ), array( 'source' => self::SOURCE ) );
	}

	/** @param string $message Message. */
	public static function error( $message ) {
		self::log( 'error', $message );
	}

	/** @param string $message Message. */
	public static function info( $message ) {
		self::log( 'info', $message );
	}

	/** @param string $message Message. */
	public static function debug( $message ) {
		self::log( 'debug', $message );
	}
}
