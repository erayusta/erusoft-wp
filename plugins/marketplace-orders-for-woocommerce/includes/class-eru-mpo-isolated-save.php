<?php
/**
 * Isolated save: saves a marketplace order while the shop's order-creation and order-status listeners are
 * muted, so a marketplace order does not trigger the shop's e-mails, payment, shipping, fraud, ERP or stock
 * automations that are meant for web orders.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How it works. For the duration of one WC_Order::save() call, each muted hook's WP_Hook object in $wp_filter is
 * replaced by a new WP_Hook that holds only the allow-listed callbacks; the original objects are put back in a
 * finally block, so an exception anywhere inside the save restores every hook. Callbacks that someone adds to a
 * muted hook during the save are carried over to the original object. Nested saves (a listener saving another
 * order) run without a second swap.
 *
 * Muted hooks: woocommerce_new_order, woocommerce_order_edit_status and every woocommerce_order_status_* hook
 * registered at that moment (status-specific, from_to_ and _changed). Filter eru_mpo_isolated_save_hooks adds
 * or removes hooks, except woocommerce_after_order_object_save, which is never muted (the completeness watch below
 * listens there). woocommerce_update_order is not muted by default either; add it through the filter if needed (the
 * HPOS synchroniser and the cache-version invalidator on it are allow-listed).
 *
 * Allow-list (WooCommerce's own bookkeeping keeps running): the order-count cache (OrderCountCacheService) so the
 * order list's status counts stay right, the HPOS/posts data synchroniser (DataSynchronizer) so a store in
 * compatibility mode gets the copy of the new order at once, the orders cache-version invalidator
 * (OrdersVersionStringInvalidator) and the product sales counter (wc_update_total_sales_counts). Filter
 * eru_mpo_isolated_save_keep( bool $keep, callable $callback, string $hook ) keeps any other callback.
 *
 * Completeness check: WC_Order::save() catches an Exception thrown inside it, logs it and returns the id, even when
 * the order row was inserted but its lines were not saved. save() therefore watches woocommerce_after_order_object_save
 * (fired after the lines are saved; never muted) for this very object, and after the save checks that it fired
 * and that every line, shipping, tax, fee and coupon item has an id. Otherwise it throws a RuntimeException, so
 * the caller can remove a half-saved order (importer) or report the save as failed (status moves). When the save
 * did complete and a listener threw afterwards (e.g. in a status hook while muting is off), Eru_MPO_Listener_Error
 * is thrown instead: the order is complete and is kept.
 *
 * Switch: setting "Bildirimleri sustur" (mute_notifications, default on). When off, orders are saved normally.
 */
/**
 * Thrown by Eru_MPO_Isolated_Save::save() when the save completed (marker fired, every item has an id) but a
 * listener threw afterwards. The order is complete and must be kept; getPrevious() holds the listener's error.
 */
class Eru_MPO_Listener_Error extends RuntimeException {}

class Eru_MPO_Isolated_Save {

	/** WooCommerce functions that keep running: product sales counters follow marketplace orders too. */
	const KEEP_FUNCTIONS = array( 'wc_update_total_sales_counts' );

	/** @var int Nesting depth (a swap is active when > 0). */
	private static $depth = 0;

	/** @var int Saves of this class in progress, muted or not. */
	private static $saving = 0;

	/** The completeness watch listens here: never muted, whatever eru_mpo_isolated_save_hooks returns. */
	const NEVER_MUTED = array( 'woocommerce_after_order_object_save' );

	/** @return string[] Hooks to mute now. */
	public static function hooks() {
		global $wp_filter;
		$hooks = array( 'woocommerce_new_order', 'woocommerce_order_edit_status' );
		foreach ( array_keys( (array) $wp_filter ) as $name ) {
			if ( 0 === strpos( (string) $name, 'woocommerce_order_status_' ) ) {
				$hooks[] = (string) $name;
			}
		}
		$hooks = apply_filters( 'eru_mpo_isolated_save_hooks', $hooks );
		return array_values( array_diff( array_unique( array_filter( (array) $hooks, 'is_string' ) ), self::NEVER_MUTED ) );
	}

