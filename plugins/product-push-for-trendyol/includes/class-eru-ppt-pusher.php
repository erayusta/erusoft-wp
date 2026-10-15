<?php
/**
 * Sends products to Trendyol: create for new items, the approved-product update endpoints (content +
 * variant) for approved ones and the unapproved update endpoint for items waiting for approval.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per item (simple product or variation, keyed by barcode) the plugin keeps:
 *   _eru_ppt_remote  ''|unknown|unapproved|approved   what Trendyol has, as far as we know
 *   _eru_ppt_status  ''|sending|pending|success|failed|invalid|needs_review   last operation
 *   _eru_ppt_errors, _eru_ppt_batch, _eru_ppt_open (open batch ids), _eru_ppt_content_id,
 *   _eru_ppt_attempt_at, _eru_ppt_checked_at, _eru_ppt_pushed_at.
 *
 * A create is sent only for an item Trendyol does not know. While the create runs the item is "unknown";
 * a clear answer (batch id, or a 4xx) settles it. Anything ambiguous (timeout, 5xx, unreadable 2xx) leaves
 * it "unknown": the next push first searches the approved and unapproved listings by barcode and creates
 * again only when both explicitly say "not found" and AMBIGUOUS_WAIT has passed. A failed lookup turns the
 * item into needs_review, which only an admin reset clears. One global lock serialises pushes.
 */
class Eru_PPT_Pusher {

	const HOOK           = 'eru_ppt_push';
	const GROUP          = 'eru-ppt';
	const BATCH          = 100;
	const QUEUE_CHUNK    = 10;
	const AMBIGUOUS_WAIT = HOUR_IN_SECONDS;
	const BATCH_PREFIX   = 'eru_ppt_batch_';

