<?php
/**
 * Personal data: WordPress privacy exporter and eraser for what this plugin stores about a buyer.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looked up by the billing e-mail of marketplace orders (_eru_mpo_marketplace), 20 orders per page, plus raw
 * payload rows whose JSON holds the e-mail as a value (also when the order is gone).
 *
 * Export: marketplace, order and package numbers, the identity number (opt-in "e-Arşiv" copy) and an 11-digit tax
 * number masked (first 2 + last 2 digits), the tax office, and the stored raw payload (already masked).
 * Erase: the identity number meta, an 11-digit (personal) tax number, the raw payload rows and the log rows of
 * those packages; on orders of the older Erusoft Trendyol/Hepsiburada plugins (migrated or not) also their identity
 * number, 11-digit tax number and stored raw payload meta. The order itself, its addresses and notes follow
 * WooCommerce's own eraser setting ("Kişisel verileri siparişlerden kaldır"); this plugin never deletes orders.
 *
 * Order of erasers: WooCommerce anonymises the billing e-mail of the orders it erases, after which they can no
 * longer be found by e-mail. This eraser is therefore registered ahead of WooCommerce's (priority 5), and the same
 * clean-up also runs on woocommerce_privacy_remove_order_personal_data, i.e. whenever WooCommerce erases an order
 * (also outside an erasure request).
 */
class Eru_MPO_Privacy {

	const KEY      = 'marketplace-orders-for-woocommerce';
	const PER_PAGE = 20;
	/** Meta always removed on erase (identity numbers, legacy raw payloads). */
	const REMOVE = array( '_eru_mpo_identity_number', '_eru_trendyol_identity_number', '_eru_hepsiburada_identity_number', '_eru_trendyol_raw', '_eru_hepsiburada_raw' );
	/** Tax number meta removed when it is an 11-digit identity number. */
	const TAX = array( '_eru_mpo_tax_number', '_eru_trendyol_tax_number', '_eru_hepsiburada_tax_number' );
	/** Legacy reference meta => marketplace. */
	const LEGACY_REF = array(
		'_eru_trendyol_package_id'      => 'trendyol',
		'_eru_hepsiburada_order_number' => 'hepsiburada',
	);

