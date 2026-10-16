<?php
/**
 * Stock bridge module: WooCommerce stock and price changes are queued per marketplace and pushed in batches
 * through the adapters (Trendyol price-and-inventory, Hepsiburada listing uploads), with the rules applied.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switches per marketplace (eru_mpo_mp_<key>): push_stock, push_price (yes|no, default no). The bridge of a
 * marketplace is active only while the master switch is on, the adapter is configured (and, when it has
 * bridge_ready(), ready for the bridge: Hepsiburada needs its listing API address), one of the two pushes is on and
 * the older plugin of that marketplace is not active. Observe mode logs "gönderilirdi" and sends nothing.
 *
 * - Dirty marking: woocommerce_product_set_stock, woocommerce_variation_set_stock, product saves that change
 *   stock or price properties (woocommerce_product_object_updated_props) and trashing write one row per product,
 *   marketplace and kind into {prefix}eru_mpo_sync_queue (UNIQUE; a repeated change bumps seq). Excluded products
 *   are not queued. A variable product queues its variations.
 * - Flush: eru_mpo_stock_flush( $mp ), a single Action Scheduler action 15 seconds after the first change (one
 *   pending action per marketplace: later changes inside the window join it). Values are read at flush time, so
 *   the push always carries the latest stock and price (absolute values: a retry never applies a change twice).
 *   A row is removed only if its seq did not change during the push. A failed push keeps its rows with a backoff
 *   (1, 2, 4 … 30 minutes); after 8 attempts a row stops until the product changes again.
 * - Safety sweep: eru_mpo_stock_sweep every 10 minutes (re-armed pending-only) schedules a flush for rows that are
 *   due and have none.
 * - Listings refresh: eru_mpo_listings_refresh( $mp ) every hour reads the marketplace listings (remote SKU, id,
 *   barcode, salable) into {prefix}eru_mpo_listings, continued by eru_mpo_listings_next( $mp, $page ) (no page
 *   cap); pushes use the listing's barcode, and order lines match it. Only when the last run read every page are
 *   products without a listing held back: their rows wait (marker UNLISTED, a day), they are never dropped, and the
 *   next complete run or a new change of the product makes them due again.
 * - Full sync: eru_mpo_full_sync( $mp, $after_id ) queues every published product in batches of 200
 *   (filter eru_mpo_full_sync_batch); nothing is sent from the page request.
 *
 * Run state per marketplace: options eru_mpo_sync_bridge_<mp>, eru_mpo_sync_full_<mp>, eru_mpo_sync_listings_<mp>
 * (autoload off).
 */
class Eru_MPO_Stock_Bridge {

