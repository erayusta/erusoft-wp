<?php
/**
 * Raw payload store (table eru_mpo_raw): the latest payload per package, for support and field checks. Never
 * written to order meta. Rows older than the raw retention (default 30 days since the payload last changed) are
 * purged by the daily job.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Before storing, values under identity-number and credential-looking keys are replaced with "[gizli]" and
 * 11-digit strings anywhere are masked, whatever the e-Arşiv setting says (that setting governs order meta only).
 */
class Eru_MPO_Raw {

	const MAX_BYTES = 262144;
	const SECRET_KEY = '/(identity|tckn|tc_?kimlik|kimlik_?no|password|secret|token|api_?key|authorization|iban)/i';

	/**
	 * @param mixed $v Payload part.
	 * @return mixed Redacted copy.
	 */
	public static function redact( $v ) {
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $x ) {
				$out[ $k ] = ( is_string( $k ) && preg_match( self::SECRET_KEY, $k ) && ( is_scalar( $x ) && '' !== (string) $x ) ) ? '[gizli]' : self::redact( $x );
			}
			return $out;
		}
		if ( is_string( $v ) ) {
			return (string) preg_replace( '/(?<!\d)\d{11}(?!\d)/', '[gizli-11]', $v );
		}
		return $v;
	}

	/**
	 * Stores (or replaces) the payload of a package. stored_at moves only when the payload changed.
	 *
	 * @param string $marketplace Adapter key.
	 * @param string $ref         Remote reference.
	 * @param array  $payload     Raw payload.
	 */
	public static function store( $marketplace, $ref, array $payload ) {
		global $wpdb;
		if ( array() === $payload ) {
			return;
		}
		$json = (string) wp_json_encode( self::redact( $payload ), JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			$json = (string) wp_json_encode( array( '_truncated' => true, 'bytes' => strlen( $json ) ) );
		}
		$t   = Eru_MPO_Installer::table( 'raw' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$t} (marketplace, remote_ref, payload, stored_at) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE stored_at = IF(payload = VALUES(payload), stored_at, VALUES(stored_at)), payload = VALUES(payload)", // phpcs:ignore
				substr( (string) $marketplace, 0, 32 ),
				substr( (string) $ref, 0, 64 ),
				$json,
				$now
			)
		);
		if ( class_exists( 'Eru_MPO_Cron' ) ) {
			Eru_MPO_Cron::maybe_arm();
		}
	}

	/** @param string $marketplace Key. @param string $ref Reference. @return array|null Decoded payload. */
	public static function get( $marketplace, $ref ) {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . Eru_MPO_Installer::table( 'raw' ) . ' WHERE marketplace = %s AND remote_ref = %s', $marketplace, $ref ) ); // phpcs:ignore
		if ( null === $v ) {
			return null;
		}
		$d = json_decode( (string) $v, true );
		return is_array( $d ) ? $d : array();
	}

	/** @param string $marketplace Key. @param string $ref Reference. */
	public static function delete( $marketplace, $ref ) {
		global $wpdb;
		$wpdb->delete( Eru_MPO_Installer::table( 'raw' ), array( 'marketplace' => $marketplace, 'remote_ref' => $ref ) ); // phpcs:ignore
	}

	/** @var bool The last purge stopped at its batch cap with rows left. */
	public static $more = false;

	/**
	 * Deletes payloads older than the raw retention, 500 per batch, up to $batches batches (filter
	 * eru_mpo_purge_batches); see Eru_MPO_Logger::purge() for the continuation.
	 *
	 * @param int|null $batches Batch cap.
	 * @return int Deleted rows.
	 */
	public static function purge( $batches = null ) {
		global $wpdb;
		$batches    = max( 1, (int) ( null === $batches ? apply_filters( 'eru_mpo_purge_batches', 20 ) : $batches ) );
		$cut        = gmdate( 'Y-m-d H:i:s', time() - Eru_MPO_Settings::days( 'raw_days' ) * DAY_IN_SECONDS );
		$n          = 0;
		self::$more = false;
		for ( $i = 0; $i < $batches; $i++ ) {
			$d  = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_MPO_Installer::table( 'raw' ) . ' WHERE stored_at < %s ORDER BY id ASC LIMIT 500', $cut ) ); // phpcs:ignore
			$n += $d;
			if ( $d < 500 ) {
				return $n;
			}
		}
		self::$more = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Eru_MPO_Installer::table( 'raw' ) . ' WHERE stored_at < %s LIMIT 1', $cut ) ); // phpcs:ignore
		return $n;
	}

	/** @return bool The table has rows. */
	public static function has_rows() {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . Eru_MPO_Installer::table( 'raw' ) . ' LIMIT 1' ); // phpcs:ignore
	}
}
