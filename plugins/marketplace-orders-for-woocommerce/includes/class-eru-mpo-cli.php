<?php
/**
 * WP-CLI: wp eru-mpo pull | lifecycle | push-stock | migrate | status.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marketplace Orders for WooCommerce from the command line. The commands run in this process (not in a page
 * request) and respect the same switches as the scheduled jobs: master switch, marketplace switch, observe mode.
 */
class Eru_MPO_CLI {

	/** @param array $assoc Assoc args. @return string[] Marketplaces to work on. */
	private static function keys( array $assoc ) {
		if ( ! empty( $assoc['marketplace'] ) ) {
			$k = sanitize_key( (string) $assoc['marketplace'] );
			if ( ! Eru_MPO_Registry::get( $k ) ) {
				WP_CLI::error( sprintf( 'Bilinmeyen pazaryeri: %s', $k ) );
			}
			return array( $k );
		}
		return array_keys( Eru_MPO_Registry::all() );
	}

	/** Prints a pull summary. */
	private static function summary( $key, array $r ) {
		WP_CLI::log( sprintf( '%s: %s · alınan %d, yeni %d, güncellenen %d, değişmeyen %d, gözlenen %d, atlanan %d, hata %d', $key, $r['result'], $r['fetched'], $r['created'], $r['updated'], $r['unchanged'], $r['observed'], $r['skipped'], $r['errors'] ) );
	}

