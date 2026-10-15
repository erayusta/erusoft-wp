<?php
/**
 * Scheduled automations: due selection with the done flag in the query, batches that re-arm while work remains, locks.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One recurring Action Scheduler job per automation every 15 minutes. A run handles `batch_size` rows and, when more
 * are due, queues another run at once, so a backlog is drained completely and nothing is left outside a "newest N" window.
 */
class Eru_GRA_Runner {

	/** Hooks. */
	public static function init() {
		add_action( 'eru_gra_run', array( __CLASS__, 'run' ) );
		add_action( 'eru_gra_run_more', array( __CLASS__, 'run' ) );
		add_action( 'eru_gra_daily', array( __CLASS__, 'daily' ) );
		add_action( 'eru_gra_purge_more', array( 'Eru_GRA_Privacy', 'purge' ) );
		add_action( 'eru_gra_cleanup_more', array( 'Eru_GRA_Coupons', 'cleanup' ) );
	}

	/** Daily housekeeping: retention, expired coupons, stale carts, log size. */
	public static function daily() {
		Eru_GRA_Privacy::purge();
		Eru_GRA_Coupons::cleanup();
		Eru_GRA_Cart_Maintenance::expire_stale();
		Eru_GRA_DB::trim_log();
	}

	/**
	 * Pending-only check. `as_has_scheduled_action()` also counts the action that is running right now, which would stop a
	 * job from re-arming itself.
	 *
	 * @param string $hook Hook. @param array $args Args. @return bool
	 */
	public static function has_pending( $hook, array $args ) {
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => 'eru-gra',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/** @param string $hook Hook. @param array $args Args. Queue one run now unless one is already waiting. */
	public static function enqueue_unique( $hook, array $args ) {
		if ( function_exists( 'as_schedule_single_action' ) && ! self::has_pending( $hook, $args ) ) {
			as_schedule_single_action( time(), $hook, $args, 'eru-gra' );
		}
	}

	/** @param string $key Automation. Queue the follow-up run. */
	public static function rearm( $key ) {
		self::enqueue_unique( 'eru_gra_run_more', array( $key ) );
	}

	/** @param string $hook Hook. @param array $args Args. Queue a follow-up of a draining job. */
	public static function rearm_hook( $hook, array $args ) {
		self::enqueue_unique( $hook, $args );
	}

	/** @var array<string,string> Owner tokens of the run locks this process holds. */
	private static $tokens = array();

	/** @param string $key Automation. @return bool Lock taken (atomic, see Eru_GRA_Lock). */
	private static function lock( $key ) {
		$token = Eru_GRA_Lock::acquire( $key, 10 * MINUTE_IN_SECONDS );
		if ( false === $token ) {
			return false;
		}
		self::$tokens[ $key ] = $token;
		return true;
	}

	/** @param string $key Automation. */
	private static function unlock( $key ) {
		Eru_GRA_Lock::release( $key, self::$tokens[ $key ] ?? null );
		unset( self::$tokens[ $key ] );
	}

	/**
	 * Run one batch of an automation.
	 *
	 * @param string $key Automation. @param bool $dry Report only, change nothing.
	 * @return array{processed:int,sent:int,more:bool,items:array}
	 */
	public static function run( $key, $dry = false ) {
		$out = array( 'processed' => 0, 'sent' => 0, 'more' => false, 'items' => array() );
		if ( ! in_array( $key, Eru_GRA_Settings::RUNNABLE, true ) || ( ! $dry && ! Eru_GRA_Settings::is_on( $key ) ) || ! self::lock( $key ) ) {
			return $out;
		}
		$size = max( 1, (int) Eru_GRA_Settings::get( $key, 'batch_size' ) );
		try {
			$ids = self::due_ids( $key, $size );
			foreach ( $ids as $id ) {
				$r = self::process( $key, (int) $id, (bool) $dry );
				++$out['processed'];
				if ( 'sent' === $r['result'] ) {
					++$out['sent'];
				}
				if ( $dry ) {
					$out['items'][] = array( 'email' => Eru_GRA_DB::mask( $r['email'] ), 'result' => $r['result'] );
				} elseif ( ! in_array( $r['result'], array( 'skip', 'newer', 'recent' ), true ) ) {
					Eru_GRA_DB::log( $key, $r['email'], $r['result'] );
				}
			}
		} finally {
			self::unlock( $key );
		}
		if ( ! $dry ) {
			Eru_GRA_DB::trim_log();
			if ( $out['processed'] > 0 && $out['processed'] >= $size && self::due_ids( $key, 1 ) ) {
				$out['more'] = true;
				self::rearm( $key );
			}
		}
		return $out;
	}

	/** @param string $key Automation. @param int $limit Limit. @return int[] Due IDs (orders, or cart rows). */
	public static function due_ids( $key, $limit ) {
		$now = time();
		$cfg = Eru_GRA_Settings::all()[ $key ];
		$max = (int) $cfg['max_age_days'] * DAY_IN_SECONDS;
		switch ( $key ) {
			case 'bacs':
				$ids = wc_get_orders(
					array(
						'status'         => 'on-hold',
						'payment_method' => 'bacs',
						'limit'          => $limit,
						'orderby'        => 'date',
						'order'          => 'ASC',
						'return'         => 'ids',
						'date_created'   => ( $now - $max ) . '...' . ( $now - (int) $cfg['delay'] * HOUR_IN_SECONDS ),
						'meta_query'     => array( array( 'key' => '_eru_gra_bacs_reminded', 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
				return array_map( 'intval', $ids );
			case 'review':
			case 'winback':
				$flag = 'review' === $key ? '_eru_gra_review_requested' : '_eru_gra_winback_at';
				$ids  = wc_get_orders(
					array(
						'status'         => 'completed',
						'limit'          => $limit,
						'orderby'        => 'date',
						'order'          => 'ASC',
						'return'         => 'ids',
						'date_completed' => ( $now - $max - (int) $cfg['delay'] * DAY_IN_SECONDS ) . '...' . ( $now - (int) $cfg['delay'] * DAY_IN_SECONDS ),
						'meta_query'     => array( array( 'key' => $flag, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
				return array_map( 'intval', $ids );
			case 'cart':
				global $wpdb;
				$t = Eru_GRA_DB::carts();
				return array_map(
					'intval',
					$wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'open' AND updated_at <= %s AND updated_at >= %s ORDER BY id ASC LIMIT %d", gmdate( 'Y-m-d H:i:s', $now - (int) $cfg['delay'] * HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now - $max ), $limit ) ) // phpcs:ignore WordPress.DB.PreparedSQL
				);
		}
		return array();
	}

	/**
	 * @param string $key Automation. @param int $id Order or row ID. @param bool $dry Dry run.
	 * @return array{result:string,email:string}
	 */
	private static function process( $key, $id, $dry ) {
		switch ( $key ) {
			case 'bacs':
				return self::process_bacs( $id, $dry );
			case 'review':
				return self::process_review( $id, $dry );
			case 'winback':
				return self::process_winback( $id, $dry );
			case 'cart':
				return self::process_cart( $id, $dry );
		}
		return array( 'result' => 'skip', 'email' => '' );
	}

	/** @param WC_Order $order Order. @return string Greeting name. */
	private static function name( WC_Order $order ) {
		$n = trim( (string) $order->get_billing_first_name() );
		return '' !== $n ? $n : __( 'değerli müşterimiz', 'growth-automations-for-woocommerce' );
	}

	/**
	 * Resolve the coupon a message promises, creating it first. Returns an error code when it cannot be provided.
	 *
	 * @param array $cfg Automation settings. @param string $email Recipient.
	 * @return array{code:string,expiry:string}|string
	 */
	private static function coupon( array $cfg, $email ) {
		$fmt = get_option( 'date_format' );
		if ( 'generate' === $cfg['coupon_mode'] ) {
			$c = Eru_GRA_Coupons::create(
				array(
					'prefix'   => $cfg['coupon_prefix'],
					'type'     => $cfg['coupon_type'],
					'amount'   => $cfg['coupon_amount'],
					'min_cart' => $cfg['coupon_min'],
					'expires'  => time() + (int) $cfg['coupon_days'] * DAY_IN_SECONDS,
					'email'    => $email,
				)
			);
			return $c ? array( 'code' => $c['code'], 'expiry' => wp_date( $fmt, $c['expires'] ) ) : 'coupon-failed';
		}
		if ( 'existing' === $cfg['coupon_mode'] && '' !== $cfg['coupon_code'] ) {
			$id = wc_get_coupon_id_by_code( $cfg['coupon_code'] );
			if ( ! $id ) {
				return 'coupon-missing';
			}
			$c   = new WC_Coupon( $id );
			$exp = $c->get_date_expires() ? $c->get_date_expires()->getTimestamp() : 0;
			if ( $exp && $exp < time() ) {
				return 'coupon-expired';
			}
			return array( 'code' => strtoupper( $c->get_code() ), 'expiry' => $exp ? wp_date( $fmt, $exp ) : '' );
		}
		return 'no-coupon';
	}

	/**
	 * Build and send one message, creating its coupon first when the text promises one.
	 *
	 * @param string $key Automation. @param string $email Recipient. @param array $cfg Settings. @param array $vars Placeholder values. @param string $extra Extra HTML.
	 * @return string Result code.
	 */
	private static function deliver( $key, $email, array $cfg, array $vars, $extra = '' ) {
		$marketing = in_array( $key, array( 'cart', 'winback' ), true );
		if ( $marketing && Eru_GRA_Consent::is_suppressed( $email ) ) {
			return 'suppressed';
		}
		if ( Eru_GRA_Mail::mentions_coupon( $cfg['subject'] . ' ' . $cfg['heading'] . ' ' . $cfg['body'] ) ) {
			$c = self::coupon( $cfg, $email );
			if ( is_string( $c ) ) {
				return $c;
			}
			$vars['coupon_code']   = $c['code'];
			$vars['coupon_expiry'] = $c['expiry'];
		}
		return Eru_GRA_Mail::send( $email, $cfg, $vars, array( 'marketing' => $marketing, 'extra_html' => $extra ) ) ? 'sent' : 'failed';
	}

	/** Havale/EFT reminder (transactional). */
	private static function process_bacs( $id, $dry ) {
		$order = wc_get_order( $id );
		if ( ! $order || ! $order->has_status( 'on-hold' ) || 'bacs' !== $order->get_payment_method() || '' !== (string) $order->get_meta( '_eru_gra_bacs_reminded' ) ) {
			return array( 'result' => 'skip', 'email' => '' );
		}
		$email = Eru_GRA_Consent::norm( $order->get_billing_email() );
		if ( $dry ) {
			return array( 'result' => 'would-send', 'email' => $email );
		}
		// Flag first: a failed mail is logged, not retried in a loop, and a re-run cannot send a second reminder.
		$order->update_meta_data( '_eru_gra_bacs_reminded', time() );
		$order->save_meta_data();
		if ( '' === $email ) {
			return array( 'result' => 'no-email', 'email' => '' );
		}
		$extra = '';
		$gws   = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( isset( $gws['bacs'] ) && method_exists( $gws['bacs'], 'email_instructions' ) ) {
			ob_start();
			$gws['bacs']->email_instructions( $order, false, false );
			$extra = (string) ob_get_clean();
		}
		$cfg = Eru_GRA_Settings::all()['bacs'];
		$res = self::deliver( 'bacs', $email, $cfg, array( 'customer_name' => self::name( $order ), 'order_number' => $order->get_order_number() ), $extra );
		return array( 'result' => $res, 'email' => $email );
	}

	/** Review request (respects the suppression list). */
	private static function process_review( $id, $dry ) {
		$order = wc_get_order( $id );
		if ( ! $order || ! $order->has_status( 'completed' ) || '' !== (string) $order->get_meta( '_eru_gra_review_requested' ) ) {
			return array( 'result' => 'skip', 'email' => '' );
		}
		$email = Eru_GRA_Consent::norm( $order->get_billing_email() );
		if ( $dry ) {
			return array( 'result' => 'would-send', 'email' => $email );
		}
		$order->update_meta_data( '_eru_gra_review_requested', time() );
		$order->save_meta_data();
		if ( '' === $email ) {
			return array( 'result' => 'no-email', 'email' => '' );
		}
		if ( ! wc_reviews_enabled() ) {
			return array( 'result' => 'skip', 'email' => $email );
		}
		if ( Eru_GRA_Consent::is_suppressed( $email ) ) {
			return array( 'result' => 'suppressed', 'email' => $email );
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$p = $item->get_product();
			$p = $p && $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
			if ( $p && 'publish' === $p->get_status() && $p->get_reviews_allowed() ) {
				$items[ $p->get_id() ] = '<li><a href="' . esc_url( get_permalink( $p->get_id() ) . '#reviews' ) . '">' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</a></li>';
			}
		}
		if ( ! $items ) {
			return array( 'result' => 'skip', 'email' => $email );
		}
		$cfg = Eru_GRA_Settings::all()['review'];
		$res = self::deliver( 'review', $email, $cfg, array( 'customer_name' => self::name( $order ), 'order_number' => $order->get_order_number() ), '<ul>' . implode( '', $items ) . '</ul>' );
		return array( 'result' => $res, 'email' => $email );
	}

	/** Win-back (marketing: consent, generated coupon, one mail per customer per period). */
	private static function process_winback( $id, $dry ) {
		$order = wc_get_order( $id );
		if ( ! $order || ! $order->has_status( 'completed' ) || '' !== (string) $order->get_meta( '_eru_gra_winback_at' ) ) {
			return array( 'result' => 'skip', 'email' => '' );
		}
		$cfg   = Eru_GRA_Settings::all()['winback'];
		$email = Eru_GRA_Consent::norm( $order->get_billing_email() );
		$flag  = function ( $v ) use ( $order, $dry ) {
			if ( ! $dry ) {
				$order->update_meta_data( '_eru_gra_winback_at', (int) $v );
				$order->save_meta_data();
			}
		};
		if ( '' === $email ) {
			$flag( 0 );
			return array( 'result' => 'skip', 'email' => '' );
		}
		// Only the customer's latest order counts: an older one is skipped when a newer order exists.
		$latest = wc_get_orders( array( 'billing_email' => $email, 'status' => array( 'completed', 'processing', 'on-hold' ), 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ) );
		if ( $latest && (int) $latest[0] !== (int) $id ) {
			$flag( 0 );
			return array( 'result' => 'newer', 'email' => $email );
		}
		$recent = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 1,
				'return'        => 'ids',
				'meta_query'    => array( array( 'key' => '_eru_gra_winback_at', 'value' => time() - (int) $cfg['delay'] * DAY_IN_SECONDS, 'compare' => '>', 'type' => 'NUMERIC' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $recent ) {
			$flag( 0 );
			return array( 'result' => 'recent', 'email' => $email );
		}
		if ( ! Eru_GRA_Consent::has( $email, (int) $order->get_customer_id() ) ) {
			$flag( 0 );
			return array( 'result' => Eru_GRA_Consent::is_suppressed( $email ) ? 'suppressed' : 'no-consent', 'email' => $email );
		}
		if ( $dry ) {
			return array( 'result' => 'would-send', 'email' => $email );
		}
		$res = self::deliver( 'winback', $email, $cfg, array( 'customer_name' => self::name( $order ), 'order_number' => $order->get_order_number() ) );
		$flag( 'sent' === $res ? time() : 0 );
		return array( 'result' => $res, 'email' => $email );
	}

	/** Abandoned-cart e-mail (marketing: consent re-checked at send time). */
	private static function process_cart( $id, $dry ) {
		global $wpdb;
		$t   = Eru_GRA_DB::carts();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row || 'open' !== $row['status'] ) {
			return array( 'result' => 'skip', 'email' => '' );
		}
		$email  = $row['email'];
		$expire = function () use ( $wpdb, $t, $id, $dry ) {
			if ( ! $dry ) {
				$wpdb->update( $t, array( 'status' => 'expired', 'token' => '' ), array( 'id' => $id ) );
			}
		};
		if ( ! Eru_GRA_Consent::row_consent( $row ) ) {
			$expire();
			return array( 'result' => Eru_GRA_Consent::is_suppressed( $email ) ? 'suppressed' : 'no-consent', 'email' => $email );
		}
		$names = array();
		foreach ( (array) json_decode( $row['cart'], true ) as $line ) {
			$p = wc_get_product( (int) ( $line['variation_id'] ?? 0 ) ? (int) $line['variation_id'] : (int) ( $line['product_id'] ?? 0 ) );
			if ( $p && $p->is_purchasable() && $p->is_in_stock() ) {
				$names[] = '<li>' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . ' &times; ' . (int) ( $line['qty'] ?? 1 ) . '</li>';
			}
		}
		if ( ! $names ) {
			$expire();
			return array( 'result' => 'skip', 'email' => $email );
		}
		$cfg  = Eru_GRA_Settings::all()['cart'];
		$cool = (int) $cfg['cooldown'];
		if ( $cool > 0 && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE email = %s AND id <> %d AND status IN ('sent','recovered') AND sent_at > %s LIMIT 1", $email, $id, gmdate( 'Y-m-d H:i:s', time() - $cool * HOUR_IN_SECONDS ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$expire();
			return array( 'result' => 'recent', 'email' => $email );
		}
		if ( $dry ) {
			return array( 'result' => 'would-send', 'email' => $email );
		}
		$raw  = wp_generate_password( 32, false, false );
		$won  = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'sent', token = %s, sent_at = %s WHERE id = %d AND status = 'open'", Eru_GRA_Cart::token_hash( $raw ), gmdate( 'Y-m-d H:i:s' ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( 1 !== (int) $won ) {
			return array( 'result' => 'skip', 'email' => $email );
		}
		$user = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
		$name = $user && '' !== trim( (string) $user->first_name ) ? $user->first_name : __( 'değerli müşterimiz', 'growth-automations-for-woocommerce' );
		$res  = self::deliver(
			'cart',
			$email,
			$cfg,
			array( 'customer_name' => $name, 'cart_link' => add_query_arg( 'eru_gra_restore', $raw, home_url( '/' ) ) ),
			'<ul>' . implode( '', $names ) . '</ul>'
		);
		if ( 'sent' !== $res ) {
			$wpdb->update( $t, array( 'status' => 'expired', 'token' => '' ), array( 'id' => $id ) );
		}
		return array( 'result' => $res, 'email' => $email );
	}
}

/**
 * Carts that can no longer be mailed are closed (they are removed later by the retention purge).
 */
class Eru_GRA_Cart_Maintenance {

	/** Close open carts older than the maximum age, a bounded number per run. */
	public static function expire_stale() {
		global $wpdb;
		$max = (int) Eru_GRA_Settings::get( 'cart', 'max_age_days' ) * DAY_IN_SECONDS;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Eru_GRA_DB::carts() . " SET status = 'expired' WHERE status = 'open' AND updated_at < %s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - $max ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