	/** Hooks. */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ), 5 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( __CLASS__, 'on_wc_order_erase' ) );
	}

	/** @param array $list Exporters. @return array */
	public static function exporters( $list ) {
		$list[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Pazaryeri siparişleri', 'marketplace-orders-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	/** @param array $list Erasers. @return array */
	public static function erasers( $list ) {
		$list[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Pazaryeri siparişleri', 'marketplace-orders-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** @param string $v Number. @return string First 2 + stars + last 2. */
	public static function mask( $v ) {
		$v = (string) $v;
		return strlen( $v ) > 4 ? substr( $v, 0, 2 ) . str_repeat( '*', strlen( $v ) - 4 ) . substr( $v, -2 ) : str_repeat( '*', strlen( $v ) );
	}

	/** @return int[] Marketplace orders of the e-mail (one page). */
	private static function order_ids( $email, $page ) {
		if ( ! is_email( $email ) ) {
			return array();
		}
		return array_map(
			'intval',
			wc_get_orders(
				array(
					'billing_email' => $email,
					'type'          => 'shop_order',
					'status'        => array_keys( wc_get_order_statuses() ),
					'limit'         => self::PER_PAGE,
					'page'          => max( 1, (int) $page ),
					'orderby'       => 'ID',
					'order'         => 'ASC',
					'return'        => 'ids',
					'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						'relation' => 'OR',
						array(
							'key'     => '_eru_mpo_marketplace',
							'compare' => 'EXISTS',
						),
						array(
							'key'     => '_eru_trendyol_package_id',
							'compare' => 'EXISTS',
						),
						array(
							'key'     => '_eru_hepsiburada_order_number',
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
	}

	/** @param WC_Order $o Order. @return string Marketplace key (also for an unmigrated legacy order). */
	private static function mp_of( $o ) {
		$mp = (string) $o->get_meta( '_eru_mpo_marketplace' );
		foreach ( self::LEGACY_REF as $k => $legacy ) {
			if ( '' === $mp && '' !== (string) $o->get_meta( $k ) ) {
				$mp = $legacy;
			}
		}
		return $mp;
	}

	/** @param WC_Order $o Order. @return string[] Every package reference of the order. */
	private static function refs( $o ) {
		$refs = array( (string) $o->get_meta( '_eru_mpo_remote_ref' ) );
		foreach ( (array) $o->get_meta( '_eru_mpo_packages' ) as $p ) {
			$refs[] = (string) $p;
		}
		foreach ( array_keys( self::LEGACY_REF ) as $k ) {
			$refs[] = (string) $o->get_meta( $k );
		}
		return array_values( array_unique( array_filter( $refs ) ) );
	}

	/**
	 * Removes this plugin's (and the legacy plugins') identity data from one order: identity numbers, 11-digit tax
	 * numbers, legacy raw payload meta, and the raw and log rows of its packages.
	 *
	 * @param WC_Order $o Order.
	 * @return bool Something was removed.
	 */
	public static function erase_order( $o ) {
		if ( ! $o instanceof WC_Order ) {
			return false;
		}
		$changed = false;
		foreach ( self::REMOVE as $k ) {
			if ( '' !== (string) $o->get_meta( $k ) ) {
				$o->delete_meta_data( $k );
				$changed = true;
			}
		}
		foreach ( self::TAX as $k ) {
			if ( Eru_MPO_Builder::is_identity_like( (string) $o->get_meta( $k ) ) ) {
				$o->delete_meta_data( $k );
				$changed = true;
			}
		}
		if ( $changed ) {
			$o->save_meta_data();
		}
		$mp = self::mp_of( $o );
		if ( '' !== $mp ) {
			foreach ( self::refs( $o ) as $ref ) {
				$changed = self::delete_package_rows( $mp, $ref ) > 0 || $changed;
			}
		}
		return $changed;
	}

	/** woocommerce_privacy_remove_order_personal_data: WooCommerce erased an order. @param WC_Order $order Order. */
	public static function on_wc_order_erase( $order ) {
		if ( $order instanceof WC_Order && ( '' !== self::mp_of( $order ) ) ) {
			self::erase_order( $order );
		}
	}

	/** @return object[] Raw rows (marketplace, remote_ref, payload) that hold the e-mail as a JSON value. */
	private static function raw_rows_by_email( $email ) {
		global $wpdb;
		if ( ! is_email( $email ) ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( wp_json_encode( (string) $email ) ) . '%';
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT marketplace, remote_ref, payload FROM ' . Eru_MPO_Installer::table( 'raw' ) . ' WHERE payload LIKE %s ORDER BY id ASC LIMIT 200', $like ) ); // phpcs:ignore
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array{data: array, done: bool}
	 */
	public static function export( $email, $page = 1 ) {
		$data = array();
		$seen = array();
		$ids  = self::order_ids( $email, $page );
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			$mp    = self::mp_of( $o );
			$a     = Eru_MPO_Registry::get( $mp );
			$items = array(
				array(
					'name'  => __( 'Pazaryeri', 'marketplace-orders-for-woocommerce' ),
					'value' => $a ? $a->label() : $mp,
				),
				array(
					'name'  => __( 'Pazaryeri sipariş numarası', 'marketplace-orders-for-woocommerce' ),
					'value' => (string) $o->get_meta( '_eru_mpo_order_number' ),
				),
				array(
					'name'  => __( 'Paket', 'marketplace-orders-for-woocommerce' ),
					'value' => (string) $o->get_meta( '_eru_mpo_remote_ref' ),
				),
			);
			$idn = '';
			foreach ( array( '_eru_mpo_identity_number', '_eru_trendyol_identity_number', '_eru_hepsiburada_identity_number' ) as $ik ) {
				$idn = '' !== $idn ? $idn : (string) $o->get_meta( $ik );
			}
			if ( '' !== $idn ) {
				$items[] = array(
					'name'  => __( 'TC kimlik no (maskeli)', 'marketplace-orders-for-woocommerce' ),
					'value' => self::mask( $idn ),
				);
			}
			$tax = (string) $o->get_meta( '_eru_mpo_tax_number' );
			if ( '' !== $tax ) {
				$items[] = array(
					'name'  => __( 'Vergi / TC kimlik no (maskeli)', 'marketplace-orders-for-woocommerce' ),
					'value' => self::mask( $tax ),
				);
			}
			$office = (string) $o->get_meta( '_eru_mpo_tax_office' );
			if ( '' !== $office ) {
				$items[] = array(
					'name'  => __( 'Vergi dairesi', 'marketplace-orders-for-woocommerce' ),
					'value' => $office,
				);
			}
			foreach ( self::refs( $o ) as $ref ) {
				$seen[ $mp . '|' . $ref ] = true;
				$raw                      = Eru_MPO_Raw::get( $mp, $ref );
				if ( is_array( $raw ) ) {
					$items[] = array(
						/* translators: %s: package reference */
						'name'  => sprintf( __( 'Pazaryerinden gelen ham veri (%s)', 'marketplace-orders-for-woocommerce' ), $ref ),
						'value' => (string) wp_json_encode( $raw, JSON_UNESCAPED_UNICODE ),
					);
				}
			}
			$data[] = array(
				'group_id'    => 'eru-mpo-orders',
				'group_label' => __( 'Pazaryeri siparişleri', 'marketplace-orders-for-woocommerce' ),
				'item_id'     => 'eru-mpo-order-' . $id,
				'data'        => $items,
			);
		}
		if ( 1 === (int) $page ) {
			foreach ( self::raw_rows_by_email( $email ) as $r ) {
				if ( isset( $seen[ $r->marketplace . '|' . $r->remote_ref ] ) ) {
					continue;
				}
				$o = Eru_MPO_Importer::find_order_by_ref( $r->marketplace, $r->remote_ref );
				$o = $o ? wc_get_order( $o ) : null;
				if ( $o && strtolower( (string) $o->get_billing_email() ) === strtolower( (string) $email ) ) {
					continue; // Exported with its order (another page).
				}
				$data[] = array(
					'group_id'    => 'eru-mpo-raw',
					'group_label' => __( 'Pazaryeri ham verisi', 'marketplace-orders-for-woocommerce' ),
					'item_id'     => 'eru-mpo-raw-' . md5( $r->marketplace . '|' . $r->remote_ref ),
					'data'        => array(
						array(
							'name'  => __( 'Paket', 'marketplace-orders-for-woocommerce' ),
							'value' => $r->remote_ref,
						),
						array(
							'name'  => __( 'Ham veri', 'marketplace-orders-for-woocommerce' ),
							'value' => (string) $r->payload,
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/** Deletes the raw and log rows of one package. @return int Rows removed. */
	private static function delete_package_rows( $mp, $ref ) {
		global $wpdb;
		$n  = (int) $wpdb->delete( Eru_MPO_Installer::table( 'raw' ), array( 'marketplace' => $mp, 'remote_ref' => $ref ) ); // phpcs:ignore
		$n += (int) $wpdb->delete( Eru_MPO_Installer::table( 'log' ), array( 'marketplace' => $mp, 'remote_ref' => $ref ) ); // phpcs:ignore
		return $n;
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email, $page = 1 ) {
		$removed = false;
		$msgs    = array();
		$ids     = self::order_ids( $email, $page );
		foreach ( $ids as $id ) {
			$removed = self::erase_order( wc_get_order( $id ) ) || $removed;
		}
		if ( 1 === (int) $page ) {
			foreach ( self::raw_rows_by_email( $email ) as $r ) {
				$removed = self::delete_package_rows( $r->marketplace, $r->remote_ref ) > 0 || $removed;
			}
		}
		if ( $removed ) {
			$msgs[] = __( 'Pazaryeri ham verisi, günlük kayıtları ve kimlik numarası silindi. Siparişin kendisi WooCommerce\'in kişisel veri ayarına göre işlenir.', 'marketplace-orders-for-woocommerce' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $msgs,
			'done'           => count( $ids ) < self::PER_PAGE,
		);
	}
}
