<?php
/**
 * WP-CLI: wp eru-yk dispatch <order> | track [--order=<id>] | cancel <order>
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the same code as the scheduled jobs, in the foreground.
 */
class Eru_YK_CLI {

	/** Fails unless the integration is enabled. */
	private function require_enabled() {
		if ( ! Eru_YK_Settings::is_enabled() ) {
			WP_CLI::error( 'Entegrasyon kapalı ya da kullanılabilir hesap yok (WooCommerce → Ayarlar → Yurtiçi Kargo).' );
		}
	}

	/**
	 * Creates the Yurtiçi shipment of an order now (manual run: also for "needs_review" orders).
	 *
	 * ## OPTIONS
	 *
	 * <order>
	 * : Order id.
	 *
	 * @subcommand dispatch
	 * @param array $args Args.
	 */
	public function dispatch( $args ) {
		$this->require_enabled();
		$id = absint( $args[0] ?? 0 );
		$r  = Eru_YK_Dispatcher::dispatch( $id, true );
		$o  = wc_get_order( $id );
		$msg = $r . ( $o ? ' — ' . $o->get_meta( Eru_YK_Dispatcher::M_KEY ) . ' ' . $o->get_meta( Eru_YK_Dispatcher::M_ERROR ) : '' );
		in_array( $r, array( 'created', 'recovered', 'exists', 'retry' ), true ) ? WP_CLI::success( trim( $msg ) ) : WP_CLI::error( trim( $msg ) );
	}

	/**
	 * Runs one tracking pass, or tracks a single order regardless of its next poll time.
	 *
	 * ## OPTIONS
	 *
	 * [--order=<id>]
	 * : Order id.
	 *
	 * @subcommand track
	 * @param array $args  Args.
	 * @param array $assoc Options.
	 */
	public function track( $args, $assoc ) {
		$this->require_enabled();
		if ( ! empty( $assoc['order'] ) ) {
			$o = wc_get_order( absint( $assoc['order'] ) );
			if ( ! $o ) {
				WP_CLI::error( 'Sipariş bulunamadı.' );
			}
			WP_CLI::success( Eru_YK_Tracker::track( $o ) . ' — ' . $o->get_meta( Eru_YK_Dispatcher::M_LASTEVENT ) );
			return;
		}
		WP_CLI::success( wp_json_encode( Eru_YK_Tracker::run() ) );
	}

	/**
	 * Cancels the order's shipment (cancelShipment); the next dispatch uses a new key.
	 *
	 * ## OPTIONS
	 *
	 * <order>
	 * : Order id.
	 *
	 * @subcommand cancel
	 * @param array $args Args.
	 */
	public function cancel( $args ) {
		$this->require_enabled();
		$r = Eru_YK_Dispatcher::cancel( absint( $args[0] ?? 0 ) );
		$r['ok'] ? WP_CLI::success( $r['message'] ) : WP_CLI::error( $r['message'] );
	}
}
