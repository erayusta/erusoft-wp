<?php
/**
 * WP-CLI: wp eru-cmi queue status|flush|retry [--id=<id>], status <recipient>, pull, reconcile [--dry-run],
 * export --file=<path> [--full], import --file=<path> [--at-iys] [--dry-run],
 * import-table --table=<name> --id=<col> --recipient=<col> --status=<col> --date=<col>
 * [--type=<col> | --channel=<EPOSTA|MESAJ|ARAMA>] [--source=<col>] [--recipient-type=<col>] [--dry-run].
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue maintenance and consent look-up. Output masks every recipient.
 */
class Eru_CMI_CLI {

	/**
	 * Shows, flushes or retries the İYS queue.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status, flush or retry.
	 *
	 * [--id=<id>]
	 * : retry: only this row (default: every failed row).
	 *
	 * [--rejected]
	 * : retry: also rows İYS rejected.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-cmi queue status
	 *     wp eru-cmi queue flush
	 *     wp eru-cmi queue retry --id=42
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function queue( $args, $assoc_args ) {
		$action = $args[0] ?? 'status';
		if ( 'status' === $action ) {
			foreach ( Eru_CMI_Queue::counts() as $status => $n ) {
				WP_CLI::line( str_pad( $status, 10 ) . $n );
			}
			WP_CLI::line( 'enabled   ' . ( Eru_CMI_Settings::enabled() ? 'yes' : 'no' ) . ' (' . Eru_CMI_Settings::env() . ')' );
			return;
		}
		if ( 'flush' === $action ) {
			if ( ! Eru_CMI_Settings::enabled() ) {
				WP_CLI::error( 'İYS sending is switched off or not configured (WooCommerce > İYS İzinleri).' );
			}
			$f = Eru_CMI_Queue::flush();
			$p = Eru_CMI_Queue::poll();
			if ( $f['locked'] ) {
				WP_CLI::warning( 'Another flush is running; nothing sent.' );
			}
			WP_CLI::success( sprintf( 'Sent %d, skipped %d, rejected %d, retry later %d, failed %d; results: accepted %d, rejected %d, re-queued %d.', $f['sent'], $f['skipped'], $f['rejected'], $f['retry'], $f['failed'], $p['accepted'], $p['rejected'], $p['requeued'] ) );
			return;
		}
		if ( 'retry' === $action ) {
			$n = Eru_CMI_Queue::retry( isset( $assoc_args['id'] ) ? absint( $assoc_args['id'] ) : null, ! empty( $assoc_args['rejected'] ) );
			WP_CLI::success( sprintf( '%d row(s) queued again.', $n ) );
			return;
		}
		WP_CLI::error( 'Unknown action. Use: status, flush or retry.' );
	}

	/**
	 * Reads consent changes made at İYS (pages of up to 999) and applies them as actor "iys".
	 *
	 * ## OPTIONS
	 *
	 * [--pages=<n>]
	 * : Pages to read at most (default 10).
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-cmi pull
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function pull( $args, $assoc_args ) {
		if ( ! Eru_CMI_Settings::enabled() ) {
			WP_CLI::error( 'İYS sending is switched off or not configured (WooCommerce > İYS İzinleri).' );
		}
		$pages   = max( 1, min( 1000, absint( $assoc_args['pages'] ?? 10 ) ) );
		$applied = 0;
		$ignored = 0;
		for ( $i = 0; $i < $pages; $i++ ) {
			$r = Eru_CMI_Pull::page();
			if ( ! $r['ok'] ) {
				WP_CLI::error( 'Pull failed: ' . Eru_CMI_Logger::redact( (string) $r['error'] ) );
			}
			$applied += $r['applied'];
			$ignored += $r['ignored'];
			if ( ! $r['more'] ) {
				break;
			}
		}
		WP_CLI::success( sprintf( 'Applied %d change(s), ignored %d.', $applied, $ignored ) );
	}

	/**
	 * Runs tonight's reconcile pass to the end: every consent registered at İYS in the selected environment is
	 * looked up; missing or older ones are queued again once.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Ask İYS and report, change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-cmi reconcile --dry-run
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function reconcile( $args, $assoc_args ) {
		if ( ! Eru_CMI_Settings::enabled() ) {
			WP_CLI::error( 'İYS sending is switched off or not configured (WooCommerce > İYS İzinleri).' );
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$t = Eru_CMI_Reconcile::dry_run_all();
		} else {
			$t = array_fill_keys( array( 'checked', 'match', 'requeued', 'held', 'applied', 'conflict', 'errors', 'skipped' ), 0 );
			for ( $i = 0; $i < 100000; $i++ ) {
				$r = Eru_CMI_Reconcile::step();
				if ( ! empty( $r['already'] ) ) {
					WP_CLI::line( 'Tonight\'s pass is already finished.' );
					break;
				}
				if ( ! empty( $r['locked'] ) ) {
					WP_CLI::error( 'Another reconcile run is in progress.' );
				}
				foreach ( $t as $k => $v ) {
					$t[ $k ] = $v + (int) $r[ $k ];
				}
				if ( ! empty( $r['limited'] ) ) {
					WP_CLI::warning( 'İYS rate limit (429): stopped; the pass continues later from where it stopped.' );
					break;
				}
				if ( ! empty( $r['done'] ) ) {
					break;
				}
			}
		}
		WP_CLI::success( sprintf( '%sChecked %d: %d match, %d re-queued, %d held by the rules, %d newer at İYS applied, %d conflicts, %d errors, %d skipped (in flight).', ! empty( $assoc_args['dry-run'] ) ? '[dry run] ' : '', $t['checked'], $t['match'], $t['requeued'], $t['held'], $t['applied'], $t['conflict'], $t['errors'], $t['skipped'] ) );
	}

	/**
	 * Writes the consent store as CSV. Recipients are masked unless --full is given.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Output file.
	 *
	 * [--full]
	 * : Full addresses (personal data: keep the file safe).
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-cmi export --file=izinler.csv
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function export( $args, $assoc_args ) {
		$path = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $path ) {
			WP_CLI::error( '--file=<path> is required.' );
		}
		$fh = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			WP_CLI::error( 'Cannot write the file.' );
		}
		$n = Eru_CMI_Import::export( $fh, ! empty( $assoc_args['full'] ) );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		WP_CLI::success( sprintf( '%d row(s) written%s.', $n, ! empty( $assoc_args['full'] ) ? ' with full addresses' : ' (masked)' ) );
	}

	/**
	 * Imports consents from a CSV file (recipient,type,status,consent_date[,source,recipient_type]).
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : CSV file with a header row.
	 *
	 * [--at-iys]
	 * : The records are already registered at İYS: mark them so instead of queueing them.
	 *
	 * [--dry-run]
	 * : Validate and count only.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-cmi import --file=izinler.csv --dry-run
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function import( $args, $assoc_args ) {
		$r = Eru_CMI_Import::csv(
			(string) ( $assoc_args['file'] ?? '' ),
			array(
				'at_iys' => ! empty( $assoc_args['at-iys'] ),
				'dry'    => ! empty( $assoc_args['dry-run'] ),
			)
		);
		foreach ( array_slice( $r['errors'], 0, 200 ) as $e ) {
			WP_CLI::warning( sprintf( 'Line %d: %s (%s)', $e['line'], $e['message'], $e['code'] ) );
		}
		WP_CLI::success( sprintf( '%s%d line(s): %d imported, %d unchanged, %d older than the stored record, %d RET kept, %d error(s).', ! empty( $assoc_args['dry-run'] ) ? '[dry run] ' : '', $r['lines'], $r['imported'], $r['unchanged'], $r['older'], $r['kept_ret'], count( $r['errors'] ) ) );
	}

	/**
	 * Imports an append-only consent-log table of this database: you name the columns that hold each field.
	 * The row with the highest order column per recipient and channel wins; history actor "import"; a RET is
	 * never lifted.
	 *
	 * ## OPTIONS
	 *
	 * --table=<name>
	 * : Table name (with its prefix).
	 *
	 * --id=<column>
	 * : Increasing integer column that orders the rows (e.g. the primary key).
	 *
	 * --recipient=<column>
	 * : Column with the e-mail address or phone number.
	 *
	 * --status=<column>
	 * : Column with ONAY or RET.
	 *
	 * --date=<column>
	 * : Column with the consent date (Turkish time).
	 *
	 * [--type=<column>]
	 * : Column with the channel (EPOSTA, MESAJ, ARAMA).
	 *
	 * [--channel=<channel>]
	 * : Channel of every row when no column holds it.
	 *
	 * [--source=<column>]
	 * : Column with the İYS source (HS_WEB …); other values become HS_WEB.
	 *
	 * [--recipient-type=<column>]
	 * : Column with BIREYSEL or TACIR.
	 *
	 * [--dry-run]
	 * : Count only, write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-cmi import-table --table=wp_consent_log --id=log_id --recipient=email --channel=EPOSTA --status=state --date=created --dry-run
	 *
	 * @subcommand import-table
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function import_table( $args, $assoc_args ) {
		$map = array(
			'id'             => (string) ( $assoc_args['id'] ?? '' ),
			'recipient'      => (string) ( $assoc_args['recipient'] ?? '' ),
			'status'         => (string) ( $assoc_args['status'] ?? '' ),
			'consent_date'   => (string) ( $assoc_args['date'] ?? '' ),
			'type'           => (string) ( $assoc_args['type'] ?? '' ),
			'source'         => (string) ( $assoc_args['source'] ?? '' ),
			'recipient_type' => (string) ( $assoc_args['recipient-type'] ?? '' ),
			'fixed_type'     => (string) ( $assoc_args['channel'] ?? '' ),
		);
		$r   = Eru_CMI_Import::table_import( (string) ( $assoc_args['table'] ?? '' ), $map, ! empty( $assoc_args['dry-run'] ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( sprintf( '%s%d row(s) read: %d imported, %d unchanged, %d older, %d RET kept, %d invalid, %d failed.', ! empty( $assoc_args['dry-run'] ) ? '[dry run] ' : '', $r['read'], $r['imported'], $r['unchanged'], $r['older'], $r['kept_ret'], $r['invalid'], $r['failed'] ) );
	}

	/**
	 * Shows the consent state of a recipient on every channel (masked).
	 *
	 * ## OPTIONS
	 *
	 * <recipient>
	 * : E-mail address or phone number.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-cmi status ornek@example.com
	 *
	 * @param array $args Positional.
	 */
	public function status( $args ) {
		$rows = Eru_CMI_Repository::for_recipient( (string) ( $args[0] ?? '' ) );
		if ( ! $rows ) {
			WP_CLI::line( 'No record.' );
			return;
		}
		foreach ( $rows as $r ) {
			WP_CLI::line( implode( "\t", array( Eru_CMI_Recipient::mask( $r->recipient ), $r->type, $r->status, $r->recipient_type, '' !== $r->iys_status ? $r->iys_status : '-', $r->consent_date . ' UTC' ) ) );
		}
	}
}
