<?php
/**
 * One polling run: fetch, match, then approve under the run lock (ledger first, status second).
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs from Action Scheduler (recurring or "Şimdi çalıştır") or WP-CLI, never inside a page request.
 * An incomplete fetch ends the run before any matching. Before each approval the order status is read
 * again from the database and the transactions are claimed in the ledger; the status changes only after
 * the claim succeeded. Dry-run writes one log row per match and nothing else. While the master switch is
 * off every run is a dry-run, whatever started it, and manual approval is refused.
 */
class Eru_GHA_Approver {

	const LOCK      = 'run';
	/** Longer than a worst-case fetch (30 pages, one mint + one request each, with timeouts). */
	const LOCK_TTL  = HOUR_IN_SECONDS;
	const CACHE_TTL = 10 * MINUTE_IN_SECONDS;
	const MAX_PAGES = 100;

	/** @var array<string,array> Normalised transactions of the current run by id. */
	private static $txns = array();

	/** @return array Empty summary. */
	private static function summary( $status, $error = '' ) {
		return array(
			'status'   => $status,
			'error'    => $error,
			'fetched'  => 0,
			'orders'   => 0,
			'approved' => array(),
			'would'    => array(),
			'review'   => array(),
			'mismatch' => 0,
			'dry_run'  => false,
			'forced_dry' => false,
		);
	}

	/** @param array $sum Summary. @return array Same, stored as the last run. */
	private static function remember( array $sum ) {
		update_option(
			'eru_gha_last_run',
			array(
				'time'     => time(),
				'status'   => $sum['status'],
				'error'    => $sum['error'],
				'fetched'  => $sum['fetched'],
				'orders'   => $sum['orders'],
				'approved' => count( $sum['approved'] ),
				'would'    => count( $sum['would'] ),
				'review'   => count( $sum['review'] ),
				'mismatch' => $sum['mismatch'],
				'dry_run'  => $sum['dry_run'],
			),
			false
		);
		return $sum;
	}

	/** @return string Account currency (the transactions API reports amounts in the account currency). */
	public static function account_currency() {
		return strtoupper( (string) apply_filters( 'eru_gha_account_currency', 'TRY' ) );
	}

	/**
	 * Fetches, normalises and caches the transactions (10 minutes) for the İşlemler tab.
	 *
	 * @return array Client result with normalised transactions.
	 */
	public static function fetch_only() {
		$res = ( new Eru_GHA_Client() )->fetch_transactions( Eru_GHA_Settings::lookback() );
		if ( ! $res['ok'] ) {
			Eru_GHA_Log::add( 'error', 'fetch_error', 0, array( substr( md5( $res['error'] ), 0, 8 ) ), $res['error'], array(), true );
			return $res;
		}
		$list = array();
		foreach ( $res['transactions'] as $raw ) {
			$t = Eru_GHA_Matcher::normalize( $raw );
			if ( '' !== $t['id'] ) {
				$list[ $t['id'] ] = $t;
			}
		}
		$res['transactions'] = $list;
		$cached              = array_map(
			function ( $t ) {
				$t['text'] = Eru_GHA_Log::mask( $t['text'] );
				return $t;
			},
			$list
		);
		set_transient(
			Eru_GHA_Client::CACHE,
			array(
				'time'         => time(),
				'transactions' => $cached,
			),
			self::CACHE_TTL
		);
		return $res;
	}

