<?php
/**
 * Importer: order DTO → WooCommerce order, exactly once per package.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicate protection, in this order, for every package:
 *
 * 1. An atomic per-package lock (Eru_MPO_Lock, option row with owner token). A second import of the same
 *    package while the first runs returns "locked".
 * 2. The map row (UNIQUE marketplace + remote_ref). A known package is updated, never rebuilt. A package
 *    without a row is claimed by inserting a "pending" row before anything is built; a claim that loses the
 *    insert returns "skipped". The row becomes "done" with the order id right after a complete save.
 * 3. Before a claim, an order already carrying the reference (map lost, or saved just before a crash) or a legacy
 *    order of the older Trendyol/Hepsiburada plugins (not migrated yet) is adopted instead of creating a second
 *    order. An order of this plugin adopted that way gets the post-import steps it missed (once).
 * 4. Then the eru_mpo_link_order filter ( 0, $dto, $adapter ) may name an existing order of the same marketplace that
 *    a new package belongs to (a package cancelled and re-created under the same order number with the same
 *    lines). The new package is mapped to that order, which follows it from then on (see relink()); payloads of
 *    the earlier package are ignored afterwards ("superseded"). A new package that replaces an order with other
 *    lines (a split) becomes its own order, and eru_mpo_replaced_orders names the orders it replaces: those are
 *    cancelled and zeroed (see mark_replaced()), so no line is counted twice.
 *
 * A save that does not complete (Eru_MPO_Isolated_Save throws; or the stored line count differs from the DTO) is
 * undone: the half order is deleted and the row becomes "error" with a backoff of 1, 2, 4 … 60 minutes. A save
 * that completed where only a listener failed afterwards keeps the order (row done, the error logged once, an
 * order note "Bir eklenti sipariş kaydı sonrasında hata verdi; sipariş korunuyor."). A failing update keeps the row "done" (it still owns its order) and records the error and backoff on it. pull() isolates
 * every package: one failure is logged and counted, the others continue.
 *
 * A done row whose order was deleted is stale: it is removed, logged ("Bayat eşleme"), and the package is imported
 * again. Deleting an order also removes its row at once. An order in the trash is left alone (never revived or
 * re-imported); that is logged once.
 *
 * Observe mode logs "aktarılırdı" once per package per day and writes nothing else. After the save (outside the
 * muted hooks) the raw payload goes to the raw table, the stored total is compared with the marketplace total
 * (difference: review flag + note with both totals), stock is reduced once when the mode is "reduce", lifecycle
 * events are applied and eru_mpo_order_imported( $order, $dto ) fires.
 *
 * Results: array{ result: created|updated|unchanged|observed|locked|skipped|unknown|error, order_id: int,
 * review: bool, message: string }.
 */
class Eru_MPO_Importer {

