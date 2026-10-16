<?php
/**
 * Adapter contract: one class per marketplace turns its API into normalised DTOs. Register an adapter through
 * the eru_mpo_adapters filter (key => instance).
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A marketplace adapter. It never writes WooCommerce data itself: the importer, lifecycle and stock bridge do,
 * from what the adapter returns. Adapters make their HTTP calls with wp_remote_* only.
 */
interface Eru_MPO_Adapter {

	/** @return string Key, [a-z0-9_]{2,20} (used in created_via "eru-mpo-<key>", meta and option names). */
	public function key();

	/** @return string Human label ("Trendyol"). */
	public function label();

	/** @return bool The master switch and this marketplace's own switch are on and it is configured. */
	public function is_enabled();

	/** @return bool Credentials present (no HTTP is made when false). */
	public function is_configured();

	/** @return bool Fetch and log only, create nothing. */
	public function is_observe_mode();

	/**
	 * New and recently changed orders.
	 *
	 * @param int $since UTC timestamp (scan window start).
	 * @param int $until UTC timestamp.
	 * @return Eru_MPO_Order_DTO[]|WP_Error
	 */
	public function fetch_orders( $since, $until );

	/**
	 * Orders whose lifecycle changed (cargo, delivery, cancel, return) in the lifecycle window. The importer only
	 * updates orders it already knows from these; it never creates one.
	 *
	 * @param int $since UTC timestamp.
	 * @param int $until UTC timestamp.
	 * @return Eru_MPO_Order_DTO[]|WP_Error
	 */
	public function fetch_lifecycle( $since, $until );

	/**
	 * Tells the marketplace the shop accepted the package.
	 *
	 * @param WC_Order $order Imported order.
	 * @return true|WP_Error
	 */
	public function push_acknowledge( $order );

	/**
	 * One page of the shop's listings on the marketplace.
	 *
	 * @param int $page Page, 0-based.
	 * @return array{items: Eru_MPO_Listing_DTO[], more: bool}|WP_Error
	 */
	public function fetch_listings( $page );

	/**
	 * @param array $items List of {sku, barcode, quantity}.
	 * @return array{ok: int, failed: array<string,string>}|WP_Error failed: sku => reason.
	 */
	public function push_inventory( array $items );

	/**
	 * @param array $items List of {sku, barcode, price, list_price}.
	 * @return array{ok: int, failed: array<string,string>}|WP_Error failed: sku => reason.
	 */
	public function push_price( array $items );
}

/**
 * Defaults shared by adapters: switches come from the settings, unsupported operations return an error.
 * Subclasses implement key(), label(), is_configured(), fetch_orders() and fetch_lifecycle() at least.
 */
abstract class Eru_MPO_Adapter_Base implements Eru_MPO_Adapter {

	/** @return bool Master switch + marketplace switch (eru_mpo_mp_<key>[enabled]) + configured. */
	public function is_enabled() {
		return Eru_MPO_Settings::enabled() && 'yes' === Eru_MPO_Settings::mp_get( $this->key(), 'enabled', 'no' ) && $this->is_configured();
	}

	/** @return bool Global observe mode, or this marketplace's own observe switch. */
	public function is_observe_mode() {
		return Eru_MPO_Settings::observe() || 'yes' === Eru_MPO_Settings::mp_get( $this->key(), 'observe', 'no' );
	}

	/** @return WP_Error */
	protected function unsupported() {
		return new WP_Error( 'eru_mpo_unsupported', __( 'Bu pazaryeri bu işlemi desteklemiyor.', 'marketplace-orders-for-woocommerce' ) );
	}

	/** @param WC_Order $order Order. @return true|WP_Error */
	public function push_acknowledge( $order ) {
		return $this->unsupported();
	}

	/** @param int $page Page. @return array|WP_Error */
	public function fetch_listings( $page ) {
		return $this->unsupported();
	}

	/** @param array $items Items. @return array|WP_Error */
	public function push_inventory( array $items ) {
		return $this->unsupported();
	}

	/** @param array $items Items. @return array|WP_Error */
	public function push_price( array $items ) {
		return $this->unsupported();
	}
}
