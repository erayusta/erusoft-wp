<?php
/**
 * Import runs: staging, batched processing, "missing" handling, locking and scheduling.
 *
 * A run goes staging (fetch + stream-parse the XML once into a private JSON-lines file)
 * -> processing (batches of rows) -> finalizing (missing products) -> done.
 * Manual runs are driven step by step from the admin screen; scheduled runs re-enqueue
 * themselves through Action Scheduler. Both call Eru_SXI_Runner::step(), which is guarded
 * by a per-feed lock, so overlapping runs cannot happen.
 *
 * @package supplier-xml-importer-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SXI_Runner {

	const ACTIVE = array( 'staging', 'processing', 'finalizing' );

	/** @var array Memoised seen-SKU set for the current run. */
	private static $seen_cache = array();
	/** @var int Times the seen file was read (diagnostics / tests). */
	public static $seen_loads = 0;

	public static function init() {
		add_action( 'eru_sxi_scheduled', array( __CLASS__, 'scheduled' ) );
		add_action( 'eru_sxi_batch', array( __CLASS__, 'batch_action' ) );
	}

	/* ---- state ---- */

	private static function opt( $feed_id ) {
		return 'eru_sxi_run_' . preg_replace( '/[^a-z0-9]/i', '', (string) $feed_id );
	}

	public static function state( $feed_id ) {
		$s = get_option( self::opt( $feed_id ), null );
		return is_array( $s ) ? $s : null;
	}

	/** Read straight from the database, bypassing option/object caches (another process may have written). */
	private static function fresh_state( $feed_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::opt( $feed_id ) ) );
		$s   = null === $raw ? null : maybe_unserialize( $raw );
		return is_array( $s ) ? $s : null;
	}

	private static function save_state( $feed_id, array $s ) {
		// A cancel issued while a step was running must not be overwritten by that step's stale copy.
		$fresh = self::fresh_state( $feed_id );
		if ( $fresh && 'cancelled' === $fresh['status'] && 'cancelled' !== $s['status'] && $fresh['run_id'] === $s['run_id'] ) {
			return $fresh;
		}
		$s['updated'] = time();
		update_option( self::opt( $feed_id ), $s, false );
		return $s;
	}

	public static function forget( $feed_id ) {
		$s = self::state( $feed_id );
		if ( $s ) {
			self::cleanup_files( $s );
		}
		delete_option( self::opt( $feed_id ) );
		foreach ( array( 'step_', 'start_', '' ) as $prefix ) {
			delete_option( 'eru_sxi_lock_' . $prefix . $feed_id );
		}
	}

	public static function is_active( $state ) {
		return $state && in_array( $state['status'], self::ACTIVE, true );
	}

	private static function stale_after() {
		return (int) apply_filters( 'eru_sxi_stale_seconds', 15 * MINUTE_IN_SECONDS );
	}

	/* ---- lock (atomic via add_option; stale locks are taken over with compare-and-swap) ---- */

	/** @var array<string,string> Tokens of the locks this process holds. */
	private static $tokens = array();

	private static function lock_time( $value ) {
		$parts = explode( '|', (string) $value );
		return (int) end( $parts );
	}

	/**
	 * Atomic lock: INSERT IGNORE on the unique option_name, a random token as value.
	 * A stale lock (older than the TTL) is taken over with a compare-and-swap UPDATE.
	 */
	public static function lock( $key ) {
		global $wpdb;
		$name  = 'eru_sxi_lock_' . $key;
		$now   = time();
		$token = wp_generate_password( 16, false ) . '|' . $now;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ins = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token ) );
		if ( 1 === (int) $ins ) {
			self::$tokens[ $name ] = $token;
			return true;
		}
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null !== $held && ( $now - self::lock_time( $held ) ) < self::stale_after() ) {
			return false;
		}
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, (string) $held ) );
		// phpcs:enable
		if ( 1 === (int) $taken ) {
			self::$tokens[ $name ] = $token;
			return true;
		}
		return false;
	}

	/** Release only a lock this process still owns (compare-and-delete). */
	public static function unlock( $key ) {
		global $wpdb;
		$name = 'eru_sxi_lock_' . $key;
		if ( isset( self::$tokens[ $name ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, self::$tokens[ $name ] ) );
			unset( self::$tokens[ $name ] );
		}
	}

	/* ---- run lifecycle ---- */

	/**
	 * Begin a run. Refuses when another run of this feed is active.
	 *
	 * @param string $mode    'dry' or 'live'.
	 * @param string $trigger 'manual' or 'schedule'.
	 * @return array|WP_Error
	 */
	public static function start( $feed_id, $mode, $trigger = 'manual' ) {
		$feed = Eru_SXI_Feeds::get( $feed_id );
		if ( ! $feed ) {
			return new WP_Error( 'eru_sxi_nofeed', __( 'Besleme bulunamadı.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$problems = Eru_SXI_Feeds::problems( $feed );
		if ( $problems ) {
			return new WP_Error( 'eru_sxi_config', implode( ' ', $problems ) );
		}
		if ( ! self::lock( 'start_' . $feed_id ) ) {
			return new WP_Error( 'eru_sxi_busy', __( 'Bu besleme için başka bir işlem şu anda başlatılıyor.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		try {
			$old = self::state( $feed_id );
			if ( self::is_active( $old ) && ( time() - (int) $old['updated'] ) < self::stale_after() ) {
				return new WP_Error( 'eru_sxi_running', __( 'Bu besleme için bir çalıştırma zaten sürüyor.', 'supplier-xml-importer-for-woocommerce' ) );
			}
			if ( $old ) {
				self::cleanup_files( $old );
			}
			self::sweep_old_files();
			$run_id = wp_generate_password( 10, false );
			return self::save_state(
				$feed_id,
				array(
					'run_id'   => $run_id,
					'mode'     => 'live' === $mode ? 'live' : 'dry',
					'trigger'  => $trigger,
					'status'   => 'staging',
					'started'  => time(),
					'finished' => 0,
					'total'    => 0,
					'pos'      => 0,
					'last_id'  => 0,
					'rows'     => 'rows-' . $run_id . '.ndjson',
					'seen'     => 'seen-' . $run_id . '.txt',
					'download' => '',
					'counts'   => array(
						'create'    => 0,
						'update'    => 0,
						'unchanged' => 0,
						'skip'      => 0,
						'error'     => 0,
						'missing'   => 0,
					),
					'preview'  => array(),
					'messages' => array(),
				)
			);
		} finally {
			self::unlock( 'start_' . $feed_id );
		}
	}

	public static function cancel( $feed_id ) {
		$s = self::state( $feed_id );
		if ( self::is_active( $s ) ) {
			$s['status']   = 'cancelled';
			$s['finished'] = time();
			self::cleanup_files( $s );
			self::save_state( $feed_id, $s );
		}
		return $s;
	}

	/**
	 * Do one unit of work. Returns the state (with 'busy' => true when another worker holds the lock).
	 */
	public static function step( $feed_id ) {
		$s = self::state( $feed_id );
		if ( ! self::is_active( $s ) ) {
			return $s;
		}
		if ( ! self::lock( 'step_' . $feed_id ) ) {
			$s['busy'] = true;
			return $s;
		}
		try {
			$s = self::fresh_state( $feed_id ); // Re-read under the lock.
			if ( ! self::is_active( $s ) ) {
				return $s;
			}
			$feed = Eru_SXI_Feeds::get( $feed_id );
			if ( ! $feed ) {
				return self::fail( $feed_id, $s, __( 'Besleme silinmiş.', 'supplier-xml-importer-for-woocommerce' ) );
			}
			if ( function_exists( 'wc_set_time_limit' ) ) {
				wc_set_time_limit( 0 );
			}
			switch ( $s['status'] ) {
				case 'staging':
					$s = self::stage( $feed_id, $feed, $s );
					break;
				case 'processing':
					$s = self::process( $feed_id, $feed, $s );
					break;
				case 'finalizing':
					$s = self::finalize( $feed_id, $feed, $s );
					break;
			}
			return $s;
		} finally {
			self::unlock( 'step_' . $feed_id );
		}
	}

	/** Synchronous driver (tests, WP-CLI). */
	public static function run_sync( $feed_id, $mode ) {
		$s = self::start( $feed_id, $mode, 'cli' );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$guard = 100000;
		while ( self::is_active( $s ) && $guard-- ) {
			$s = self::step( $feed_id );
		}
		return $s;
	}

	private static function fail( $feed_id, array $s, $message ) {
		$s['status']     = 'failed';
		$s['finished']   = time();
		$s['messages'][] = $message;
		self::cleanup_files( $s );
		return self::save_state( $feed_id, $s );
	}

	/* ---- stages ---- */

	private static function stage( $feed_id, array $feed, array $s ) {
		$dir = Eru_SXI_Feeds::dir();
		if ( 'url' === $feed['source'] ) {
			$tmp = 'dl-' . $s['run_id'] . '.xml';
			$res = Eru_SXI_Feeds::fetch( $feed['url'], $dir . '/' . $tmp );
			if ( is_wp_error( $res ) ) {
				return self::fail( $feed_id, $s, $res->get_error_message() );
			}
			$s['download'] = $tmp;
			$source        = $dir . '/' . $tmp;
			if ( filesize( $source ) >= Eru_SXI_Feeds::max_bytes() ) {
				return self::fail( $feed_id, $s, __( 'XML dosyası izin verilen boyuttan büyük.', 'supplier-xml-importer-for-woocommerce' ) );
			}
		} else {
			$source = $dir . '/' . basename( $feed['file'] );
		}
		$out = fopen( $dir . '/' . $s['rows'], 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			return self::fail( $feed_id, $s, __( 'Geçici dosya yazılamadı.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$max = (int) apply_filters( 'eru_sxi_max_items', 200000 );
		$res = Eru_SXI_Reader::parse(
			$source,
			$feed,
			function ( $row ) use ( $out ) {
				fwrite( $out, wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			},
			$max
		);
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( is_wp_error( $res ) ) {
			return self::fail( $feed_id, $s, $res->get_error_message() );
		}
		if ( 0 === $res ) {
			return self::fail( $feed_id, $s, __( 'Belirtilen öğe yolunda hiç ürün bulunamadı; hiçbir şey değiştirilmedi.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		if ( $s['download'] ) {
			wp_delete_file( $dir . '/' . $s['download'] ); // Rows are staged; the raw download is no longer needed.
			$s['download'] = '';
		}
		$s['total']  = $res;
		$s['status'] = 'processing';
		return self::save_state( $feed_id, $s );
	}

	private static function process( $feed_id, array $feed, array $s ) {
		$dir  = Eru_SXI_Feeds::dir();
		$path = $dir . '/' . $s['rows'];
		$fh   = is_file( $path ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return self::fail( $feed_id, $s, __( 'Hazırlanan satır dosyası bulunamadı.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		fseek( $fh, (int) $s['pos'] );
		$dry     = 'dry' === $s['mode'];
		$size    = max( 1, (int) apply_filters( 'eru_sxi_batch_size', 20 ) );
		$budget  = (int) apply_filters( 'eru_sxi_time_budget', 20 );
		$started = microtime( true );
		$seen    = fopen( $dir . '/' . $s['seen'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$limit   = (int) apply_filters( 'eru_sxi_preview_limit', 200 );
		$done    = 0;
		while ( $done < $size && ( microtime( true ) - $started ) < $budget && false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) ) {
				continue;
			}
			$res = Eru_SXI_Importer::process_row( $feed, $row, $dry, $s['run_id'] );
			++$s['counts'][ $res['action'] ];
			if ( $seen && '' !== $res['sku'] ) {
				fwrite( $seen, $res['sku'] . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
			if ( 'error' === $res['action'] && count( $s['messages'] ) < 50 ) {
				$s['messages'][] = $res['sku'] . ': ' . $res['note'];
			}
			if ( count( $s['preview'] ) < $limit ) {
				unset( $res['id'] );
				$s['preview'][] = $res;
			}
			++$done;
		}
		$s['pos'] = (int) ftell( $fh );
		$eof      = feof( $fh ) || $s['pos'] >= filesize( $path );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( $seen ) {
			fclose( $seen ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $eof ) {
			$s['status'] = 'ignore' !== $feed['missing'] ? 'finalizing' : 'done';
			if ( 'done' === $s['status'] ) {
				return self::done( $feed_id, $s );
			}
		}
		return self::save_state( $feed_id, $s );
	}

	private static function finalize( $feed_id, array $feed, array $s ) {
		global $wpdb;
		$dry = 'dry' === $s['mode'];
		if ( $dry ) {
			// Count feed products whose SKU never appeared in the file; nothing is written.
			$seen = self::seen_set( $s );
			$last = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id > %d ORDER BY post_id LIMIT 500", Eru_SXI_Importer::META_FEED, $feed_id, $last ) );
				foreach ( $ids as $id ) {
					$last = (int) $id;
					$p    = wc_get_product( $id );
					if ( $p && ! $p->is_type( 'variable' ) && ! isset( $seen[ $p->get_sku() ] ) ) {
						++$s['counts']['missing'];
					}
				}
			} while ( $ids );
			return self::done( $feed_id, $s );
		}
		$seen = self::seen_set( $s );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT f.post_id FROM {$wpdb->postmeta} f
				 WHERE f.meta_key = %s AND f.meta_value = %s AND f.post_id > %d
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} r WHERE r.post_id = f.post_id AND r.meta_key = %s AND r.meta_value = %s )
				 ORDER BY f.post_id LIMIT 50",
				Eru_SXI_Importer::META_FEED,
				$feed_id,
				(int) $s['last_id'],
				Eru_SXI_Importer::META_RUN,
				$s['run_id']
			)
		);
		// phpcs:enable
		if ( ! $ids ) {
			return self::done( $feed_id, $s );
		}
		foreach ( $ids as $id ) {
			$s['last_id'] = (int) $id;
			$sku          = (string) get_post_meta( (int) $id, '_sku', true );
			if ( '' !== $sku && isset( $seen[ $sku ] ) ) {
				continue; // Present in the feed (even if its row was skipped): never "missing".
			}
			if ( Eru_SXI_Importer::handle_missing( (int) $id, $feed['missing'] ) ) {
				++$s['counts']['missing'];
			}
		}
		return self::save_state( $feed_id, $s );
	}

	/** SKUs present in the feed for this run, as a lookup set. */
	private static function seen_set( array $s ) {
		if ( isset( self::$seen_cache[ $s['run_id'] ] ) ) {
			return self::$seen_cache[ $s['run_id'] ];
		}
		++self::$seen_loads;
		$path = Eru_SXI_Feeds::dir() . '/' . $s['seen'];
		self::$seen_cache = array( $s['run_id'] => is_file( $path ) ? array_flip( array_map( 'trim', file( $path ) ?: array() ) ) : array() );
		return self::$seen_cache[ $s['run_id'] ];
	}

	private static function done( $feed_id, array $s ) {
		$s['status']   = 'done';
		$s['finished'] = time();
		self::cleanup_files( $s );
		return self::save_state( $feed_id, $s );
	}

	private static function cleanup_files( array $s ) {
		unset( self::$seen_cache[ $s['run_id'] ?? '' ] );
		$dir = Eru_SXI_Feeds::dir();
		foreach ( array( 'rows', 'seen', 'download' ) as $k ) {
			if ( ! empty( $s[ $k ] ) && is_file( $dir . '/' . basename( $s[ $k ] ) ) ) {
				wp_delete_file( $dir . '/' . basename( $s[ $k ] ) );
			}
		}
	}

	/** Remove leftovers of crashed runs (older than a day). */
	private static function sweep_old_files() {
		$dir = Eru_SXI_Feeds::dir();
		foreach ( (array) glob( $dir . '/{rows-,seen-,dl-}*', GLOB_BRACE ) as $f ) {
			if ( is_file( $f ) && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $f );
			}
		}
	}

	/* ---- Action Scheduler ---- */

	public static function scheduled( $feed_id ) {
		$feed = Eru_SXI_Feeds::get( $feed_id );
		if ( ! $feed || 'off' === $feed['schedule'] ) {
			return;
		}
		$s = self::start( $feed_id, 'live', 'schedule' );
		if ( ! is_wp_error( $s ) ) {
			self::enqueue( $feed_id );
		}
	}

	public static function batch_action( $feed_id ) {
		$budget  = (int) apply_filters( 'eru_sxi_action_budget', 20 );
		$started = microtime( true );
		do {
			$step_start = microtime( true );
			$s          = self::step( $feed_id );
			$last       = microtime( true ) - $step_start;
			// Do not start another step that would likely overshoot the budget.
		} while ( self::is_active( $s ) && empty( $s['busy'] ) && ( microtime( true ) - $started ) + $last < $budget );
		if ( self::is_active( $s ) ) {
			self::enqueue( $feed_id, ! empty( $s['busy'] ) ? 30 : 0 );
		}
	}

	private static function enqueue( $feed_id, $delay = 0 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		// Only a PENDING action counts: the running one would otherwise block its own continuation.
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => 'eru_sxi_batch',
				'args'     => array( $feed_id ),
				'group'    => 'eru-sxi',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( $pending ) {
			return;
		}
		as_schedule_single_action( time() + $delay, 'eru_sxi_batch', array( $feed_id ), 'eru-sxi' );
	}
}
