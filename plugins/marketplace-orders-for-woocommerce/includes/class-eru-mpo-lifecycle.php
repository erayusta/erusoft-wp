<?php
/**
 * Lifecycle events on an imported order: status moves, cargo details, cancelled lines.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each event is applied once per order: its id is remembered in _eru_mpo_events (the latest 200 ids). Status
 * events go through Eru_MPO_Statuses::advance() (forward only). A cancel_lines event restores the cancelled
 * units if this order reduced stock, adds an order note, flags the order for review (the marketplace total
 * changes with it) and fires eru_mpo_order_cancelled_lines( $order, $lines ) so an ERP bridge can react.
 */
class Eru_MPO_Lifecycle {

	const META     = '_eru_mpo_events';
	const KEEP_IDS = 200;

	/**
	 * @param WC_Order            $order  Saved order.
	 * @param Eru_MPO_Event_DTO[] $events Events.
	 * @return int Events applied now.
	 */
	public static function apply( &$order, array $events ) {
		$done = $order->get_meta( self::META );
		$done = is_array( $done ) ? $done : array();
		$n    = 0;
		foreach ( $events as $e ) {
			if ( ! $e instanceof Eru_MPO_Event_DTO || in_array( $e->id, $done, true ) ) {
				continue;
			}
			if ( 'status' === $e->type ) {
				if ( Eru_MPO_Statuses::can_advance( Eru_MPO_Statuses::current( $order ), $e->status ) && ! Eru_MPO_Statuses::advance( $order, $e->status, '' !== $e->note ? sanitize_text_field( $e->note ) : '' ) ) {
					// Stop for this order: its status save failed and $order is a fresh copy now. The event stays
					// unapplied and is retried on the next run (the caller records the failure with a backoff).
					throw new RuntimeException( __( 'Durum olayı uygulanamadı.', 'marketplace-orders-for-woocommerce' ) );
				}
			} elseif ( 'cargo' === $e->type ) {
				foreach ( array( '_eru_mpo_cargo_provider' => $e->cargo_provider, '_eru_mpo_cargo_tracking' => $e->tracking_number, '_eru_mpo_cargo_link' => esc_url_raw( $e->tracking_url ) ) as $k => $v ) {
					$v = sanitize_text_field( $v );
					if ( '' !== $v && (string) $order->get_meta( $k ) !== $v ) {
						$order->update_meta_data( $k, $v );
					}
				}
			} elseif ( 'cancel_lines' === $e->type && $e->lines ) {
				Eru_MPO_Stock::restore_lines( $order, $e->lines );
				$names = array();
				foreach ( $e->lines as $l ) {
					$item    = self::find_item( $order, $l );
					$names[] = ( $item ? $item->get_name() : ( '' !== $l['sku'] ? $l['sku'] : $l['remote_line_id'] ) ) . ' × ' . (int) $l['quantity'];
				}
				/* translators: %s: cancelled lines */
				$order->add_order_note( sprintf( __( 'Pazaryeri kısmi iptal: %s', 'marketplace-orders-for-woocommerce' ), implode( ', ', $names ) ) );
				$order->update_meta_data( '_eru_mpo_review', 'yes' );
				Eru_MPO_Map::set_review( (string) $order->get_meta( '_eru_mpo_marketplace' ), (string) $order->get_meta( '_eru_mpo_remote_ref' ), true );
			}
			$done[] = $e->id;
			$order->update_meta_data( self::META, array_slice( $done, -self::KEEP_IDS ) );
			Eru_MPO_Isolated_Save::save( $order );
			if ( 'cancel_lines' === $e->type && $e->lines ) {
				do_action( 'eru_mpo_order_cancelled_lines', $order, $e->lines );
			}
			++$n;
		}
		return $n;
	}

	/**
	 * The order line an event line refers to: the remote line id first; then the line's position (index, the DTO
	 * line order the order was built in) when it carries the same SKU; then SKU, then barcode. Zero-priced lines
	 * kept for lines the marketplace had already cancelled/returned (_eru_mpo_line_status) are skipped unless
	 * $include_dropped is true.
	 *
	 * @param WC_Order $order           Order.
	 * @param array    $l               {remote_line_id, sku, barcode, index}.
	 * @param bool     $include_dropped Return a dropped line too.
	 * @return WC_Order_Item_Product|null
	 */
	public static function find_item( $order, array $l, $include_dropped = false ) {
		$ok  = fn( $item ) => $item instanceof WC_Order_Item_Product && ( $include_dropped || '' === (string) $item->get_meta( '_eru_mpo_line_status' ) );
		$rid = (string) ( $l['remote_line_id'] ?? '' );
		if ( '' !== $rid ) {
			foreach ( $order->get_items() as $item ) {
				if ( $ok( $item ) && (string) $item->get_meta( '_eru_mpo_remote_line_id' ) === $rid ) {
					return $item;
				}
			}
		}
		$idx = isset( $l['index'] ) ? (int) $l['index'] : -1;
		$sku = (string) ( $l['sku'] ?? '' );
		if ( $idx >= 0 ) {
			$items = array_values( $order->get_items( 'line_item' ) );
			$item  = $items[ $idx ] ?? null;
			if ( $item instanceof WC_Order_Item_Product && ( '' === $sku || (string) $item->get_meta( '_eru_mpo_sku' ) === $sku ) ) {
				return $ok( $item ) ? $item : null;
			}
		}
		foreach ( array( '_eru_mpo_sku' => 'sku', '_eru_mpo_barcode' => 'barcode' ) as $meta => $field ) {
			$want = (string) ( $l[ $field ] ?? '' );
			if ( '' === $want ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( $ok( $item ) && (string) $item->get_meta( $meta ) === $want ) {
					return $item;
				}
			}
		}
		return null;
	}
}
