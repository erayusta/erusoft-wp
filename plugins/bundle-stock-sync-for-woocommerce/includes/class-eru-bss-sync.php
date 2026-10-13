<?php
/**
 * Stock synchronisation hooks.
 *
 * Recursion guard: a bundle is only recalculated once at a time (`$running`) and the
 * depth is capped; bundles cannot contain bundles, so a bundle save never fans out again.
 *
 * @package bundle-stock-sync-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BSS_Sync {

	const META_REDUCED = '_eru_bss_reduced';

	/** @var array<int,bool> */
	private static $running = array();
	/** @var int */
	private static $depth = 0;

	public static function init() {
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_component_change' ), 20 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_component_change' ), 20 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_component_change_id' ), 20 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_component_change_id' ), 20 );
		add_action( 'woocommerce_delete_product', array( __CLASS__, 'on_component_change_id' ), 20 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_id' ), 20 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post_id' ), 20 );
		add_action( 'woocommerce_reduce_order_item_stock', array( __CLASS__, 'reduce_components' ), 20, 2 );
		add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'restore_components' ), 20, 1 );
		add_action( 'eru_bss_retry', array( __CLASS__, 'recalc' ) );
		add_action( 'woocommerce_restock_refunded_item', array( __CLASS__, 'restock_refunded' ), 20, 4 );
	}

	/* ---- component change -> recalc bundles ---- */

	public static function on_component_change( $product ) {
		if ( $product instanceof WC_Product ) {
			self::recalc_for_product( $product );
		}
	}

	public static function on_component_change_id( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			self::recalc_for_product( $product );
		}
	}

	public static function on_post_id( $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			self::on_component_change_id( $post_id );
		}
	}

	public static function on_transition( $new, $old, $post ) {
		if ( $new !== $old && $post instanceof WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			self::on_component_change_id( $post->ID );
		}
	}

	private static function recalc_for_product( WC_Product $product ) {
		if ( self::$depth > 0 ) {
			return; // Changes made by this plugin never cascade.
		}
		$ids = array( $product->get_id() );
		if ( $product->is_type( 'variable' ) ) {
			$ids = array_merge( $ids, $product->get_children() );
		} elseif ( $product->is_type( 'variation' ) ) {
			$ids[] = $product->get_parent_id();
		}
		foreach ( Eru_BSS_Bundles::bundles_containing( $ids ) as $bundle_id ) {
			self::recalc( $bundle_id );
		}
	}

	/**
	 * Recalculate and store one bundle's stock. Returns the new quantity (null = unlimited) or false.
	 */
	public static function recalc( $bundle_id ) {
		$bundle_id = (int) $bundle_id;
		if ( isset( self::$running[ $bundle_id ] ) || self::$depth >= (int) apply_filters( 'eru_bss_max_depth', 1 ) ) {
			return false;
		}
		$bundle = wc_get_product( $bundle_id );
		$avail  = $bundle ? Eru_BSS_Bundles::availability( $bundle_id ) : null;
		if ( ! $bundle || null === $avail ) {
			return false;
		}
		if ( ! self::lock( $bundle_id ) ) {
			self::defer( $bundle_id ); // Another process holds the bundle: try again shortly.
			return false;
		}
		self::$running[ $bundle_id ] = true;
		++self::$depth;
		try {
			wp_cache_delete( $bundle_id, 'post_meta' );
			$bundle = wc_get_product( $bundle_id );
			// Component stock may have moved while we waited for the lock: read it again right before saving.
			do_action( 'eru_bss_before_save', $bundle_id, $avail );
			$avail        = Eru_BSS_Bundles::availability( $bundle_id, true );
			$was_in_stock = 'outofstock' !== $bundle->get_stock_status();
			if ( null === $avail['qty'] ) {
				$bundle->set_manage_stock( false );
				$bundle->set_stock_status( 'instock' );
			} else {
				$bundle->set_manage_stock( true );
				$bundle->set_stock_quantity( $avail['qty'] );
				$bundle->set_stock_status( $avail['qty'] > 0 ? 'instock' : 'outofstock' );
			}
			if ( $bundle->get_changes() ) {
				$bundle->save();
				if ( $was_in_stock && 'outofstock' === $bundle->get_stock_status() ) {
					self::notify( $bundle );
				}
			}
		} finally {
			--self::$depth;
			unset( self::$running[ $bundle_id ] );
			self::unlock( $bundle_id );
		}
		return $avail['qty'];
	}

	/** @var array<string,string> Tokens of the locks this process holds. */
	private static $tokens = array();

	/**
	 * Short per-bundle lock: INSERT IGNORE on the unique option_name with a random token;
	 * expired locks are taken over by compare-and-swap; release is compare-and-delete.
	 */
	private static function lock( $bundle_id ) {
		global $wpdb;
		$name = 'eru_bss_lock_' . $bundle_id;
		$ttl  = (int) apply_filters( 'eru_bss_lock_seconds', 30 );
		for ( $try = 0; $try < 10; $try++ ) {
			$now   = time();
			$token = wp_generate_password( 16, false ) . '|' . $now;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token ) ) ) {
				self::$tokens[ $name ] = $token;
				return true;
			}
			$held  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$parts = explode( '|', (string) $held );
			if ( null === $held || ( $now - (int) end( $parts ) ) >= $ttl ) {
				if ( 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, (string) $held ) ) ) {
					self::$tokens[ $name ] = $token;
					return true;
				}
			}
			// phpcs:enable
			usleep( 50000 );
		}
		return false;
	}

	private static function unlock( $bundle_id ) {
		global $wpdb;
		$name = 'eru_bss_lock_' . $bundle_id;
		if ( isset( self::$tokens[ $name ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, self::$tokens[ $name ] ) );
			unset( self::$tokens[ $name ] );
		}
	}

	/** One deferred retry per bundle (pending-only dedupe) when the lock could not be had. */
	private static function defer( $bundle_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => 'eru_bss_retry',
				'args'     => array( (int) $bundle_id ),
				'group'    => 'eru-bss',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( ! $pending ) {
			as_schedule_single_action( time() + (int) apply_filters( 'eru_bss_retry_delay', 30 ), 'eru_bss_retry', array( (int) $bundle_id ), 'eru-bss' );
		}
	}

	private static function notify( WC_Product $bundle ) {
		$opt = get_option( 'eru_bss_settings', array() );
		if ( empty( $opt['notify'] ) ) {
			return;
		}
		$to = ! empty( $opt['email'] ) && is_email( $opt['email'] ) ? $opt['email'] : get_option( 'admin_email' );
		/* translators: %s: bundle name */
		$subject = sprintf( __( 'Paket stokta kalmadı: %s', 'bundle-stock-sync-for-woocommerce' ), $bundle->get_name() );
		/* translators: 1: bundle name, 2: SKU */
		$body = sprintf( __( '"%1$s" (SKU: %2$s) paketi, bileşenlerinin stoğu bittiği veya yayından kalktığı için stokta yok olarak işaretlendi.', 'bundle-stock-sync-for-woocommerce' ), $bundle->get_name(), $bundle->get_sku() ? $bundle->get_sku() : '#' . $bundle->get_id() );
		wp_mail( $to, $subject, $body );
	}

	/* ---- order flow ---- */

	/**
	 * A bundle line item had its own stock reduced: reduce the components too.
	 *
	 * @param WC_Order_Item_Product $item   Line item.
	 * @param array                 $change from/to of the bundle.
	 */
	public static function reduce_components( $item, $change ) {
		$bundle_id = $item->get_product_id();
		$variation = $item->get_variation_id();
		$bundle_id = $variation ? $variation : $bundle_id;
		if ( ! Eru_BSS_Bundles::is_bundle( $bundle_id ) || $item->get_meta( self::META_REDUCED ) ) {
			return;
		}
		$units = (int) round( (float) ( $change['from'] - $change['to'] ) );
		if ( $units < 1 ) {
			return;
		}
		$snapshot = array();
		foreach ( Eru_BSS_Bundles::resolve( $bundle_id ) as $r ) {
			if ( $r['issue'] || ! $r['product'] || ! $r['product']->managing_stock() ) {
				continue;
			}
			$snapshot[ $r['id'] ] = $r['qty'];
		}
		++self::$depth; // The component writes below must not trigger recalculation one by one.
		try {
			foreach ( $snapshot as $id => $per ) {
				wc_update_product_stock( wc_get_product( $id ), $units * $per, 'decrease' );
			}
		} finally {
			--self::$depth;
		}
		$item->update_meta_data(
			self::META_REDUCED,
			array(
				'units' => $units,
				'comps' => $snapshot,
			)
		);
		$item->save();
		self::recalc( $bundle_id );
	}

	/** Order cancelled / back to pending: give the components back. */
	public static function restore_components( $item ) {
		$snap = $item->get_meta( self::META_REDUCED );
		if ( ! is_array( $snap ) || empty( $snap['units'] ) ) {
			return;
		}
		self::give_back( $item, (int) $snap['units'], $snap );
	}

	/** Refund with "restock items": give back the refunded quantity. */
	public static function restock_refunded( $product_id, $old_stock, $new_stock, $order ) {
		$qty = (int) round( (float) $new_stock - (float) $old_stock );
		if ( $qty < 1 || ! $order instanceof WC_Order || ! Eru_BSS_Bundles::is_bundle( $product_id ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$id   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$snap = $item->get_meta( self::META_REDUCED );
			if ( (int) $id === (int) $product_id && is_array( $snap ) && ! empty( $snap['units'] ) ) {
				self::give_back( $item, min( $qty, (int) $snap['units'] ), $snap );
				return;
			}
		}
	}

	private static function give_back( $item, $units, array $snap ) {
		++self::$depth;
		try {
			foreach ( (array) $snap['comps'] as $id => $per ) {
				$comp = wc_get_product( (int) $id );
				if ( $comp && $comp->managing_stock() ) {
					wc_update_product_stock( $comp, $units * (int) $per, 'increase' );
				}
			}
		} finally {
			--self::$depth;
		}
		$left = (int) $snap['units'] - $units;
		if ( $left > 0 ) {
			$snap['units'] = $left;
			$item->update_meta_data( self::META_REDUCED, $snap );
		} else {
			$item->delete_meta_data( self::META_REDUCED );
		}
		$item->save();
		$bundle_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		self::recalc( $bundle_id );
	}
}