	/** Hooks. */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_queued' ) );
	}

	/**
	 * Queues products for a background push (Action Scheduler), in chunks.
	 *
	 * @param int[] $ids Product ids.
	 * @return int Products queued.
	 */
	public static function enqueue( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids || ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}
		foreach ( array_chunk( $ids, self::QUEUE_CHUNK ) as $chunk ) {
			as_enqueue_async_action( self::HOOK, array( 'ids' => $chunk ), self::GROUP );
		}
		return count( $ids );
	}

	/** @param array $ids Product ids (Action Scheduler). */
	public static function run_queued( $ids ) {
		$ids = array_map( 'absint', (array) $ids );
		if ( ! $ids || ! Eru_PPT_Settings::is_enabled() ) {
			return;
		}
		$res = self::push( $ids );
		if ( ! empty( $res['locked'] ) && function_exists( 'as_schedule_single_action' ) ) {
			$args = array( 'ids' => $ids );
			// Pending-only: the running action is this chunk itself; another pending copy means it is already re-queued.
			$dupe = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'args'     => $args,
					'group'    => self::GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			);
			if ( ! $dupe ) {
				as_schedule_single_action( time() + 2 * MINUTE_IN_SECONDS, self::HOOK, $args, self::GROUP );
			}
		}
	}

	/** @param int $id Object. @param string $k Key. @return mixed */
	public static function get( $id, $k ) {
		return get_post_meta( $id, '_eru_ppt_' . $k, true );
	}

	/** @param int $id Object. @param string $k Key. @return string[] List meta without empty entries. */
	public static function arr( $id, $k ) {
		$v = get_post_meta( $id, '_eru_ppt_' . $k, true );
		return is_array( $v ) ? array_values( array_filter( array_map( 'strval', $v ), 'strlen' ) ) : array();
	}

	/**
	 * @param int   $id Object (product or variation).
	 * @param array $kv Values (null deletes).
	 */
	public static function set( $id, array $kv ) {
		$o = wc_get_product( $id );
		if ( ! $o ) {
			return;
		}
		foreach ( $kv as $k => $v ) {
			if ( null === $v ) {
				$o->delete_meta_data( '_eru_ppt_' . $k );
			} else {
				$o->update_meta_data( '_eru_ppt_' . $k, $v );
			}
		}
		$o->save_meta_data();
	}

	/**
	 * Validation only (no HTTP except cached category data).
	 *
	 * @param int[] $ids Product ids.
	 * @return array<int,string[]> Errors per product (empty array = valid).
	 */
	public static function validate( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$p          = wc_get_product( absint( $id ) );
			$out[ $id ] = $p ? Eru_PPT_Builder::build( $p )['errors'] : array( __( 'Ürün bulunamadı.', 'product-push-for-trendyol' ) );
		}
		return $out;
	}

	/**
	 * Pushes products now (background job, CLI).
	 *
	 * @param int[] $ids Product ids.
	 * @return array{sent:array,errors:array,skipped:array,batches:string[],locked:bool,error:string}
	 */
	public static function push( array $ids ) {
		$res = array(
			'sent'    => array(
				'create'     => 0,
				'content'    => 0,
				'variant'    => 0,
				'unapproved' => 0,
			),
			'errors'  => array(),
			'skipped' => array(),
			'batches' => array(),
			'locked'  => false,
			'error'   => '',
		);
		if ( ! Eru_PPT_Settings::is_enabled() ) {
			$res['error'] = __( 'Trendyol entegrasyonu kapalı veya API bilgileri eksik.', 'product-push-for-trendyol' );
			return $res;
		}
		if ( ! Eru_PPT_Lock::acquire( 'push', 15 * MINUTE_IN_SECONDS ) ) {
			$res['locked'] = true;
			$res['error']  = __( 'Başka bir Trendyol gönderimi sürüyor; birazdan tekrar denenecek.', 'product-push-for-trendyol' );
			return $res;
		}
		try {
			$routes = array(
				'create'     => array(),
				'approved'   => array(),
				'unapproved' => array(),
			);
			foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
				$product = wc_get_product( $id );
				if ( $product && $product->is_type( 'variation' ) ) {
					$product = wc_get_product( $product->get_parent_id() );
				}
				if ( ! $product ) {
					$res['errors'][ $id ] = array( __( 'Ürün bulunamadı.', 'product-push-for-trendyol' ) );
					continue;
				}
				$pid = $product->get_id();
				Eru_PPT_Lock::refresh( 'push' ); // Long runs (many lookups) keep the lock fresh.
				$b   = Eru_PPT_Builder::build( $product );
				if ( $b['errors'] ) {
					$res['errors'][ $pid ] = $b['errors'];
					self::set(
						$pid,
						array(
							'status' => 'invalid',
							'errors' => $b['errors'],
						)
					);
					continue;
				}
				if ( $product->is_type( 'variable' ) ) {
					self::set(
						$pid,
						array(
							'status' => null,
							'errors' => null,
						)
					);
				}
				foreach ( $b['items'] as $item ) {
					$bc    = $item['barcode'];
					$oid   = (int) $b['objects'][ $bc ];
					$entry = array(
						'item'    => $item,
						'obj'     => $oid,
						'pid'     => $pid,
						'variant' => $b['variant_attrs'][ $bc ] ?? array(),
					);
					$route = self::route( $oid, $bc, $res, $pid );
					if ( $route ) {
						$routes[ $route ][] = $entry;
					}
				}
			}
			self::send_create( $routes['create'], $res );
			self::send_approved( $routes['approved'], $res );
			self::send_unapproved( $routes['unapproved'], $res );
		} finally {
			Eru_PPT_Lock::release( 'push' );
		}
		return $res;
	}

	/**
	 * Decides the endpoint for one item, refreshing its approval state from Trendyol when it may exist there.
	 *
	 * @param int    $oid Object.
	 * @param string $bc  Barcode.
	 * @param array  $res Result (skips are added).
	 * @param int    $pid Product.
	 * @return string|null create|approved|unapproved, or null to skip.
	 */
	private static function route( $oid, $bc, array &$res, $pid ) {
		$status = (string) self::get( $oid, 'status' );
		$remote = (string) self::get( $oid, 'remote' );
		/* translators: %s: barcode */
		$tag  = sprintf( __( 'Barkod %s: ', 'product-push-for-trendyol' ), $bc );
		$skip = function ( $msg ) use ( &$res, $pid, $tag ) {
			$res['skipped'][ $pid ][] = $tag . $msg;
			return null;
		};
		if ( 'needs_review' === $status ) {
			return $skip( __( 'inceleme gerekiyor; Trendyol panelinde kontrol edip ürün ekranından durumu sıfırlayın.', 'product-push-for-trendyol' ) );
		}
		if ( 'pending' === $status && self::has_open_batch( $oid ) ) {
			return $skip( __( 'önceki gönderimin sonucu bekleniyor.', 'product-push-for-trendyol' ) );
		}
		if ( '' === $remote ) {
			return 'create';
		}
		$found = self::lookup( $bc );
		if ( 'approved' === $found['state'] ) {
			self::set(
				$oid,
				array(
					'remote'     => 'approved',
					'content_id' => $found['content_id'] ? $found['content_id'] : self::get( $oid, 'content_id' ),
					'checked_at' => time(),
				)
			);
			return 'approved';
		}
		if ( 'unapproved' === $found['state'] ) {
			self::set(
				$oid,
				array(
					'remote'     => 'unapproved',
					'checked_at' => time(),
				)
			);
			return 'unapproved';
		}
		if ( 'error' === $found['state'] ) {
			if ( 'unknown' === $remote ) {
				$msg = __( 'Belirsiz önceki gönderimden sonra Trendyol listeleri sorgulanamadı: ', 'product-push-for-trendyol' ) . $found['error'];
				self::set(
					$oid,
					array(
						'status' => 'needs_review',
						'errors' => array( $msg ),
					)
				);
				return $skip( $msg );
			}
			return $skip( $found['error'] );
		}
		// Explicitly absent from both listings.
		if ( 'unknown' === $remote ) {
			$wait = (int) self::get( $oid, 'attempt_at' ) + self::AMBIGUOUS_WAIT - time();
			if ( $wait > 0 ) {
				/* translators: %d: minutes */
				return $skip( sprintf( __( 'önceki gönderimin sonucu belirsiz ve ürün Trendyol\'da henüz görünmüyor; yinelenen kayıt açmamak için %d dakika sonra tekrar deneyin.', 'product-push-for-trendyol' ), max( 1, (int) ceil( $wait / 60 ) ) ) );
			}
			return 'create';
		}
		$msg = __( 'Trendyol listelerinde bulunamadı (silinmiş veya reddedilmiş olabilir). Trendyol panelinde kontrol edip durumu sıfırlayın.', 'product-push-for-trendyol' );
		self::set(
			$oid,
			array(
				'status' => 'needs_review',
				'errors' => array( $msg ),
			)
		);
		return $skip( $msg );
	}

	/** @param int $oid Object. @return bool Any of its open batches is still queued. */
	private static function has_open_batch( $oid ) {
		foreach ( self::arr( $oid, 'open' ) as $b ) {
			if ( null !== self::batch_get( $b ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Searches one listing for an exact barcode match.
	 *
	 * @param string $bc    Barcode.
	 * @param string $which approved|unapproved.
	 * @return array{ok:bool,found:bool,content_id:int,error:string}
	 */
	public static function find( $bc, $which ) {
		$r = Eru_PPT_Client::call(
			$which,
			null,
			array(),
			array(
				'barcode' => $bc,
				'page'    => 0,
				'size'    => 50,
			)
		);
		if ( ! $r['ok'] ) {
			return array(
				'ok'         => false,
				'found'      => false,
				'content_id' => 0,
				'error'      => $r['error'],
			);
		}
		foreach ( (array) ( $r['data']['content'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$variants = is_array( $it['variants'] ?? null ) ? $it['variants'] : array( $it );
			foreach ( $variants as $v ) {
				if ( is_array( $v ) && (string) ( $v['barcode'] ?? '' ) === (string) $bc ) {
					return array(
						'ok'         => true,
						'found'      => true,
						'content_id' => absint( $it['contentId'] ?? $v['contentId'] ?? 0 ),
						'error'      => '',
					);
				}
			}
		}
		return array(
			'ok'         => true,
			'found'      => false,
			'content_id' => 0,
			'error'      => '',
		);
	}

	/**
	 * @param string $bc Barcode.
	 * @return array{state:string,content_id:int,error:string} state approved|unapproved|absent|error.
	 */
	public static function lookup( $bc ) {
		$a = self::find( $bc, 'approved' );
		if ( ! $a['ok'] ) {
			return array(
				'state'      => 'error',
				'content_id' => 0,
				'error'      => $a['error'],
			);
		}
		if ( $a['found'] ) {
			return array(
				'state'      => 'approved',
				'content_id' => $a['content_id'],
				'error'      => '',
			);
		}
		$u = self::find( $bc, 'unapproved' );
		if ( ! $u['ok'] ) {
			return array(
				'state'      => 'error',
				'content_id' => 0,
				'error'      => $u['error'],
			);
		}
		return array(
			'state'      => $u['found'] ? 'unapproved' : 'absent',
			'content_id' => 0,
			'error'      => '',
		);
	}

	/** @param array $entries Entries. @param array $res Result. */
	private static function send_create( array $entries, array &$res ) {
		foreach ( array_chunk( $entries, self::BATCH ) as $chunk ) {
			$before = array();
			foreach ( $chunk as $e ) {
				$before[ $e['obj'] ] = (string) self::get( $e['obj'], 'remote' );
				// Saved before the request: a crash or timeout leaves the item "unknown", never "new".
				self::set(
					$e['obj'],
					array(
						'remote'     => 'unknown',
						'status'     => 'sending',
						'errors'     => array(),
						'open'       => array(),
						'attempt_at' => time(),
					)
				);
			}
			$r     = Eru_PPT_Client::call( 'create', array( 'items' => array_column( $chunk, 'item' ) ) );
			$batch = $r['ok'] ? sanitize_text_field( (string) ( $r['data']['batchRequestId'] ?? '' ) ) : '';
			if ( '' !== $batch ) {
				self::opened( $batch, 'create', $chunk, $res );
				$res['sent']['create'] += count( $chunk );
				continue;
			}
			$ambiguous = $r['ambiguous'] || $r['ok'];
			$msg       = $r['ok'] ? __( 'Trendyol yanıtında batch kimliği yok.', 'product-push-for-trendyol' ) : $r['error'];
			if ( $ambiguous ) {
				$msg .= ' ' . __( 'Sonuç belirsiz: bir sonraki gönderimde ürün önce Trendyol\'da aranacak.', 'product-push-for-trendyol' );
			}
			foreach ( $chunk as $e ) {
				self::set(
					$e['obj'],
					array(
						'remote' => $ambiguous ? 'unknown' : $before[ $e['obj'] ],
						'status' => 'failed',
						'errors' => array( $msg ),
					)
				);
				$res['errors'][ $e['pid'] ][] = $e['item']['barcode'] . ': ' . $msg;
			}
		}
	}

	/** @param array $entries Entries. @param array $res Result. */
	private static function send_approved( array $entries, array &$res ) {
		$content = array();
		$ready   = array();
		foreach ( $entries as $e ) {
			$cid = absint( self::get( $e['obj'], 'content_id' ) );
			if ( ! $cid ) {
				$msg = __( 'Trendyol içerik kimliği (contentId) bulunamadı.', 'product-push-for-trendyol' );
				self::set(
					$e['obj'],
					array(
						'status' => 'failed',
						'errors' => array( $msg ),
					)
				);
				$res['errors'][ $e['pid'] ][] = $e['item']['barcode'] . ': ' . $msg;
				continue;
			}
			$e['cid'] = $cid;
			$ready[]  = $e;
			if ( ! isset( $content[ $cid ] ) ) {
				$it              = $e['item'];
				$content[ $cid ] = array(
					'entries' => array(),
					'item'    => array(
						'contentId'   => $cid,
						'title'       => $it['title'],
						'description' => $it['description'],
						'images'      => $it['images'],
						'attributes'  => array_values( array_filter( $it['attributes'], fn( $a ) => ! in_array( (int) $a['attributeId'], $e['variant'], true ) ) ),
					),
				);
			}
			$content[ $cid ]['entries'][] = $e;
		}
		foreach ( $ready as $e ) {
			self::set(
				$e['obj'],
				array(
					'status' => 'sending',
					'errors' => array(),
					'open'   => array(),
				)
			);
		}
		foreach ( array_chunk( $content, self::BATCH, true ) as $chunk ) {
			$entries_c = array();
			foreach ( $chunk as $cid => $c ) {
				foreach ( $c['entries'] as $e ) {
					$e['key']    = 'c:' . $cid;
					$entries_c[] = $e;
				}
			}
			$r = Eru_PPT_Client::call( 'content_update', array( 'items' => array_values( array_column( $chunk, 'item' ) ) ) );
			self::after_update( $r, 'content', $entries_c, $res, count( $chunk ) );
		}
		foreach ( array_chunk( $ready, self::BATCH ) as $chunk ) {
			$items = array();
			foreach ( $chunk as $e ) {
				$it   = $e['item'];
				$v    = array(
					'barcode'           => $it['barcode'],
					'stockCode'         => $it['stockCode'],
					'vatRate'           => $it['vatRate'],
					'dimensionalWeight' => $it['dimensionalWeight'],
				);
				foreach ( array( 'shipmentAddressId', 'returningAddressId' ) as $k ) {
					if ( isset( $it[ $k ] ) ) {
						$v[ $k ] = $it[ $k ];
					}
				}
				$items[] = $v;
			}
			$r = Eru_PPT_Client::call( 'variant_update', array( 'items' => $items ) );
			self::after_update( $r, 'variant', $chunk, $res, count( $chunk ) );
		}
	}

	/** @param array $entries Entries. @param array $res Result. */
	private static function send_unapproved( array $entries, array &$res ) {
		$keep = array( 'barcode', 'title', 'description', 'productMainId', 'brandId', 'categoryId', 'stockCode', 'dimensionalWeight', 'vatRate', 'cargoCompanyId', 'deliveryOption', 'shipmentAddressId', 'returningAddressId', 'images', 'attributes' );
		foreach ( array_chunk( $entries, self::BATCH ) as $chunk ) {
			$items = array();
			foreach ( $chunk as $e ) {
				self::set(
					$e['obj'],
					array(
						'status' => 'sending',
						'errors' => array(),
						'open'   => array(),
					)
				);
				$items[] = array_intersect_key( $e['item'], array_flip( $keep ) );
			}
			$r = Eru_PPT_Client::call( 'unapproved_update', array( 'items' => $items ) );
			self::after_update( $r, 'unapproved', $chunk, $res, count( $chunk ) );
		}
	}

	/**
	 * Records the answer of an update call (updates are idempotent: an ambiguous answer is just a failure).
	 *
	 * @param array  $r       Client result.
	 * @param string $op      content|variant|unapproved.
	 * @param array  $entries Entries.
	 * @param array  $res     Result.
	 * @param int    $count   Items sent.
	 */
	private static function after_update( array $r, $op, array $entries, array &$res, $count ) {
		$batch = $r['ok'] ? sanitize_text_field( (string) ( $r['data']['batchRequestId'] ?? '' ) ) : '';
		if ( '' !== $batch ) {
			self::opened( $batch, $op, $entries, $res );
			$res['sent'][ $op ] += $count;
			return;
		}
		$msg = $r['ok'] ? __( 'Trendyol yanıtında batch kimliği yok.', 'product-push-for-trendyol' ) : $r['error'];
		foreach ( $entries as $e ) {
			$errs   = self::arr( $e['obj'], 'errors' );
			$errs[] = $msg;
			self::set(
				$e['obj'],
				array(
					'status' => 'failed',
					'errors' => array_values( array_unique( $errs ) ),
				)
			);
			$res['errors'][ $e['pid'] ][] = $e['item']['barcode'] . ': ' . $msg;
		}
	}

	/**
	 * Stores an accepted batch and marks its items pending.
	 *
	 * @param string $batch   Batch id.
	 * @param string $op      Operation.
	 * @param array  $entries Entries (key defaults to the barcode).
	 * @param array  $res     Result.
	 */
	private static function opened( $batch, $op, array $entries, array &$res ) {
		$map = array();
		foreach ( $entries as $e ) {
			$key           = $e['key'] ?? $e['item']['barcode'];
			$map[ $key ][] = (int) $e['obj'];
			$open          = self::arr( $e['obj'], 'open' );
			$open[]        = $batch;
			self::set(
				$e['obj'],
				array(
					'status'    => 'pending',
					'batch'     => $batch,
					'open'      => array_values( array_unique( $open ) ),
					'op'        => $op,
					'pushed_at' => time(),
				)
			);
		}
		self::batch_put(
			$batch,
			array(
				'op'    => $op,
				't'     => time(),
				'p'     => 0,
				'tries' => 0,
				'items' => array_map( fn( $ids ) => array_values( array_unique( $ids ) ), $map ),
			)
		);
		$res['batches'][] = $batch;
	}

	/**
	 * Open batches are stored one option row per batch, so a push opening a batch and the poller closing
	 * another never overwrite each other's rows.
	 *
	 * @param string $id Batch id.
	 * @return string Option name.
	 */
	private static function batch_option( $id ) {
		return self::BATCH_PREFIX . md5( (string) $id );
	}

	/** @param string $id Batch id. @return array|null Row. */
	public static function batch_get( $id ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::batch_option( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = null === $raw ? null : maybe_unserialize( $raw );
		return is_array( $row ) ? $row : null;
	}

	/** @param string $id Batch id. @param array $row Row (op, t, p, tries, items). */
	public static function batch_put( $id, array $row ) {
		$row['id'] = (string) $id;
		$opt       = self::batch_option( $id );
		if ( ! add_option( $opt, $row, '', false ) ) {
			update_option( $opt, $row, false );
		}
	}

	/** @param string $id Batch id. */
	public static function batch_delete( $id ) {
		delete_option( self::batch_option( $id ) );
	}

	/** @return array<string,array> Open batches by id (read from the database, not a cached snapshot). */
	public static function batches() {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::BATCH_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( $rows as $raw ) {
			$row = maybe_unserialize( $raw );
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$out[ (string) $row['id'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Admin reset of a product's items after needs_review / failure: the next push looks them up first.
	 *
	 * @param int $id Product or variation.
	 * @return int Items reset, -1 when a push is running (the push lock is held).
	 */
	public static function reset( $id ) {
		$p = wc_get_product( absint( $id ) );
		if ( ! $p ) {
			return 0;
		}
		if ( ! Eru_PPT_Lock::acquire( 'push', 15 * MINUTE_IN_SECONDS ) ) {
			return -1;
		}
		try {
			return self::reset_items( $p );
		} finally {
			Eru_PPT_Lock::release( 'push' );
		}
	}

	/** @param WC_Product $p Product or variation. @return int Items reset. */
	private static function reset_items( $p ) {
		if ( $p->is_type( 'variation' ) ) {
			$objs = array( $p );
		} else {
			$objs = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : array( $p );
			if ( $p->is_type( 'variable' ) ) {
				self::set(
					$p->get_id(),
					array(
						'status' => null,
						'errors' => null,
					)
				);
			}
		}
		$n = 0;
		foreach ( $objs as $o ) {
			$status = (string) self::get( $o->get_id(), 'status' );
			if ( ! in_array( $status, array( 'needs_review', 'failed', 'invalid', 'sending' ), true ) ) {
				continue;
			}
			$remote = (string) self::get( $o->get_id(), 'remote' );
			self::set(
				$o->get_id(),
				array(
					'status' => '',
					'errors' => array(),
					'remote' => '' === $remote ? '' : 'unknown',
					'open'   => array(),
				)
			);
			++$n;
		}
		return $n;
	}
}
