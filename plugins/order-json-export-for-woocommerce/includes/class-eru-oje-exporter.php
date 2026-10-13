<?php
/** Order → array/JSON. Schema "eru-oje/1" (see README). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OJE_Exporter {

	const SCHEMA = 'eru-oje/1';

	/** Meta keys the admin allows in the "meta" object (one per line in settings). */
	public static function meta_allowlist(): array {
		$raw  = (string) get_option( 'eru_oje_meta_allowlist', '' );
		$keys = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
		/**
		 * Filter the order meta keys that may appear in the export.
		 *
		 * @param string[] $keys Allowed meta keys (empty by default).
		 */
		return array_values( array_unique( (array) apply_filters( 'eru_oje_meta_allowlist', $keys ) ) );
	}

	private static function money( $value ): string {
		return wc_format_decimal( $value, wc_get_price_decimals() );
	}

	private static function date( $date ): ?string {
		return $date ? $date->date( 'c' ) : null;
	}

	/** @return array<string,mixed> */
	public static function order_to_array( WC_Order $order ): array {
		$address = function ( string $type ) use ( $order ): array {
			$out = array();
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $f ) {
				$out[ $f ] = (string) $order->{"get_{$type}_{$f}"}();
			}
			if ( 'billing' === $type ) {
				$out['email'] = (string) $order->get_billing_email();
				$out['phone'] = (string) $order->get_billing_phone();
			} else {
				$out['phone'] = (string) $order->get_shipping_phone();
			}
			return $out;
		};

		$items = array();
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			$qty     = max( 1, (int) $item->get_quantity() );
			$items[] = array(
				'id'           => $item->get_id(),
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'sku'          => $product ? (string) $product->get_sku() : '',
				'name'         => $item->get_name(),
				'quantity'     => (int) $item->get_quantity(),
				'unit_price'   => self::money( (float) $item->get_total() / $qty ),
				'subtotal'     => self::money( $item->get_subtotal() ),
				'subtotal_tax' => self::money( $item->get_subtotal_tax() ),
				'total'        => self::money( $item->get_total() ),
				'total_tax'    => self::money( $item->get_total_tax() ),
				'tax_rate'     => self::item_tax_rate( $item ),
			);
		}

		$shipping_lines = array();
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			/** @var WC_Order_Item_Shipping $line */
			$shipping_lines[] = array(
				'method_id'   => $line->get_method_id(),
				'instance_id' => (int) $line->get_instance_id(),
				'title'       => $line->get_name(),
				'total'       => self::money( $line->get_total() ),
				'total_tax'   => self::money( $line->get_total_tax() ),
			);
		}

		$fees = array();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fees[] = array(
				'name'      => $fee->get_name(),
				'total'     => self::money( $fee->get_total() ),
				'total_tax' => self::money( $fee->get_total_tax() ),
			);
		}

		$coupons = array();
		foreach ( $order->get_items( 'coupon' ) as $coupon ) {
			$coupons[] = array(
				'code'     => $coupon->get_code(),
				'discount' => self::money( $coupon->get_discount() ),
			);
		}

		$meta = array();
		foreach ( self::meta_allowlist() as $key ) {
			$value = $order->get_meta( $key, true );
			if ( '' !== $value && null !== $value ) {
				$meta[ $key ] = is_scalar( $value ) ? $value : wp_json_encode( $value );
			}
		}

		$data = array(
			'id'                   => $order->get_id(),
			'number'               => (string) $order->get_order_number(),
			'status'               => $order->get_status(),
			'currency'             => $order->get_currency(),
			'created_at'           => self::date( $order->get_date_created() ),
			'paid_at'              => self::date( $order->get_date_paid() ),
			'completed_at'         => self::date( $order->get_date_completed() ),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_note'        => 'yes' === get_option( 'eru_oje_include_note', 'no' ) ? $order->get_customer_note() : '',
			'totals'               => array(
				'subtotal'  => self::money( $order->get_subtotal() ),
				'discount'  => self::money( $order->get_total_discount() ),
				'shipping'  => self::money( $order->get_shipping_total() ),
				'fees'      => self::money( $order->get_total_fees() ),
				'tax'       => self::money( $order->get_total_tax() ),
				'total'     => self::money( $order->get_total() ),
				'refunded'  => self::money( $order->get_total_refunded() ),
			),
			'billing'              => $address( 'billing' ),
			'shipping'             => $address( 'shipping' ),
			'items'                => $items,
			'shipping_lines'       => $shipping_lines,
			'fees'                 => $fees,
			'coupons'              => $coupons,
			'meta'                 => (object) $meta,
		);

		/**
		 * Filter one exported order.
		 *
		 * @param array    $data  Schema array.
		 * @param WC_Order $order Order.
		 */
		return (array) apply_filters( 'eru_oje_order', $data, $order );
	}

	private static function item_tax_rate( WC_Order_Item_Product $item ): string {
		$net = (float) $item->get_total();
		if ( $net <= 0 ) {
			return '0';
		}
		return wc_format_decimal( round( (float) $item->get_total_tax() / $net * 100, 2 ), 2 );
	}

	/** @param WC_Order[] $orders */
	public static function to_document( array $orders ): array {
		$list = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$list[] = self::order_to_array( $order );
			}
		}
		return array(
			'schema'       => self::SCHEMA,
			'generated_at' => wp_date( 'c' ),
			'count'        => count( $list ),
			'orders'       => $list,
		);
	}

	/** @param WC_Order[] $orders */
	public static function to_json( array $orders ): string {
		return (string) wp_json_encode( self::to_document( $orders ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	/**
	 * Query orders (HPOS-safe).
	 *
	 * @param array $args after, before (strings parseable by strtotime in site timezone), statuses, limit, page.
	 * @return array{orders:WC_Order[],total:int,pages:int}
	 */
	public static function query( array $args ): array {
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$q     = array(
			'type'     => 'shop_order',
			'limit'    => $limit,
			'paged'    => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'ASC',
			'status'   => ! empty( $args['statuses'] ) ? array_map( 'sanitize_key', (array) $args['statuses'] ) : array_keys( wc_get_order_statuses() ),
		);
		$tz     = wp_timezone();
		$after  = ! empty( $args['after'] ) ? date_create_immutable( (string) $args['after'], $tz ) : false;
		$before = ! empty( $args['before'] ) ? date_create_immutable( (string) $args['before'], $tz ) : false;
		if ( $after && $before ) {
			$q['date_created'] = $after->getTimestamp() . '...' . $before->getTimestamp();
		} elseif ( $after ) {
			$q['date_created'] = '>' . $after->getTimestamp();
		} elseif ( $before ) {
			$q['date_created'] = '<' . $before->getTimestamp();
		}
		if ( ! empty( $args['ids'] ) ) {
			$q['post__in'] = array_map( 'absint', (array) $args['ids'] );
			$q['limit']    = min( 500, count( $q['post__in'] ) );
			unset( $q['paged'] );
		}
		$res = wc_get_orders( $q );
		if ( is_object( $res ) ) {
			return array( 'orders' => (array) $res->orders, 'total' => (int) $res->total, 'pages' => (int) $res->max_num_pages );
		}
		return array( 'orders' => (array) $res, 'total' => count( (array) $res ), 'pages' => 1 );
	}

	/** Send a JSON download and stop. */
	public static function download( string $json, string $name = '' ): void {
		$name = $name ? $name : 'orders-' . wp_date( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
		exit;
	}
}