	const FLUSH          = 'eru_mpo_stock_flush';
	const SWEEP          = 'eru_mpo_stock_sweep';
	const LISTINGS       = 'eru_mpo_listings_refresh';
	const LISTINGS_NEXT  = 'eru_mpo_listings_next';
	/** Queue rows of products the marketplace does not list (after a complete listings refresh) wait with this marker. */
	const UNLISTED       = 'eru_mpo_unlisted';
	const FULL           = 'eru_mpo_full_sync';
	const KINDS          = array( 'stock', 'price' );
	const DEBOUNCE       = 15;
	const SWEEP_EVERY    = 600;
	const LISTINGS_EVERY = 3600;
	const PUSH_BATCH     = 100;
	const FLUSH_MAX      = 1000;
	const MAX_ATTEMPTS   = 8;
	const MAX_DELAY      = 1800;
	const PAGES_PER_RUN  = 50;
	const STOCK_PROPS    = array( 'stock_quantity', 'stock_status', 'manage_stock', 'status', 'backorders', 'sku', 'global_unique_id' );
	const PRICE_PROPS    = array( 'price', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'sku', 'global_unique_id' );

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_set_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_set_stock' ) );
		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'on_props' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( self::FLUSH, array( __CLASS__, 'run_flush' ) );
		add_action( self::SWEEP, array( __CLASS__, 'sweep' ) );
		add_action( self::LISTINGS, array( __CLASS__, 'run_listings' ) );
		add_action( self::LISTINGS_NEXT, array( __CLASS__, 'run_listings_next' ), 10, 2 );
		add_action( self::FULL, array( __CLASS__, 'full_sync_batch' ), 10, 2 );
		add_action( 'eru_mpo_ensure', array( __CLASS__, 'ensure' ) );
	}

	/** @return string Queue table. */
	private static function table() {
		return Eru_MPO_Installer::table( 'sync_queue' );
	}

	/**
	 * @param string $mp Marketplace.
	 * @return bool The bridge runs for this marketplace.
	 */
	public static function active( $mp ) {
		if ( ! Eru_MPO_Settings::enabled() ) {
			return false;
		}
		$a = Eru_MPO_Registry::get( (string) $mp );
		if ( ! $a || ! $a->is_configured() || ( method_exists( $a, 'bridge_ready' ) && ! $a->bridge_ready() ) ) {
			return false;
		}
		if ( 'yes' !== Eru_MPO_Settings::mp_get( $mp, 'push_stock', 'no' ) && 'yes' !== Eru_MPO_Settings::mp_get( $mp, 'push_price', 'no' ) ) {
			return false;
		}
		return ! in_array( (string) $mp, Eru_MPO_Migrator::legacy_active(), true );
	}

	/** @param string $mp Marketplace. @param string $kind stock | price. @return bool */
	public static function wants( $mp, $kind ) {
		return in_array( $kind, self::KINDS, true ) && 'yes' === Eru_MPO_Settings::mp_get( $mp, 'push_' . $kind, 'no' ) && self::active( $mp );
	}

	/** @return string[] Marketplaces whose bridge runs. */
	public static function active_marketplaces() {
		if ( ! Eru_MPO_Settings::enabled() ) {
			return array();
		}
		return array_values( array_filter( array_keys( Eru_MPO_Registry::all() ), array( __CLASS__, 'active' ) ) );
	}

	/** @param WC_Product $p Product. @return bool A sellable item (not a variable parent, grouped or external product). */
	public static function pushable( $p ) {
		return $p instanceof WC_Product && ! $p->is_type( array( 'variable', 'grouped', 'external' ) );
	}

	/** woocommerce_product_set_stock / woocommerce_variation_set_stock. @param WC_Product $product Product. */
	public static function on_set_stock( $product ) {
		if ( $product instanceof WC_Product && self::active_marketplaces() ) {
			self::mark( $product->get_id(), array( 'stock' ) );
		}
	}

	/** woocommerce_product_object_updated_props. @param WC_Product $product Product. @param string[] $props Changed. */
	public static function on_props( $product, $props ) {
		if ( ! $product instanceof WC_Product || ! self::active_marketplaces() ) {
			return;
		}
		$props = (array) $props;
		$kinds = array();
		if ( array_intersect( $props, self::STOCK_PROPS ) ) {
			$kinds[] = 'stock';
		}
		if ( array_intersect( $props, self::PRICE_PROPS ) ) {
			$kinds[] = 'price';
		}
		if ( $kinds ) {
			self::mark( $product->get_id(), $kinds );
		}
	}

	/** trashed_post / untrashed_post: a trashed product is pushed with stock 0. @param int $post_id Post. */
	public static function on_trash( $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) && self::active_marketplaces() ) {
			self::mark( (int) $post_id, array( 'stock' ) );
		}
	}

	/**
	 * before_delete_post: a permanently deleted product leaves no queue or listing rows behind. (The marketplace
	 * keeps its last stock: trash the product first, or set its stock to 0, to send 0.)
	 *
	 * @param int $post_id Post.
	 */
	public static function on_delete( $post_id ) {
		global $wpdb;
		if ( ! in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$wpdb->delete( self::table(), array( 'product_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Eru_MPO_Installer::table( 'listings' ), array( 'wc_product_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Queues a product for every marketplace whose bridge wants the kind (excluded products are skipped).
	 *
	 * @param int      $product_id Product or variation (a variable product queues its variations).
	 * @param string[] $kinds      stock and/or price.
	 * @param bool     $schedule   Schedule the debounced flush (false: the caller schedules once).
	 * @param string   $only       Only this marketplace ('' = every active one).
	 * @return int Rows queued or bumped.
	 */
	public static function mark( $product_id, array $kinds = self::KINDS, $schedule = true, $only = '' ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return 0;
		}
		if ( $product->is_type( 'variable' ) ) {
			$n = 0;
			foreach ( array_slice( $product->get_children(), 0, 500 ) as $child ) {
				$n += self::mark( (int) $child, $kinds, $schedule, $only );
			}
			return $n;
		}
		if ( ! self::pushable( $product ) ) {
			return 0;
		}
		$n = 0;
		foreach ( self::active_marketplaces() as $mp ) {
			if ( ( '' !== $only && $only !== $mp ) || Eru_MPO_Rules::is_excluded( $product, Eru_MPO_Rules::get( $mp ) ) ) {
				continue;
			}
			$touched = false;
			foreach ( array_intersect( $kinds, self::KINDS ) as $kind ) {
				if ( self::wants( $mp, $kind ) ) {
					self::queue( $product->get_id(), $mp, $kind );
					$touched = true;
					++$n;
				}
			}
			if ( $touched && $schedule ) {
				self::schedule_flush( $mp );
			}
		}
		return $n;
	}

	/** Inserts or bumps one queue row (a given-up row starts over: the product changed). */
	private static function queue( $product_id, $mp, $kind ) {
		global $wpdb;
		$t = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$t} (product_id, marketplace, kind, queued_at, seq, attempts) VALUES (%d, %s, %s, %s, 1, 0) ON DUPLICATE KEY UPDATE seq = seq + 1, queued_at = VALUES(queued_at), next_try_at = IF(attempts >= %d OR last_error = %s, NULL, next_try_at), last_error = IF(attempts >= %d OR last_error = %s, NULL, last_error), attempts = IF(attempts >= %d, 0, attempts)", // phpcs:ignore
				(int) $product_id,
				substr( (string) $mp, 0, 32 ),
				$kind,
				gmdate( 'Y-m-d H:i:s' ),
				self::MAX_ATTEMPTS,
				self::UNLISTED,
				self::MAX_ATTEMPTS,
				self::UNLISTED,
				self::MAX_ATTEMPTS
			)
		);
	}

	/**
	 * @param string     $hook Hook.
	 * @param array|null $args Exact args, or null for any.
	 * @return int[] Pending (not running) actions.
	 */
	public static function pending( $hook, $args = null ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}
		$q = array(
			'hook'     => $hook,
			'group'    => Eru_MPO_Cron::GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 20,
		);
		if ( null !== $args ) {
			$q['args'] = $args;
		}
		return as_get_scheduled_actions( $q, 'ids' );
	}

	/** @param int $id Action. @return int Scheduled UTC timestamp (0 = unknown). */
	private static function action_time( $id ) {
		$a = ActionScheduler::store()->fetch_action( $id );
		$d = $a && $a->get_schedule() ? $a->get_schedule()->get_date() : null;
		return $d ? $d->getTimestamp() : 0;
	}

	/** @return bool Action Scheduler can be used now (deferred to action_scheduler_init otherwise). */
	private static function scheduler_ready( callable $retry ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		if ( ! did_action( 'action_scheduler_init' ) ) {
			add_action( 'action_scheduler_init', $retry );
			return false;
		}
		return true;
	}

	/**
	 * Makes sure one flush is pending no later than $delay seconds from now (a later one is moved earlier).
	 *
	 * @param string $mp    Marketplace.
	 * @param int    $delay Seconds.
	 */
	public static function schedule_flush( $mp, $delay = self::DEBOUNCE ) {
		if ( ! self::scheduler_ready( fn() => self::schedule_flush( $mp, $delay ) ) ) {
			return;
		}
		$want = time() + max( 1, (int) $delay );
		$late = array();
		foreach ( self::pending( self::FLUSH, array( (string) $mp ) ) as $id ) {
			if ( self::action_time( $id ) <= $want + 5 ) {
				return;
			}
			$late[] = $id;
		}
		foreach ( $late as $id ) {
			ActionScheduler::store()->cancel_action( $id );
		}
		as_schedule_single_action( $want, self::FLUSH, array( (string) $mp ), Eru_MPO_Cron::GROUP );
	}

	/**
	 * After a flush: one pending flush at the earliest useful time (rows due now: after the debounce; rows backing
	 * off: when the first backoff ends), earlier useless ones removed; none when the queue is empty.
	 *
	 * @param string $mp Marketplace.
	 */
	private static function rearm_after( $mp ) {
		global $wpdb;
		$t   = self::table();
		$min = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(COALESCE(next_try_at, queued_at)) FROM {$t} WHERE marketplace = %s AND attempts < %d", $mp, self::MAX_ATTEMPTS ) ); // phpcs:ignore
		if ( null === $min ) {
			return;
		}
		$want  = max( time() + self::DEBOUNCE, (int) strtotime( $min . ' UTC' ) );
		$found = false;
		foreach ( self::pending( self::FLUSH, array( (string) $mp ) ) as $id ) {
			if ( ! $found && abs( self::action_time( $id ) - $want ) <= 5 ) {
				$found = true;
				continue;
			}
			ActionScheduler::store()->cancel_action( $id ); // Too early (nothing due yet) or too late.
		}
		if ( ! $found ) {
			as_schedule_single_action( $want, self::FLUSH, array( (string) $mp ), Eru_MPO_Cron::GROUP );
		}
	}

	/**
	 * Push identity of a product on a marketplace: the listing row (matched by product or SKU) gives the barcode
	 * the marketplace knows; otherwise the product's GTIN. The SKU is the shop's SKU.
	 *
	 * @param WC_Product $p  Product.
	 * @param string     $mp Marketplace.
	 * @return array{sku: string, barcode: string, listed: bool}
	 */
	public static function remote_item( $p, $mp ) {
		global $wpdb;
		$sku = (string) $p->get_sku();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT wc_sku, barcode FROM ' . Eru_MPO_Installer::table( 'listings' ) . " WHERE marketplace = %s AND ( wc_product_id = %d OR ( %s <> '' AND wc_sku = %s ) ) ORDER BY wc_product_id DESC, id ASC LIMIT 1", $mp, $p->get_id(), $sku, $sku ) ); // phpcs:ignore
		$gtin = method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id() : '';
		$out  = array(
			'sku'     => $row && '' !== (string) $row->wc_sku ? (string) $row->wc_sku : $sku,
			'barcode' => $row && '' !== (string) $row->barcode ? (string) $row->barcode : $gtin,
			'listed'  => (bool) $row,
		);
		return (array) apply_filters( 'eru_mpo_remote_item', $out, $p, $mp );
	}

	/** Removes a pushed row unless it changed during the push. */
	private static function done_row( $r ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE id = %d AND seq = %d', (int) $r->id, (int) $r->seq ) ); // phpcs:ignore
	}

	/** Records a failed push on a row: attempts + 1, backoff, error text. */
	private static function fail_row( $r, $msg, $retry_after = 0 ) {
		global $wpdb;
		$n     = (int) $r->attempts + 1;
		$delay = (int) min( self::MAX_DELAY, max( MINUTE_IN_SECONDS * ( 2 ** min( 10, $n - 1 ) ), min( self::MAX_DELAY, (int) $retry_after ) ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'attempts'    => $n,
				'next_try_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'last_error'  => mb_substr( Eru_MPO_Logger::redact( (string) $msg ), 0, 500 ),
			),
			array( 'id' => (int) $r->id )
		);
	}

	/**
	 * Holds the row of a product the marketplace does not list: kept with the UNLISTED marker and a day's wait. A
	 * complete listings refresh, or a new change of the product, makes it due again.
	 */
	private static function wait_unlisted( $r ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET next_try_at = %s, last_error = %s WHERE id = %d AND seq = %d', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), self::UNLISTED, (int) $r->id, (int) $r->seq ) ); // phpcs:ignore
	}

	/** Action Scheduler entry point. @param string $mp Marketplace. */
	public static function run_flush( $mp ) {
		self::flush( (string) $mp );
	}

	/**
	 * Pushes the due rows of a marketplace (up to 1.000 per run, 100 per request).
	 *
	 * @param string $mp Marketplace.
	 * @return array{result: string, stock: int, price: int, skipped: int, failed: int, observed: int}
	 */
	public static function flush( $mp ) {
		global $wpdb;
		$mp  = sanitize_key( (string) $mp );
		$sum = array(
			'result'   => 'ok',
			'stock'    => 0,
			'price'    => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'observed' => 0,
			'unlisted' => 0,
		);
		$adapter = Eru_MPO_Registry::get( $mp );
		if ( ! $adapter || ! self::active( $mp ) ) {
			return array( 'result' => 'off' ) + $sum;
		}
		$lock  = 'flush_' . $mp;
		$token = Eru_MPO_Lock::acquire( $lock, 10 * MINUTE_IN_SECONDS );
		if ( ! $token ) {
			self::schedule_flush( $mp, MINUTE_IN_SECONDS );
			return array( 'result' => 'locked' ) + $sum;
		}
		try {
			$t       = self::table();
			$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, product_id, kind, seq, attempts FROM {$t} WHERE marketplace = %s AND attempts < %d AND ( next_try_at IS NULL OR next_try_at <= %s ) ORDER BY id ASC LIMIT %d", $mp, self::MAX_ATTEMPTS, gmdate( 'Y-m-d H:i:s' ), self::FLUSH_MAX ) ); // phpcs:ignore
			$rules   = Eru_MPO_Rules::get( $mp );
			// Only after the last listings refresh read every page are unlisted products held back (they wait, they
			// are never dropped); while a refresh is partial, failed or never ran, every product is sent.
			$listed  = ! empty( self::state( 'listings', $mp )['complete'] );
			$batches = array(
				'stock' => array(),
				'price' => array(),
			);
			foreach ( (array) $rows as $r ) {
				$p = self::wants( $mp, $r->kind ) ? wc_get_product( (int) $r->product_id ) : null;
				if ( ! $p || ! self::pushable( $p ) || Eru_MPO_Rules::is_excluded( $p, $rules ) ) {
					self::done_row( $r );
					++$sum['skipped'];
					continue;
				}
				$item = self::remote_item( $p, $mp ) + array( 'product_id' => $p->get_id() );
				if ( $listed && empty( $item['listed'] ) ) {
					self::wait_unlisted( $r );
					++$sum['unlisted'];
					continue;
				}
				if ( 'stock' === $r->kind ) {
					$item['quantity'] = Eru_MPO_Rules::stock_for( $p, $rules );
				} else {
					$prices = Eru_MPO_Rules::prices_for( $p, $rules );
					if ( ! $prices ) {
						self::done_row( $r );
						++$sum['skipped'];
						continue;
					}
					$item += $prices;
				}
				$batches[ $r->kind ][] = array(
					'item' => $item,
					'row'  => $r,
				);
			}
			$observe = $adapter->is_observe_mode();
			$label   = $adapter->label();
			foreach ( $batches as $kind => $entries ) {
				foreach ( array_chunk( $entries, self::PUSH_BATCH ) as $chunk ) {
					$items = array_column( $chunk, 'item' );
					$what  = 'stock' === $kind ? __( 'stoğu', 'marketplace-orders-for-woocommerce' ) : __( 'fiyatı', 'marketplace-orders-for-woocommerce' );
					if ( $observe ) {
						$sample = array_slice( array_map( fn( $i ) => $i['sku'] . '=' . ( 'stock' === $kind ? $i['quantity'] : $i['price'] ), $items ), 0, 10 );
						/* translators: 1: marketplace, 2: number of products, 3: "stoğu" or "fiyatı", 4: sample list */
						Eru_MPO_Logger::info( sprintf( __( 'Gözlem modu: %1$s için %2$d ürünün %3$s gönderilirdi (%4$s).', 'marketplace-orders-for-woocommerce' ), $label, count( $items ), $what, implode( ', ', $sample ) ), $mp );
						foreach ( $chunk as $e ) {
							self::done_row( $e['row'] );
						}
						$sum['observed'] += count( $items );
						continue;
					}
					try {
						$res = 'stock' === $kind ? $adapter->push_inventory( $items ) : $adapter->push_price( $items );
					} catch ( Throwable $e ) {
						$res = new WP_Error( 'eru_mpo_push_exception', $e->getMessage(), array( 'retryable' => true ) );
					}
					if ( is_wp_error( $res ) ) {
						$data = $res->get_error_data();
						foreach ( $chunk as $e ) {
							self::fail_row( $e['row'], $res->get_error_message(), is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0 );
						}
						$sum['failed'] += count( $chunk );
						$sum['result']  = 'error';
						/* translators: 1: marketplace, 2: number of products, 3: "stoğu" or "fiyatı", 4: error */
						Eru_MPO_Logger::error( sprintf( __( '%1$s: %2$d ürünün %3$s gönderilemedi, yeniden denenecek: %4$s', 'marketplace-orders-for-woocommerce' ), $label, count( $chunk ), $what, $res->get_error_message() ), $mp );
						continue;
					}
					$bad = is_array( $res['failed'] ?? null ) ? $res['failed'] : array();
					$ok  = 0;
					foreach ( $chunk as $i => $e ) {
						$key = '' !== (string) $e['item']['sku'] ? (string) $e['item']['sku'] : '#' . $i;
						if ( isset( $bad[ $key ] ) ) {
							self::fail_row( $e['row'], (string) $bad[ $key ] );
							++$sum['failed'];
							continue;
						}
						self::done_row( $e['row'] );
						++$ok;
					}
					$sum[ $kind ] += $ok;
					/* translators: 1: marketplace, 2: number of products, 3: "stoğu" or "fiyatı", 4: batch id or "-" */
					Eru_MPO_Logger::info( sprintf( __( '%1$s: %2$d ürünün %3$s gönderildi (iş no: %4$s).', 'marketplace-orders-for-woocommerce' ), $label, $ok, $what, '' !== (string) ( $res['batch'] ?? '' ) ? (string) $res['batch'] : '-' ), $mp );
					if ( $bad ) {
						/* translators: 1: marketplace, 2: SKU => reason list */
						Eru_MPO_Logger::warning( sprintf( __( '%1$s: gönderilemeyen ürünler: %2$s', 'marketplace-orders-for-woocommerce' ), $label, implode( '; ', array_slice( array_map( fn( $k, $v ) => $k . ': ' . $v, array_keys( $bad ), $bad ), 0, 20 ) ) ), $mp );
					}
				}
			}
			self::rearm_after( $mp );
			self::save_state(
				'bridge',
				$mp,
				array_merge( $sum, array( 'last' => gmdate( 'Y-m-d H:i:s' ) ) )
			);
			return $sum;
		} finally {
			Eru_MPO_Lock::release( $lock, $token );
		}
	}

	/** @param string $kind bridge | full | listings. @param string $mp Marketplace. @return array State. */
	public static function state( $kind, $mp ) {
		$s = get_option( 'eru_mpo_sync_' . $kind . '_' . sanitize_key( $mp ), array() );
		return is_array( $s ) ? $s : array();
	}

	/** Stores a run state (autoload off). */
	private static function save_state( $kind, $mp, array $s ) {
		$name = 'eru_mpo_sync_' . $kind . '_' . sanitize_key( $mp );
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $s, '', false );
		} else {
			update_option( $name, $s, false );
		}
	}

	/**
	 * @param string $mp Marketplace.
	 * @return array{due: int, waiting: int, unlisted: int, given_up: int} Queue counts.
	 */
	public static function queue_counts( $mp ) {
		global $wpdb;
		$t   = self::table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM( attempts < %d AND ( next_try_at IS NULL OR next_try_at <= %s ) ) AS due, SUM( attempts < %d AND next_try_at > %s AND ( last_error IS NULL OR last_error <> %s ) ) AS waiting, SUM( attempts < %d AND next_try_at > %s AND last_error = %s ) AS unlisted, SUM( attempts >= %d ) AS given_up FROM {$t} WHERE marketplace = %s", self::MAX_ATTEMPTS, $now, self::MAX_ATTEMPTS, $now, self::UNLISTED, self::MAX_ATTEMPTS, $now, self::UNLISTED, self::MAX_ATTEMPTS, $mp ) ); // phpcs:ignore
		return array(
			'due'      => (int) ( $row->due ?? 0 ),
			'waiting'  => (int) ( $row->waiting ?? 0 ),
			'unlisted' => (int) ( $row->unlisted ?? 0 ),
			'given_up' => (int) ( $row->given_up ?? 0 ),
		);
	}

	/**
	 * Action Scheduler: hourly listings refresh. Arms the next hourly run first (pending-only); a run still being
	 * continued is not restarted.
	 *
	 * @param string $mp Marketplace.
	 */
	public static function run_listings( $mp ) {
		$mp = sanitize_key( (string) $mp );
		if ( ! self::active( $mp ) ) {
			return;
		}
		if ( ! self::pending( self::LISTINGS, array( $mp ) ) ) {
			as_schedule_single_action( time() + self::LISTINGS_EVERY, self::LISTINGS, array( $mp ), Eru_MPO_Cron::GROUP );
		}
		if ( 'running' === ( self::state( 'listings', $mp )['status'] ?? '' ) && self::listings_next_pending( $mp ) ) {
			return;
		}
		self::refresh_listings( $mp );
	}

	/** Action Scheduler: the continuation of a listings run. @param string $mp Marketplace. @param int $page Next page. */
	public static function run_listings_next( $mp, $page ) {
		$mp = sanitize_key( (string) $mp );
		if ( self::active( $mp ) ) {
			self::refresh_listings( $mp, max( 1, (int) $page ) );
		}
	}

	/** @param string $mp Marketplace. @return bool A continuation of its listings run is pending. */
	private static function listings_next_pending( $mp ) {
		foreach ( self::pending( self::LISTINGS_NEXT ) as $id ) {
			$a = ActionScheduler::store()->fetch_action( $id );
			if ( $a && (string) ( $a->get_args()[0] ?? '' ) === (string) $mp ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads the listing pages of a marketplace into the listings table (matched to products by SKU, then GTIN).
	 *
	 * Page 0 starts a run; at most PAGES_PER_RUN pages (filter eru_mpo_listings_pages_per_run) are read per call
	 * and the rest is continued by eru_mpo_listings_next( $mp, $page ), so there is no page cap. Only a run that
	 * read every page is "complete": it then removes rows it did not see, records completed_at, and releases queue
	 * rows that were waiting as unlisted. A failed or partial run changes no rows it did not read.
	 *
	 * State (option eru_mpo_sync_listings_<mp>): status running|complete|error, complete (bool), started, page,
	 * listings, matched, last, completed_at.
	 *
	 * @param string $mp   Marketplace.
	 * @param int    $page Page to continue from (0 = new run).
	 * @return array{result: string, listings: int, matched: int} result: ok | continued | error | not_ready | locked | stale.
	 */
	public static function refresh_listings( $mp, $page = 0 ) {
		global $wpdb;
		$mp      = sanitize_key( (string) $mp );
		$page    = max( 0, (int) $page );
		$adapter = Eru_MPO_Registry::get( $mp );
		if ( ! $adapter || ! $adapter->is_configured() ) {
			return array(
				'result'   => 'not_ready',
				'listings' => 0,
				'matched'  => 0,
			);
		}
		$lock  = 'listings_' . $mp;
		$token = Eru_MPO_Lock::acquire( $lock, 30 * MINUTE_IN_SECONDS );
		if ( ! $token ) {
			return array(
				'result'   => 'locked',
				'listings' => 0,
				'matched'  => 0,
			);
		}
		try {
			$prev = self::state( 'listings', $mp );
			if ( 0 === $page ) {
				$state = array(
					'status'       => 'running',
					'complete'     => false,
					'started'      => gmdate( 'Y-m-d H:i:s' ),
					'page'         => 0,
					'listings'     => 0,
					'matched'      => 0,
					'last'         => gmdate( 'Y-m-d H:i:s' ),
					'completed_at' => (string) ( $prev['completed_at'] ?? '' ),
				);
			} elseif ( 'running' === ( $prev['status'] ?? '' ) && (int) ( $prev['page'] ?? -1 ) === $page ) {
				$state = $prev;
			} else {
				return array(
					'result'   => 'stale',
					'listings' => 0,
					'matched'  => 0,
				);
			}
			$t     = Eru_MPO_Installer::table( 'listings' );
			$limit = max( 1, (int) apply_filters( 'eru_mpo_listings_pages_per_run', self::PAGES_PER_RUN, $mp ) );
			$read  = 0;
			do {
				try {
					$res = $adapter->fetch_listings( $page );
				} catch ( Throwable $e ) {
					$res = new WP_Error( 'eru_mpo_listings_exception', $e->getMessage() );
				}
				if ( is_wp_error( $res ) ) {
					/* translators: 1: marketplace, 2: page, 3: error */
					Eru_MPO_Logger::error( sprintf( __( '%1$s ilanları okunamadı (sayfa %2$d): %3$s. Yenileme eksik kaldı; ilanı görünmeyen ürünler yine gönderilir.', 'marketplace-orders-for-woocommerce' ), $adapter->label(), $page + 1, $res->get_error_message() ), $mp );
					$state['status']   = 'error';
					$state['complete'] = false;
					$state['last']     = gmdate( 'Y-m-d H:i:s' );
					self::save_state( 'listings', $mp, $state );
					return array(
						'result'   => 'error',
						'listings' => (int) $state['listings'],
						'matched'  => (int) $state['matched'],
					);
				}
				foreach ( (array) ( $res['items'] ?? array() ) as $l ) {
					if ( ! $l instanceof Eru_MPO_Listing_DTO || '' === $l->wc_sku ) {
						continue;
					}
					$pid = (int) wc_get_product_id_by_sku( $l->wc_sku );
					if ( ! $pid && '' !== $l->barcode && function_exists( 'wc_get_product_id_by_global_unique_id' ) ) {
						$pid = (int) wc_get_product_id_by_global_unique_id( $l->barcode );
					}
					// Numbers only: NULL or a cast value, never request text.
					$stock = null === $l->stock ? 'NULL' : (string) (int) $l->stock;
					$price = null === $l->price ? 'NULL' : number_format( (float) $l->price, 2, '.', '' );
					$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"INSERT INTO {$t} (marketplace, wc_product_id, wc_sku, remote_sku, barcode, remote_id, salable, remote_stock, remote_price, updated_at) VALUES (%s, %d, %s, %s, %s, %s, %d, {$stock}, {$price}, %s) ON DUPLICATE KEY UPDATE wc_product_id = VALUES(wc_product_id), remote_sku = VALUES(remote_sku), barcode = VALUES(barcode), remote_id = VALUES(remote_id), salable = VALUES(salable), remote_stock = VALUES(remote_stock), remote_price = VALUES(remote_price), updated_at = VALUES(updated_at)", // phpcs:ignore
							$mp,
							$pid,
							substr( $l->wc_sku, 0, 100 ),
							substr( $l->remote_sku, 0, 100 ),
							substr( $l->barcode, 0, 100 ),
							substr( $l->remote_id, 0, 64 ),
							$l->salable ? 1 : 0,
							gmdate( 'Y-m-d H:i:s' )
						)
					);
					++$state['listings'];
					$state['matched'] += $pid ? 1 : 0;
				}
				++$page;
				++$read;
			} while ( ! empty( $res['more'] ) && $read < $limit );
			$state['page'] = $page;
			$state['last'] = gmdate( 'Y-m-d H:i:s' );
			if ( ! empty( $res['more'] ) ) {
				self::save_state( 'listings', $mp, $state );
				if ( ! self::pending( self::LISTINGS_NEXT, array( $mp, $page ) ) ) {
					as_schedule_single_action( time() + 5, self::LISTINGS_NEXT, array( $mp, $page ), Eru_MPO_Cron::GROUP );
				}
				return array(
					'result'   => 'continued',
					'listings' => (int) $state['listings'],
					'matched'  => (int) $state['matched'],
				);
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE marketplace = %s AND updated_at < %s", $mp, $state['started'] ) ); // phpcs:ignore
			$state['status']       = 'complete';
			$state['complete']     = true;
			$state['completed_at'] = gmdate( 'Y-m-d H:i:s' );
			self::save_state( 'listings', $mp, $state );
			$released = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET next_try_at = NULL, last_error = NULL WHERE marketplace = %s AND last_error = %s', $mp, self::UNLISTED ) ); // phpcs:ignore
			if ( $released ) {
				self::schedule_flush( $mp );
			}
			/* translators: 1: marketplace, 2: listings, 3: matched products */
			Eru_MPO_Logger::info( sprintf( __( '%1$s ilanları yenilendi: %2$d ilan, %3$d ürünle eşleşti.', 'marketplace-orders-for-woocommerce' ), $adapter->label(), (int) $state['listings'], (int) $state['matched'] ), $mp );
			return array(
				'result'   => 'ok',
				'listings' => (int) $state['listings'],
				'matched'  => (int) $state['matched'],
			);
		} finally {
			Eru_MPO_Lock::release( $lock, $token );
		}
	}

	/** @param string $mp Marketplace. @return int[] Pending full sync actions of the marketplace. */
	private static function full_pending( $mp ) {
		$ids = array();
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return $ids;
		}
		foreach ( self::pending( self::FULL ) as $id ) {
			$a = ActionScheduler::store()->fetch_action( $id );
			if ( $a && (string) ( $a->get_args()[0] ?? '' ) === (string) $mp ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Queues every product of the shop for a marketplace (Action Scheduler batches; nothing is sent here).
	 *
	 * @param string $mp Marketplace.
	 * @return true|WP_Error
	 */
	public static function full_sync_start( $mp ) {
		if ( ! self::active( $mp ) ) {
			return new WP_Error( 'eru_mpo_not_ready', __( 'Bu pazaryeri için stok köprüsü kapalı (ana anahtar, API bilgileri ve stok/fiyat gönderimi gerekli).', 'marketplace-orders-for-woocommerce' ) );
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'eru_mpo_no_scheduler', __( 'Action Scheduler bulunamadı.', 'marketplace-orders-for-woocommerce' ) );
		}
		if ( ! self::full_pending( $mp ) ) {
			as_schedule_single_action( time(), self::FULL, array( (string) $mp, 0 ), Eru_MPO_Cron::GROUP );
		}
		self::save_state(
			'full',
			$mp,
			array(
				'status'     => 'running',
				'started_at' => gmdate( 'Y-m-d H:i:s' ),
				'marked'     => 0,
				'cursor'     => 0,
			)
		);
		return true;
	}

	/**
	 * One full sync batch: the next published products after $after (by id), queued; the next batch is armed
	 * pending-only; the flush is scheduled once per batch.
	 *
	 * @param string $mp    Marketplace.
	 * @param int    $after Last product id of the previous batch.
	 */
	public static function full_sync_batch( $mp, $after = 0 ) {
		global $wpdb;
		$mp    = sanitize_key( (string) $mp );
		$state = self::state( 'full', $mp );
		if ( ! self::active( $mp ) ) {
			self::save_state( 'full', $mp, array_merge( $state, array( 'status' => 'stopped' ) ) );
			return;
		}
		$limit = max( 1, min( 1000, (int) apply_filters( 'eru_mpo_full_sync_batch', 200 ) ) );
		$ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", (int) $after, $limit ) ) ); // phpcs:ignore
		$n     = 0;
		foreach ( $ids as $id ) {
			if ( 'product' === get_post_type( $id ) && wc_get_product( $id ) && wc_get_product( $id )->is_type( 'variable' ) ) {
				continue; // Its variations are in the list themselves.
			}
			$n += self::mark( $id, self::KINDS, false, $mp );
		}
		$last = $ids ? (int) end( $ids ) : (int) $after;
		$more = count( $ids ) === $limit;
		self::save_state(
			'full',
			$mp,
			array_merge(
				$state,
				array(
					'status'      => $more ? 'running' : 'done',
					'marked'      => (int) ( $state['marked'] ?? 0 ) + $n,
					'cursor'      => $last,
					'finished_at' => $more ? '' : gmdate( 'Y-m-d H:i:s' ),
				)
			)
		);
		if ( $n ) {
			self::schedule_flush( $mp );
		}
		if ( $more && ! self::pending( self::FULL, array( $mp, $last ) ) ) {
			as_schedule_single_action( time() + 5, self::FULL, array( $mp, $last ), Eru_MPO_Cron::GROUP );
		}
	}

	/**
	 * Every 10 minutes while a bridge runs: re-arm (pending-only), schedule a flush for due rows without one, keep
	 * the listings chain alive.
	 */
	public static function sweep() {
		global $wpdb;
		$active = self::active_marketplaces();
		if ( ! $active ) {
			return;
		}
		if ( ! self::pending( self::SWEEP ) ) {
			as_schedule_single_action( time() + self::SWEEP_EVERY, self::SWEEP, array(), Eru_MPO_Cron::GROUP );
		}
		$t = self::table();
		foreach ( $active as $mp ) {
			$due = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE marketplace = %s AND attempts < %d AND ( next_try_at IS NULL OR next_try_at <= %s ) LIMIT 1", $mp, self::MAX_ATTEMPTS, gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
			if ( $due && ! self::pending( self::FLUSH, array( $mp ) ) ) {
				as_schedule_single_action( time() + 5, self::FLUSH, array( $mp ), Eru_MPO_Cron::GROUP );
			}
			if ( ! self::pending( self::LISTINGS, array( $mp ) ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::LISTINGS, array( $mp ), Eru_MPO_Cron::GROUP );
			}
		}
	}

	/**
	 * Arms the sweep and the listings chains of active bridges, removes the others (after settings change, from
	 * the daily job and after reactivation, through eru_mpo_ensure).
	 */
	public static function ensure() {
		if ( ! self::scheduler_ready( array( __CLASS__, 'ensure' ) ) ) {
			return;
		}
		$any = false;
		foreach ( array_keys( Eru_MPO_Registry::all() ) as $mp ) {
			if ( self::active( $mp ) ) {
				$any = true;
				if ( ! self::pending( self::LISTINGS, array( $mp ) ) ) {
					as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::LISTINGS, array( $mp ), Eru_MPO_Cron::GROUP );
				}
				continue;
			}
			foreach ( array( self::LISTINGS, self::FLUSH ) as $hook ) {
				if ( self::pending( $hook, array( $mp ) ) ) {
					as_unschedule_all_actions( $hook, array( $mp ), Eru_MPO_Cron::GROUP );
				}
			}
			foreach ( self::pending( self::LISTINGS_NEXT ) as $id ) {
				$a = ActionScheduler::store()->fetch_action( $id );
				if ( $a && (string) ( $a->get_args()[0] ?? '' ) === (string) $mp ) {
					ActionScheduler::store()->cancel_action( $id );
				}
			}
			foreach ( self::full_pending( $mp ) as $id ) {
				ActionScheduler::store()->cancel_action( $id );
			}
		}
		if ( $any && ! self::pending( self::SWEEP ) ) {
			as_schedule_single_action( time() + self::SWEEP_EVERY, self::SWEEP, array(), Eru_MPO_Cron::GROUP );
		} elseif ( ! $any && self::pending( self::SWEEP ) ) {
			as_unschedule_all_actions( self::SWEEP, array(), Eru_MPO_Cron::GROUP );
		}
	}

	/**
	 * P9 double-decrement / overwrite warnings for marketplaces whose stock push is on.
	 *
	 * @return string[]
	 */
	public static function warnings() {
		$out = array();
		foreach ( Eru_MPO_Registry::all() as $mp => $a ) {
			if ( ! self::wants( $mp, 'stock' ) ) {
				continue;
			}
			if ( 'reduce' === Eru_MPO_Settings::stock_mode() ) {
				/* translators: %s: marketplace */
				$out[] = sprintf( __( '%s: stok gönderimi açık ve pazaryeri siparişleri içe aktarılırken WooCommerce stoğundan düşülüyor. Pazaryeri kendi stoğunu satışta zaten düşürdüğü için eklenti fark değil, WooCommerce\'teki güncel stoğu (mutlak değer) gönderir; tek başına çift düşüm olmaz. Aynı siparişi WooCommerce stoğundan ayrıca başka bir sistem (ERP, başka bir eklenti) de düşüyorsa stok çift düşer: o sistemi ya da içe aktarımdaki düşümü kapatın.', 'marketplace-orders-for-woocommerce' ), $a->label() );
			} else {
				/* translators: %s: marketplace */
				$out[] = sprintf( __( '%s: stok gönderimi açık ama pazaryeri siparişleri WooCommerce stoğundan düşülmüyor ("WooCommerce stoğuna dokunma"). Bu siparişleri WooCommerce stoğundan başka bir sistem (ERP) düşmüyorsa, gönderilen stok pazaryerinin satışta düştüğü stoğu yeniden yükseltir ve fazla satış olabilir.', 'marketplace-orders-for-woocommerce' ), $a->label() );
			}
		}
		return $out;
	}
}
