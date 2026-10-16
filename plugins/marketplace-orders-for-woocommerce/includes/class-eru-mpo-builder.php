<?php
/**
 * Builds an unsaved WC_Order from an order DTO: addresses with Turkish state resolution, product matching,
 * VAT split into tax lines, marketplace totals kept as reported.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Money is handled in minor units (kuruş) and converted once at the edge. Marketplace prices include VAT: when a
 * WooCommerce tax rate with the line's VAT percent exists (TR or any country, the matched product's class first),
 * the line is split into net + tax with that rate and a tax line is added; otherwise the line keeps the gross
 * amount without a tax split. Shipping is split the same way when the DTO gives its VAT percent
 * (totals.shipping_vat_rate). The order total is the marketplace total, never recalculated from shop prices.
 *
 * totals.discount is an order-level discount that is not already in the line discounts: it is spread over the
 * active lines in proportion to their amounts (minor units, the remainder on the largest line), as a WooCommerce
 * line discount (subtotal stays, total drops), so VAT and the order total stay right.
 *
 * Lines are matched to products by (1) the listings table (remote SKU, shop SKU or barcode), (2) the shop SKU =
 * line SKU, (3) the shop SKU = barcode, (4) the product GTIN/EAN field = barcode; filter eru_mpo_match_product
 * has the last word. Unmatched lines stay as product lines without a product (name, quantity, price kept,
 * _eru_mpo_unmatched = yes) and the order is flagged for review. No fee lines are created.
 *
 * Lines the marketplace already cancelled or returned (line status) are kept for the record at zero price and
 * without a product link, so they never count in the total and never reduce stock: name suffix "(pazaryerinde
 * iptal)" / "(pazaryerinde iade)", _eru_mpo_line_status, the original quantity and the product id in line meta,
 * and an order note.
 */
class Eru_MPO_Builder {

	/** Order meta keys the DTO's extra meta may not overwrite. */
	const RESERVED = array( 'marketplace', 'remote_ref', 'package_id', 'order_number', 'remote_order_id', 'status', 'remote_status', 'review', 'identity_number', 'events', 'migrated', 'imported_at' );

	/** @param float|int|string $v Decimal. @return int Minor units. */
	public static function minor( $v ) {
		return (int) round( (float) $v * 100 );
	}

