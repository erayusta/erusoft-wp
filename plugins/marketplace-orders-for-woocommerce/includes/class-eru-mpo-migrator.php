<?php
/**
 * Migrator from the older public Erusoft Trendyol and Hepsiburada plugins.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sources (read only, never changed or deleted):
 * - Trendyol:    orders with _eru_trendyol_package_id, statuses trendyol-yeni|alindi|hazir|kargo|teslim|iptal|iade,
 *                table {prefix}eru_trendyol_orders (shipment_package_id → wc_order_id).
 * - Hepsiburada: orders with _eru_hepsiburada_order_number, statuses hepsiburada-yeni|hazir|iptal,
 *                table {prefix}eru_hepsiburada_orders (order_number → wc_order_id).
 *
 * For each order: the _eru_mpo_* meta is added (existing _eru_mpo_* values are not overwritten), line meta is
 * mapped, the status moves to the matching marketplace status (silently, with the isolated save forced on), a map
 * row is inserted and _eru_mpo_migrated marks the order. The remote reference is the package id (Trendyol) or the
 * order number (Hepsiburada), the same reference the adapters use, so the importer recognises migrated orders.
 * The identity number is copied only when "e-Arşiv için TC kimlik no sakla" is on; legacy raw payloads are not
 * copied anywhere (they stay in the legacy meta).
 *
 * Non-destructive and re-runnable: legacy meta, the legacy plugins' own status registration and the legacy tables
 * are untouched, so a reactivated legacy plugin finds its own rows and meta as before and keeps updating the same
 * orders (no duplicates). It may move a migrated order back to a legacy status; after deactivating it, run the
 * migrator again: its repair phase moves such orders to the matching status (forward only: a legacy status that is
 * further along wins, otherwise the stored logical status is restored). Selection puts "already migrated" into
 * the query (NOT EXISTS _eru_mpo_migrated) plus an id cursor, so a second run migrates nothing.
 *
 * Mapped as well: Hepsiburada line cancellations the legacy plugin already noted become applied lifecycle event
 * ids ("cancel-line-<lineItemId>"), its noted new lines go to _eru_mpo_noted_lines, a table-only Hepsiburada order
 * gets hb_order_id as _eru_mpo_remote_order_id, legacy site-local times become UTC, and an 11-digit legacy tax number
 * is treated as an identity number (copied only with "e-Arşiv için TC kimlik no sakla").
 *
 * Legacy statuses stay registered while legacy data is present; when a finished run leaves no order in a legacy
 * status, the flag is cleared and they retire (a legacy plugin that becomes active again sets it back).
 *
 * Runs in Action Scheduler batches (hook eru_mpo_migrate, group eru-mpo, 100 orders per run, filter
 * eru_mpo_migrate_batch); a batch re-arms the next one only when no pending action exists (the running action does
 * not count). scan() is the dry run: counts only, writes nothing. start() refuses while a legacy plugin is active.
 */
class Eru_MPO_Migrator {

	const HOOK   = 'eru_mpo_migrate';
	const GROUP  = 'eru-mpo';
	const STATE  = 'eru_mpo_migration';
	const BATCH  = 100;
	const FLAG   = 'eru_mpo_legacy_present';
	const MARKER = '_eru_mpo_migrated';

	const SOURCES = array(
		'trendyol'    => array(
			'ref_meta'  => '_eru_trendyol_package_id',
			'table'     => 'eru_trendyol_orders',
			'table_ref' => 'shipment_package_id',
			'identity'  => '_eru_trendyol_identity_number',
		),
		'hepsiburada' => array(
			'ref_meta'  => '_eru_hepsiburada_order_number',
			'table'     => 'eru_hepsiburada_orders',
			'table_ref' => 'order_number',
			'identity'  => '_eru_hepsiburada_identity_number',
		),
	);

