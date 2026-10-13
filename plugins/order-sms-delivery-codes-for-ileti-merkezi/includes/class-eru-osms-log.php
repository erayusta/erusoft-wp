<?php
/**
 * Send log (eru_osms_log). `text` always keeps the {teslimat_kodu} placeholder.
 * status: queued | sent | error | test | skipped — source: rule | resend
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Log {

	/** Statuses that count as "already sent" for de-duplication. */
	const COUNTED = array( 'queued', 'sent', 'test' );

	public static function add( array $fields ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array_merge(
			array(
				'created_at'   => $now,
				'updated_at'   => $now,
				'order_id'     => 0,
				'source'       => 'rule',
				'rule_id'      => '',
				'phone'        => '',
				'text'         => '',
				'has_code'     => 0,
				'segments'     => 0,
				'status'       => 'queued',
				'error'        => '',
				'api_response' => '',
				'attempts'     => 0,
				'user_id'      => 0,
			),
			$fields
		);
		$wpdb->insert( Eru_OSMS_Installer::log_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( Eru_OSMS_Installer::log_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$t   = Eru_OSMS_Installer::log_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	public static function for_order( int $order_id ): array {
		global $wpdb;
		$t = Eru_OSMS_Installer::log_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id DESC LIMIT 100", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Was this rule already sent / queued / logged in test mode for this order? (Resends do not count.) */
	public static function rule_logged( int $order_id, string $rule_id ): bool {
		global $wpdb;
		$t     = Eru_OSMS_Installer::log_table();
		$marks = implode( ',', array_fill( 0, count( self::COUNTED ), '%s' ) );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE order_id = %d AND rule_id = %s AND source = 'rule' AND status IN ({$marks}) LIMIT 1", array_merge( array( $order_id, $rule_id ), self::COUNTED ) ) ); // phpcs:ignore WordPress.DB
	}

	/** Latest code send of the order (resends included); 0 if none. */
	public static function last_code_id( int $order_id ): int {
		global $wpdb;
		$t = Eru_OSMS_Installer::log_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$t} WHERE order_id = %d AND has_code = 1", $order_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return array{rows:array,total:int}
	 */
	public static function page( string $status, int $page, int $per = 50 ): array {
		global $wpdb;
		$t     = Eru_OSMS_Installer::log_table();
		$page  = max( 1, $page );
		$where = '' !== $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} {$where}" ); // phpcs:ignore WordPress.DB
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array( 'rows' => (array) $rows, 'total' => $total );
	}
}