	/** @param int $minor Minor units. @return string Decimal string with 2 places. */
	public static function dec( $minor ) {
		$minor = (int) $minor;
		$sign  = $minor < 0 ? '-' : '';
		$minor = abs( $minor );
		return $sign . intdiv( $minor, 100 ) . '.' . str_pad( (string) ( $minor % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/** Lower-cases Turkish text (İ→i, I→ı) and folds spaces/punctuation for name comparison. */
	public static function fold( $s ) {
		$s = strtr( (string) $s, array( 'İ' => 'i', 'I' => 'ı' ) );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return trim( (string) preg_replace( '/[\s.\-]+/u', ' ', $s ) );
	}

	/**
	 * ASCII key of a place name, the same for "İZMİR", "IZMIR", "izmir" and "İzmir": Turkish letters in both cases
	 * become ASCII before lower-casing, so ASCII upper case (I) and Turkish casing (İ, ı) meet.
	 *
	 * @param string $s Name.
	 * @return string
	 */
	public static function place_key( $s ) {
		$s = strtr(
			(string) $s,
			array(
				'İ' => 'i',
				'I' => 'i',
				'ı' => 'i',
				'Ş' => 's',
				'ş' => 's',
				'Ğ' => 'g',
				'ğ' => 'g',
				'Ü' => 'u',
				'ü' => 'u',
				'Ö' => 'o',
				'ö' => 'o',
				'Ç' => 'c',
				'ç' => 'c',
				'Â' => 'a',
				'â' => 'a',
			)
		);
		return trim( (string) preg_replace( '/[\s.\-]+/', ' ', strtolower( $s ) ) );
	}

	/**
	 * WooCommerce state key for a Turkish address: plate code first, then the il name (any casing, ASCII or Turkish
	 * letters, with the common alternative names), otherwise the name as given.
	 *
	 * @param Eru_MPO_Address_DTO $a Address.
	 * @return string
	 */
	public static function state( Eru_MPO_Address_DTO $a ) {
		if ( 'TR' !== $a->country ) {
			return $a->city;
		}
		if ( $a->city_code >= 1 && $a->city_code <= 81 ) {
			return sprintf( 'TR%02d', $a->city_code );
		}
		if ( '' === $a->city ) {
			return '';
		}
		$alias = array(
			'afyonkarahisar' => 'afyon',
			'icel'           => 'mersin',
			'k maras'        => 'kahramanmaras',
			'maras'          => 'kahramanmaras',
			'urfa'           => 'sanliurfa',
		);
		$want  = self::place_key( $a->city );
		$want  = $alias[ $want ] ?? $want;
		$list  = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_states( 'TR' ) : array();
		foreach ( is_array( $list ) ? $list : array() as $k => $label ) {
			if ( self::place_key( $label ) === $want ) {
				return (string) $k;
			}
		}
		return $a->city;
	}

	/**
	 * @param Eru_MPO_Address_DTO  $a Address.
	 * @param Eru_MPO_Customer_DTO $c Buyer (fallback name/phone).
	 * @return array WooCommerce address fields.
	 */
	public static function address( Eru_MPO_Address_DTO $a, Eru_MPO_Customer_DTO $c ) {
		$line2 = trim( $a->address_2 . ( '' !== $a->neighborhood && false === strpos( self::fold( $a->address_1 . ' ' . $a->address_2 ), self::fold( $a->neighborhood ) ) ? ' ' . $a->neighborhood : '' ) );
		return array(
			'first_name' => '' !== $a->first_name ? $a->first_name : $c->first_name,
			'last_name'  => '' !== $a->last_name ? $a->last_name : $c->last_name,
			'company'    => '' !== $a->company ? $a->company : $c->company,
			'address_1'  => $a->address_1,
			'address_2'  => $line2,
			'city'       => '' !== $a->district ? $a->district : $a->city,
			'state'      => self::state( $a ),
			'postcode'   => $a->postcode,
			'country'    => $a->country,
			'phone'      => '' !== $a->phone ? $a->phone : $c->phone,
		);
	}

	/**
	 * @param string           $mp   Marketplace.
	 * @param Eru_MPO_Line_DTO $line Line.
	 * @return int Product or variation id (0 = unmatched).
	 */
	public static function match_product( $mp, Eru_MPO_Line_DTO $line ) {
		global $wpdb;
		$id = 0;
		if ( '' !== $line->sku || '' !== $line->barcode ) {
			$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT wc_product_id FROM ' . Eru_MPO_Installer::table( 'listings' ) . " WHERE marketplace = %s AND wc_product_id > 0 AND ( ( %s <> '' AND ( remote_sku = %s OR wc_sku = %s ) ) OR ( %s <> '' AND barcode = %s ) ) ORDER BY id ASC LIMIT 1", // phpcs:ignore
					$mp,
					$line->sku,
					$line->sku,
					$line->sku,
					$line->barcode,
					$line->barcode
				)
			);
		}
		if ( ! $id && '' !== $line->sku ) {
			$id = (int) wc_get_product_id_by_sku( $line->sku );
		}
		if ( ! $id && '' !== $line->barcode ) {
			$id = (int) wc_get_product_id_by_sku( $line->barcode );
		}
		if ( ! $id && '' !== $line->barcode && function_exists( 'wc_get_product_id_by_global_unique_id' ) ) {
			$id = (int) wc_get_product_id_by_global_unique_id( $line->barcode );
		}
		$id = (int) apply_filters( 'eru_mpo_match_product', $id, $line, $mp );
		if ( $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || $p->is_type( 'variable' ) ) {
				return 0; // A parent of variations cannot be sold as such.
			}
		}
		return $id;
	}

	/**
	 * WooCommerce tax rate for a VAT percent.
	 *
	 * @param float|null $percent   VAT percent (null = the class's first rate).
	 * @param string     $tax_class Preferred class ('' = standard).
	 * @return array{id:int,percent:float,label:string}|null
	 */
	public static function tax_rate( $percent, $tax_class = '' ) {
		static $cache = array();
		$ck = ( null === $percent ? 'x' : (string) $percent ) . '|' . $tax_class;
		if ( array_key_exists( $ck, $cache ) ) {
			return $cache[ $ck ];
		}
		$classes = array_values( array_unique( array_merge( array( (string) $tax_class, '' ), class_exists( 'WC_Tax' ) ? WC_Tax::get_tax_class_slugs() : array() ) ) );
		$found   = null;
		foreach ( $classes as $cls ) {
			foreach ( class_exists( 'WC_Tax' ) ? WC_Tax::get_rates_for_tax_class( $cls ) : array() as $r ) {
				if ( ! in_array( (string) $r->tax_rate_country, array( 'TR', '' ), true ) ) {
					continue;
				}
				if ( null === $percent ? (float) $r->tax_rate > 0 : abs( (float) $r->tax_rate - (float) $percent ) < 0.0001 ) {
					$found = array(
						'id'      => (int) $r->tax_rate_id,
						'percent' => (float) $r->tax_rate,
						'label'   => '' !== (string) $r->tax_rate_name ? (string) $r->tax_rate_name : __( 'KDV', 'marketplace-orders-for-woocommerce' ),
					);
					break 2;
				}
			}
			if ( null === $percent ) {
				break; // Without a percent only the product's own class counts.
			}
		}
		$cache[ $ck ] = $found;
		return $found;
	}