	/**
	 * Pulls new orders now.
	 *
	 * ## OPTIONS
	 *
	 * [--marketplace=<key>]
	 * : Only this marketplace (trendyol, hepsiburada …). Default: every registered one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-mpo pull --marketplace=trendyol
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function pull( $args, $assoc ) {
		foreach ( self::keys( $assoc ) as $k ) {
			self::summary( $k, Eru_MPO_Importer::pull( $k ) );
		}
	}

	/**
	 * Pulls lifecycle changes (cargo, delivery, cancel, return) of known orders now.
	 *
	 * ## OPTIONS
	 *
	 * [--marketplace=<key>]
	 * : Only this marketplace.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function lifecycle( $args, $assoc ) {
		foreach ( self::keys( $assoc ) as $k ) {
			self::summary( $k, Eru_MPO_Importer::pull( $k, array( 'lifecycle' => true ) ) );
		}
	}

	/**
	 * Pushes queued stock and price changes now (stock bridge).
	 *
	 * ## OPTIONS
	 *
	 * [--marketplace=<key>]
	 * : Only this marketplace.
	 *
	 * [--all]
	 * : Queue every published product first (full sync).
	 *
	 * @subcommand push-stock
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function push_stock( $args, $assoc ) {
		global $wpdb;
		foreach ( self::keys( $assoc ) as $k ) {
			if ( ! Eru_MPO_Stock_Bridge::active( $k ) ) {
				WP_CLI::warning( sprintf( '%s: stok köprüsü kapalı (ana anahtar, API bilgileri, stok/fiyat gönderimi).', $k ) );
				continue;
			}
			if ( ! empty( $assoc['all'] ) ) {
				$after = 0;
				$n     = 0;
				do {
					$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT 500", $after ) ) ); // phpcs:ignore
					foreach ( $ids as $id ) {
						$p = wc_get_product( $id );
						if ( $p && ! $p->is_type( 'variable' ) ) {
							$n += Eru_MPO_Stock_Bridge::mark( $id, Eru_MPO_Stock_Bridge::KINDS, false, $k );
						}
						$after = $id;
					}
				} while ( count( $ids ) === 500 );
				WP_CLI::log( sprintf( '%s: %d kayıt kuyruğa alındı.', $k, $n ) );
			}
			$rounds = 0;
			do {
				$r = Eru_MPO_Stock_Bridge::flush( $k );
				WP_CLI::log( sprintf( '%s: %s · stok %d, fiyat %d, atlanan %d, hata %d, gözlenen %d', $k, $r['result'], $r['stock'], $r['price'], $r['skipped'], $r['failed'], $r['observed'] ) );
				$due = Eru_MPO_Stock_Bridge::queue_counts( $k )['due'];
				++$rounds;
			} while ( 'ok' === $r['result'] && $due > 0 && $rounds < 50 );
		}
	}

	/**
	 * Migrates orders of the older Erusoft Trendyol/Hepsiburada plugins.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only count what would be migrated (writes nothing).
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function migrate( $args, $assoc ) {
		$scan = Eru_MPO_Migrator::scan();
		$rows = array();
		foreach ( $scan as $mp => $s ) {
			$rows[] = array(
				'kaynak'     => $mp,
				'eski'       => $s['orders'],
				'tasinacak'  => $s['to_migrate'],
				'tasinmis'   => $s['migrated'],
				'onarilacak' => $s['to_repair'] ?? 0,
				'yalniz_tablo' => $s['table_only'],
				'sahipsiz'   => $s['orphans'],
				'eski_etkin' => $s['legacy_active'] ? 'evet' : 'hayır',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ?? array( 'kaynak' => '' ) ) );
		if ( ! empty( $assoc['dry-run'] ) ) {
			return;
		}
		$r = Eru_MPO_Migrator::start();
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		$guard = 0;
		do {
			$b = Eru_MPO_Migrator::run_batch();
			WP_CLI::log( sprintf( 'grup: taşınan %d, onarılan %d, çakışma %d, sahipsiz %d, hata %d', $b['migrated'], $b['repaired'], $b['conflicts'], $b['orphans'], $b['errors'] ) );
			++$guard;
		} while ( empty( $b['done'] ) && 'running' === ( $b['status'] ?? 'running' ) && $guard < 10000 );
		as_unschedule_all_actions( Eru_MPO_Migrator::HOOK, array(), Eru_MPO_Cron::GROUP ); // This process did the work.
		WP_CLI::success( 'Geçiş bitti.' );
	}

	/**
	 * Shows switches, last runs, queues and the migration state.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function status( $args, $assoc ) {
		WP_CLI::log( sprintf( 'Ana anahtar: %s · gözlem modu: %s · stok: %s · durumlar: %s', Eru_MPO_Settings::enabled() ? 'açık' : 'kapalı', Eru_MPO_Settings::observe() ? 'açık' : 'kapalı', Eru_MPO_Settings::stock_mode(), Eru_MPO_Settings::status_mode() ) );
		$rows = array();
		foreach ( Eru_MPO_Registry::all() as $k => $a ) {
			$o      = Eru_MPO_Sync::state( 'orders', $k );
			$l      = Eru_MPO_Sync::state( 'lifecycle', $k );
			$q      = Eru_MPO_Stock_Bridge::queue_counts( $k );
			$rows[] = array(
				'pazaryeri'   => $k,
				'acik'        => $a->is_enabled() ? 'evet' : 'hayır',
				'ayarli'      => $a->is_configured() ? 'evet' : 'hayır',
				'gozlem'      => $a->is_observe_mode() ? 'evet' : 'hayır',
				'siparis'     => ( $o['last'] ?? '-' ) . ' ' . ( $o['result'] ?? '' ),
				'yasam'       => ( $l['last'] ?? '-' ) . ' ' . ( $l['result'] ?? '' ),
				'kopru'       => Eru_MPO_Stock_Bridge::active( $k ) ? 'açık' : 'kapalı',
				'kuyruk'      => $q['due'] . '/' . $q['waiting'] . '/' . $q['given_up'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'pazaryeri', 'acik', 'ayarli', 'gozlem', 'siparis', 'yasam', 'kopru', 'kuyruk' ) );
		$m = get_option( Eru_MPO_Migrator::STATE );
		WP_CLI::log( 'Geçiş: ' . ( is_array( $m ) ? sprintf( '%s · taşınan %d · onarılan %d · hata %d', $m['status'] ?? '', (int) ( $m['migrated'] ?? 0 ), (int) ( $m['repaired'] ?? 0 ), (int) ( $m['errors'] ?? 0 ) ) : 'çalıştırılmadı' ) );
	}
}
