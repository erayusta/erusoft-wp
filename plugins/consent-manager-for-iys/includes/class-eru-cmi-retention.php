<?php
/**
 * Daily retention purge, in bounded batches.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - İYS API log: rows older than the "log" setting (default 7 days).
 * - Expired daily mail markers (re-consent and resend limits).
 * - History: the IP and user-agent hashes are cleared after the "hash" setting (default 0 = kept). The rows
 *   themselves (who, when, which status, source, verified, consent text hash, note) are the audit trail and stay.
 * - Queue rows of erased consents: once a row has finished (accepted, rejected, skipped, failed, superseded),
 *   the address in its payload is replaced by the consent's placeholder. Rows still on their way keep it until
 *   they finish (İYS needs the address), which the eraser reports.
 */
class Eru_CMI_Retention {

	const BATCH   = 1000;
	const BATCHES = 20;
	const FINAL   = "'accepted','rejected','skipped','failed','superseded'";

	/** @return array{log:int,markers:int,hashes:int,queue:int} Rows touched. */
	public static function run() {
		return array(
			'log'     => Eru_CMI_Logger::purge(),
			'markers' => Eru_CMI_Links::purge_markers(),
			'hashes'  => self::clear_hashes(),
			'queue'   => self::purge_queue_addresses(),
		);
	}

	/** @return bool Something is left for a later run. */
	public static function has_work() {
		global $wpdb;
		if ( Eru_CMI_Logger::has_rows() || Eru_CMI_Links::has_markers() || self::open_erased_rows() ) {
			return true;
		}
		return Eru_CMI_Settings::hash_days() > 0 && (bool) $wpdb->get_var( 'SELECT 1 FROM ' . Eru_CMI_Installer::table( 'history' ) . " WHERE ip_hash <> '' OR ua_hash <> '' LIMIT 1" ); // phpcs:ignore
	}

	/** @return int History rows whose IP/UA hashes were cleared. */
	public static function clear_hashes() {
		global $wpdb;
		$days = Eru_CMI_Settings::hash_days();
		if ( $days <= 0 ) {
			return 0;
		}
		$cut = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$n   = 0;
		for ( $i = 0; $i < self::BATCHES; $i++ ) {
			$d  = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Eru_CMI_Installer::table( 'history' ) . " SET ip_hash = '', ua_hash = '' WHERE created_at < %s AND ( ip_hash <> '' OR ua_hash <> '' ) ORDER BY id ASC LIMIT %d", $cut, self::BATCH ) ); // phpcs:ignore
			$n += $d;
			if ( $d < self::BATCH ) {
				break;
			}
		}
		return $n;
	}

	/** @return bool Queue rows of erased consents still carry an address. */
	private static function open_erased_rows() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Eru_CMI_Installer::table( 'queue' ) . ' q JOIN ' . Eru_CMI_Installer::table( 'consents' ) . ' c ON c.id = q.consent_id WHERE c.recipient LIKE %s AND q.payload NOT LIKE %s LIMIT 1', '#%', '%' . $wpdb->esc_like( '"recipient":"#' ) . '%' ) ); // phpcs:ignore
	}

	/** @return int Finished queue rows of erased consents whose address was removed. */
	public static function purge_queue_addresses() {
		global $wpdb;
		$q = Eru_CMI_Installer::table( 'queue' );
		$c = Eru_CMI_Installer::table( 'consents' );
		$n = 0;
		for ( $i = 0; $i < self::BATCHES; $i++ ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT q.id, q.payload, c.recipient AS ph FROM {$q} q JOIN {$c} c ON c.id = q.consent_id WHERE c.recipient LIKE '#%%' AND q.status IN (" . self::FINAL . ") AND q.payload NOT LIKE %s ORDER BY q.id ASC LIMIT %d", '%' . $wpdb->esc_like( '"recipient":"#' ) . '%', self::BATCH ) ); // phpcs:ignore
			foreach ( $rows as $row ) {
				$p              = json_decode( (string) $row->payload, true );
				$p              = is_array( $p ) ? $p : array();
				$p['recipient'] = (string) $row->ph;
				$wpdb->update( $q, array( 'payload' => (string) wp_json_encode( $p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
				++$n;
			}
			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}
		return $n;
	}
}