	/** A pending row older than this (seconds) belongs to a run that died; it may be taken over. */
	const PENDING_STALE = 600;
	const LOCK_TTL      = 300;
	/** Order meta set once the post-import steps ran. */
	const POST_META = '_eru_mpo_post_import';
	/** Action Scheduler hook retrying a failed replace once: eru_mpo_replace( $old_id, $new_id, $attempt ). */
	const REPLACE_HOOK = 'eru_mpo_replace';
	/** Seconds before that retry. */
	const REPLACE_DELAY = 300;

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'on_delete' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( self::REPLACE_HOOK, array( __CLASS__, 'retry_replace' ), 10, 3 );
	}

	/** @param int $order_id Order being deleted permanently (not trashed). */
	public static function on_delete( $order_id ) {
		Eru_MPO_Map::delete_by_order( (int) $order_id );
	}

	/** Posts storage: the same clean-up for shop_order posts. @param int $post_id Post id. */
	public static function on_delete_post( $post_id ) {
		if ( ! self::hpos() && 'shop_order' === get_post_type( $post_id ) ) {
			Eru_MPO_Map::delete_by_order( (int) $post_id );
		}
	}

	/** @return bool HPOS is the authoritative order storage. */
	public static function hpos() {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** @param string $mp Marketplace. @param string $ref Reference. @return string Lock key. */
	public static function lock_key( $mp, $ref ) {
		return 'o_' . md5( $mp . '|' . $ref );
	}

	/**
	 * The order exists in the order table (read directly: a cached object of a deleted order does not count).
	 *
	 * @param int $id Order id.
	 * @return bool
	 */
	public static function order_exists( $id ) {
		global $wpdb;
		if ( (int) $id <= 0 ) {
			return false;
		}
		if ( self::hpos() ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE id = %d AND type = 'shop_order'", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'shop_order'", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * An order that already carries this reference (read from the order meta table).
	 *
	 * @param string $mp  Marketplace.
	 * @param string $ref Reference.
	 * @return int Order id or 0.
	 */
	public static function find_order_by_ref( $mp, $ref ) {
		global $wpdb;
		if ( self::hpos() ) {
			$sql = "SELECT m.order_id FROM {$wpdb->prefix}wc_orders_meta m JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id AND o.type = 'shop_order' JOIN {$wpdb->prefix}wc_orders_meta k ON k.order_id = m.order_id AND k.meta_key = '_eru_mpo_marketplace' AND k.meta_value = %s WHERE m.meta_key = '_eru_mpo_remote_ref' AND m.meta_value = %s ORDER BY m.order_id ASC LIMIT 1";
		} else {
			$sql = "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} o ON o.ID = m.post_id AND o.post_type = 'shop_order' JOIN {$wpdb->postmeta} k ON k.post_id = m.post_id AND k.meta_key = '_eru_mpo_marketplace' AND k.meta_value = %s WHERE m.meta_key = '_eru_mpo_remote_ref' AND m.meta_value = %s ORDER BY m.post_id ASC LIMIT 1";
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $mp, $ref ) ); // phpcs:ignore
	}


	/**
	 * The stored status of an order, read from the order table (no "wc-" prefix; "trash" for trashed orders).
	 *
	 * @param int $id Order id.
	 * @return string '' when the order does not exist.
	 */
	public static function stored_status( $id ) {
		global $wpdb;
		if ( self::hpos() ) {
			$s = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$s = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return null === $s ? '' : (string) preg_replace( '/^wc-/', '', (string) $s );
	}

	/** @param int $id Order id. @return int Stored total in minor units. */
	public static function stored_total( $id ) {
		global $wpdb;
		if ( self::hpos() ) {
			$t = $wpdb->get_var( $wpdb->prepare( "SELECT total_amount FROM {$wpdb->prefix}wc_orders WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$t = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_order_total'", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return Eru_MPO_Builder::minor( (string) $t );
	}

	/** @param int $id Order id. @return int Stored product lines. */
	public static function stored_line_count( $id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = 'line_item'", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Drops the cached object of an order so the next read comes from the database. @param int $id Order id. */
	public static function forget_order( $id ) {
		if ( (int) $id <= 0 ) {
			return;
		}
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( (int) $id );
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
		clean_post_cache( (int) $id );
	}

	/** @return array Result. */
	private static function result( $result, $order_id = 0, $review = false, $message = '' ) {
		return array(
			'result'   => $result,
			'order_id' => (int) $order_id,
			'review'   => (bool) $review,
			'message'  => (string) $message,
		);
	}

	/**
	 * Fetches orders from one marketplace and imports them, each package isolated from the others.
	 *
	 * @param string $key  Adapter key.
	 * @param array  $args since (UTC timestamp), until, lifecycle (bool: fetch_lifecycle, update only).
	 * @return array{result: string, fetched: int, created: int, updated: int, unchanged: int, observed: int, skipped: int, errors: int}
	 *               result: ok | disabled | unknown | not_ready | legacy_active | locked | error.
	 */
	public static function pull( $key, array $args = array() ) {
		$sum = array(
			'result'    => 'ok',
			'fetched'   => 0,
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'observed'  => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);
		if ( ! Eru_MPO_Settings::enabled() ) {
			return array( 'result' => 'disabled' ) + $sum;
		}
		$adapter = Eru_MPO_Registry::get( $key );
		if ( ! $adapter ) {
			return array( 'result' => 'unknown' ) + $sum;
		}
		if ( ! $adapter->is_enabled() || ! $adapter->is_configured() ) {
			return array( 'result' => 'not_ready' ) + $sum;
		}
		if ( in_array( $key, Eru_MPO_Migrator::legacy_active(), true ) ) {
			Eru_MPO_Logger::warning( __( 'Eski eklenti etkin olduğu için siparişler çekilmedi: önce eski eklentiyi devre dışı bırakın.', 'marketplace-orders-for-woocommerce' ), $key );
			return array( 'result' => 'legacy_active' ) + $sum;
		}
		$lifecycle = ! empty( $args['lifecycle'] );
		$lock_key  = ( $lifecycle ? 'l_' : 'p_' ) . $key;
		$token     = Eru_MPO_Lock::acquire( $lock_key, 15 * MINUTE_IN_SECONDS );
		if ( ! $token ) {
			return array( 'result' => 'locked' ) + $sum;
		}
		try {
			$until = isset( $args['until'] ) ? (int) $args['until'] : time();
			$days  = Eru_MPO_Settings::days( $lifecycle ? 'lifecycle_days' : 'window_days' );
			$since = isset( $args['since'] ) ? (int) $args['since'] : $until - $days * DAY_IN_SECONDS;
			try {
				$list = $lifecycle ? $adapter->fetch_lifecycle( $since, $until ) : $adapter->fetch_orders( $since, $until );
			} catch ( Throwable $e ) {
				$list = new WP_Error( 'eru_mpo_fetch_exception', $e->getMessage(), array( 'retryable' => true ) );
			}
			if ( is_wp_error( $list ) ) {
				/* translators: %s: error message */
				Eru_MPO_Logger::error( sprintf( __( 'Siparişler alınamadı: %s', 'marketplace-orders-for-woocommerce' ), $list->get_error_message() ), $key );
				return array( 'result' => 'error' ) + $sum;
			}
			foreach ( (array) $list as $dto ) {
				++$sum['fetched'];
				if ( ! $dto instanceof Eru_MPO_Order_DTO ) {
					++$sum['errors'];
					continue;
				}
				try {
					$res = self::import( $adapter, $dto, array( 'create' => ! $lifecycle ) )['result'];
				} catch ( Throwable $e ) {
					$res = 'error';
					self::failed( $key, (string) $dto->remote_ref, $e );
				}
				if ( isset( $sum[ $res ] ) && 'result' !== $res ) {
					++$sum[ $res ];
				} elseif ( 'error' === $res ) {
					++$sum['errors'];
				} else {
					++$sum['skipped'];
				}
			}
			return $sum;
		} finally {
			Eru_MPO_Lock::release( $lock_key, $token );
		}
	}

	/** Logs a package failure and records it (with backoff) on its map row. */
	private static function failed( $mp, $ref, Throwable $e ) {
		if ( '' !== $ref ) {
			Eru_MPO_Map::note_failure( $mp, substr( $ref, 0, 64 ), $e->getMessage() );
		}
		/* translators: %s: error */
		Eru_MPO_Logger::error( sprintf( __( 'Paket işlenemedi: %s', 'marketplace-orders-for-woocommerce' ), $e->getMessage() ), $mp, substr( $ref, 0, 64 ) );
	}

	/** Logs once (per map row) that the order of a package is in the trash. */
	private static function trashed( $mp, $ref, $order_id, $row ) {
		if ( ! $row || 'trashed' !== (string) $row->error ) {
			/* translators: %d: order id */
			Eru_MPO_Logger::info( sprintf( __( '#%d numaralı sipariş çöpte; pazaryeri güncellemeleri uygulanmıyor ve sipariş yeniden oluşturulmuyor.', 'marketplace-orders-for-woocommerce' ), (int) $order_id ), $mp, $ref );
			Eru_MPO_Map::set_error( $mp, $ref, 'trashed' );
		}
		return self::result( 'skipped', $order_id, false, 'trashed' );
	}

	/**
	 * Imports (or updates) one package.
	 *
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @param Eru_MPO_Order_DTO $dto     Order.
	 * @param array             $opts    create (bool, default true): false = update known orders only.
	 * @return array See class doc.
	 */
	public static function import( Eru_MPO_Adapter $adapter, Eru_MPO_Order_DTO $dto, array $opts = array() ) {
		$mp               = $adapter->key();
		$dto->marketplace = $mp;
		$ref              = substr( trim( $dto->remote_ref ), 0, 64 );
		$dto->remote_ref  = $ref;
		if ( '' === $ref ) {
			Eru_MPO_Logger::error( __( 'Paket referansı olmayan sipariş atlandı.', 'marketplace-orders-for-woocommerce' ), $mp );
			return self::result( 'error', 0, false, 'no_ref' );
		}
		$create = ! array_key_exists( 'create', $opts ) || ! empty( $opts['create'] );

		if ( $adapter->is_observe_mode() ) {
			$tag = __( 'Gözlem modu', 'marketplace-orders-for-woocommerce' );
			if ( ! Eru_MPO_Logger::seen_since( $mp, $ref, $tag, gmdate( 'Y-m-d 00:00:00' ) ) ) {
				/* translators: 1: "Gözlem modu", 2: reference, 3: number of lines, 4: total, 5: status */
				Eru_MPO_Logger::info( sprintf( __( '%1$s: %2$s paketi aktarılırdı (%3$d satır, toplam %4$s, durum %5$s).', 'marketplace-orders-for-woocommerce' ), $tag, $ref, count( $dto->lines ), Eru_MPO_Builder::dec( Eru_MPO_Builder::minor( $dto->totals->total ) ), $dto->status ), $mp, $ref );
			}
			return self::result( 'observed' );
		}

		$lock  = self::lock_key( $mp, $ref );
		$token = Eru_MPO_Lock::acquire( $lock, self::LOCK_TTL );
		if ( ! $token ) {
			return self::result( 'locked', 0, false, 'locked' );
		}
		try {
			$row = Eru_MPO_Map::get( $mp, $ref );
			if ( $row && 'done' === $row->status ) {
				if ( Eru_MPO_Map::backing_off( $row ) ) {
					return self::result( 'skipped', (int) $row->wc_order_id, false, 'backoff' );
				}
				if ( $row->wc_order_id && self::order_exists( (int) $row->wc_order_id ) ) {
					if ( 'trash' === self::stored_status( (int) $row->wc_order_id ) ) {
						return self::trashed( $mp, $ref, (int) $row->wc_order_id, $row );
					}
					$order = wc_get_order( (int) $row->wc_order_id );
					if ( $order ) {
						return self::update( $adapter, $order, $dto );
					}
				}
				Eru_MPO_Map::delete( (int) $row->id );
				/* translators: %d: deleted order id */
				Eru_MPO_Logger::info( sprintf( __( 'Bayat eşleme temizlendi: #%d numaralı sipariş silinmiş; paket yeniden içe aktarılacak.', 'marketplace-orders-for-woocommerce' ), (int) $row->wc_order_id ), $mp, $ref );
				$row = null;
			}

			$found = self::find_order_by_ref( $mp, $ref );
			if ( ! $found ) {
				$found = Eru_MPO_Migrator::take_over_legacy( $mp, $ref );
			}
			$linked = false;
			if ( ! $found && $create ) {
				$found  = self::linked_order( $adapter, $dto );
				$linked = $found > 0;
			}
			if ( $found ) {
				if ( ! Eru_MPO_Map::insert_done( $mp, $ref, $found, false ) ) {
					return self::result( 'skipped', $found, false, 'conflict' );
				}
				if ( 'trash' === self::stored_status( $found ) ) {
					return self::trashed( $mp, $ref, $found, Eru_MPO_Map::get( $mp, $ref ) );
				}
				$order = wc_get_order( $found );
				if ( $order ) {
					if ( $linked ) {
						return self::relink( $adapter, $order, $dto );
					}
					Eru_MPO_Map::set_review( $mp, $ref, 'yes' === $order->get_meta( '_eru_mpo_review' ) );
					self::finish_adopted( $order, $dto );
					return self::update( $adapter, $order, $dto );
				}
			}

			if ( ! $create ) {
				return self::result( 'unknown' );
			}

			if ( ! $row ) {
				if ( ! Eru_MPO_Map::claim( $mp, $ref ) ) {
					return self::result( 'skipped', 0, false, 'claimed' );
				}
			} elseif ( 'pending' === $row->status ) {
				if ( strtotime( $row->updated_at . ' UTC' ) > time() - self::PENDING_STALE ) {
					return self::result( 'skipped', 0, false, 'pending' );
				}
				if ( ! Eru_MPO_Map::retake( (int) $row->id, 'pending', $row->updated_at ) ) {
					return self::result( 'skipped', 0, false, 'pending' );
				}
				Eru_MPO_Logger::warning( __( 'Yarım kalmış içe aktarım devralındı.', 'marketplace-orders-for-woocommerce' ), $mp, $ref );
			} else {
				if ( Eru_MPO_Map::backing_off( $row ) ) {
					return self::result( 'skipped', 0, false, 'backoff' );
				}
				if ( ! Eru_MPO_Map::retake( (int) $row->id, (string) $row->status ) ) {
					return self::result( 'skipped', 0, false, 'busy' );
				}
			}

			$res = self::create( $adapter, $dto );
			if ( 'created' === $res['result'] ) {
				self::replace_predecessors( $adapter, $dto, (int) $res['order_id'] );
			}
			return $res;
		} finally {
			Eru_MPO_Lock::release( $lock, $token );
		}
	}

	/**
	 * An existing order a new package belongs to, as answered by the eru_mpo_link_order filter (a marketplace that
	 * cancels a package and re-creates it under the same order number). Only a live, untrashed order of the same
	 * marketplace is accepted.
	 *
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @param Eru_MPO_Order_DTO $dto     New package.
	 * @return int Order id or 0.
	 */
	private static function linked_order( Eru_MPO_Adapter $adapter, Eru_MPO_Order_DTO $dto ) {
		$id = (int) apply_filters( 'eru_mpo_link_order', 0, $dto, $adapter );
		if ( $id <= 0 || ! self::order_exists( $id ) || 'trash' === self::stored_status( $id ) ) {
			return 0;
		}
		$order = wc_get_order( $id );
		return $order && $adapter->key() === (string) $order->get_meta( '_eru_mpo_marketplace' ) ? $id : 0;
	}

	/**
	 * Links a re-created package to the order of its predecessor (see eru_mpo_link_order). The order follows the
	 * new package: its reference meta moves to the new package and every package it had is kept in
	 * _eru_mpo_packages. An order the earlier package's cancellation had closed is opened again at the new
	 * package's status, and stock is reduced again when the mode is "reduce" (the cancel restored it). Lines are
	 * never rebuilt: when the stored lines or total no longer match the new package the order is flagged for review.
	 * Later payloads of the earlier package are ignored (see update()). Fires eru_mpo_order_relinked( $order,
	 * $old_ref, $dto ) and, when the order was opened again, eru_mpo_order_reopened( $order, $from, $to ).
	 *
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @param WC_Order          $order   Existing order.
	 * @param Eru_MPO_Order_DTO $dto     New package.
	 * @return array
	 */
	private static function relink( Eru_MPO_Adapter $adapter, $order, Eru_MPO_Order_DTO $dto ) {
		$mp = $adapter->key();
		try {
			$old  = (string) $order->get_meta( '_eru_mpo_remote_ref' );
			$list = $order->get_meta( '_eru_mpo_packages' );
			$list = is_array( $list ) ? array_map( 'strval', $list ) : array();
			$list = array_values( array_unique( array_filter( array_merge( $list, array( $old, $dto->remote_ref ) ), 'strlen' ) ) );
			$order->update_meta_data( '_eru_mpo_packages', $list );
			Eru_MPO_Builder::apply_meta( $order, $mp, $dto, true );

			$from   = Eru_MPO_Statuses::current( $order );
			$reopen = 'cancelled' === $from && ! in_array( $dto->status, Eru_MPO_Statuses::TERMINAL, true );
			/* translators: 1: earlier package, 2: new package */
			$note = sprintf( __( 'Pazaryeri %1$s paketini iptal edip aynı sipariş numarasıyla %2$s paketini oluşturdu; sipariş yeni pakete bağlandı (ikinci sipariş oluşturulmadı).', 'marketplace-orders-for-woocommerce' ), $old, $dto->remote_ref );
			if ( $reopen ) {
				Eru_MPO_Statuses::prime( $order, $dto->status, $note );
				$order->delete_meta_data( Eru_MPO_Statuses::HAND );
				$order->delete_meta_data( '_eru_mpo_hand_noted' );
			} elseif ( $dto->status !== $from && Eru_MPO_Statuses::can_advance( $from, $dto->status ) ) {
				Eru_MPO_Statuses::prime( $order, $dto->status, $note );
			} else {
				$order->add_order_note( $note );
			}

			$active = 0;
			foreach ( $dto->lines as $line ) {
				$active += 'active' === $line->status ? 1 : 0;
			}
			$stored = 0;
			foreach ( $order->get_items() as $item ) {
				$stored += '' === (string) $item->get_meta( '_eru_mpo_line_status' ) ? 1 : 0;
			}
			$want   = Eru_MPO_Builder::minor( $dto->totals->total );
			$review = $active !== $stored || ( $want > 0 && abs( $want - Eru_MPO_Builder::minor( $order->get_total() ) ) > 1 );
			if ( $review ) {
				$order->update_meta_data( '_eru_mpo_review', 'yes' );
				/* translators: 1: package total, 2: order total */
				$order->add_order_note( sprintf( __( 'İnceleme gerekli: yeni paketin satırları veya tutarı (%1$s) siparişten (%2$s) farklı; satırlar yeniden oluşturulmadı.', 'marketplace-orders-for-woocommerce' ), Eru_MPO_Builder::dec( $want ), Eru_MPO_Builder::dec( Eru_MPO_Builder::minor( $order->get_total() ) ) ) );
			}
			try {
				Eru_MPO_Isolated_Save::save( $order );
			} catch ( Eru_MPO_Listener_Error $le ) {
				/* translators: %s: error */
				Eru_MPO_Logger::error( sprintf( __( 'Sipariş kaydedildi, ardından bir eklenti hata verdi: %s', 'marketplace-orders-for-woocommerce' ), $le->getMessage() ), $mp, $dto->remote_ref );
			}
			Eru_MPO_Map::set_review( $mp, $dto->remote_ref, 'yes' === $order->get_meta( '_eru_mpo_review' ) );
			if ( $reopen ) {
				Eru_MPO_Stock::reduce( $order );
				do_action( 'eru_mpo_order_reopened', $order, $from, $dto->status );
			}
			Eru_MPO_Lifecycle::apply( $order, $dto->events );
			Eru_MPO_Raw::store( $mp, $dto->remote_ref, $dto->raw );
			/* translators: 1: new package, 2: order id, 3: earlier package */
			Eru_MPO_Logger::info( sprintf( __( '%1$s paketi #%2$d numaralı siparişe bağlandı (önceki paket %3$s iptal).', 'marketplace-orders-for-woocommerce' ), $dto->remote_ref, $order->get_id(), $old ), $mp, $dto->remote_ref );
			do_action( 'eru_mpo_order_relinked', $order, $old, $dto );
		} catch ( Throwable $e ) {
			self::forget_order( $order->get_id() );
			self::failed( $mp, $dto->remote_ref, $e );
			// The new package's map row (inserted before the relink) goes again, so the next pull runs the link
			// decision anew (reopen, stock reduced once) instead of treating it as an update of a cancelled order.
			$row = Eru_MPO_Map::get( $mp, $dto->remote_ref );
			if ( $row && (int) $row->wc_order_id === (int) $order->get_id() ) {
				Eru_MPO_Map::delete( (int) $row->id );
			}
			return self::result( 'error', $order->get_id(), false, $e->getMessage() );
		}
		return self::result( 'updated', $order->get_id(), 'yes' === $order->get_meta( '_eru_mpo_review' ), 'relinked' );
	}

	/**
	 * After a new package became its own order: orders it replaces (filter eru_mpo_replaced_orders( array(), $dto,
	 * $adapter, $new_id ); e.g. Trendyol package A unpacked into B and C with other lines) are marked replaced.
	 *
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @param Eru_MPO_Order_DTO $dto     New package.
	 * @param int               $new_id  Its new order.
	 */
	private static function replace_predecessors( Eru_MPO_Adapter $adapter, Eru_MPO_Order_DTO $dto, $new_id ) {
		$ids = apply_filters( 'eru_mpo_replaced_orders', array(), $dto, $adapter, $new_id );
		foreach ( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) as $id ) {
			if ( $id <= 0 || $id === (int) $new_id || ! self::order_exists( $id ) || 'trash' === self::stored_status( $id ) ) {
				continue;
			}
			$old = wc_get_order( $id );
			if ( ! $old || $adapter->key() !== (string) $old->get_meta( '_eru_mpo_marketplace' ) ) {
				continue;
			}
			self::replace_locked( $old, (int) $new_id, $dto, 1 );
		}
	}

	/**
	 * mark_replaced() under the OLD package's lock too (the caller holds the new one), so a lifecycle update of the
	 * old package cannot cancel and restore it at the same time. When the lock is held elsewhere, or the replace
	 * fails, replace_failed() flags both orders and (on attempt 1) queues the retry.
	 *
	 * @param WC_Order          $old     Order to replace.
	 * @param int               $new_id  New order.
	 * @param Eru_MPO_Order_DTO $dto     New package.
	 * @param int               $attempt Attempt.
	 * @return bool Replaced.
	 */
	private static function replace_locked( $old, $new_id, Eru_MPO_Order_DTO $dto, $attempt ) {
		$key   = self::lock_key( (string) $old->get_meta( '_eru_mpo_marketplace' ), (string) $old->get_meta( '_eru_mpo_remote_ref' ) );
		$token = Eru_MPO_Lock::acquire( $key, self::LOCK_TTL );
		if ( ! $token ) {
			self::replace_failed( (int) $old->get_id(), (int) $new_id, __( 'eski paket başka bir çalışmada kilitli', 'marketplace-orders-for-woocommerce' ), $attempt );
			return false;
		}
		try {
			self::forget_order( $old->get_id() );
			$fresh = wc_get_order( $old->get_id() );
			self::mark_replaced( $fresh ? $fresh : $old, (int) $new_id, $dto );
			return true;
		} catch ( Throwable $e ) {
			self::replace_failed( (int) $old->get_id(), (int) $new_id, $e->getMessage(), $attempt );
			return false;
		} finally {
			Eru_MPO_Lock::release( $key, $token );
		}
	}

	/**
	 * A replace that failed: both orders are flagged for review (order and map row) with a note, because until the
	 * old order is cancelled its lines and stock count twice; the first failure queues one retry
	 * (REPLACE_HOOK, after REPLACE_DELAY seconds). A failed retry leaves the flags and adds a note to do it by hand.
	 *
	 * @param int    $old_id  Order that should be replaced.
	 * @param int    $new_id  New order.
	 * @param string $error   Error.
	 * @param int    $attempt Attempt that failed (1 = during the import).
	 */
	private static function replace_failed( $old_id, $new_id, $error, $attempt ) {
		self::forget_order( $old_id );
		self::forget_order( $new_id );
		$retry = 1 === (int) $attempt && function_exists( 'as_schedule_single_action' );
		$mp    = '';
		$ref   = '';
		foreach ( array( $old_id => $new_id, $new_id => $old_id ) as $id => $other ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			$o->update_meta_data( '_eru_mpo_review', 'yes' );
			/* translators: 1: replaced order id, 2: new order id, 3: error */
			$msg = sprintf( __( 'İnceleme gerekli: #%1$d numaralı sipariş #%2$d ile değiştirilemedi (%3$s); iptal edilene kadar satırları ve stoğu iki kez sayılıyor.', 'marketplace-orders-for-woocommerce' ), $old_id, $new_id, Eru_MPO_Logger::redact( $error ) );
			$msg .= ' ' . ( $retry ? __( 'Birkaç dakika sonra otomatik olarak yeniden denenecek.', 'marketplace-orders-for-woocommerce' ) : __( 'Eski siparişi elle iptal edin.', 'marketplace-orders-for-woocommerce' ) );
			$o->add_order_note( $msg );
			$o->save_meta_data();
			Eru_MPO_Map::set_review( (string) $o->get_meta( '_eru_mpo_marketplace' ), (string) $o->get_meta( '_eru_mpo_remote_ref' ), true );
			if ( $id === $new_id ) {
				$mp  = (string) $o->get_meta( '_eru_mpo_marketplace' );
				$ref = (string) $o->get_meta( '_eru_mpo_remote_ref' );
			}
			unset( $other );
		}
		if ( $retry ) {
			$args = array( (int) $old_id, (int) $new_id, 2 );
			if ( ! as_get_scheduled_actions( array( 'hook' => self::REPLACE_HOOK, 'args' => $args, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) ) {
				as_schedule_single_action( time() + self::REPLACE_DELAY, self::REPLACE_HOOK, $args, Eru_MPO_Cron::GROUP );
			}
		}
		/* translators: 1: replaced order id, 2: new order id, 3: error */
		Eru_MPO_Logger::error( sprintf( __( '#%1$d numaralı sipariş #%2$d ile değiştirilemedi: %3$s', 'marketplace-orders-for-woocommerce' ), $old_id, $new_id, $error ), $mp, $ref );
	}

	/**
	 * Action Scheduler: the retry of a failed replace.
	 *
	 * @param int $old_id  Order to replace.
	 * @param int $new_id  New order.
	 * @param int $attempt Attempt number.
	 */
	public static function retry_replace( $old_id, $new_id, $attempt = 2 ) {
		$old = self::order_exists( (int) $old_id ) ? wc_get_order( (int) $old_id ) : null;
		$new = self::order_exists( (int) $new_id ) ? wc_get_order( (int) $new_id ) : null;
		if ( ! $old || ! $new ) {
			return;
		}
		$dto = Eru_MPO_Order_DTO::from_array(
			array(
				'marketplace'  => (string) $new->get_meta( '_eru_mpo_marketplace' ),
				'remote_ref'   => (string) $new->get_meta( '_eru_mpo_remote_ref' ),
				'order_number' => (string) $new->get_meta( '_eru_mpo_order_number' ),
			)
		);
		if ( self::replace_locked( $old, (int) $new_id, $dto, (int) $attempt ) ) {
			/* translators: 1: replaced order id, 2: new order id */
			Eru_MPO_Logger::info( sprintf( __( '#%1$d numaralı sipariş yeniden denemede #%2$d ile değiştirildi.', 'marketplace-orders-for-woocommerce' ), (int) $old_id, (int) $new_id ), $dto->marketplace, $dto->remote_ref );
		}
	}

	/**
	 * Marks an order whose package the marketplace replaced with packages that carry other lines. The order is
	 * cancelled (stock restored once: the restore is guarded by the order's "stock reduced" flag, so an order that
	 * was cancelled before is not restored again), every remaining line is zeroed and marked "replaced"
	 * (_eru_mpo_line_status; the amount stays in _eru_mpo_original_price), shipping and tax lines are zeroed and the
	 * order total becomes 0 (the earlier total stays in _eru_mpo_original_total), so the lines now living in the new
	 * orders are never counted twice. It is never reopened. _eru_mpo_replaced_by lists the new orders; a note names
	 * them. Fires eru_mpo_order_replaced( $order, $new_id, $dto ).
	 *
	 * @param WC_Order          $order  Replaced order.
	 * @param int               $new_id New order.
	 * @param Eru_MPO_Order_DTO $dto    New package.
	 */
	public static function mark_replaced( $order, $new_id, Eru_MPO_Order_DTO $dto ) {
		$by = $order->get_meta( '_eru_mpo_replaced_by' );
		$by = is_array( $by ) ? array_map( 'intval', $by ) : array();
		if ( in_array( (int) $new_id, $by, true ) ) {
			return;
		}
		$first = ! $by;
		$from  = Eru_MPO_Statuses::current( $order );
		/* translators: 1: earlier package, 2: new package, 3: new order id */
		$note = sprintf( __( 'Pazaryeri %1$s paketini farklı satırlarla yeni paketlere böldü; %2$s paketi #%3$d numaralı sipariş oldu. Bu siparişin satırları sıfırlandı (iki kez sayılmasın), stok bir kez iade edildi.', 'marketplace-orders-for-woocommerce' ), (string) $order->get_meta( '_eru_mpo_remote_ref' ), $dto->remote_ref, (int) $new_id );
		if ( ! in_array( $from, Eru_MPO_Statuses::TERMINAL, true ) ) {
			if ( ! Eru_MPO_Statuses::advance( $order, 'cancelled', $note ) ) {
				throw new RuntimeException( __( 'Sipariş iptal edilemedi.', 'marketplace-orders-for-woocommerce' ) );
			}
			$note = '';
		}
		Eru_MPO_Stock::restore( $order ); // No-op when the cancel already restored it.
		if ( $first ) {
			$order->update_meta_data( '_eru_mpo_original_total', wc_format_decimal( $order->get_total(), 2 ) );
			foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
				if ( $item instanceof WC_Order_Item_Product ) {
					if ( '' !== (string) $item->get_meta( '_eru_mpo_line_status' ) ) {
						continue;
					}
					$item->update_meta_data( '_eru_mpo_original_price', wc_format_decimal( (float) $item->get_total() + (float) $item->get_total_tax(), 2 ) );
					$item->update_meta_data( '_eru_mpo_line_status', 'replaced' );
					$item->set_subtotal( 0 );
				}
				$item->set_total( 0 );
				$item->set_taxes( false );
				$item->save();
			}
			foreach ( $order->get_items( 'tax' ) as $tax ) {
				$tax->set_tax_total( 0 );
				$tax->set_shipping_tax_total( 0 );
				$tax->save();
			}
			$order->set_cart_tax( 0 );
			$order->set_shipping_total( 0 );
			$order->set_shipping_tax( 0 );
			$order->set_discount_total( 0 );
			$order->set_discount_tax( 0 );
			$order->set_total( 0 );
		}
		$by[] = (int) $new_id;
		$order->update_meta_data( '_eru_mpo_replaced_by', $by );
		if ( '' !== $note ) {
			$order->add_order_note( $note );
		}
		try {
			Eru_MPO_Isolated_Save::save( $order );
		} catch ( Eru_MPO_Listener_Error $le ) {
			unset( $le );
		}
		$new = wc_get_order( $new_id );
		if ( $new ) {
			$new->update_meta_data( '_eru_mpo_replaces', $order->get_id() );
			$new->save_meta_data();
		}
		/* translators: 1: replaced order id, 2: new order id */
		Eru_MPO_Logger::info( sprintf( __( '#%1$d numaralı sipariş yeni pakete bölündü (#%2$d); satırları sıfırlandı.', 'marketplace-orders-for-woocommerce' ), $order->get_id(), (int) $new_id ), (string) $order->get_meta( '_eru_mpo_marketplace' ), (string) $order->get_meta( '_eru_mpo_remote_ref' ) );
		do_action( 'eru_mpo_order_replaced', $order, (int) $new_id, $dto );
	}

	/**
	 * Builds and saves a new order for a package whose map row this request owns (pending).
	 *
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @param Eru_MPO_Order_DTO $dto     Order.
	 * @return array
	 */
	private static function create( Eru_MPO_Adapter $adapter, Eru_MPO_Order_DTO $dto ) {
		$mp       = $adapter->key();
		$ref      = $dto->remote_ref;
		$order    = null;
		$listener = '';
		try {
			$built  = Eru_MPO_Builder::build( $adapter, $dto );
			$order  = $built['order'];
			$review = $built['review'];
			do_action( 'eru_mpo_order_before_save', $order, $dto, $adapter );
			try {
				$id = Eru_MPO_Isolated_Save::save( $order );
			} catch ( Eru_MPO_Listener_Error $le ) {
				// The save completed; a listener failed afterwards. Keep the order (checked below like any other).
				$id       = (int) $order->get_id();
				$listener = $le->getMessage();
			}
			if ( self::stored_line_count( $id ) < count( $dto->lines ) ) { // More is fine: a listener may add lines.
				throw new RuntimeException( __( 'Kaydedilen sipariş satırları pazaryeri siparişiyle eşleşmiyor.', 'marketplace-orders-for-woocommerce' ) );
			}
		} catch ( Throwable $e ) {
			$half = $order instanceof WC_Order ? (int) $order->get_id() : 0;
			if ( $half > 0 && self::order_exists( $half ) ) {
				// Inserted, then something failed: remove the half-saved order so the retry builds it cleanly.
				try {
					$order->delete( true );
				} catch ( Throwable $ignored ) {
					Eru_MPO_Map::done( $mp, $ref, $half, true );
					/* translators: 1: order id, 2: error */
					Eru_MPO_Logger::error( sprintf( __( 'Sipariş #%1$d kayıttan sonra hata verdi ve silinemedi; inceleyin: %2$s', 'marketplace-orders-for-woocommerce' ), $half, $e->getMessage() ), $mp, $ref );
					return self::result( 'error', $half, true, $e->getMessage() );
				}
			}
			Eru_MPO_Map::fail( $mp, $ref, $e->getMessage() );
			/* translators: %s: error */
			Eru_MPO_Logger::error( sprintf( __( 'İçe aktarım başarısız: %s', 'marketplace-orders-for-woocommerce' ), $e->getMessage() ), $mp, $ref );
			return self::result( 'error', 0, false, $e->getMessage() );
		}

		Eru_MPO_Map::done( $mp, $ref, $id, $review );
		$notes = array();
		if ( '' !== $listener ) {
			$notes[] = __( 'Bir eklenti sipariş kaydı sonrasında hata verdi; sipariş korunuyor.', 'marketplace-orders-for-woocommerce' );
			/* translators: 1: order id, 2: error */
			Eru_MPO_Logger::error( sprintf( __( 'Bir eklenti #%1$d numaralı siparişin kaydından sonra hata verdi; sipariş korunuyor: %2$s', 'marketplace-orders-for-woocommerce' ), $id, $listener ), $mp, $ref );
		}
		if ( $review ) {
			/* translators: %s: reasons */
			$notes[] = sprintf( __( 'İnceleme gerekli: %s', 'marketplace-orders-for-woocommerce' ), implode( ' ', $built['reasons'] ) );
		}
		if ( $built['dropped'] ) {
			/* translators: %s: lines */
			$notes[] = sprintf( __( 'Pazaryerinde iptal/iade edilmiş satırlar sıfır tutarla kaydedildi (stok düşülmedi): %s', 'marketplace-orders-for-woocommerce' ), implode( ', ', $built['dropped'] ) );
		}
		$review = self::post_import( $order, $dto, $notes ) || $review;
		/* translators: 1: reference, 2: order id */
		Eru_MPO_Logger::info( sprintf( __( '%1$s paketi #%2$d numaralı sipariş olarak içe aktarıldı.', 'marketplace-orders-for-woocommerce' ), $ref, $id ) . ( $review ? ' ' . __( '(inceleme gerekli)', 'marketplace-orders-for-woocommerce' ) : '' ), $mp, $ref );
		return self::result( 'created', $id, $review );
	}

	/**
	 * Steps after the first complete save: raw payload, notes, total check, stock, events, the imported action.
	 * Marked with _eru_mpo_post_import so an order adopted after a crash gets them exactly once.
	 *
	 * @param WC_Order          $order Saved order.
	 * @param Eru_MPO_Order_DTO $dto   Order DTO.
	 * @param string[]          $notes Order notes to add.
	 * @return bool The total check flagged the order for review.
	 */
	private static function post_import( $order, Eru_MPO_Order_DTO $dto, array $notes = array() ) {
		$mp      = $dto->marketplace;
		$ref     = $dto->remote_ref;
		$flagged = false;
		try {
			Eru_MPO_Raw::store( $mp, $ref, $dto->raw );
			foreach ( $notes as $n ) {
				$order->add_order_note( $n );
			}
			$want = Eru_MPO_Builder::minor( $dto->totals->total );
			$have = self::stored_total( $order->get_id() );
			if ( $want > 0 && abs( $want - $have ) > 1 ) {
				$flagged = true;
				$order->update_meta_data( '_eru_mpo_review', 'yes' );
				Eru_MPO_Map::set_review( $mp, $ref, true );
				/* translators: 1: stored total, 2: marketplace total */
				$order->add_order_note( sprintf( __( 'İnceleme gerekli: kaydedilen toplam %1$s, pazaryeri toplamı %2$s.', 'marketplace-orders-for-woocommerce' ), Eru_MPO_Builder::dec( $have ), Eru_MPO_Builder::dec( $want ) ) );
			}
			if ( ! in_array( Eru_MPO_Statuses::current( $order ), Eru_MPO_Statuses::TERMINAL, true ) ) {
				Eru_MPO_Stock::reduce( $order );
			}
			Eru_MPO_Lifecycle::apply( $order, $dto->events );
			$order->update_meta_data( self::POST_META, gmdate( 'Y-m-d H:i:s' ) );
			Eru_MPO_Isolated_Save::save( $order );
			do_action( 'eru_mpo_order_imported', $order, $dto );
		} catch ( Throwable $e ) {
			/* translators: %s: error */
			Eru_MPO_Logger::error( sprintf( __( 'Sipariş kaydedildi, sonraki adım başarısız: %s', 'marketplace-orders-for-woocommerce' ), $e->getMessage() ), $mp, $ref );
		}
		return $flagged;
	}

	/**
	 * An order of this plugin found by its reference without a done map row (saved just before a crash): run the
	 * post-import steps it missed, once. Legacy orders taken over from the older plugins are not touched here
	 * (those plugins never reduced stock and their imports are long done).
	 *
	 * @param WC_Order          $order Order.
	 * @param Eru_MPO_Order_DTO $dto   Order DTO.
	 */
	private static function finish_adopted( $order, Eru_MPO_Order_DTO $dto ) {
		if ( 0 !== strpos( (string) $order->get_created_via(), 'eru-mpo-' ) || '' !== (string) $order->get_meta( self::POST_META ) ) {
			return;
		}
		Eru_MPO_Logger::warning( __( 'Kayıttan sonra yarım kalmış sipariş devralındı; içe aktarım sonrası adımlar tamamlanıyor.', 'marketplace-orders-for-woocommerce' ), $dto->marketplace, $dto->remote_ref );
		self::post_import( $order, $dto );
	}

	/**
	 * Updates a known order from a fresh DTO: reference/cargo meta, forward status, lifecycle events (including a
	 * cancel_lines event for each line the DTO now reports as cancelled). Lines are never rebuilt (manual edits
	 * survive). A failure is caught here: logged, recorded on the map row with a backoff, result "error".
	 *
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @param WC_Order          $order   Order.
	 * @param Eru_MPO_Order_DTO $dto     Order DTO.
	 * @return array
	 */
	private static function update( Eru_MPO_Adapter $adapter, $order, Eru_MPO_Order_DTO $dto ) {
		$mp       = $adapter->key();
		$current  = (string) $order->get_meta( '_eru_mpo_remote_ref' );
		$packages = $order->get_meta( '_eru_mpo_packages' );
		if ( '' !== $current && $current !== $dto->remote_ref && is_array( $packages ) && in_array( $dto->remote_ref, array_map( 'strval', $packages ), true ) ) {
			// An earlier package of a re-linked order: the order follows its current package only.
			return self::result( 'unchanged', $order->get_id(), 'yes' === $order->get_meta( '_eru_mpo_review' ), 'superseded' );
		}
		try {
			$changed = Eru_MPO_Builder::apply_meta( $order, $mp, $dto, false );
			$from    = Eru_MPO_Statuses::current( $order );
			$moved   = false;
			$applies = $dto->status === $from || Eru_MPO_Statuses::can_advance( $from, $dto->status );
			$rstatus = sanitize_text_field( $dto->remote_status );
			if ( $applies && '' !== $rstatus && (string) $order->get_meta( '_eru_mpo_remote_status' ) !== $rstatus ) {
				$order->update_meta_data( '_eru_mpo_remote_status', $rstatus );
				$changed = true;
			}
			if ( 'cancelled' === $from && 'cancelled' === (string) $order->get_meta( Eru_MPO_Statuses::HAND ) && ! in_array( $dto->status, Eru_MPO_Statuses::TERMINAL, true ) && '' === (string) $order->get_meta( '_eru_mpo_hand_noted' ) ) {
				// Cancelled by the shop, still active on the marketplace: never revived (its stock was restored), only
				// flagged once for a person to settle it on the marketplace or reopen it by hand.
				$order->update_meta_data( '_eru_mpo_review', 'yes' );
				$order->update_meta_data( '_eru_mpo_hand_noted', $dto->status );
				/* translators: %s: marketplace status */
				$order->add_order_note( sprintf( __( 'İnceleme gerekli: sipariş mağazada elle iptal edildi, pazaryeri ise hâlâ "%s" diyor. Sipariş yeniden açılmadı ve stok yeniden düşülmedi; pazaryerinde de iptal edin ya da siparişi elle yeniden açın.', 'marketplace-orders-for-woocommerce' ), '' !== $dto->remote_status ? $dto->remote_status : $dto->status ) );
				Eru_MPO_Map::set_review( $mp, $dto->remote_ref, true );
				$changed = true;
			}
			if ( $dto->status !== $from && Eru_MPO_Statuses::can_advance( $from, $dto->status ) ) {
				/* translators: %s: remote status */
				$moved = Eru_MPO_Statuses::advance( $order, $dto->status, sprintf( __( 'Pazaryeri durumu: %s', 'marketplace-orders-for-woocommerce' ), '' !== $dto->remote_status ? $dto->remote_status : $dto->status ) );
				if ( ! $moved ) {
					throw new RuntimeException( __( 'Durum değişikliği kaydedilemedi.', 'marketplace-orders-for-woocommerce' ) );
				}
			} elseif ( $changed ) {
				Eru_MPO_Isolated_Save::save( $order );
			}
			$events = Eru_MPO_Lifecycle::apply( $order, array_merge( self::line_events( $order, $dto ), $dto->events ) );
			$events += self::note_new_lines( $order, $dto ) ? 1 : 0;
			Eru_MPO_Raw::store( $mp, $dto->remote_ref, $dto->raw );
			Eru_MPO_Map::clear_error( $mp, $dto->remote_ref );
		} catch ( Throwable $e ) {
			self::forget_order( $order->get_id() );
			self::failed( $mp, $dto->remote_ref, $e );
			return self::result( 'error', $order->get_id(), false, $e->getMessage() );
		}
		$review = 'yes' === $order->get_meta( '_eru_mpo_review' );
		return self::result( ( $moved || $changed || $events ) ? 'updated' : 'unchanged', $order->get_id(), $review );
	}

	/**
	 * Active DTO lines that match no order line (by remote line id, else SKU or barcode: migrated and adopted legacy
	 * orders may lack line ids) — the marketplace added a line to a known order; lines are never rebuilt: the order is flagged for review and one note lists them. Each line is noted once
	 * (_eru_mpo_noted_lines). Orders that follow a re-created package (_eru_mpo_packages) are skipped: their line
	 * ids come from the earlier package.
	 *
	 * @param WC_Order          $order Order.
	 * @param Eru_MPO_Order_DTO $dto   Order DTO.
	 * @return bool A note was added.
	 */
	private static function note_new_lines( $order, Eru_MPO_Order_DTO $dto ) {
		if ( is_array( $order->get_meta( '_eru_mpo_packages' ) ) && $order->get_meta( '_eru_mpo_packages' ) ) {
			return false;
		}
		$noted = $order->get_meta( '_eru_mpo_noted_lines' );
		$noted = is_string( $noted ) && '' !== $noted ? json_decode( $noted, true ) : $noted; // Migrated legacy value: JSON or a list.
		$noted = is_array( $noted ) ? array_map( 'strval', $noted ) : array();
		$new   = array();
		foreach ( $dto->lines as $line ) {
			$id = $line->remote_line_id;
			if ( 'active' !== $line->status || '' === $id || in_array( $id, $noted, true ) ) {
				continue;
			}
			// Known when any order line matches: by remote line id, else position, SKU or barcode. Migrated and
			// adopted legacy orders may have lines without a remote line id.
			$spec = array(
				'remote_line_id' => $id,
				'sku'            => $line->sku,
				'barcode'        => $line->barcode,
				'index'          => -1,
			);
			if ( Eru_MPO_Lifecycle::find_item( $order, $spec, true ) ) {
				continue;
			}
			$new[]   = ( '' !== $line->name ? $line->name : $line->sku ) . ' × ' . $line->quantity;
			$noted[] = $id;
		}
		if ( ! $new ) {
			return false;
		}
		/* translators: %s: lines */
		$order->add_order_note( sprintf( __( 'İnceleme gerekli: pazaryeri siparişe sonradan satır ekledi; satırlar siparişe eklenmedi: %s', 'marketplace-orders-for-woocommerce' ), implode( ', ', $new ) ) );
		$order->update_meta_data( '_eru_mpo_noted_lines', array_slice( $noted, -200 ) );
		$order->update_meta_data( '_eru_mpo_review', 'yes' );
		Eru_MPO_Isolated_Save::save( $order );
		Eru_MPO_Map::set_review( (string) $order->get_meta( '_eru_mpo_marketplace' ), $dto->remote_ref, true );
		return true;
	}

	/**
	 * cancel_lines events for DTO lines now reported as cancelled (id "cancel-line-<remote line id>", or
 * "cancel-line-#<position>-<SKU>" without one), for
	 * order lines that were imported as active.
	 *
	 * @param WC_Order          $order Order.
	 * @param Eru_MPO_Order_DTO $dto   Order DTO.
	 * @return Eru_MPO_Event_DTO[]
	 */
	private static function line_events( $order, Eru_MPO_Order_DTO $dto ) {
		$out = array();
		foreach ( array_values( $dto->lines ) as $i => $line ) {
			if ( 'cancelled' !== $line->status ) {
				continue;
			}
			// Unique per DTO line: the remote line id, or the line's position plus its SKU.
			$key  = '' !== $line->remote_line_id ? $line->remote_line_id : '#' . $i . '-' . $line->sku;
			$spec = array(
				'remote_line_id' => $line->remote_line_id,
				'sku'            => $line->sku,
				'barcode'        => $line->barcode,
				'quantity'       => $line->quantity,
				'index'          => '' === $line->remote_line_id ? $i : -1,
			);
			$item = Eru_MPO_Lifecycle::find_item( $order, $spec, true );
			if ( ! $item || '' !== (string) $item->get_meta( '_eru_mpo_line_status' ) ) {
				continue; // Unknown line, or it was imported as already cancelled/returned.
			}
			$out[] = Eru_MPO_Event_DTO::from_array(
				array(
					'id'    => 'cancel-line-' . $key,
					'type'  => 'cancel_lines',
					'lines' => array( $spec ),
				)
			);
		}
		return $out;
	}
}
