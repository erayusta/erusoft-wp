<?php
/**
 * Background jobs started from the admin screens (connection test, "yeniden çek", log purge). The screens only
 * enqueue them (Eru_MPO_Admin_Actions); Action Scheduler runs them, so no marketplace request is ever made inside
 * a page request.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks (group eru-mpo):
 * - eru_mpo_test_connection( $mp ): one read request through the adapter's test_connection(); the result goes to
 *   option eru_mpo_sync_conn_<mp> (ok, at, message; redacted, autoload off).
 * - eru_mpo_refetch( $order_id ): clears the package's retry backoff; when the adapter can read one order
 *   (fetch_order(), Trendyol: by order number) only that order's packages are read and applied as updates;
 *   otherwise the lifecycle and order pulls of the whole marketplace run (the importer updates the order; it never
 *   creates a second one).
 * - eru_mpo_purge_log( $scope, $mp ): "expired" runs the retention purge; "all" deletes the rows (of one
 *   marketplace, or all), 1.000 per query, 20 queries per run, then re-arms itself until none are left.
 */
class Eru_MPO_Jobs {

	const TEST    = 'eru_mpo_test_connection';
	const REFETCH = 'eru_mpo_refetch';
	const PURGE   = 'eru_mpo_purge_log';

	/** Hooks. */
	public static function init() {
		add_action( self::TEST, array( __CLASS__, 'test_connection' ) );
		add_action( self::REFETCH, array( __CLASS__, 'refetch' ) );
		add_action( self::PURGE, array( __CLASS__, 'purge_log' ), 10, 2 );
	}

	/**
	 * Schedules a job once (pending-only check on the exact args).
	 *
	 * @param string $hook  Hook.
	 * @param array  $args  Args.
	 * @param int    $delay Seconds.
	 * @return bool Scheduled or already pending.
	 */
	public static function enqueue( $hook, array $args, $delay = 0 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		if ( ! Eru_MPO_Stock_Bridge::pending( $hook, $args ) ) {
			as_schedule_single_action( time() + max( 0, (int) $delay ), $hook, $args, Eru_MPO_Cron::GROUP );
		}
		return true;
	}

	/** @param string $mp Marketplace. @return string Result option. */
	public static function conn_option( $mp ) {
		return 'eru_mpo_sync_conn_' . sanitize_key( $mp );
	}

	/** Stores an option with autoload off. */
	private static function store( $name, $value ) {
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', false );
		} else {
			update_option( $name, $value, false );
		}
	}

	/** @param string $mp Marketplace. */
	public static function test_connection( $mp ) {
		$mp = sanitize_key( (string) $mp );
		$a  = Eru_MPO_Registry::get( $mp );
		if ( ! $a || ! method_exists( $a, 'test_connection' ) ) {
			return;
		}
		try {
			$r = $a->test_connection();
		} catch ( Throwable $e ) {
			$r = new WP_Error( 'eru_mpo_test_exception', $e->getMessage() );
		}
		$ok = true === $r;
		self::store(
			self::conn_option( $mp ),
			array(
				'status'  => 'done',
				'ok'      => $ok,
				'at'      => gmdate( 'Y-m-d H:i:s' ),
				'message' => $ok ? '' : mb_substr( Eru_MPO_Logger::redact( is_wp_error( $r ) ? $r->get_error_message() : '' ), 0, 300 ),
			)
		);
		/* translators: 1: marketplace, 2: result */
		Eru_MPO_Logger::log( $ok ? 'info' : 'warning', sprintf( __( '%1$s bağlantı testi: %2$s', 'marketplace-orders-for-woocommerce' ), $a->label(), $ok ? __( 'başarılı', 'marketplace-orders-for-woocommerce' ) : ( is_wp_error( $r ) ? $r->get_error_message() : __( 'başarısız', 'marketplace-orders-for-woocommerce' ) ) ), $mp );
	}

	/** @param int $order_id Order. */
	public static function refetch( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || ! Eru_MPO_Stock::is_marketplace_order( $order ) ) {
			return;
		}
		$mp  = (string) $order->get_meta( '_eru_mpo_marketplace' );
		$ref = (string) $order->get_meta( '_eru_mpo_remote_ref' );
		Eru_MPO_Map::clear_error( $mp, $ref );
		$a = Eru_MPO_Registry::get( $mp );
		if ( $a && method_exists( $a, 'fetch_order' ) ) {
			if ( ! Eru_MPO_Settings::enabled() || ! $a->is_configured() ) {
				Eru_MPO_Logger::warning( __( 'Yeniden çekilemedi: ana anahtar kapalı ya da API bilgileri eksik.', 'marketplace-orders-for-woocommerce' ), $mp, $ref );
				return;
			}
			try {
				$list = $a->fetch_order( $order );
			} catch ( Throwable $e ) {
				$list = new WP_Error( 'eru_mpo_refetch_exception', $e->getMessage() );
			}
			if ( is_wp_error( $list ) ) {
				/* translators: %s: error */
				Eru_MPO_Logger::error( sprintf( __( 'Sipariş yeniden çekilemedi: %s', 'marketplace-orders-for-woocommerce' ), $list->get_error_message() ), $mp, $ref );
				return;
			}
			$results = array();
			foreach ( (array) $list as $dto ) {
				if ( $dto instanceof Eru_MPO_Order_DTO ) {
					$results[] = $dto->remote_ref . ': ' . Eru_MPO_Importer::import( $a, $dto, array( 'create' => false ) )['result'];
				}
			}
			/* translators: %s: package results */
			Eru_MPO_Logger::info( sprintf( __( 'Sipariş ekranından yeniden çekildi (%s).', 'marketplace-orders-for-woocommerce' ), $results ? implode( ', ', $results ) : '-' ), $mp, $ref );
			return;
		}
		$l = Eru_MPO_Importer::pull( $mp, array( 'lifecycle' => true ) );
		$o = Eru_MPO_Importer::pull( $mp );
		/* translators: 1: lifecycle result, 2: orders result */
		Eru_MPO_Logger::info( sprintf( __( 'Sipariş ekranından pazaryerinin tamamı çekildi (yaşam döngüsü: %1$s, siparişler: %2$s).', 'marketplace-orders-for-woocommerce' ), $l['result'], $o['result'] ), $mp, $ref );
	}

	/**
	 * @param string $scope expired | all.
	 * @param string $mp    Marketplace ('' = every row).
	 */
	public static function purge_log( $scope, $mp = '' ) {
		global $wpdb;
		if ( 'expired' === $scope ) {
			Eru_MPO_Logger::purge();
			if ( Eru_MPO_Logger::$more ) {
				self::enqueue( self::PURGE, array( 'expired', '' ), MINUTE_IN_SECONDS );
			}
			return;
		}
		$t  = Eru_MPO_Installer::table( 'log' );
		$mp = sanitize_key( (string) $mp );
		for ( $i = 0; $i < 20; $i++ ) {
			$n = '' === $mp
				? (int) $wpdb->query( "DELETE FROM {$t} ORDER BY id ASC LIMIT 1000" ) // phpcs:ignore
				: (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE marketplace = %s ORDER BY id ASC LIMIT 1000", $mp ) ); // phpcs:ignore
			if ( $n < 1000 ) {
				return;
			}
		}
		self::enqueue( self::PURGE, array( 'all', $mp ), MINUTE_IN_SECONDS );
	}
}
