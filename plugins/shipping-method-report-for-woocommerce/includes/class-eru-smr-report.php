<?php
/** Aggregation. Pickup location is read from the documented order meta `_eru_ccl_location` (Click & Collect for WooCommerce). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SMR_Report {

	const LOCATION_META = '_eru_ccl_location';
	const MAX_ORDERS    = 20000;

	/**
	 * @param string   $from     Y-m-d (site timezone), inclusive.
	 * @param string   $to       Y-m-d (site timezone), inclusive.
	 * @param string[] $statuses Order statuses with the wc- prefix; empty = processing, on-hold, completed.
	 * @return array{rows:array<int,array>,orders:int,items:int,truncated:bool}
	 */
	public static function build( string $from, string $to, array $statuses = array() ): array {
		$tz     = wp_timezone();
		$start  = date_create_immutable( $from . ' 00:00:00', $tz );
		$end    = date_create_immutable( $to . ' 23:59:59', $tz );
		$valid  = array_keys( wc_get_order_statuses() );
		$status = array_values( array_intersect( $statuses ? $statuses : array( 'wc-processing', 'wc-on-hold', 'wc-completed' ), $valid ) );
		$empty  = array( 'rows' => array(), 'orders' => 0, 'items' => 0, 'truncated' => false );
		if ( ! $start || ! $end || ! $status ) {
			return $empty;
		}

		$rows   = array();
		$orders = 0;
		$items  = 0;
		$page   = 1;
		do {
			$batch = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => $status,
					'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
					'limit'        => 200,
					'paged'        => $page,
					'paginate'     => true,
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);
			foreach ( $batch->orders as $order ) {
				$orders++;
				$qty = 0;
				foreach ( $order->get_items() as $it ) {
					$qty += (int) $it->get_quantity();
				}
				$items += $qty;
				$lines = $order->get_items( 'shipping' );
				$loc   = (string) $order->get_meta( self::LOCATION_META );
				if ( ! $lines ) {
					self::add( $rows, 'none', __( 'Kargo yok', 'shipping-method-report-for-woocommerce' ), '', 0.0, $qty );
					continue;
				}
				$first = true;
				foreach ( $lines as $line ) {
					/** @var WC_Order_Item_Shipping $line */
					self::add(
						$rows,
						(string) $line->get_method_id(),
						(string) $line->get_name(),
						$loc,
						(float) $line->get_total(),
						$first ? $qty : 0,
						$first
					);
					$first = false;
				}
			}
			$page++;
		} while ( $page <= $batch->max_num_pages && $orders < self::MAX_ORDERS );

		uasort( $rows, static fn( $a, $b ) => array( $a['method_id'], $a['location'] ) <=> array( $b['method_id'], $b['location'] ) );
		return array( 'rows' => array_values( $rows ), 'orders' => $orders, 'items' => $items, 'truncated' => $orders >= self::MAX_ORDERS );
	}

	private static function add( array &$rows, string $method_id, string $label, string $loc, float $cost, int $qty, bool $count_order = true ): void {
		$key = $method_id . '|' . $loc;
		if ( ! isset( $rows[ $key ] ) ) {
			$rows[ $key ] = array( 'method_id' => $method_id, 'label' => $label, 'location' => $loc, 'orders' => 0, 'items' => 0, 'shipping_total' => 0.0 );
		}
		$rows[ $key ]['orders']        += $count_order ? 1 : 0;
		$rows[ $key ]['items']         += $qty;
		$rows[ $key ]['shipping_total'] += $cost;
	}

	public static function to_csv( array $rows ): string {
		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel reads Turkish characters.
		fputcsv( $fh, array( 'method_id', 'method', 'location', 'orders', 'items', 'shipping_total' ), ';', '"', '' );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array( self::safe( $r['method_id'] ), self::safe( $r['label'] ), self::safe( $r['location'] ), $r['orders'], $r['items'], wc_format_decimal( $r['shipping_total'], 2 ) ), ';', '"', '' );
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh );
		return (string) $out;
	}

	/** Neutralise spreadsheet formula injection. */
	private static function safe( string $v ): string {
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}
}