	/**
	 * Builds the order (not saved).
	 *
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @param Eru_MPO_Order_DTO $dto     Order.
	 * @return array{order: WC_Order, review: bool, reasons: string[]}
	 */
	public static function build( Eru_MPO_Adapter $adapter, Eru_MPO_Order_DTO $dto ) {
		$mp      = $adapter->key();
		$order   = new WC_Order();
		$reasons = array();
		$order->set_created_via( 'eru-mpo-' . $mp );
		$order->set_currency( $dto->currency );
		$order->set_prices_include_tax( true );
		// The marketplace's name as method and title: no gateway is registered for it, so WooCommerce prints the
		// method value itself on the order screen ("Trendyol ile ödeme", not an internal id).
		$order->set_payment_method( $adapter->label() );
		$order->set_payment_method_title( $adapter->label() );
		if ( $dto->ordered_at > 0 ) {
			$order->set_date_created( $dto->ordered_at );
		}
		$refs = array_values( array_unique( array_filter( array( $dto->order_number, $dto->package_id, $dto->remote_ref ) ) ) );
		$order->set_transaction_id( implode( ' / ', $refs ) );

		$billing_src = $dto->billing->is_empty() ? $dto->shipping : $dto->billing;
		$billing     = self::address( $billing_src, $dto->customer );
		$email       = sanitize_email( $dto->customer->email );
		if ( '' !== $email && is_email( $email ) ) {
			$billing['email'] = $email;
		}
		$order->set_address( $billing, 'billing' );
		$order->set_address( self::address( $dto->shipping->is_empty() ? $billing_src : $dto->shipping, $dto->customer ), 'shipping' );

		self::apply_meta( $order, $mp, $dto );
		$order->update_meta_data( '_eru_mpo_imported_at', gmdate( 'Y-m-d H:i:s' ) );

		// Order-level discount, spread over the active lines in proportion to their amounts (minor units).
		$active = array();
		foreach ( $dto->lines as $i => $line ) {
			if ( 'active' === $line->status ) {
				$active[ $i ] = max( 0, self::minor( $line->unit_price ) * max( 1, $line->quantity ) - self::minor( $line->discount ) );
			}
		}
		$alloc = self::allocate( self::minor( $dto->totals->discount ), $active );
		if ( self::minor( $dto->totals->discount ) > array_sum( $alloc ) ) {
			/* translators: %s: discount */
			$reasons[] = sprintf( __( 'Sipariş indirimi (%s) satırlardan büyük.', 'marketplace-orders-for-woocommerce' ), self::dec( self::minor( $dto->totals->discount ) ) );
		}

		$tax_acc   = array();
		$sum_gross = 0;
		$disc_net  = 0;
		$disc_tax  = 0;
		$unmatched = array();
		$dropped   = array();
		foreach ( $dto->lines as $i => $line ) {
			if ( 'active' !== $line->status ) {
				self::add_dropped_line( $order, $line );
				$dropped[] = ( '' !== $line->name ? $line->name : $line->sku ) . ' × ' . $line->quantity . ' (' . $line->status . ')';
				continue;
			}
			$r          = self::add_line( $order, $mp, $line, $tax_acc, $alloc[ $i ] ?? 0 );
			$sum_gross += $r['gross'];
			$disc_net  += $r['disc_net'];
			$disc_tax  += $r['disc_tax'];
			if ( ! $r['matched'] ) {
				$unmatched[] = '' !== $line->sku ? $line->sku : ( '' !== $line->barcode ? $line->barcode : $line->name );
			}
		}
		if ( array() === $dto->lines ) {
			$reasons[] = __( 'Siparişte satır yok.', 'marketplace-orders-for-woocommerce' );
		}
		if ( $unmatched ) {
			/* translators: %s: SKU list */
			$reasons[] = sprintf( __( 'Eşleşmeyen ürün: %s', 'marketplace-orders-for-woocommerce' ), implode( ', ', $unmatched ) );
		}

		$ship     = self::minor( $dto->totals->shipping );
		$ship_tax = 0;
		if ( $ship > 0 ) {
			$s    = new WC_Order_Item_Shipping();
			$rate = null !== $dto->totals->shipping_vat_rate ? self::tax_rate( $dto->totals->shipping_vat_rate ) : null;
			$s->set_method_title( __( 'Pazaryeri kargosu', 'marketplace-orders-for-woocommerce' ) );
			$s->set_method_id( 'eru_mpo_' . $mp );
			if ( $rate && $rate['percent'] > 0 ) {
				$net      = (int) round( $ship * 100 / ( 100 + $rate['percent'] ) );
				$ship_tax = $ship - $net;
				$s->set_total( self::dec( $net ) );
				$s->set_taxes( array( 'total' => array( $rate['id'] => self::dec( $ship_tax ) ) ) );
				self::acc( $tax_acc, $rate, 0, $ship_tax );
			} else {
				$s->set_total( self::dec( $ship ) );
				$s->set_taxes( array( 'total' => array() ) );
			}
			$order->add_item( $s );
		}
		$cart_tax = 0;
		foreach ( $tax_acc as $rate_id => $t ) {
			$ti = new WC_Order_Item_Tax();
			$ti->set_rate_id( (int) $rate_id );
			$ti->set_rate_percent( (float) $t['percent'] );
			$ti->set_label( $t['label'] );
			$ti->set_compound( false );
			$ti->set_tax_total( self::dec( $t['total'] ) );
			$ti->set_shipping_tax_total( self::dec( $t['shipping'] ) );
			$order->add_item( $ti );
			$cart_tax += $t['total'];
		}
		$order->set_shipping_total( self::dec( $ship - $ship_tax ) );
		$order->set_shipping_tax( self::dec( $ship_tax ) );
		$order->set_cart_tax( self::dec( $cart_tax ) );
		$order->set_discount_total( self::dec( $disc_net ) );
		$order->set_discount_tax( self::dec( $disc_tax ) );
		$computed = $sum_gross + $ship;
		$reported = self::minor( $dto->totals->total );
		$order->set_total( self::dec( $reported > 0 ? $reported : $computed ) );
		if ( $reported > 0 && abs( $reported - $computed ) > 1 ) {
			/* translators: 1: marketplace total, 2: lines + shipping */
			$reasons[] = sprintf( __( 'Pazaryeri toplamı (%1$s) satır toplamından (%2$s) farklı.', 'marketplace-orders-for-woocommerce' ), self::dec( $reported ), self::dec( $computed ) );
		}

		$review = array() !== $reasons;
		if ( $review ) {
			$order->update_meta_data( '_eru_mpo_review', 'yes' );
		}
		/* translators: 1: marketplace label, 2: remote status */
		Eru_MPO_Statuses::prime( $order, $dto->status, sprintf( __( '%1$s siparişi içe aktarıldı (pazaryeri durumu: %2$s).', 'marketplace-orders-for-woocommerce' ), $adapter->label(), '' !== $dto->remote_status ? $dto->remote_status : $dto->status ) );
		return array(
			'order'   => $order,
			'review'  => $review,
			'reasons' => $reasons,
			'dropped' => $dropped,
		);
	}