	const META_MAP = array(
		'trendyol'    => array(
			'_eru_trendyol_package_id'         => '_eru_mpo_package_id',
			'_eru_trendyol_order_number'       => '_eru_mpo_order_number',
			'_eru_trendyol_imported_at'        => '_eru_mpo_imported_at',
			'_eru_trendyol_review'             => '_eru_mpo_review',
			'_eru_trendyol_status'             => '_eru_mpo_remote_status',
			'_eru_trendyol_cargo_provider'     => '_eru_mpo_cargo_provider',
			'_eru_trendyol_cargo_tracking'     => '_eru_mpo_cargo_tracking',
			'_eru_trendyol_cargo_tracking_link' => '_eru_mpo_cargo_link',
			'_eru_trendyol_cargo_sender_number' => '_eru_mpo_cargo_sender_number',
			'_eru_trendyol_tax_number'         => '_eru_mpo_tax_number',
			'_eru_trendyol_tax_office'         => '_eru_mpo_tax_office',
			'_eru_trendyol_commercial'         => '_eru_mpo_commercial',
			'_eru_trendyol_einvoice'           => '_eru_mpo_einvoice',
			'_eru_trendyol_micro'              => '_eru_mpo_micro',
			'_eru_trendyol_invoice_link'       => '_eru_mpo_invoice_link',
			'_eru_trendyol_etgb_no'            => '_eru_mpo_etgb_no',
			'_eru_trendyol_etgb_date'          => '_eru_mpo_etgb_date',
			'_eru_trendyol_picking_pushed_at'  => '_eru_mpo_ack_at',
		),
		'hepsiburada' => array(
			'_eru_hepsiburada_order_number'  => '_eru_mpo_order_number',
			'_eru_hepsiburada_order_id'      => '_eru_mpo_remote_order_id',
			'_eru_hepsiburada_imported_at'   => '_eru_mpo_imported_at',
			'_eru_hepsiburada_review'        => '_eru_mpo_review',
			'_eru_hepsiburada_cargo_company' => '_eru_mpo_cargo_provider',
			'_eru_hepsiburada_due_date'      => '_eru_mpo_due_date',
			'_eru_hepsiburada_delivery_type' => '_eru_mpo_delivery_type',
			'_eru_hepsiburada_tax_number'    => '_eru_mpo_tax_number',
			'_eru_hepsiburada_tax_office'    => '_eru_mpo_tax_office',
		),
	);

	/** New meta keys whose legacy values are site-local "Y-m-d H:i:s" times. */
	const LOCAL_TIMES = array( '_eru_mpo_imported_at', '_eru_mpo_ack_at' );

	const ITEM_MAP = array(
		'trendyol_stock_code'                => '_eru_mpo_sku',
		'trendyol_barcode'                   => '_eru_mpo_barcode',
		'_eru_trendyol_unmatched'            => '_eru_mpo_unmatched',
		'_eru_trendyol_commission_rate'      => '_eru_mpo_commission_rate',
		'_eru_trendyol_commission_amount'    => '_eru_mpo_commission_amount',
		'hepsiburada_merchant_sku'           => '_eru_mpo_sku',
		'hepsiburada_sku'                    => '_eru_mpo_remote_sku',
		'_eru_hepsiburada_line_item_id'      => '_eru_mpo_remote_line_id',
		'_eru_hepsiburada_unmatched'         => '_eru_mpo_unmatched',
		'_eru_hepsiburada_commission_rate'   => '_eru_mpo_commission_rate',
		'_eru_hepsiburada_commission_amount' => '_eru_mpo_commission_amount',
		'_eru_hepsiburada_customized'        => '_eru_mpo_customized',
	);

