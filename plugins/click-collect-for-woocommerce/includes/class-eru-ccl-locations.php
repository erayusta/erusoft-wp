<?php
/** Pickup locations (option `eru_ccl_locations`) and the order-meta interface. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CCL_Locations {

	const OPTION = 'eru_ccl_locations';

	/**
	 * Order meta written for a pickup order (documented interface, read by
	 * Shipping Method Report for WooCommerce): META_LOCATION holds the location name.
	 */
	const META_ID       = '_eru_ccl_location_id';
	const META_LOCATION = '_eru_ccl_location';
	const META_ADDRESS  = '_eru_ccl_location_address';
	const META_HOURS    = '_eru_ccl_location_hours';

	/** @return array<string,array{id:string,name:string,address:string,hours:string}> keyed by id */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}
			$out[ (string) $row['id'] ] = array(
				'id'      => (string) $row['id'],
				'name'    => (string) $row['name'],
				'address' => (string) ( $row['address'] ?? '' ),
				'hours'   => (string) ( $row['hours'] ?? '' ),
			);
		}
		return (array) apply_filters( 'eru_ccl_locations', $out );
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	public static function settings(): array {
		$s = get_option( 'eru_ccl_settings', array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array( 'ready_status' => 0, 'ready_email' => 1 ) );
	}

	/** True when the order has a Click & Collect shipping line. */
	public static function is_pickup_order( WC_Order $order ): bool {
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			if ( 'eru_ccl' === $line->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate and store a chosen location on an order (does not save the order).
	 * With exactly one configured location a missing choice falls back to it.
	 */
	public static function save_to_order( WC_Order $order, string $location_id ): bool {
		$all = self::all();
		if ( '' === $location_id && 1 === count( $all ) ) {
			$location_id = (string) key( $all );
		}
		$loc = $all[ $location_id ] ?? null;
		if ( ! $loc ) {
			return false;
		}
		$order->update_meta_data( self::META_ID, $loc['id'] );
		$order->update_meta_data( self::META_LOCATION, $loc['name'] );
		$order->update_meta_data( self::META_ADDRESS, $loc['address'] );
		$order->update_meta_data( self::META_HOURS, $loc['hours'] );
		return true;
	}

	/** @return array{name:string,address:string,hours:string}|null */
	public static function order_location( WC_Order $order ): ?array {
		$name = (string) $order->get_meta( self::META_LOCATION );
		if ( '' === $name ) {
			return null;
		}
		return array(
			'name'    => $name,
			'address' => (string) $order->get_meta( self::META_ADDRESS ),
			'hours'   => (string) $order->get_meta( self::META_HOURS ),
		);
	}

	public static function order_info_html( WC_Order $order ): string {
		$l = self::order_location( $order );
		if ( ! $l ) {
			return '';
		}
		$html  = '<div class="eru-ccl-info" style="margin:0 0 16px"><h2 style="font-size:16px">' . esc_html__( 'Mağazadan teslim noktası', 'click-collect-for-woocommerce' ) . '</h2>';
		$html .= '<p><strong>' . esc_html( $l['name'] ) . '</strong>';
		if ( '' !== $l['address'] ) {
			$html .= '<br>' . esc_html( $l['address'] );
		}
		if ( '' !== $l['hours'] ) {
			$html .= '<br>' . esc_html__( 'Çalışma saatleri:', 'click-collect-for-woocommerce' ) . ' ' . esc_html( $l['hours'] );
		}
		return $html . '</p></div>';
	}

	public static function order_info_text( WC_Order $order ): string {
		$l = self::order_location( $order );
		if ( ! $l ) {
			return '';
		}
		$t = "\n" . __( 'Mağazadan teslim noktası', 'click-collect-for-woocommerce' ) . ': ' . $l['name'];
		if ( '' !== $l['address'] ) {
			$t .= "\n" . $l['address'];
		}
		if ( '' !== $l['hours'] ) {
			$t .= "\n" . __( 'Çalışma saatleri:', 'click-collect-for-woocommerce' ) . ' ' . $l['hours'];
		}
		return $t . "\n";
	}
}