	/**
	 * Reference, cargo, invoice and extra meta (used on create and on update).
	 *
	 * @param WC_Order          $order Order.
	 * @param string            $mp    Marketplace.
	 * @param Eru_MPO_Order_DTO $dto   Order DTO.
	 * @param bool              $with_status Also write the remote status (false on updates: the importer writes
	 *                                       it only when the logical status is applied, so a stale payload never
	 *                                       leaves a regressed remote status behind).
	 * @return bool Something changed.
	 */
	public static function apply_meta( $order, $mp, Eru_MPO_Order_DTO $dto, $with_status = true ) {
		$set     = array(
			'_eru_mpo_marketplace'     => $mp,
			'_eru_mpo_remote_ref'      => $dto->remote_ref,
			'_eru_mpo_package_id'      => $dto->package_id,
			'_eru_mpo_order_number'    => $dto->order_number,
			'_eru_mpo_remote_order_id' => $dto->remote_order_id,
			'_eru_mpo_remote_status'   => $dto->remote_status,
			'_eru_mpo_cargo_provider'  => $dto->cargo_provider,
			'_eru_mpo_cargo_tracking'  => $dto->tracking_number,
			'_eru_mpo_cargo_link'      => esc_url_raw( $dto->tracking_url ),
			'_eru_mpo_tax_number'      => $dto->customer->tax_number,
			'_eru_mpo_tax_office'      => $dto->customer->tax_office,
		);
		if ( ! $with_status ) {
			unset( $set['_eru_mpo_remote_status'] );
		}
		if ( self::is_identity_like( $dto->customer->tax_number ) && ! Eru_MPO_Settings::store_identity() ) {
			unset( $set['_eru_mpo_tax_number'] ); // An 11-digit "tax number" is an individual's identity number (P8).
		}
		if ( Eru_MPO_Settings::store_identity() ) {
			$set['_eru_mpo_identity_number'] = preg_match( '/^\d{11}$/', $dto->customer->identity_number ) ? $dto->customer->identity_number : '';
		}
		foreach ( $dto->meta as $k => $v ) {
			if ( ! in_array( $k, self::RESERVED, true ) && ! isset( $set[ '_eru_mpo_' . $k ] ) ) {
				$set[ '_eru_mpo_' . $k ] = sanitize_text_field( $v );
			}
		}
		$changed = false;
		foreach ( $set as $k => $v ) {
			$v = sanitize_text_field( (string) $v );
			if ( '' === $v ) {
				continue; // Never blank out a value a later payload no longer carries.
			}
			if ( (string) $order->get_meta( $k ) !== $v ) {
				$order->update_meta_data( $k, $v );
				$changed = true;
			}
		}
		return $changed;
	}

