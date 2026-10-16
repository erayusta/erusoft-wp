<?php
/**
 * Plugin log (table eru_mpo_log): importer, lifecycle and migrator events, redacted, kept for the log retention
 * (default 14 days) and purged by the daily job.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing secret or personal is stored: credential-looking fields and Authorization values are removed,
 * 11-digit runs (identity numbers) and IBANs are hidden, e-mail addresses and phone numbers are masked.
 */
class Eru_MPO_Logger {

	const LEVELS    = array( 'error', 'warning', 'info' );
	const MAX_CHARS = 2000;

	/** @param string $text Text. @return string Redacted text. */
	public static function redact( $text ) {
		$text = (string) $text;
		$text = (string) preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/i', '$1 [gizli]', $text );
		$text = (string) preg_replace( '/("?\b(?:password|[a-z_]*secret|[a-z_]*token|api_?key|authorization|identitynumber|identitytaxnumber|tcidentitynumber|tckn|iban)"?\s*(?:=|:)\s*"?)[^&\s",}]+/i', '$1[gizli]', $text );
		$text = (string) preg_replace( '/\bTR\s?\d{2}(?:\s?\d){22}(?!\d)/i', 'TR[gizli-iban]', $text );
		$text = (string) preg_replace( '/(?<!\d)\d{11}(?!\d)/', '[gizli-11]', $text );
		$text = (string) preg_replace_callback(
			'/([A-Z0-9._%+\-])[A-Z0-9._%+\-]*@([A-Z0-9.\-]+\.[A-Z]{2,})/i',
			fn( $m ) => $m[1] . '***@' . $m[2],
			$text
		);
		return (string) preg_replace_callback(
			'/(?<![\d])(?:\+?90|0)?[\s.]?\(?5\d{2}\)?[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}(?!\d)/',
			fn( $m ) => '5** *** ** ' . substr( (string) preg_replace( '/\D+/', '', $m[0] ), -2 ),
			$text
		);
	}

	/**
	 * Writes one row and makes sure the retention job exists.
	 *
	 * @param string $level       error | warning | info.
	 * @param string $message     Message (redacted here).
	 * @param string $marketplace Adapter key or "migrator".
	 * @param string $ref         Remote reference.
	 * @param int    $max         Message length cap (default 2000; at most 20000, used by the request log for
	 *                            its 8 KB bodies).
	 */
	public static function log( $level, $message, $marketplace = '', $ref = '', $max = self::MAX_CHARS ) {
		global $wpdb;
		$max = max( 100, min( 20000, (int) $max ) );
		$msg = self::redact( $message );
		if ( mb_strlen( $msg ) > $max ) {
			$msg = mb_substr( $msg, 0, $max - 1 ) . '…';
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Eru_MPO_Installer::table( 'log' ),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'level'       => in_array( $level, self::LEVELS, true ) ? $level : 'info',
				'marketplace' => substr( sanitize_key( (string) $marketplace ), 0, 32 ),
				'remote_ref'  => substr( (string) $ref, 0, 64 ),
				'message'     => $msg,
			)
		);
		if ( class_exists( 'Eru_MPO_Cron' ) ) {
			Eru_MPO_Cron::maybe_arm();
		}
	}

	/** @param string $m Message. @param string $mp Marketplace. @param string $ref Reference. */
	public static function info( $m, $mp = '', $ref = '' ) {
		self::log( 'info', $m, $mp, $ref );
	}

	/** @param string $m Message. @param string $mp Marketplace. @param string $ref Reference. */
	public static function warning( $m, $mp = '', $ref = '' ) {
		self::log( 'warning', $m, $mp, $ref );
	}

	/** @param string $m Message. @param string $mp Marketplace. @param string $ref Reference. */
	public static function error( $m, $mp = '', $ref = '' ) {
		self::log( 'error', $m, $mp, $ref );
	}

	/** @var bool The last purge stopped at its batch cap with rows left. */
	public static $more = false;

	/**
	 * Deletes rows older than the log retention, 1.000 per batch, up to $batches batches (filter
	 * eru_mpo_purge_batches, default 20). When the cap is reached with rows left, self::$more is set and the daily
	 * job re-runs a minute later until nothing is left.
	 *
	 * @param int|null $batches Batch cap.
	 * @return int Deleted rows.
	 */
	public static function purge( $batches = null ) {
		global $wpdb;
		$batches    = max( 1, (int) ( null === $batches ? apply_filters( 'eru_mpo_purge_batches', 20 ) : $batches ) );
		$cut        = gmdate( 'Y-m-d H:i:s', time() - Eru_MPO_Settings::days( 'log_days' ) * DAY_IN_SECONDS );
		$n          = 0;
		self::$more = false;
		for ( $i = 0; $i < $batches; $i++ ) {
			$d  = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_MPO_Installer::table( 'log' ) . ' WHERE created_at < %s ORDER BY id ASC LIMIT 1000', $cut ) ); // phpcs:ignore
			$n += $d;
			if ( $d < 1000 ) {
				return $n;
			}
		}
		self::$more = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Eru_MPO_Installer::table( 'log' ) . ' WHERE created_at < %s LIMIT 1', $cut ) ); // phpcs:ignore
		return $n;
	}

	/**
	 * A row for this package containing $fragment was written since $since (UTC).
	 *
	 * @return bool
	 */
	public static function seen_since( $marketplace, $ref, $fragment, $since ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Eru_MPO_Installer::table( 'log' ) . ' WHERE marketplace = %s AND remote_ref = %s AND created_at >= %s AND message LIKE %s LIMIT 1', substr( sanitize_key( (string) $marketplace ), 0, 32 ), substr( (string) $ref, 0, 64 ), $since, '%' . $wpdb->esc_like( $fragment ) . '%' ) ); // phpcs:ignore
	}

	/** @return bool The log has rows. */
	public static function has_rows() {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . Eru_MPO_Installer::table( 'log' ) . ' LIMIT 1' ); // phpcs:ignore
	}

	/**
	 * @param int    $limit       Rows (1–200).
	 * @param string $marketplace Filter ('' = all).
	 * @return array Latest rows.
	 */
	public static function recent( $limit = 50, $marketplace = '' ) {
		global $wpdb;
		$t     = Eru_MPO_Installer::table( 'log' );
		$limit = max( 1, min( 200, (int) $limit ) );
		if ( '' !== $marketplace ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE marketplace = %s ORDER BY id DESC LIMIT %d", $marketplace, $limit ) ) ?: array(); // phpcs:ignore
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", $limit ) ) ?: array(); // phpcs:ignore
	}
}
