<?php
/**
 * Order push to Dopigo: queued on the chosen trigger, sent under a per-order lock with a persisted
 * "pending" state, so two events in two requests create one Dopigo order.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order data is read and written only through WC_Order (HPOS-safe).
 */
class Eru_DPG_Orders {

	const M_ID      = '_eru_dpg_order_id';
	const M_STATE   = '_eru_dpg_order_state';
	const M_PENDING = '_eru_dpg_order_pending_at';
	const M_ERROR   = '_eru_dpg_order_error';
	const M_SYNCED  = '_eru_dpg_synced_at';

	/** Seconds after which a pending state or lock is considered abandoned. */
	const STALE = 10 * MINUTE_IN_SECONDS;

	/** @var WP_Error|null Error of the last push. */
	public static $last_error = null;

	/** Hooks (callbacks check the settings each time). */
	public static function init() {
		foreach ( array( 'woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed', 'woocommerce_new_order' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_created' ), 20, 1 );
		}
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_paid' ), 20, 1 );
		}
	}

	/** @param int|WC_Order $order Order or id. */
	public static function on_created( $order ) {
		if ( 'created' === Eru_DPG_Settings::get( 'order_trigger' ) ) {
			self::on_trigger( $order, MINUTE_IN_SECONDS );
		}
	}

	/** @param int|WC_Order $order Order or id. */
	public static function on_paid( $order ) {
		if ( 'paid' === Eru_DPG_Settings::get( 'order_trigger' ) ) {
			self::on_trigger( $order );
		}
	}

	/**
	 * Queues a push (de-duplicated by order id). Nothing is sent inside the request.
	 *
	 * @param int|WC_Order $order Order or id.
	 * @param int          $delay Seconds.
	 */
	public static function on_trigger( $order, $delay = 0 ) {
		if ( ! Eru_DPG_Settings::is_enabled() || ! Eru_DPG_Settings::on( 'order_push' ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) ( is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : $order ) );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || '' !== (string) $order->get_meta( self::M_ID ) || 'needs_review' === $order->get_meta( self::M_STATE ) ) {
			return;
		}
		Eru_DPG_Cron::enqueue( 'eru_dpg_push_order', array( $order->get_id() ), $delay );
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $id    Order id.
	 * @param int $force 1 = resend an order that already exists in Dopigo (update).
	 */
	public static function run_job( $id, $force = 0 ) {
		$args = $force ? array( (int) $id, 1 ) : array( (int) $id );
		$r    = self::push( (int) $id, (bool) $force );
		if ( 'retry' === $r && self::$last_error ) {
			Eru_DPG_Cron::retry( 'eru_dpg_push_order', $args, self::$last_error, (string) $id );
			return;
		}
		Eru_DPG_Cron::reset_attempts( 'eru_dpg_push_order', $args );
	}