	/** @param string $v Value. @return bool Looks like a personal identity number (11 digits). */
	public static function is_identity_like( $v ) {
		return (bool) preg_match( '/^\d{11}$/', trim( (string) $v ) );
	}

	/**
	 * Splits an amount over weights in minor units: proportional, floors, the remainder to the largest weights.
	 *
	 * @param int   $amount  Amount (minor units).
	 * @param int[] $weights Key => weight.
	 * @return int[] Key => share; the shares sum to min( amount, sum of weights ).
	 */
	public static function allocate( $amount, array $weights ) {
		$total  = array_sum( $weights );
		$amount = min( max( 0, (int) $amount ), $total );
		$out    = array_fill_keys( array_keys( $weights ), 0 );
		if ( $amount <= 0 || $total <= 0 ) {
			return $out;
		}
		foreach ( $weights as $k => $w ) {
			$out[ $k ] = intdiv( $amount * $w, $total );
		}
		$left = $amount - array_sum( $out );
		arsort( $weights );
		foreach ( array_keys( $weights ) as $k ) {
			if ( $left <= 0 ) {
				break;
			}
			if ( $out[ $k ] < $weights[ $k ] ) {
				++$out[ $k ];
				--$left;
			}
		}
		return $out;
	}

	/** Adds line tax and shipping tax to the accumulator of a rate. */
	private static function acc( array &$tax_acc, array $rate, $line_tax, $ship_tax ) {
		if ( ! isset( $tax_acc[ $rate['id'] ] ) ) {
			$tax_acc[ $rate['id'] ] = array(
				'percent'  => $rate['percent'],
				'label'    => $rate['label'],
				'total'    => 0,
				'shipping' => 0,
			);
		}
		$tax_acc[ $rate['id'] ]['total']    += (int) $line_tax;
		$tax_acc[ $rate['id'] ]['shipping'] += (int) $ship_tax;
	}

	/**
	 * Keeps a line the marketplace already cancelled/returned, at zero price and without a product link.
	 *
	 * @param WC_Order         $order Order.
	 * @param Eru_MPO_Line_DTO $line  Line.
	 */
	private static function add_dropped_line( $order, Eru_MPO_Line_DTO $line ) {
		$item  = new WC_Order_Item_Product();
		$label = 'returned' === $line->status ? __( 'pazaryerinde iade', 'marketplace-orders-for-woocommerce' ) : __( 'pazaryerinde iptal', 'marketplace-orders-for-woocommerce' );
		$name  = '' !== $line->name ? $line->name : ( '' !== $line->sku ? $line->sku : __( 'Pazaryeri ürünü', 'marketplace-orders-for-woocommerce' ) );
		$item->set_name( $name . ' (' . $label . ')' );
		$item->set_quantity( max( 1, $line->quantity ) );
		$item->set_subtotal( '0' );
		$item->set_total( '0' );
		$item->set_taxes( false );
		$item->add_meta_data( '_eru_mpo_line_status', $line->status, true );
		$item->add_meta_data( '_eru_mpo_original_price', self::dec( self::minor( $line->unit_price ) ), true );
		foreach ( array( '_eru_mpo_sku' => $line->sku, '_eru_mpo_barcode' => $line->barcode, '_eru_mpo_remote_line_id' => $line->remote_line_id ) as $k => $v ) {
			if ( '' !== $v ) {
				$item->add_meta_data( $k, $v, true );
			}
		}
		$order->add_item( $item );
	}