	/** Hooks. */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ) );
	}

	/** @return string[] Keys of legacy plugins that are active (filter eru_mpo_legacy_active). */
	public static function legacy_active() {
		$on = array();
		if ( defined( 'ERU_TRENDYOL_VERSION' ) || class_exists( 'Eru_Trendyol', false ) ) {
			$on[] = 'trendyol';
		}
		if ( defined( 'ERU_HEPSIBURADA_VERSION' ) || class_exists( 'Eru_Hepsiburada', false ) ) {
			$on[] = 'hepsiburada';
		}
		$on = apply_filters( 'eru_mpo_legacy_active', $on );
		return array_values( array_intersect( array_keys( self::SOURCES ), (array) $on ) );
	}

	/** @return bool Legacy data was found on this site (flag set at install and by scans). */
	public static function legacy_present() {
		return 'yes' === get_option( self::FLAG );
	}

	/**
	 * Sets the legacy-data flag (autoloaded: statuses read it on every request). Legacy data counts while legacy
	 * tables exist and no migration has finished, or while orders are still in a legacy status; once a finished
	 * migration leaves none, the legacy statuses retire.
	 */
	public static function detect_legacy() {
		$found = false;
		foreach ( array_keys( self::SOURCES ) as $mp ) {
			if ( self::table_exists( $mp, true ) ) {
				$found = true;
			}
		}
		$state = get_option( self::STATE );
		if ( $found && is_array( $state ) && 'done' === ( $state['status'] ?? '' ) ) {
			$found = self::legacy_status_orders() > 0;
		}
		update_option( self::FLAG, $found ? 'yes' : 'no', true );
		return $found;
	}

	/** @return string[] Legacy statuses with the wc- prefix, optionally of one source. */
	private static function legacy_slugs( $mp = '' ) {
		$out = array();
		foreach ( array_keys( Eru_MPO_Statuses::LEGACY ) as $slug ) {
			if ( '' === $mp || 0 === strpos( $slug, $mp . '-' ) ) {
				$out[] = 'wc-' . $slug;
			}
		}
		return $out;
	}

	/** @return int Orders currently in a legacy status. */
	public static function legacy_status_orders() {
		global $wpdb;
		$t     = self::tables();
		$slugs = self::legacy_slugs();
		$in    = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['orders']} WHERE {$t['otype']} = 'shop_order' AND {$t['ostat']} IN ({$in})", $slugs ) ); // phpcs:ignore
	}

	/** @param string $mp Source. @return string Legacy table name. */
	private static function table( $mp ) {
		global $wpdb;
		return $wpdb->prefix . self::SOURCES[ $mp ]['table'];
	}

	/** @var array<string,bool> Request cache of table_exists(). */
	private static $tables = array();

	/**
	 * @param string $mp    Source.
	 * @param bool   $fresh Skip the request cache.
	 * @return bool
	 */
	private static function table_exists( $mp, $fresh = false ) {
		global $wpdb;
		if ( $fresh || ! isset( self::$tables[ $mp ] ) ) {
			$t                    = self::table( $mp );
			self::$tables[ $mp ] = $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$tables[ $mp ];
	}

	/** @return array{meta: string, id: string, orders: string, otype: string} Authoritative order tables. */
	private static function tables() {
		global $wpdb;
		if ( Eru_MPO_Importer::hpos() ) {
			return array(
				'meta'   => $wpdb->prefix . 'wc_orders_meta',
				'id'     => 'order_id',
				'orders' => $wpdb->prefix . 'wc_orders',
				'oid'    => 'id',
				'otype'  => 'type',
				'ostat'  => 'status',
			);
		}
		return array(
			'meta'   => $wpdb->postmeta,
			'id'     => 'post_id',
			'orders' => $wpdb->posts,
			'oid'    => 'ID',
			'otype'  => 'post_type',
			'ostat'  => 'post_status',
		);
	}

	/**
	 * Legacy orders of a source that are not migrated yet, after an id cursor.
	 *
	 * @param string $mp     Source.
	 * @param int    $after  Cursor.
	 * @param int    $limit  Rows.
	 * @return int[]
	 */
	private static function pending_ids( $mp, $after, $limit ) {
		global $wpdb;
		$t = self::tables();
		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT DISTINCT m.{$t['id']} FROM {$t['meta']} m JOIN {$t['orders']} o ON o.{$t['oid']} = m.{$t['id']} AND o.{$t['otype']} = 'shop_order' WHERE m.meta_key = %s AND m.meta_value <> '' AND m.{$t['id']} > %d AND NOT EXISTS ( SELECT 1 FROM {$t['meta']} x WHERE x.{$t['id']} = m.{$t['id']} AND x.meta_key = %s ) ORDER BY m.{$t['id']} ASC LIMIT %d", // phpcs:ignore
					self::SOURCES[ $mp ]['ref_meta'],
					(int) $after,
					self::MARKER,
					(int) $limit
				)
			)
		);
	}

	/**
	 * Migrated orders of a source that are back in a legacy status (a reactivated legacy plugin moved them).
	 *
	 * @return int[]
	 */
	private static function repair_ids( $mp, $after, $limit ) {
		global $wpdb;
		$t     = self::tables();
		$slugs = self::legacy_slugs( $mp );
		$in    = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$args  = array_merge( array( self::MARKER, $wpdb->esc_like( $mp . '|' ) . '%' ), $slugs, array( (int) $after, (int) $limit ) );
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.{$t['id']} FROM {$t['meta']} m JOIN {$t['orders']} o ON o.{$t['oid']} = m.{$t['id']} AND o.{$t['otype']} = 'shop_order' WHERE m.meta_key = %s AND m.meta_value LIKE %s AND o.{$t['ostat']} IN ({$in}) AND m.{$t['id']} > %d ORDER BY m.{$t['id']} ASC LIMIT %d", $args ) ) // phpcs:ignore
		);
	}

	/**
	 * Legacy table rows after a cursor whose package has no map row yet.
	 *
	 * @return array List of {id, ref, wc_order_id}.
	 */
	private static function unmapped_rows( $mp, $after, $limit ) {
		global $wpdb;
		if ( ! self::table_exists( $mp ) ) {
			return array();
		}
		$lt  = self::table( $mp );
		$col = self::SOURCES[ $mp ]['table_ref'];
		$map = Eru_MPO_Installer::table( 'orders' );
		$rid = 'hepsiburada' === $mp ? 'l.hb_order_id' : "''";
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT l.id, CAST(l.{$col} AS CHAR) AS ref, {$rid} AS remote_order_id, l.wc_order_id FROM {$lt} l LEFT JOIN {$map} m ON m.marketplace = %s AND m.remote_ref = l.{$col} WHERE l.id > %d AND m.id IS NULL ORDER BY l.id ASC LIMIT %d", // phpcs:ignore
				$mp,
				(int) $after,
				(int) $limit
			)
		) ?: array();
	}

	/**
	 * Dry run: what a run would do. Writes nothing (the legacy flag is only read).
	 *
	 * @return array<string,array{orders:int,to_migrate:int,migrated:int,statuses:array,table_rows:int,table_only:int,orphans:int,legacy_active:bool}>
	 */
	public static function scan() {
		global $wpdb;
		$t      = self::tables();
		$active = self::legacy_active();
		$out    = array();
		foreach ( self::SOURCES as $mp => $s ) {
			$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.{$t['id']}) FROM {$t['meta']} m JOIN {$t['orders']} o ON o.{$t['oid']} = m.{$t['id']} AND o.{$t['otype']} = 'shop_order' WHERE m.meta_key = %s AND m.meta_value <> ''", $s['ref_meta'] ) ); // phpcs:ignore
			$statuses = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT o.{$t['ostat']} AS st, COUNT(DISTINCT o.{$t['oid']}) AS n FROM {$t['meta']} m JOIN {$t['orders']} o ON o.{$t['oid']} = m.{$t['id']} AND o.{$t['otype']} = 'shop_order' WHERE m.meta_key = %s AND m.meta_value <> '' AND NOT EXISTS ( SELECT 1 FROM {$t['meta']} x WHERE x.{$t['id']} = m.{$t['id']} AND x.meta_key = %s ) GROUP BY o.{$t['ostat']}", $s['ref_meta'], self::MARKER ) ) as $r ) { // phpcs:ignore
				$statuses[ preg_replace( '/^wc-/', '', (string) $r->st ) ] = (int) $r->n;
			}
			$pending    = array_sum( $statuses );
			$migrated   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.{$t['id']}) FROM {$t['meta']} m JOIN {$t['orders']} o ON o.{$t['oid']} = m.{$t['id']} AND o.{$t['otype']} = 'shop_order' WHERE m.meta_key = %s AND m.meta_value LIKE %s", self::MARKER, $wpdb->esc_like( $mp . '|' ) . '%' ) ); // phpcs:ignore
			$table_rows = 0;
			$table_only = 0;
			$orphans    = 0;
			if ( self::table_exists( $mp ) ) {
				$lt         = self::table( $mp );
				$table_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt}" ); // phpcs:ignore
				$after      = 0;
				do {
					$rows = self::unmapped_rows( $mp, $after, 500 );
					foreach ( $rows as $r ) {
						$after = (int) $r->id;
						$oid   = (int) $r->wc_order_id;
						if ( ! $oid || ! Eru_MPO_Importer::order_exists( $oid ) ) {
							++$orphans;
							continue;
						}
						$o = wc_get_order( $oid );
						if ( $o && '' === (string) $o->get_meta( $s['ref_meta'] ) && '' === (string) $o->get_meta( self::MARKER ) ) {
							++$table_only;
							$st              = $o->get_status();
							$statuses[ $st ] = ( $statuses[ $st ] ?? 0 ) + 1;
						}
					}
				} while ( count( $rows ) === 500 );
			}
			$out[ $mp ] = array(
				'to_repair'     => count( self::repair_ids( $mp, 0, 100000 ) ),
				'orders'        => $total,
				'to_migrate'    => $pending + $table_only,
				'migrated'      => $migrated,
				'statuses'      => $statuses,
				'table_rows'    => $table_rows,
				'table_only'    => $table_only,
				'orphans'       => $orphans,
				'legacy_active' => in_array( $mp, $active, true ),
			);
		}
		return $out;
	}

	/**
	 * Starts (or restarts) a run: resets the cursors and schedules the first batch.
	 *
	 * @return true|WP_Error
	 */
	public static function start() {
		if ( self::legacy_active() ) {
			return new WP_Error( 'eru_mpo_legacy_active', __( 'Eski Trendyol/Hepsiburada eklentisi etkin: önce eski eklentiyi devre dışı bırakın, sonra geçişi çalıştırın.', 'marketplace-orders-for-woocommerce' ) );
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'eru_mpo_no_scheduler', __( 'Action Scheduler bulunamadı.', 'marketplace-orders-for-woocommerce' ) );
		}
		update_option(
			self::STATE,
			array(
				'status'     => 'running',
				'started_at' => gmdate( 'Y-m-d H:i:s' ),
				'cursor'     => array(),
				'phase'      => array(),
				'migrated'   => 0,
				'repaired'   => 0,
				'conflicts'  => 0,
				'orphans'    => 0,
				'errors'     => 0,
				'batches'    => 0,
			),
			false
		);
		self::detect_legacy();
		self::arm( 0 );
		return true;
	}

	/** Schedules the next batch when no pending one exists (the running action does not count). */
	private static function arm( $delay ) {
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( ! $pending ) {
			as_schedule_single_action( time() + (int) $delay, self::HOOK, array(), self::GROUP );
		}
	}

	/** Re-arms a run that was interrupted (deactivation removes the job). */
	public static function resume() {
		$s = get_option( self::STATE );
		if ( is_array( $s ) && 'running' === ( $s['status'] ?? '' ) && function_exists( 'as_schedule_single_action' ) && ! self::legacy_active() ) {
			self::arm( 5 );
		}
	}

	/**
	 * One batch: per source, legacy orders by meta first, then legacy table rows without a map row, then migrated
	 * orders that are back in a legacy status (status repair). Each order is isolated: a failure is logged and
	 * counted, the batch goes on.
	 *
	 * @param int|null $limit Orders per batch (default 100, filter eru_mpo_migrate_batch).
	 * @return array{migrated:int, repaired:int, conflicts:int, orphans:int, errors:int, done:bool, status:string}
	 */
	public static function run_batch( $limit = null ) {
		$limit = max( 1, (int) ( null === $limit ? apply_filters( 'eru_mpo_migrate_batch', self::BATCH ) : $limit ) );
		$out   = array(
			'migrated'  => 0,
			'repaired'  => 0,
			'conflicts' => 0,
			'orphans'   => 0,
			'errors'    => 0,
			'done'      => false,
			'status'    => 'running',
		);
		$state = get_option( self::STATE );
		if ( ! is_array( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			$out['status'] = is_array( $state ) ? (string) ( $state['status'] ?? 'idle' ) : 'idle';
			$out['done']   = true;
			return $out;
		}
		if ( self::legacy_active() ) {
			$out['status'] = 'legacy_active';
			Eru_MPO_Logger::warning( __( 'Geçiş durduruldu: eski eklenti etkin. Önce eski eklentiyi devre dışı bırakın.', 'marketplace-orders-for-woocommerce' ), 'migrator' );
			return $out;
		}
		$token = Eru_MPO_Lock::acquire( 'migrate', 10 * MINUTE_IN_SECONDS );
		if ( ! $token ) {
			$out['status'] = 'locked';
			return $out;
		}
		try {
			$budget = $limit;
			$more   = false;
			foreach ( array_keys( self::SOURCES ) as $mp ) {
				// Phase 1: orders with the legacy reference meta.
				$phase = $state['phase'][ $mp ] ?? '';
				if ( ! in_array( $phase, array( 'rows', 'repair', 'done' ), true ) ) {
					$ids = $budget > 0 ? self::pending_ids( $mp, (int) ( $state['cursor'][ $mp ] ?? 0 ), $budget ) : array();
					foreach ( $ids as $id ) {
						self::count( self::isolated( fn() => self::migrate_order( $id, $mp ), $mp, $id ), $out );
						$state['cursor'][ $mp ] = $id;
						--$budget;
					}
					if ( $budget > 0 ) {
						$state['phase'][ $mp ]  = 'rows';
						$state['cursor'][ $mp ] = 0;
					} else {
						$more = true;
					}
				}
				// Phase 2: legacy table rows (orders that lost their meta; deleted orders are counted as orphans).
				if ( 'rows' === ( $state['phase'][ $mp ] ?? '' ) && $budget > 0 ) {
					$rows = self::unmapped_rows( $mp, (int) ( $state['cursor'][ $mp ] ?? 0 ), $budget );
					foreach ( $rows as $row ) {
						$state['cursor'][ $mp ] = (int) $row->id;
						--$budget;
						$oid = (int) $row->wc_order_id;
						if ( ! $oid || ! Eru_MPO_Importer::order_exists( $oid ) ) {
							++$out['orphans'];
							continue;
						}
						self::count( self::isolated( fn() => self::migrate_order( $oid, $mp, (string) $row->ref, (string) $row->remote_order_id ), $mp, $oid ), $out );
					}
					if ( $budget > 0 ) {
						$state['phase'][ $mp ]  = 'repair';
						$state['cursor'][ $mp ] = 0;
					} else {
						$more = true;
					}
				}
				// Phase 3: migrated orders a reactivated legacy plugin moved back to a legacy status.
				if ( 'repair' === ( $state['phase'][ $mp ] ?? '' ) && $budget > 0 ) {
					$ids = self::repair_ids( $mp, (int) ( $state['cursor'][ $mp ] ?? 0 ), $budget );
					foreach ( $ids as $id ) {
						$state['cursor'][ $mp ] = $id;
						--$budget;
						self::count( self::isolated( fn() => self::repair_order( $id ), $mp, $id ), $out );
					}
					if ( $budget > 0 ) {
						$state['phase'][ $mp ] = 'done';
					} else {
						$more = true;
					}
				}
				if ( 'done' !== ( $state['phase'][ $mp ] ?? '' ) ) {
					$more = true;
				}
			}
			$state['migrated']  = (int) $state['migrated'] + $out['migrated'];
			$state['repaired']  = (int) ( $state['repaired'] ?? 0 ) + $out['repaired'];
			$state['errors']    = (int) ( $state['errors'] ?? 0 ) + $out['errors'];
			$state['conflicts'] = (int) $state['conflicts'] + $out['conflicts'];
			$state['orphans']   = (int) $state['orphans'] + $out['orphans'];
			$state['batches']   = (int) $state['batches'] + 1;
			if ( $more ) {
				update_option( self::STATE, $state, false );
				self::arm( 5 );
			} else {
				$state['status']      = 'done';
				$state['finished_at'] = gmdate( 'Y-m-d H:i:s' );
				update_option( self::STATE, $state, false );
				self::detect_legacy();
				$out['done']   = true;
				$out['status'] = 'done';
				/* translators: 1: migrated orders, 2: conflicts, 3: orphan rows */
				Eru_MPO_Logger::info( sprintf( __( 'Geçiş tamamlandı: %1$d sipariş taşındı, %2$d çakışma, %3$d sahipsiz eski kayıt.', 'marketplace-orders-for-woocommerce' ), $state['migrated'], $state['conflicts'], $state['orphans'] ), 'migrator' );
			}
			return $out;
		} finally {
			Eru_MPO_Lock::release( 'migrate', $token );
		}
	}

	/** @param string $r migrate_order() / repair_order() result. @param array $out Counters. */
	private static function count( $r, array &$out ) {
		$map = array(
			'migrated' => 'migrated',
			'repaired' => 'repaired',
			'conflict' => 'conflicts',
			'error'    => 'errors',
		);
		if ( isset( $map[ $r ] ) ) {
			++$out[ $map[ $r ] ];
		}
	}

	/**
	 * Runs one order's step; a Throwable is logged and turned into "error" so the batch continues.
	 *
	 * @param callable $step Step.
	 * @param string   $mp   Source.
	 * @param int      $id   Order id.
	 * @return string
	 */
	private static function isolated( callable $step, $mp, $id ) {
		try {
			return (string) $step();
		} catch ( Throwable $e ) {
			Eru_MPO_Importer::forget_order( (int) $id );
			/* translators: 1: order id, 2: error */
			Eru_MPO_Logger::error( sprintf( __( 'Geçiş: #%1$d numaralı sipariş taşınamadı: %2$s', 'marketplace-orders-for-woocommerce' ), (int) $id, $e->getMessage() ), $mp );
			return 'error';
		}
	}

	/**
	 * Moves a migrated order that a legacy plugin put back into a legacy status to the matching status again. The
	 * logical status only moves forward: if the legacy status is further along (the legacy plugin kept the order
	 * moving), that one is taken; otherwise the stored logical status is restored.
	 *
	 * @param int $id Order id.
	 * @return string repaired | skipped
	 */
	public static function repair_order( $id ) {
		$order = wc_get_order( (int) $id );
		if ( ! $order instanceof WC_Order ) {
			return 'skipped';
		}
		$legacy = $order->get_status();
		if ( ! isset( Eru_MPO_Statuses::LEGACY[ $legacy ] ) ) {
			return 'skipped';
		}
		$from = (string) $order->get_meta( Eru_MPO_Statuses::META );
		$key  = Eru_MPO_Statuses::LEGACY[ $legacy ];
		if ( Eru_MPO_Statuses::is_key( $from ) && ! Eru_MPO_Statuses::can_advance( $from, $key ) ) {
			$key = $from;
		}
		$order->update_meta_data( Eru_MPO_Statuses::META, $key );
		/* translators: %s: legacy status */
		$order->set_status( Eru_MPO_Statuses::wc_status_for( $key ), sprintf( __( 'Geçiş: eski eklentinin geri aldığı durum düzeltildi (eski durum: %s).', 'marketplace-orders-for-woocommerce' ), $legacy ) );
		Eru_MPO_Isolated_Save::save( $order, true );
		if ( in_array( $key, Eru_MPO_Statuses::TERMINAL, true ) ) {
			// Same path as a status move: stock restore on cancel and the status action, both idempotent.
			Eru_MPO_Statuses::after_move( $order, Eru_MPO_Statuses::is_key( $from ) ? $from : '', $key );
		}
		return 'repaired';
	}

	/**
	 * Migrates one legacy order.
	 *
	 * @param int    $id  Order id.
	 * @param string $mp  Source (trendyol | hepsiburada).
	 * @param string $ref Reference from the legacy table when the meta is missing.
	 * @param string $remote_order_id Remote order id from the legacy table (Hepsiburada hb_order_id).
	 * @return string migrated | conflict | skipped
	 */
	public static function migrate_order( $id, $mp, $ref = '', $remote_order_id = '' ) {
		$order = wc_get_order( (int) $id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || ! isset( self::SOURCES[ $mp ] ) ) {
			return 'skipped';
		}
		if ( '' !== (string) $order->get_meta( self::MARKER ) ) {
			return 'skipped';
		}
		$src = self::SOURCES[ $mp ];
		$ref = (string) $order->get_meta( $src['ref_meta'] ) ?: (string) $ref;
		if ( '' === $ref ) {
			return 'skipped';
		}
		$row = Eru_MPO_Map::get( $mp, $ref );
		if ( $row && $row->wc_order_id && (int) $row->wc_order_id !== (int) $id && Eru_MPO_Importer::order_exists( (int) $row->wc_order_id ) ) {
			$order->update_meta_data( self::MARKER, 'conflict|' . gmdate( 'Y-m-d H:i:s' ) );
			$order->update_meta_data( '_eru_mpo_review', 'yes' );
			/* translators: %d: other order id */
			$order->add_order_note( sprintf( __( 'Geçiş: bu paket zaten #%d numaralı siparişe bağlı; bu sipariş taşınmadı, inceleyin.', 'marketplace-orders-for-woocommerce' ), (int) $row->wc_order_id ) );
			Eru_MPO_Isolated_Save::save( $order, true );
			Eru_MPO_Logger::warning( sprintf( 'Geçiş çakışması: #%d / #%d', (int) $id, (int) $row->wc_order_id ), $mp, $ref );
			return 'conflict';
		}

		$set = function ( $key, $value ) use ( $order ) {
			if ( '' !== (string) $value && '' === (string) $order->get_meta( $key ) ) {
				$order->update_meta_data( $key, is_scalar( $value ) ? (string) $value : $value );
			}
		};
		$set( '_eru_mpo_marketplace', $mp );
		$set( '_eru_mpo_remote_ref', $ref );
		if ( 'trendyol' === $mp ) {
			$set( '_eru_mpo_package_id', $ref );
		}
		foreach ( self::META_MAP[ $mp ] as $from => $to ) {
			$v = $order->get_meta( $from );
			if ( in_array( $to, self::LOCAL_TIMES, true ) && is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ) {
				$v = get_gmt_from_date( $v ); // The legacy plugins stored site-local time; mpo meta is UTC.
			}
			if ( '_eru_mpo_tax_number' === $to && Eru_MPO_Builder::is_identity_like( (string) $v ) && ! Eru_MPO_Settings::store_identity() ) {
				continue; // An 11-digit tax number is an individual's identity number (P8).
			}
			$set( $to, $v );
		}
		$set( '_eru_mpo_remote_order_id', $remote_order_id );
		if ( 'hepsiburada' === $mp ) {
			$set( '_eru_mpo_noted_lines', $order->get_meta( '_eru_hepsiburada_noted_lines' ) );
			$noted = json_decode( (string) $order->get_meta( '_eru_hepsiburada_cancel_noted' ), true );
			if ( is_array( $noted ) && $noted ) {
				// Line cancellations the legacy plugin already noted count as applied lifecycle events, so they are
				// not noted (or restored) a second time.
				$events = $order->get_meta( Eru_MPO_Lifecycle::META );
				$events = is_array( $events ) ? $events : array();
				foreach ( $noted as $lid ) {
					if ( is_scalar( $lid ) && '' !== (string) $lid ) {
						$events[] = 'cancel-line-' . $lid;
					}
				}
				$order->update_meta_data( Eru_MPO_Lifecycle::META, array_values( array_unique( $events ) ) );
			}
		}
		if ( 'trendyol' === $mp && 'yes' === $order->get_meta( '_eru_trendyol_picking_pushed' ) ) {
			$set( '_eru_mpo_ack_state', 'done' );
		}
		if ( Eru_MPO_Settings::store_identity() ) {
			$tc = (string) $order->get_meta( $src['identity'] );
			if ( preg_match( '/^\d{11}$/', $tc ) ) {
				$set( '_eru_mpo_identity_number', $tc );
			}
		}
		foreach ( $order->get_items() as $item ) {
			foreach ( self::ITEM_MAP as $from => $to ) {
				$v = $item->get_meta( $from );
				if ( '' !== (string) $v && '' === (string) $item->get_meta( $to ) ) {
					$item->update_meta_data( $to, $v );
				}
			}
		}
		if ( 'trendyol' === $mp ) {
			self::line_ids_from_raw( $order );
		}
		// The marketplace's name as payment method and title, like imported orders (WooCommerce prints the method
		// value on the order screen when no gateway is registered for it).
		$method = (string) $order->get_payment_method();
		if ( '' === $method || $mp === $method || 'eru-mpo-' . $mp === $method ) {
			$label = 'trendyol' === $mp ? 'Trendyol' : 'Hepsiburada';
			$order->set_payment_method( $label );
			$order->set_payment_method_title( $label );
		}

		$legacy = $order->get_status();
		$key    = Eru_MPO_Statuses::key_from_wc_status( $legacy );
		if ( '' === $key ) {
			$core = array(
				'cancelled' => 'cancelled',
				'refunded'  => 'returned',
				'completed' => 'delivered',
			);
			$key  = $core[ $legacy ] ?? 'new';
			$set( Eru_MPO_Statuses::META, $key );
		} elseif ( isset( Eru_MPO_Statuses::LEGACY[ $legacy ] ) ) {
			$order->update_meta_data( Eru_MPO_Statuses::META, $key );
			/* translators: %s: legacy status */
			$order->set_status( Eru_MPO_Statuses::wc_status_for( $key ), sprintf( __( 'Eski eklentiden taşındı (eski durum: %s).', 'marketplace-orders-for-woocommerce' ), $legacy ) );
		}
		$order->update_meta_data( self::MARKER, $mp . '|' . gmdate( 'Y-m-d H:i:s' ) );
		try {
			Eru_MPO_Isolated_Save::save( $order, true );
		} catch ( Eru_MPO_Listener_Error $le ) {
			// Saved completely; a listener failed afterwards. The order is migrated and gets its map row.
			/* translators: %s: error */
			Eru_MPO_Logger::warning( sprintf( __( 'Geçiş kaydedildi, ardından bir eklenti hata verdi: %s', 'marketplace-orders-for-woocommerce' ), $le->getMessage() ), $mp, $ref );
		}
		Eru_MPO_Map::insert_done( $mp, $ref, (int) $id, 'yes' === $order->get_meta( '_eru_mpo_review' ) );
		return 'migrated';
	}

	/**
	 * The legacy Trendyol plugin stored no line id on its items but kept the package (_eru_trendyol_raw). Each item
	 * without _eru_mpo_remote_line_id gets the id of the package line with its stock code (else barcode), each
	 * package line used once; when nothing matches and the counts are equal, by position.
	 *
	 * @param WC_Order $order Legacy order being migrated (saved by the caller).
	 */
	private static function line_ids_from_raw( $order ) {
		$raw   = json_decode( (string) $order->get_meta( '_eru_trendyol_raw' ), true );
		$lines = is_array( $raw ) && is_array( $raw['lines'] ?? null ) ? array_values( array_filter( $raw['lines'], 'is_array' ) ) : array();
		if ( ! $lines ) {
			return;
		}
		$items = array_values( array_filter( $order->get_items( 'line_item' ), fn( $i ) => '' === (string) $i->get_meta( '_eru_mpo_remote_line_id' ) ) );
		$used  = array();
		$id_of = fn( $l ) => Eru_MPO_Adapter_Trendyol::id_string( $l['id'] ?? $l['lineId'] ?? '' );
		foreach ( $items as $pos => $item ) {
			$sku = (string) $item->get_meta( '_eru_mpo_sku' );
			$bc  = (string) $item->get_meta( '_eru_mpo_barcode' );
			$hit = null;
			foreach ( $lines as $k => $l ) {
				if ( isset( $used[ $k ] ) || '' === $id_of( $l ) ) {
					continue;
				}
				if ( ( '' !== $sku && $sku === trim( (string) ( $l['stockCode'] ?? $l['merchantSku'] ?? '' ) ) ) || ( '' !== $bc && $bc === trim( (string) ( $l['barcode'] ?? '' ) ) ) ) {
					$hit = $k;
					break;
				}
			}
			if ( null === $hit && count( $items ) === count( $lines ) && ! isset( $used[ $pos ] ) && '' !== $id_of( $lines[ $pos ] ) ) {
				$hit = $pos;
			}
			if ( null !== $hit ) {
				$used[ $hit ] = true;
				$item->update_meta_data( '_eru_mpo_remote_line_id', $id_of( $lines[ $hit ] ) );
			}
		}
	}

	/**
	 * Importer hook: a package that a legacy plugin imported and the migrator has not reached yet.
	 *
	 * @param string $mp  Marketplace key (only trendyol / hepsiburada have legacy data).
	 * @param string $ref Reference (package id / order number).
	 * @return int Order id now mapped, or 0.
	 */
	public static function take_over_legacy( $mp, $ref ) {
		global $wpdb;
		if ( ! isset( self::SOURCES[ $mp ] ) ) {
			return 0;
		}
		$src = self::SOURCES[ $mp ];
		$id  = 0;
		if ( self::table_exists( $mp ) ) {
			$col = $src['table_ref'];
			$id  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT wc_order_id FROM ' . self::table( $mp ) . " WHERE {$col} = %s AND wc_order_id IS NOT NULL ORDER BY id DESC LIMIT 1", $ref ) ); // phpcs:ignore
			if ( $id && ! Eru_MPO_Importer::order_exists( $id ) ) {
				$id = 0;
			}
		}
		if ( ! $id ) {
			$t  = self::tables();
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT m.{$t['id']} FROM {$t['meta']} m JOIN {$t['orders']} o ON o.{$t['oid']} = m.{$t['id']} AND o.{$t['otype']} = 'shop_order' WHERE m.meta_key = %s AND m.meta_value = %s ORDER BY m.{$t['id']} ASC LIMIT 1", $src['ref_meta'], $ref ) ); // phpcs:ignore
		}
		if ( ! $id ) {
			return 0;
		}
		$r = self::migrate_order( $id, $mp, $ref );
		if ( 'conflict' === $r ) {
			return 0;
		}
		Eru_MPO_Logger::info( sprintf( /* translators: %d: order id */ __( 'Eski eklentinin #%d numaralı siparişi devralındı (yeniden içe aktarılmadı).', 'marketplace-orders-for-woocommerce' ), $id ), $mp, $ref );
		return $id;
	}
}
