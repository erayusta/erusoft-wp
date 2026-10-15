<?php
/**
 * WP-CLI: wp eru-gha check|transactions|ledger.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Garanti BBVA havale onayı komutları.
 */
class Eru_GHA_CLI {

	/**
	 * Runs one matching pass now (same lock as the scheduled run). While the master switch is off the pass
	 * is always a dry-run.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only log what would be approved, whatever the setting says.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function check( $args, $assoc ) {
		$opts = array();
		if ( WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false ) ) {
			$opts['dry_run'] = true;
		}
		$s = Eru_GHA_Approver::run( $opts );
		WP_CLI::log( sprintf( 'status=%s fetched=%d orders=%d approved=%d would=%d review=%d mismatch=%d dry_run=%s', $s['status'], $s['fetched'], $s['orders'], count( $s['approved'] ), count( $s['would'] ), count( $s['review'] ), $s['mismatch'], $s['dry_run'] ? 'yes' : 'no' ) . ( $s['forced_dry'] ? ' (ana anahtar kapalı)' : '' ) );
		if ( in_array( $s['status'], array( 'aborted', 'not_configured', 'locked' ), true ) ) {
			WP_CLI::error( '' !== $s['error'] ? $s['error'] : $s['status'] );
		}
		WP_CLI::success( 'OK' );
	}

	/**
	 * Fetches and lists the account transactions (read-only; nothing is matched).
	 *
	 * ## OPTIONS
	 *
	 * [--hours=<hours>]
	 * : Lookback, 24–96.
	 * ---
	 * default: 48
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function transactions( $args, $assoc ) {
		$res = ( new Eru_GHA_Client() )->fetch_transactions( (int) ( $assoc['hours'] ?? 48 ) );
		if ( ! $res['ok'] ) {
			WP_CLI::error( $res['error'] );
		}
		$rows = array();
		foreach ( $res['transactions'] as $raw ) {
			$t      = Eru_GHA_Matcher::normalize( $raw );
			$rows[] = array(
				'id'     => $t['id'],
				'date'   => $t['date'],
				'type'   => $t['credit'] ? 'A' : 'B',
				'amount' => number_format( $t['amount'], 2, '.', '' ),
				'payer'  => Eru_GHA_Log::initials( $t['payer'] ),
				'text'   => mb_substr( Eru_GHA_Log::mask( $t['text'] ), 0, 80 ),
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'date', 'type', 'amount', 'payer', 'text' ) );
	}

	/**
	 * Lists the used-transaction ledger.
	 *
	 * ## OPTIONS
	 *
	 * [--page=<page>]
	 * : Page (50 rows).
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function ledger( $args, $assoc ) {
		$p    = Eru_GHA_Ledger::page( max( 1, (int) ( $assoc['page'] ?? 1 ) ) );
		$rows = array_map(
			fn( $r ) => array(
				'txn_id'   => $r['txn_id'],
				'order_id' => $r['order_id'],
				'amount'   => $r['amount'],
				'used_at'  => $r['used_at'],
				'source'   => $r['source'],
				'state'    => '1' === (string) $r['active'] ? 'used' : 'released',
			),
			$p['rows']
		);
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'txn_id', 'order_id', 'amount', 'used_at', 'source', 'state' ) );
		WP_CLI::log( sprintf( '%d kayıt', $p['total'] ) );
	}
}