	/**
	 * Adds one active line.
	 *
	 * @param WC_Order         $order   Order.
	 * @param string           $mp      Marketplace.
	 * @param Eru_MPO_Line_DTO $line    Line.
	 * @param array            $tax_acc Tax accumulator by rate id (minor units).
	 * @param int              $extra   Share of the order-level discount (minor units, VAT included).
	 * @return array{matched: bool, gross: int, disc_net: int, disc_tax: int}
	 */
	private static function add_line( $order, $mp, Eru_MPO_Line_DTO $line, array &$tax_acc, $extra = 0 ) {
		$qty       = max( 1, $line->quantity );
		$sub_gross = self::minor( $line->unit_price ) * $qty;
		$disc      = min( $sub_gross, self::minor( $line->discount ) + (int) $extra );
		$gross     = $sub_gross - $disc;
		$pid       = self::match_product( $mp, $line );
		$product   = $pid ? wc_get_product( $pid ) : null;
		$item      = new WC_Order_Item_Product();
		if ( $product ) {
			$item->set_product( $product );
		} else {
			$item->set_name( '' !== $line->name ? $line->name : ( '' !== $line->sku ? $line->sku : __( 'Pazaryeri ürünü', 'marketplace-orders-for-woocommerce' ) ) );
			$item->add_meta_data( '_eru_mpo_unmatched', 'yes', true );
		}
		$item->set_quantity( $qty );
		$rate = self::tax_rate( $line->vat_rate, $product ? (string) $product->get_tax_class() : '' );
		if ( null === $line->vat_rate && ! $product ) {
			$rate = null;
		}
		if ( $rate && $rate['percent'] > 0 ) {
			$p       = $rate['percent'];
			$sub_net = (int) round( $sub_gross * 100 / ( 100 + $p ) );
			$net     = (int) round( $gross * 100 / ( 100 + $p ) );
			$sub_tax = $sub_gross - $sub_net;
			$tax     = $gross - $net;
			$item->set_subtotal( self::dec( $sub_net ) );
			$item->set_total( self::dec( $net ) );
			$item->set_taxes(
				array(
					'subtotal' => array( $rate['id'] => self::dec( $sub_tax ) ),
					'total'    => array( $rate['id'] => self::dec( $tax ) ),
				)
			);
			self::acc( $tax_acc, $rate, $tax, 0 );
			$disc_net = $sub_net - $net;
			$disc_tax = $sub_tax - $tax;
		} else {
			$item->set_subtotal( self::dec( $sub_gross ) );
			$item->set_total( self::dec( $gross ) );
			$item->set_taxes( false );
			$disc_net = $disc;
			$disc_tax = 0;
		}
		foreach ( array( '_eru_mpo_sku' => $line->sku, '_eru_mpo_barcode' => $line->barcode, '_eru_mpo_remote_line_id' => $line->remote_line_id ) as $k => $v ) {
			if ( '' !== $v ) {
				$item->add_meta_data( $k, $v, true );
			}
		}
		if ( null !== $line->commission_rate && $line->commission_rate > 0 ) {
			$item->add_meta_data( '_eru_mpo_commission_rate', wc_format_decimal( $line->commission_rate ), true );
			$item->add_meta_data( '_eru_mpo_commission_amount', self::dec( (int) round( $gross * $line->commission_rate / 100 ) ), true );
		}
		foreach ( $line->meta as $k => $v ) {
			$item->add_meta_data( '_eru_mpo_' . $k, sanitize_text_field( $v ), true );
		}
		$order->add_item( $item );
		return array(
			'matched'  => (bool) $product,
			'gross'    => $gross,
			'disc_net' => $disc_net,
			'disc_tax' => $disc_tax,
		);
	}
}