	/**
	 * Creates the order in Dopigo, or updates it when $force and it already exists.
	 *
	 * @param int  $id    Order id.
	 * @param bool $force Update an existing Dopigo order.
	 * @return string created | updated | adopted | exists | locked | pending | skipped | dry-run | failed | retry | needs_review | disabled | missing
	 */
	public static function push( $id, $force = false ) {
		self::$last_error = null;
		if ( ! Eru_DPG_Settings::is_enabled() ) {
			return 'disabled';
		}
		$order = wc_get_order( (int) $id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return 'missing';
		}
		if ( ! $force && '' !== (string) $order->get_meta( self::M_ID ) ) {
			return 'exists';
		}
		if ( ! $force && 'needs_review' === $order->get_meta( self::M_STATE ) ) {
			return 'needs_review';
		}
		if ( ! $force && ! Eru_DPG_Settings::on( 'order_push' ) ) {
			return 'disabled';
		}
		$payload = self::transform( $order );
		if ( '' === $payload['billing_address']['full_address'] && '' === $payload['billing_address']['city'] ) {
			Eru_DPG_Logger::log( 'order', 'skipped', __( 'Sipariş adresi boş, gönderilmedi (sonraki tetikte yeniden denenir).', 'dopigo-integration-for-woocommerce' ), null, 'wc_to_dp', (string) $id );
			return 'skipped';
		}
		if ( Eru_DPG_Settings::dry_run() ) {
			Eru_DPG_Logger::log(
				'order',
				'info',
				__( 'Kuru çalışma: sipariş Dopigo\'ya gönderilmedi.', 'dopigo-integration-for-woocommerce' ),
				array(
					'items' => count( $payload['items'] ),
					'total' => $payload['total'],
				),
				'wc_to_dp',
				(string) $id
			);
			return 'dry-run';
		}

		$lock = 'order_' . (int) $id;
		if ( ! Eru_DPG_Lock::acquire( $lock, self::STALE ) ) {
			return 'locked';
		}
		try {
			// Re-read under the lock: another request may have finished in the meantime.
			$order = wc_get_order( (int) $id );
			$order->read_meta_data( true );
			$existing = (string) $order->get_meta( self::M_ID );
			if ( ! $force && '' !== $existing ) {
				return 'exists';
			}
			if ( 'pending' === $order->get_meta( self::M_STATE ) && (int) $order->get_meta( self::M_PENDING ) > time() - self::STALE ) {
				return 'pending';
			}
			$api = new Eru_DPG_API();

			// The last create attempt ended ambiguously (timeout/5xx, or a stale "pending" left by a crashed worker):
			// Dopigo may already have the order. Look it up before any new POST.
			if ( '' === $existing && in_array( $order->get_meta( self::M_STATE ), array( 'unknown', 'pending' ), true ) ) {
				$found = self::lookup_remote( $api, $order );
				if ( is_wp_error( $found ) ) {
					$order->update_meta_data( self::M_STATE, 'needs_review' );
					$order->update_meta_data( self::M_ERROR, __( 'Önceki gönderimin sonucu belirsiz ve Dopigo\'da arama yapılamadı. Siparişin Dopigo\'da olup olmadığını kontrol edip gerekirse elle yeniden gönderin.', 'dopigo-integration-for-woocommerce' ) );
					$order->update_meta_data( self::M_SYNCED, time() );
					$order->save();
					Eru_DPG_Logger::log( 'order', 'failed', __( 'İnceleme gerekli: belirsiz gönderim, Dopigo\'da arama başarısız: ', 'dopigo-integration-for-woocommerce' ) . $found->get_error_message(), null, 'wc_to_dp', (string) $id );
					return 'needs_review';
				}
				if ( '' !== $found ) {
					$order->update_meta_data( self::M_ID, $found );
					$order->update_meta_data( self::M_STATE, 'sent' );
					$order->update_meta_data( self::M_ERROR, '' );
					$order->update_meta_data( self::M_SYNCED, time() );
					$order->delete_meta_data( self::M_PENDING );
					/* translators: %s: Dopigo order id */
					$order->add_order_note( sprintf( __( 'Dopigo\'da zaten vardı; mevcut kayıt bağlandı (Dopigo sipariş no: %s).', 'dopigo-integration-for-woocommerce' ), $found ) );
					$order->save();
					Eru_DPG_Logger::log( 'order', 'success', __( 'Belirsiz gönderimden sonra sipariş Dopigo\'da bulundu ve bağlandı.', 'dopigo-integration-for-woocommerce' ), array( 'dopigo_id' => $found ), 'wc_to_dp', (string) $id );
					return 'adopted';
				}
			}

			$order->update_meta_data( self::M_STATE, 'pending' );
			$order->update_meta_data( self::M_PENDING, time() );
			$order->save();

			$r   = '' !== $existing ? $api->update_order( $existing, self::transform( $order ) ) : $api->create_order( self::transform( $order ) );
			$order->read_meta_data( true );

			if ( is_wp_error( $r ) ) {
				self::$last_error = $r;
				$retry            = Eru_DPG_API::is_retryable( $r );
				$unknown          = '' === $existing && Eru_DPG_API::is_ambiguous( $r );
				$order->update_meta_data( self::M_STATE, $unknown ? 'unknown' : 'failed' );
				$order->update_meta_data( self::M_ERROR, self::humanize( $r ) );
				$order->update_meta_data( self::M_SYNCED, time() );
				$order->save();
				Eru_DPG_Logger::log( 'order', $retry ? 'warning' : 'failed', __( 'Sipariş gönderilemedi: ', 'dopigo-integration-for-woocommerce' ) . self::humanize( $r ), null, 'wc_to_dp', (string) $id );
				return $retry ? 'retry' : 'failed';
			}
			$remote = (string) ( $r['id'] ?? ( $r['order']['id'] ?? $existing ) );
			if ( '' === $existing && '' !== $remote ) {
				$order->update_meta_data( self::M_ID, $remote );
				/* translators: %s: Dopigo order id */
				$order->add_order_note( sprintf( __( 'Dopigo\'ya gönderildi (Dopigo sipariş no: %s).', 'dopigo-integration-for-woocommerce' ), $remote ) );
			}
			$order->update_meta_data( self::M_STATE, 'sent' );
			$order->update_meta_data( self::M_ERROR, '' );
			$order->update_meta_data( self::M_SYNCED, time() );
			$order->delete_meta_data( self::M_PENDING );
			$order->save();
			$what = '' !== $existing ? 'updated' : 'created';
			Eru_DPG_Logger::log( 'order', 'success', 'created' === $what ? __( 'Sipariş Dopigo\'da oluşturuldu.', 'dopigo-integration-for-woocommerce' ) : __( 'Sipariş Dopigo\'da güncellendi.', 'dopigo-integration-for-woocommerce' ), array( 'dopigo_id' => $remote ), 'wc_to_dp', (string) $id );
			return $what;
		} finally {
			Eru_DPG_Lock::release( $lock );
		}
	}

