<?php
/**
 * İYS API log: redacted request/response bodies, 8 KB cap, kept for the configured number of days (default 7)
 * and purged by the daily retention job.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing secret or personal is stored: passwords, tokens and Authorization values are removed,
 * e-mail addresses and phone numbers are masked before insert.
 */
class Eru_CMI_Logger {

	const MAX_BYTES = 8192;

	/**
	 * Removes credentials and masks recipients.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact( $text ) {
		$text    = (string) $text;
		$secrets = array();
		foreach ( Eru_CMI_Settings::ENVS as $env ) {
			$secrets[] = Eru_CMI_Settings::cred( 'password', $env );
		}
		foreach ( $secrets as $s ) {
			if ( strlen( $s ) >= 4 ) {
				$text = str_replace( $s, '[gizli]', $text );
			}
		}
		$text = preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/i', '$1 [gizli]', $text );
		$text = preg_replace( '/("?(?:password|secret|[a-z_]*token|api_key|authorization|identitynumber|identitytaxnumber|tckn|iban)"?\s*(?:=|:)\s*"?)[^&\s",}]+/i', '$1[gizli]', $text );
		$text = preg_replace_callback(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			fn( $m ) => Eru_CMI_Recipient::mask( $m[0] ),
			$text
		);
		// Turkish numbers (+90 / 90 / 0 prefix or bare 10 digits); dates and request ids do not match.
		return (string) preg_replace_callback(
			'/(?<![\d])(?:\+?90|0)?[\s.]?\(?[2-5]\d{2}\)?[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}(?!\d)/',
			fn( $m ) => Eru_CMI_Recipient::mask( $m[0] ),
			$text
		);
	}

	/** @param string $text Text. @return string Redacted and cut to 8 KB (UTF-8 safe). */
	private static function cap( $text ) {
		$text = self::redact( $text );
		return strlen( $text ) > self::MAX_BYTES ? mb_strcut( $text, 0, self::MAX_BYTES - 3, 'UTF-8' ) . '...' : $text;
	}

	/**
	 * Writes one API call.
	 *
	 * @param string      $env      sandbox | live.
	 * @param string      $method   HTTP method.
	 * @param string      $endpoint Path (with query).
	 * @param string|null $request  Request body (null = not logged).
	 * @param int         $code     HTTP status (0 = transport error).
	 * @param string      $response Response body or transport error.
	 * @param int         $ms       Duration.
	 */
	public static function api( $env, $method, $endpoint, $request, $code, $response, $ms ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Eru_CMI_Installer::table( 'log' ),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'env'         => substr( (string) $env, 0, 10 ),
				'method'      => substr( strtoupper( (string) $method ), 0, 8 ),
				'endpoint'    => substr( self::redact( (string) $endpoint ), 0, 255 ),
				'http_code'   => max( 0, (int) $code ),
				'duration_ms' => max( 0, (int) $ms ),
				'request'     => null === $request ? null : self::cap( (string) $request ),
				'response'    => self::cap( (string) $response ),
			)
		);
		if ( class_exists( 'Eru_CMI_Scheduler' ) ) {
			Eru_CMI_Scheduler::arm_purge();
		}
	}

	/** Deletes rows older than the retention period, in batches. @return int Deleted rows. */
	public static function purge( $batches = 20 ) {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - Eru_CMI_Settings::log_days() * DAY_IN_SECONDS );
		$n   = 0;
		for ( $i = 0; $i < $batches; $i++ ) {
			$d  = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_CMI_Installer::table( 'log' ) . ' WHERE created_at < %s ORDER BY id ASC LIMIT 1000', $cut ) ); // phpcs:ignore
			$n += $d;
			if ( $d < 1000 ) {
				break;
			}
		}
		return $n;
	}

	/** @return bool The log has rows. */
	public static function has_rows() {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . Eru_CMI_Installer::table( 'log' ) . ' LIMIT 1' ); // phpcs:ignore
	}

	/** @param int $limit Rows. @return array Latest rows. */
	public static function recent( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Eru_CMI_Installer::table( 'log' ) . ' ORDER BY id DESC LIMIT %d', max( 1, min( 200, (int) $limit ) ) ) ) ?: array(); // phpcs:ignore
	}

	/**
	 * Latest failed calls of an environment (HTTP status 0 or ≥ 400) for the Kuyruk tab; bodies are redacted on
	 * insert already.
	 *
	 * @param string $env   Environment.
	 * @param int    $limit Rows.
	 * @return array
	 */
	public static function recent_errors( $env, $limit = 10 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, method, endpoint, http_code, response FROM ' . Eru_CMI_Installer::table( 'log' ) . ' WHERE env = %s AND ( http_code = 0 OR http_code >= 400 ) ORDER BY id DESC LIMIT %d', (string) $env, max( 1, min( 50, (int) $limit ) ) ) ) ?: array(); // phpcs:ignore
	}
}
