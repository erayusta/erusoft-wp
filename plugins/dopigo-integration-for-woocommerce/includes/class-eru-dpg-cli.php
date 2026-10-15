<?php
/**
 * WP-CLI: wp eru-dpg test|pull|push-stock|import [--pages=]|order <id>
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the same code as the scheduled jobs, in the foreground.
 */
class Eru_DPG_CLI {

	/** Fails unless the integration is enabled. */
	private function require_enabled() {
		if ( ! Eru_DPG_Settings::is_enabled() ) {
			WP_CLI::error( 'Entegrasyon kapalı ya da kimlik bilgisi eksik (WooCommerce → Dopigo → Bağlantı).' );
		}
	}

	/**
	 * Tests the connection (token + one product list request).
	 *
	 * @subcommand test
	 */
	public function test() {
		$r = Eru_DPG_Tools::test_connection();
		$r['ok'] ? WP_CLI::success( $r['message'] ) : WP_CLI::error( $r['message'] );
	}

	/**
	 * Runs one Dopigo → Woo stock/price and order-status pull.
	 *
	 * @subcommand pull
	 */
	public function pull() {
		$this->require_enabled();
		$res = Eru_DPG_Products::instance()->pull_batch( 300, (int) Eru_DPG_Settings::get( 'page_size' ), 1000 );
		WP_CLI::log( 'Stok/fiyat: ' . wp_json_encode( $res ) );
		$st = Eru_DPG_Status::pull();
		WP_CLI::log( 'Sipariş durumu: ' . wp_json_encode( $st ) );
		WP_CLI::success( 'Tamam.' );
	}

	/**
	 * Sends stock/price of every unit with a SKU to Dopigo (Woo → Dopigo directions must be on).
	 *
	 * @subcommand push-stock
	 */
	public function push_stock() {
		$this->require_enabled();
		if ( ! Eru_DPG_Products::pushes() ) {
			WP_CLI::error( 'Woo → Dopigo stok veya fiyat yönü kapalı.' );
		}
		$c      = array( 'updated' => 0, 'failed' => 0, 'noop' => 0 );
		$offset = 0;
		do {
			$batch = wc_get_products( array( 'status' => array( 'publish', 'private', 'draft', 'pending' ), 'type' => array( 'simple', 'variable' ), 'limit' => 50, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC' ) );
			foreach ( $batch as $p ) {
				$units = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : array( $p );
				foreach ( $units as $u ) {
					$r = Eru_DPG_Products::instance()->push_unit( $u );
					if ( is_wp_error( $r ) ) {
						++$c['failed'];
						WP_CLI::warning( $u->get_sku() . ': ' . $r->get_error_message() );
					} else {
						'updated' === $r ? ++$c['updated'] : ++$c['noop'];
					}
				}
			}
			$offset += 50;
		} while ( count( $batch ) === 50 );
		WP_CLI::success( wp_json_encode( $c ) );
	}

	/**
	 * Imports products from Dopigo.
	 *
	 * [--pages=<n>]
	 * : Dopigo pages to process (default 1).
	 *
	 * @subcommand import
	 */
	public function import( $args, $assoc ) {
		$this->require_enabled();
		$pages = max( 1, (int) ( $assoc['pages'] ?? 1 ) );
		for ( $i = 0; $i < $pages; $i++ ) {
			$r = Eru_DPG_Importer::instance()->run_batch( 600, (int) Eru_DPG_Settings::get( 'import_page_size' ), (int) Eru_DPG_Settings::get( 'import_image_budget' ) );
			WP_CLI::log( wp_json_encode( $r ) );
			if ( ! empty( $r['error'] ) || ! empty( $r['pass_done'] ) ) {
				break;
			}
		}
		WP_CLI::success( 'Tamam.' );
	}

	/**
	 * Sends one order to Dopigo (creates it, or updates it when it already exists).
	 *
	 * <id>
	 * : WooCommerce order id.
	 *
	 * @subcommand order
	 */
	public function order( $args ) {
		$this->require_enabled();
		$id    = absint( $args[0] ?? 0 );
		$order = wc_get_order( $id );
		if ( ! $order ) {
			WP_CLI::error( 'Sipariş bulunamadı.' );
		}
		$r = Eru_DPG_Orders::push( $id, '' !== (string) $order->get_meta( Eru_DPG_Orders::M_ID ) );
		in_array( $r, array( 'created', 'updated', 'dry-run' ), true ) ? WP_CLI::success( $r ) : WP_CLI::error( $r . ( Eru_DPG_Orders::$last_error ? ': ' . Eru_DPG_Orders::$last_error->get_error_message() : '' ) );
	}
}