	/**
	 * Finds this order in Dopigo by service_order_id (recent orders, newest first).
	 *
	 * @param Eru_DPG_API $api   Client.
	 * @param WC_Order    $order Order.
	 * @return string|WP_Error Dopigo id, '' when not found, or the lookup error.
	 */
	private static function lookup_remote( Eru_DPG_API $api, WC_Order $order ) {
		$list = $api->get_orders(
			array(
				'service_order_id' => (string) $order->get_id(),
				'limit'            => 50,
				'ordering'         => '-id',
			)
		);
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( $list as $do ) {
			if ( is_array( $do ) && ! empty( $do['id'] ) && (string) ( $do['service_order_id'] ?? '' ) === (string) $order->get_id() ) {
				return (string) $do['id'];
			}
		}
		return '';
	}

	/** @return int Orders waiting for a manual check (ambiguous push that could not be verified). */
	public static function needs_review_count() {
		return count(
			wc_get_orders(
				array(
					'limit'      => 100,
					'return'     => 'ids',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => self::M_STATE,
							'value' => 'needs_review',
						),
					),
				)
			)
		);
	}

	/** @param WP_Error $e Error. @return string Readable message incl. Dopigo field errors. */
	public static function humanize( $e ) {
		$data = $e->get_error_data();
		$body = is_array( $data ) ? json_decode( (string) ( $data['body'] ?? '' ), true ) : null;
		if ( is_array( $body ) ) {
			$parts = array();
			$walk  = function ( $arr, $prefix ) use ( &$walk, &$parts ) {
				foreach ( $arr as $k => $v ) {
					$key = '' === $prefix ? (string) $k : $prefix . '.' . $k;
					if ( is_array( $v ) ) {
						if ( array_filter( $v, 'is_array' ) || array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
							$walk( $v, $key );
						} else {
							$parts[] = $key . ': ' . implode( ' ', array_map( 'strval', $v ) );
						}
					} else {
						$parts[] = $key . ': ' . $v;
					}
				}
			};
			$walk( $body, '' );
			if ( $parts ) {
				return Eru_DPG_Logger::redact( mb_substr( implode( ' | ', $parts ), 0, 500 ) );
			}
		}
		return Eru_DPG_Logger::redact( $e->get_error_message() );
	}

	/**
	 * WC_Order → Dopigo order payload. Prices are sent VAT-inclusive with the rate per line.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function transform( WC_Order $order ) {
		$push   = (string) Eru_DPG_Settings::get( 'push_status' );
		$status = 'mapped' === $push ? ( Eru_DPG_Status::wc_to_dopigo( $order->get_status() ) ?? 'undefined' ) : $push;
		$net    = 'net' === Eru_DPG_Settings::get( 'vat_mode' );
		$def    = (int) Eru_DPG_Settings::get( 'default_vat' );

		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty     = max( 1, (int) $item->get_quantity() );
			$line    = (float) $item->get_total();
			$tax     = (float) $item->get_total_tax();
			$product = $item->get_product();
			if ( $tax > 0 && $line > 0 ) {
				$vat   = (int) round( $tax / $line * 100 );
				$gross = round( $line + $tax, 2 );
			} else {
				$vat   = $product ? Eru_DPG_Products::vat_rate( $product ) : $def;
				$gross = $net ? Eru_DPG_Products::to_gross( $line, $vat ) : round( $line, 2 );
			}
			$sku     = $product ? (string) $product->get_sku() : '';
			$sku     = '' !== $sku ? $sku : 'WC-' . $item->get_id();
			$items[] = array(
				'service_item_id'    => (string) $item->get_id(),
				'service_product_id' => (string) ( $product ? $product->get_id() : 0 ),
				'sku'                => $sku,
				'name'               => $item->get_name(),
				'attributes'         => self::item_attributes( $item ),
				'amount'             => $qty,
				'price'              => number_format( $gross, 2, '.', '' ),
				'unit_price'         => number_format( $gross / $qty, 2, '.', '' ),
				'vat'                => $vat,
				'tax_ratio'          => $vat,
				'product'            => array( 'sku' => $sku ),
				'status'             => $status,
			);
		}

		$shipping = 0.0;
		foreach ( $order->get_items( 'shipping' ) as $s ) {
			$s_net = (float) $s->get_total();
			$s_tax = (float) $s->get_total_tax();
			$shipping += $s_tax > 0 ? $s_net + $s_tax : ( $net ? Eru_DPG_Products::to_gross( $s_net, $def ) : $s_net );
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$name = '' !== $name ? $name : 'WC-' . $order->get_id();
		$tax  = self::customer_tax_info( $order );
		$corp = 'company' === $tax['account_type'];
		$tid  = $corp && '' !== $tax['tax_id'] && ctype_digit( $tax['tax_id'] ) ? (int) $tax['tax_id'] : null;
		$tel  = self::normalize_phone( $order->get_billing_phone() );

		$b_city  = self::province_name( $order->get_billing_country(), $order->get_billing_state() );
		$b_dist  = (string) $order->get_billing_city();
		$billing = array(
			'full_address'         => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'contact_full_name'    => $name,
			'contact_phone_number' => $tel,
			'city'                 => '' !== $b_city ? $b_city : $b_dist,
			'district'             => '' !== $b_dist ? $b_dist : $b_city,
			'zip_code'             => (string) $order->get_billing_postcode(),
		);
		$s_city    = self::province_name( $order->get_shipping_country() ?: $order->get_billing_country(), $order->get_shipping_state() ?: $order->get_billing_state() );
		$s_dist    = (string) ( $order->get_shipping_city() ?: $b_dist );
		$s_name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$s_addr    = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
		$ship_addr = array(
			'full_address'         => '' !== $s_addr ? $s_addr : $billing['full_address'],
			'contact_full_name'    => '' !== $s_name ? $s_name : $name,
			'contact_phone_number' => $tel,
			'city'                 => '' !== $s_city ? $s_city : $billing['city'],
			'district'             => '' !== $s_dist ? $s_dist : $billing['district'],
			'zip_code'             => (string) ( $order->get_shipping_postcode() ?: $billing['zip_code'] ),
		);
		$created = $order->get_date_created();
		$channel = trim( (string) Eru_DPG_Settings::get( 'sales_channel' ) );

		$payload = array(
			'service'          => 1,
			'service_name'     => 'WooCommerce',
			'sales_channel'    => '' !== $channel ? $channel : ( get_bloginfo( 'name' ) ?: 'WooCommerce' ),
			'service_created'  => $created ? $created->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
			'service_value'    => (string) $order->get_order_number(),
			'service_order_id' => (string) $order->get_id(),
			'customer'         => array(
				'account_type' => $tax['account_type'],
				'full_name'    => $name,
				'email'        => (string) $order->get_billing_email(),
				'phone_number' => $tel,
				'tax_id'       => $tid,
				'tax_office'   => $corp ? $tax['tax_office'] : null,
				'company_name' => $corp ? ( '' !== $tax['company'] ? $tax['company'] : $name ) : null,
				'address'      => $ship_addr,
			),
			'billing_address'  => $billing,
			'shipping_address' => $ship_addr,
			'payment_type'     => (string) apply_filters( 'eru_dpg_order_payment_type', 'undefined', $order ),
			'status'           => $status,
			'total'            => number_format( (float) $order->get_total(), 2, '.', '' ),
			'service_fee'      => number_format( $shipping, 2, '.', '' ),
			'discount'         => number_format( (float) $order->get_total_discount( false ), 2, '.', '' ),
			'archived'         => false,
			'notes'            => (string) $order->get_customer_note(),
			'items'            => $items,
		);
		return (array) apply_filters( 'eru_dpg_order_payload', $payload, $order );
	}

	/** @param WC_Order_Item_Product $item Item. @return string Visible item meta as "Label: value, …". */
	private static function item_attributes( WC_Order_Item_Product $item ) {
		$parts = array();
		foreach ( $item->get_formatted_meta_data( '_', true ) as $m ) {
			$parts[] = wp_strip_all_tags( $m->display_key ) . ': ' . trim( wp_strip_all_tags( $m->display_value ) );
		}
		return implode( ', ', $parts );
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array{account_type:string,tax_id:string,tax_office:string,company:string}
	 */
	private static function customer_tax_info( WC_Order $order ) {
		$company = (string) $order->get_billing_company();
		$info    = array(
			'account_type' => '' !== $company ? 'company' : 'person',
			'tax_id'       => (string) $order->get_meta( '_billing_tax_number' ),
			'tax_office'   => (string) $order->get_meta( '_billing_tax_office' ),
			'company'      => $company,
		);
		return (array) apply_filters( 'eru_dpg_order_customer_tax_info', $info, $order ) + $info;
	}

	/**
	 * Turkish number in the form Dopigo accepts: "+90" followed by the last 10 digits (country code, leading 0,
	 * spaces and brackets are dropped first). Shorter input is returned as digits; empty stays empty.
	 *
	 * @param string $raw Raw phone.
	 * @return string
	 */
	public static function normalize_phone( $raw ) {
		$d = preg_replace( '/\D+/', '', (string) $raw );
		return strlen( $d ) >= 10 ? '+90' . substr( $d, -10 ) : $d;
	}

	/**
	 * @param string $country Country.
	 * @param string $state   WC state code (TR35).
	 * @return string Province name (İzmir), or the raw value.
	 */
	public static function province_name( $country, $state ) {
		$state = trim( (string) $state );
		if ( '' === $state ) {
			return '';
		}
		$states = WC()->countries ? WC()->countries->get_states( $country ?: 'TR' ) : array();
		return is_array( $states ) && ! empty( $states[ $state ] ) ? html_entity_decode( (string) $states[ $state ], ENT_QUOTES, 'UTF-8' ) : $state;
	}
}
