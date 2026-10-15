<?php
/**
 * Two tables: raw request/response log (credentials redacted, short retention because it holds the
 * receiver's name, address and phone) and the per-order event timeline.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows are written with $wpdb->insert; reads are prepared. Purges run in batches.
 */
class Eru_YK_Logger {

	const DB_VERSION = '1';
	const BATCH      = 1000;

	/** @return string Raw log table. */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'eru_yk_log';
	}

	/** @return string Event table. */
	public static function event_table() {
		global $wpdb;
		return $wpdb->prefix . 'eru_yk_events';
	}

	/** Creates or migrates both tables when the stored version differs. */
	public static function maybe_install() {
		if ( get_option( 'eru_yk_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$log     = self::log_table();
		$events  = self::event_table();
		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				account varchar(190) NOT NULL DEFAULT '',
				operation varchar(40) NOT NULL DEFAULT '',
				cargo_key varchar(40) NOT NULL DEFAULT '',
				http_code smallint(5) unsigned NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				request longtext DEFAULT NULL,
				response longtext DEFAULT NULL,
				error text DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY created_at (created_at)
			) {$charset};
			CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(40) NOT NULL DEFAULT '',
				level varchar(10) NOT NULL DEFAULT 'info',
				message text NOT NULL,
				context longtext DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( 'eru_yk_db_version', self::DB_VERSION, true );
	}

	/**
	 * Removes credentials from text that is stored or shown.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact( $text ) {
		$text = (string) $text;
		$text = (string) preg_replace( '#(<(?:[A-Za-z0-9_]+:)?wsPassword\b[^>]*>).*?(</(?:[A-Za-z0-9_]+:)?wsPassword>)#s', '$1[gizli]$2', $text );
		foreach ( Eru_YK_Accounts::secrets() as $s ) {
			if ( strlen( $s ) >= 3 ) {
				$text = str_replace( array( $s, Eru_YK_Xml::esc( $s ) ), '[gizli]', $text );
			}
		}
		$text = (string) preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/', '$1 [gizli]', $text );
		return (string) preg_replace( '/("?(?:password|secret|token|api_key|authorization)"?\s*(?:=|:)\s*"?)[^&\s",}<]+/i', '$1[gizli]', $text );
	}

	/**
	 * Raw request/response row.
	 *
	 * @param array $row order_id, account, operation, cargo_key, http_code, duration_ms, request, response, error.
	 */
	public static function request( array $row ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::log_table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'order_id'    => absint( $row['order_id'] ?? 0 ),
				'account'     => substr( (string) ( $row['account'] ?? '' ), 0, 190 ),
				'operation'   => substr( (string) ( $row['operation'] ?? '' ), 0, 40 ),
				'cargo_key'   => substr( (string) ( $row['cargo_key'] ?? '' ), 0, 40 ),
				'http_code'   => absint( $row['http_code'] ?? 0 ),
				'duration_ms' => absint( $row['duration_ms'] ?? 0 ),
				'request'     => self::redact( (string) ( $row['request'] ?? '' ) ),
				'response'    => self::redact( substr( (string) ( $row['response'] ?? '' ), 0, 65000 ) ),
				'error'       => self::redact( (string) ( $row['error'] ?? '' ) ),
			)
		);
	}

	/**
	 * Timeline event.
	 *
	 * @param int    $order_id Order id (0 = global).
	 * @param string $type     Event type.
	 * @param string $message  Message.
	 * @param string $level    info|success|warn|error.
	 * @param array  $context  Context.
	 */
	public static function event( $order_id, $type, $message, $level = 'info', array $context = array() ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::event_table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'order_id'   => absint( $order_id ),
				'type'       => substr( sanitize_key( $type ), 0, 40 ),
				'level'      => in_array( $level, array( 'info', 'success', 'warn', 'error' ), true ) ? $level : 'info',
				'message'    => self::redact( (string) $message ),
				'context'    => $context ? self::redact( (string) wp_json_encode( $context ) ) : null,
			)
		);
	}

	/** @param int $order_id Order. @param int $limit Limit. @return array Newest first. */
	public static function events_for( $order_id, $limit = 30 ) {
		global $wpdb;
		$t = self::event_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id DESC LIMIT %d", absint( $order_id ), absint( $limit ) ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Paged list for the log viewer.
	 *
	 * @param string $which    events|log.
	 * @param int    $order_id Filter (0 = all).
	 * @param int    $page     Page (1-based).
	 * @param int    $per      Per page.
	 * @return array{rows:array,total:int}
	 */
	public static function page( $which, $order_id, $page, $per = 50 ) {
		global $wpdb;
		$t     = 'log' === $which ? self::log_table() : self::event_table();
		$where = $order_id ? $wpdb->prepare( 'WHERE order_id = %d', absint( $order_id ) ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} {$where}" ); // phpcs:ignore
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", absint( $per ), max( 0, ( absint( $page ) - 1 ) * absint( $per ) ) ), ARRAY_A ); // phpcs:ignore
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/** @return bool Either table has rows (keeps the daily purge scheduled). */
	public static function has_rows() {
		global $wpdb;
		$l = self::log_table();
		$e = self::event_table();
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$l} LIMIT 1" ) || (bool) $wpdb->get_var( "SELECT 1 FROM {$e} LIMIT 1" ); // phpcs:ignore
	}

	/** Retention purge in batches (raw log: log_days, events: event_days). */
	public static function purge() {
		global $wpdb;
		foreach ( array(
			self::log_table()   => (int) Eru_YK_Settings::get( 'log_days' ),
			self::event_table() => (int) Eru_YK_Settings::get( 'event_days' ),
		) as $t => $days ) {
			$cut = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
			for ( $i = 0; $i < 50; $i++ ) {
				$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s LIMIT %d", $cut, self::BATCH ) ); // phpcs:ignore
				if ( $n < self::BATCH ) {
					break;
				}
			}
		}
	}

	/** @param string $which events|log|all. Empties a table (log viewer purge). */
	public static function truncate( $which ) {
		global $wpdb;
		if ( in_array( $which, array( 'log', 'all' ), true ) ) {
			$wpdb->query( 'DELETE FROM ' . self::log_table() ); // phpcs:ignore
		}
		if ( in_array( $which, array( 'events', 'all' ), true ) ) {
			$wpdb->query( 'DELETE FROM ' . self::event_table() ); // phpcs:ignore
		}
	}
}