	/**
	 * @return array Watched order snapshots, 100 per query.
	 */
	private static function watched_orders() {
		$out      = array();
		$statuses = Eru_GHA_Settings::watch_statuses();
		if ( ! $statuses ) {
			return $out;
		}
		foreach ( Eru_GHA_Settings::gateways() as $gw ) {
			for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
				$batch = wc_get_orders(
					array(
						'type'           => 'shop_order',
						'status'         => $statuses,
						'payment_method' => $gw,
						'limit'          => 100,
						'page'           => $page,
						'orderby'        => 'ID',
						'order'          => 'DESC',
					)
				);
				foreach ( $batch as $o ) {
					if ( $o->get_payment_method() !== $gw ) {
						continue;
					}
					$created              = $o->get_date_created();
					$out[ $o->get_id() ] = array(
						'id'       => $o->get_id(),
						'number'   => (string) $o->get_order_number(),
						'total'    => (float) $o->get_total(),
						'currency' => (string) $o->get_currency(),
						'created'  => $created ? $created->getTimestamp() : time(),
					);
				}
				if ( count( $batch ) < 100 ) {
					break;
				}
				if ( self::MAX_PAGES === $page ) {
					/* translators: %d: orders */
					Eru_GHA_Log::add( 'warn', 'skipped', 0, array( $gw ), sprintf( __( 'İzlenen sipariş sayısı %d sınırını aştı; daha eski siparişler bu turda eşleştirilmedi.', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::MAX_PAGES * 100 ), array(), true );
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * One run.
	 *
	 * @param array $opts dry_run (bool|null = setting).
	 * @return array Summary.
	 */
	public static function run( array $opts = array() ) {
		$dry    = isset( $opts['dry_run'] ) && null !== $opts['dry_run'] ? (bool) $opts['dry_run'] : Eru_GHA_Settings::dry_run();
		$forced = ! Eru_GHA_Settings::is_enabled() && ! $dry;
		$dry    = $dry || $forced;
		if ( ! Eru_GHA_Settings::has_credentials() ) {
			return self::summary( 'not_configured', __( 'Kimlik bilgileri eksik.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$token = Eru_GHA_Lock::acquire( self::LOCK, self::LOCK_TTL );
		if ( ! $token ) {
			return self::summary( 'locked' );
		}
		try {
			$sum               = self::summary( 'ok' );
			$sum['dry_run']    = $dry;
			$sum['forced_dry'] = $forced;
			$res            = self::fetch_only();
			if ( ! $res['ok'] ) {
				$sum['status'] = 'aborted';
				$sum['error']  = $res['error'];
				return self::remember( $sum );
			}
			self::$txns     = $res['transactions'];
			$sum['fetched'] = count( self::$txns );
			$orders         = self::watched_orders();
			$sum['orders']  = count( $orders );
			if ( ! $orders || ! self::$txns ) {
				return self::remember( $sum );
			}
			$credit_ids = array_keys( array_filter( self::$txns, fn( $t ) => $t['credit'] ) );
			$m          = Eru_GHA_Matcher::match(
				$orders,
				array_values( self::$txns ),
				Eru_GHA_Ledger::used_ids( $credit_ids ),
				array(
					'regex'     => Eru_GHA_Matcher::compile( (string) Eru_GHA_Settings::get( 'ref_pattern' ), (string) Eru_GHA_Settings::get( 'ref_template' ) ),
					'tolerance' => Eru_GHA_Settings::tolerance(),
					'split'     => Eru_GHA_Settings::on( 'allow_split' ),
					'currency'  => self::account_currency(),
				)
			);
			$by_id = array_column( $orders, null, 'id' );

			foreach ( $m['mismatch'] as $oid => $ids ) {
				++$sum['mismatch'];
				Eru_GHA_Log::add( 'info', 'mismatch', $oid, $ids, sprintf( /* translators: 1: order total, 2: transfer amounts */ __( 'Referans bulundu ama tutar uyuşmadı: sipariş %1$s, havale %2$s.', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::money( $by_id[ $oid ]['total'] ), implode( ' + ', array_map( fn( $id ) => self::money( self::$txns[ $id ]['amount'] ), $ids ) ) ), self::context( $ids ), true );
			}

			$new_reviews = array();
			foreach ( $m['review'] as $oid => $r ) {
				$sum['review'][ $oid ] = $r;
				if ( self::flag_review( $oid, $r['reason'], $r['txns'], $dry ) && ! $dry ) {
					$new_reviews[ $oid ] = $r;
				}
			}
			if ( $new_reviews ) {
				self::mail_review( $new_reviews );
			}

			foreach ( $m['approve'] as $oid => $ids ) {
				if ( $dry ) {
					$sum['would'][ $oid ] = $ids;
					Eru_GHA_Log::add( 'info', 'would_approve', $oid, $ids, sprintf( /* translators: 1: order number, 2: amount */ __( 'Deneme modu: sipariş #%1$s bu havaleyle onaylanırdı (%2$s). Hiçbir şey değiştirilmedi.', 'bank-transfer-auto-approval-for-garanti-bbva' ), $by_id[ $oid ]['number'], self::money( self::sum( $ids ) ) ), self::context( $ids ), true, self::sum( $ids ) );
					continue;
				}
				$r = self::approve( $oid, $ids, 'auto' );
				if ( true === $r ) {
					$sum['approved'][ $oid ] = $ids;
				}
			}
			return self::remember( $sum );
		} finally {
			self::$txns = array();
			Eru_GHA_Lock::release( self::LOCK, $token );
		}
	}

	/**
	 * Manual approval from the İşlemler tab: the transaction is looked up server-side by id in the fetched
	 * list; nothing about it is taken from the request.
	 *
	 * @param string $txn_id     Transaction id.
	 * @param int    $order_id   Order.
	 * @param bool   $allow_diff Approve although the amount differs.
	 * @return true|WP_Error
	 */
	public static function manual( $txn_id, $order_id, $allow_diff = false ) {
		if ( ! Eru_GHA_Settings::is_enabled() ) {
			return new WP_Error( 'eru_gha_off', __( 'Otomatik kontrol kapalıyken elle onay yapılmaz; önce Ayarlar\'dan açın.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$cache = get_transient( Eru_GHA_Client::CACHE );
		$t     = is_array( $cache ) ? ( $cache['transactions'][ (string) $txn_id ] ?? null ) : null;
		if ( ! $t ) {
			return new WP_Error( 'eru_gha_txn', __( 'İşlem, çekilen hareket listesinde yok. Listeyi yenileyip tekrar deneyin.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		if ( empty( $t['credit'] ) || ( '' !== $t['currency'] && $t['currency'] !== self::account_currency() ) ) {
			return new WP_Error( 'eru_gha_debit', __( 'Yalnızca gelen (alacak) hareketlerle onay verilebilir.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		if ( Eru_GHA_Settings::dry_run() ) {
			$order = wc_get_order( $order_id );
			Eru_GHA_Log::add( 'info', 'would_approve', (int) $order_id, array( $t['id'] ), sprintf( /* translators: %s: order number */ __( 'Deneme modu: sipariş #%s bu işlemle elle onaylanırdı. Hiçbir şey değiştirilmedi.', 'bank-transfer-auto-approval-for-garanti-bbva' ), $order ? $order->get_order_number() : (string) $order_id ), array(), false, $t['amount'] );
			return new WP_Error( 'eru_gha_dry', __( 'Deneme modu açık: onay yalnızca günlüğe yazıldı.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$token = Eru_GHA_Lock::acquire( self::LOCK, self::LOCK_TTL );
		if ( ! $token ) {
			return new WP_Error( 'eru_gha_locked', __( 'Şu anda bir eşleştirme çalışıyor; birazdan tekrar deneyin.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		try {
			self::$txns = array( $t['id'] => $t );
			return self::approve( (int) $order_id, array( $t['id'] ), 'manual', $allow_diff );
		} finally {
			self::$txns = array();
			Eru_GHA_Lock::release( self::LOCK, $token );
		}
	}

	/** @param int $order_id Order. @return string Status read from the database, bypassing caches. */
	private static function fresh_status( $order_id ) {
		global $wpdb;
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$s = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$s = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		clean_post_cache( $order_id );
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		return preg_replace( '/^wc-/', '', $s );
	}

	/**
	 * Approves one order with the given transactions. Caller holds the run lock.
	 *
	 * @param int      $order_id   Order.
	 * @param string[] $ids        Transaction ids (present in self::$txns).
	 * @param string   $source     auto|manual.
	 * @param bool     $allow_diff Manual only: approve with a different amount.
	 * @return true|WP_Error
	 */
	private static function approve( $order_id, array $ids, $source, $allow_diff = false ) {
		do_action( 'eru_gha_before_approve', $order_id, $ids );
		if ( ! Eru_GHA_Ledger::lock_order( $order_id ) ) {
			$msg = __( 'Sipariş başka bir işlem tarafından kilitli; onaylanmadı (sonraki turda yeniden denenir).', 'bank-transfer-auto-approval-for-garanti-bbva' );
			Eru_GHA_Log::add( 'warn', 'skipped', $order_id, $ids, $msg, array(), true );
			return new WP_Error( 'eru_gha_skip', $msg );
		}
		try {
			return self::approve_locked( $order_id, $ids, $source, $allow_diff );
		} finally {
			Eru_GHA_Ledger::unlock_order( $order_id );
		}
	}

	/**
	 * approve() body; the caller holds the run lock and the per-order lock.
	 *
	 * @return true|WP_Error
	 */
	private static function approve_locked( $order_id, array $ids, $source, $allow_diff ) {
		$skip   = function ( $msg ) use ( $order_id, $ids ) {
			Eru_GHA_Log::add( 'warn', 'skipped', $order_id, $ids, $msg, array(), true );
			return new WP_Error( 'eru_gha_skip', $msg );
		};
		$status = self::fresh_status( $order_id );
		$order  = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return $skip( __( 'Sipariş bulunamadı.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		if ( ! in_array( $status, Eru_GHA_Settings::watch_statuses(), true ) ) {
			/* translators: %s: status */
			return $skip( sprintf( __( 'Sipariş durumu değişmiş (%s); onaylanmadı.', 'bank-transfer-auto-approval-for-garanti-bbva' ), $status ) );
		}
		if ( ! in_array( $order->get_payment_method(), Eru_GHA_Settings::gateways(), true ) ) {
			return $skip( __( 'Siparişin ödeme yöntemi izlenmiyor; onaylanmadı.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		if ( strtoupper( $order->get_currency() ) !== self::account_currency() ) {
			return $skip( __( 'Sipariş para birimi hesabınkiyle aynı değil; onaylanmadı.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$amount = self::sum( $ids );
		if ( ! $allow_diff && ! Eru_GHA_Matcher::amount_ok( (float) $order->get_total(), $amount, Eru_GHA_Settings::tolerance() ) ) {
			return $skip( __( 'Sipariş tutarı havale tutarıyla uyuşmuyor; onaylanmadı.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$claim = array();
		foreach ( $ids as $id ) {
			$claim[ $id ] = self::$txns[ $id ]['amount'];
		}
		if ( ! Eru_GHA_Ledger::claim( $claim, $order_id, $source ) ) {
			return $skip(
				Eru_GHA_Ledger::order_has_active( $order_id )
					? __( 'Bu sipariş daha önce bir havale işlemiyle onaylanmış; yeniden onay için defterdeki kaydı serbest bırakın.', 'bank-transfer-auto-approval-for-garanti-bbva' )
					: __( 'Havale işlemi daha önce başka bir onayda kullanılmış; onaylanmadı.', 'bank-transfer-auto-approval-for-garanti-bbva' )
			);
		}

		$lines = array();
		foreach ( $ids as $id ) {
			$t       = self::$txns[ $id ];
			$lines[] = sprintf( '%s — %s%s%s', $id, self::money( $t['amount'] ), '' !== $t['payer'] ? ' — ' . Eru_GHA_Log::initials( $t['payer'] ) : '', '' !== $t['payer_iban'] ? ' — ' . $t['payer_iban'] : '' );
		}
		$head = 'manual' === $source
			? __( 'Garanti BBVA havalesiyle elle onaylandı.', 'bank-transfer-auto-approval-for-garanti-bbva' )
			: __( 'Garanti BBVA havalesi eşleşti, sipariş otomatik onaylandı.', 'bank-transfer-auto-approval-for-garanti-bbva' );
		$order->add_order_note( Eru_GHA_Log::mask( $head . "\n" . implode( "\n", $lines ) ) );
		$order->update_meta_data( '_eru_gha_txn_ids', array_values( $ids ) );
		$order->delete_meta_data( '_eru_gha_review' );
		$order->save();

		$target = (string) Eru_GHA_Settings::get( 'approved_status' );
		if ( in_array( $target, wc_get_is_paid_statuses(), true ) && ! $order->get_date_paid() ) {
			$to    = fn() => $target;
			$valid = fn( $list ) => array_unique( array_merge( (array) $list, array( $status ) ) );
			add_filter( 'woocommerce_payment_complete_order_status', $to, 999 );
			add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $valid, 999 );
			try {
				$order->payment_complete();
			} finally {
				remove_filter( 'woocommerce_payment_complete_order_status', $to, 999 );
				remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $valid, 999 );
			}
			$order = wc_get_order( $order_id );
		}
		if ( $order->get_status() !== $target ) {
			$order->update_status( $target );
		}

		Eru_GHA_Log::add( 'success', 'manual' === $source ? 'manual' : 'approved', $order_id, $ids, implode( '; ', $lines ), self::context( $ids ), false, $amount );
		self::mail_approved( $order, $lines );
		do_action( 'eru_gha_order_approved', $order_id, $ids, $source );
		return true;
	}

	/**
	 * Marks an order for review (once per reason) and logs it once per day.
	 *
	 * @return bool A new log row was written today.
	 */
	private static function flag_review( $order_id, $reason, array $ids, $dry ) {
		$labels  = array(
			'ambiguous_txn' => __( 'aynı havale birden fazla siparişe uyuyor', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'multiple_txns' => __( 'siparişe birden fazla havale uyuyor', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'mixed_senders' => __( 'havaleler farklı göndericilerden; parçalı ödeme olarak birleştirilmedi', 'bank-transfer-auto-approval-for-garanti-bbva' ),
		);
		$label   = $labels[ $reason ] ?? $reason;
		$written = Eru_GHA_Log::add( 'warn', 'review', $order_id, $ids, sprintf( /* translators: %s: reason */ __( 'Otomatik onay yapılmadı: %s.', 'bank-transfer-auto-approval-for-garanti-bbva' ), $label ), self::context( $ids ), true, self::sum( $ids ) );
		if ( ! $dry ) {
			$order = wc_get_order( $order_id );
			if ( $order && $order->get_meta( '_eru_gha_review' ) !== $reason ) {
				$order->update_meta_data( '_eru_gha_review', $reason );
				/* translators: %s: reason */
				$order->add_order_note( sprintf( __( 'Garanti BBVA: eşleşme belirsiz (%s); otomatik onay yapılmadı. WooCommerce → Garanti Havale Onayı → Eşleşmeler sekmesinden kontrol edin.', 'bank-transfer-auto-approval-for-garanti-bbva' ), $label ) );
				$order->save();
			}
		}
		return $written;
	}

	/** @param string[] $ids Ids. @return float Sum of the amounts. */
	private static function sum( array $ids ) {
		return (float) array_sum( array_map( fn( $id ) => (float) ( self::$txns[ $id ]['amount'] ?? 0 ), $ids ) );
	}

	/** @param float $v Amount. @return string "1.234,50 TRY". */
	private static function money( $v ) {
		return number_format( (float) $v, 2, ',', '.' ) . ' ' . self::account_currency();
	}

	/** @param string[] $ids Ids. @return array Log context (masked by the logger). */
	private static function context( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$t = self::$txns[ $id ] ?? null;
			if ( $t ) {
				$out[] = array(
					'id'     => $id,
					'date'   => $t['date'],
					'amount' => $t['amount'],
					'text'   => mb_substr( Eru_GHA_Log::strip_name( $t['text'], $t['payer'] ), 0, 160 ),
					'payer'  => Eru_GHA_Log::initials( $t['payer'] ),
				);
			}
		}
		return array( 'txns' => $out );
	}

	/** @param WC_Order $order Order. @param string[] $lines Masked lines. */
	private static function mail_approved( $order, array $lines ) {
		$to = Eru_GHA_Settings::notify_emails();
		if ( ! $to ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site, 2: order number */
		$subject = sprintf( __( '[%1$s] Havale eşleşti, sipariş #%2$s onaylandı', 'bank-transfer-auto-approval-for-garanti-bbva' ), $site, $order->get_order_number() );
		$body    = sprintf( /* translators: 1: order number, 2: total */ __( 'Sipariş #%1$s (%2$s) Garanti BBVA hesap hareketiyle eşleşti ve onaylandı.', 'bank-transfer-auto-approval-for-garanti-bbva' ), $order->get_order_number(), self::money( $order->get_total() ) ) . "\n\n" . implode( "\n", $lines ) . "\n\n" . $order->get_edit_order_url() . "\n";
		wp_mail( $to, $subject, Eru_GHA_Log::mask( $body ) );
	}

	/** @param array $reviews order_id => [reason, txns]. */
	private static function mail_review( array $reviews ) {
		$to = Eru_GHA_Settings::notify_emails();
		if ( ! $to ) {
			return;
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array();
		foreach ( $reviews as $oid => $r ) {
			$o       = wc_get_order( $oid );
			$lines[] = '#' . ( $o ? $o->get_order_number() : $oid ) . ' — ' . implode( ', ', $r['txns'] ) . ( $o ? ' — ' . $o->get_edit_order_url() : '' );
		}
		/* translators: %s: site */
		$subject = sprintf( __( '[%s] Havale eşleşmesi incelenmeli', 'bank-transfer-auto-approval-for-garanti-bbva' ), $site );
		$body    = __( 'Aşağıdaki siparişlerde havale eşleşmesi belirsiz olduğu için otomatik onay yapılmadı:', 'bank-transfer-auto-approval-for-garanti-bbva' ) . "\n\n" . implode( "\n", $lines ) . "\n\n" . admin_url( 'admin.php?page=eru-gha&tab=matches' ) . "\n";
		wp_mail( $to, $subject, Eru_GHA_Log::mask( $body ) );
	}
}