	/**
	 * @param callable $callback Callback.
	 * @param string   $hook     Hook.
	 * @return bool Keep it running during the save.
	 */
	public static function keep( $callback, $hook ) {
		$class = '';
		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		}
		$keep = ( '' !== $class && (bool) preg_match( '/OrderCountCacheService|Orders\\\\DataSynchronizer|OrdersVersionStringInvalidator/', $class ) )
			|| ( is_string( $callback ) && in_array( $callback, self::KEEP_FUNCTIONS, true ) );
		return (bool) apply_filters( 'eru_mpo_isolated_save_keep', $keep, $callback, $hook );
	}

	/**
	 * @return bool This plugin is saving an order (muted or not): status changes now are the plugin's own moves, not
	 *              the shop's (see Eru_MPO_Statuses::on_status_changed()).
	 */
	public static function active() {
		return self::$depth > 0 || self::$saving > 0;
	}

	/**
	 * Saves the order with the listeners muted (or normally when muting is switched off) and checks that the save
	 * completed. Exceptions from the save propagate after the hooks are restored.
	 *
	 * @param WC_Order  $order Order.
	 * @param bool|null $mute  Force muting on/off; null = setting.
	 * @return int Order id.
	 * @throws RuntimeException When the save did not complete (see class doc).
	 */
	public static function save( $order, $mute = null ) {
		$done  = false;
		$watch = function ( $saved ) use ( $order, &$done ) {
			if ( $saved === $order ) {
				$done = true;
			}
		};
		add_action( 'woocommerce_after_order_object_save', $watch, PHP_INT_MIN );
		$caught = null;
		$id     = 0;
		++self::$saving;
		try {
			$id = self::run( $order, $mute );
		} catch ( Throwable $e ) {
			$caught = $e;
			$id     = (int) $order->get_id();
		} finally {
			--self::$saving;
			remove_action( 'woocommerce_after_order_object_save', $watch, PHP_INT_MIN );
		}
		$complete = $done && $id > 0;
		foreach ( $order->get_items( array( 'line_item', 'shipping', 'tax', 'fee', 'coupon' ) ) as $item ) {
			if ( ! $item->get_id() ) {
				$complete = false;
			}
		}
		if ( $caught ) {
			if ( $complete ) {
				throw new Eru_MPO_Listener_Error( $caught->getMessage(), 0, $caught );
			}
			throw $caught;
		}
		if ( ! $complete ) {
			throw new RuntimeException( __( 'Sipariş eksik kaydedildi (kayıt sırasında bir hata yakalandı).', 'marketplace-orders-for-woocommerce' ) );
		}
		return $id;
	}

	/**
	 * The save itself, with the hook swap.
	 *
	 * @param WC_Order  $order Order.
	 * @param bool|null $mute  Force muting on/off; null = setting.
	 * @return int
	 */
	private static function run( $order, $mute ) {
		$mute = null === $mute ? Eru_MPO_Settings::mute() : (bool) $mute;
		if ( ! $mute || self::$depth > 0 ) {
			return (int) $order->save();
		}
		global $wp_filter;
		$saved = array();
		++self::$depth;
		try {
			foreach ( self::hooks() as $hook ) {
				if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
					continue;
				}
				$orig = $wp_filter[ $hook ];
				$temp = new WP_Hook();
				foreach ( $orig->callbacks as $prio => $cbs ) {
					foreach ( $cbs as $cb ) {
						if ( self::keep( $cb['function'], $hook ) ) {
							$temp->add_filter( $hook, $cb['function'], $prio, $cb['accepted_args'] );
						}
					}
				}
				$saved[ $hook ]     = $orig;
				$wp_filter[ $hook ] = $temp; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			return (int) $order->save();
		} finally {
			// Put every original WP_Hook back. A callback added to a muted hook during the save (it is not in the
			// original object; kept callbacks are, under the same id) is carried over so it is not lost.
			foreach ( $saved as $hook => $orig ) {
				$now = $wp_filter[ $hook ] ?? null;
				if ( $now instanceof WP_Hook && $now !== $orig ) {
					foreach ( $now->callbacks as $prio => $cbs ) {
						foreach ( $cbs as $idx => $cb ) {
							if ( ! isset( $orig->callbacks[ $prio ][ $idx ] ) ) {
								$orig->add_filter( $hook, $cb['function'], $prio, $cb['accepted_args'] );
							}
						}
					}
				}
				$wp_filter[ $hook ] = $orig; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			--self::$depth;
		}
	}
}
