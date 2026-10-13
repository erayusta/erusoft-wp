<?php
/** Runtime price filters, optional USD note, and stored-price sync (for sorting/filtering). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_USD_Pricing {

	/**
	 * 998: converts the base price first; Role-Based Prices (999), Price Lists (1000) and
	 * B2B Customer Pricing (1001) then override it for their users.
	 */
	const PRIORITY = 998;

	public static function init(): void {
		foreach ( array( 'woocommerce_product_get_', 'woocommerce_product_variation_get_' ) as $prefix ) {
			add_filter( $prefix . 'price', array( __CLASS__, 'price' ), self::PRIORITY, 2 );
			add_filter( $prefix . 'regular_price', array( __CLASS__, 'regular_price' ), self::PRIORITY, 2 );
			add_filter( $prefix . 'sale_price', array( __CLASS__, 'sale_price' ), self::PRIORITY, 2 );
		}
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'variation_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'variation_regular' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'variation_sale' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'prices_hash' ) );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );

		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'sync_after_save' ), 30 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'sync_after_save' ), 30 );
		add_action( 'eru_usd_rate_changed', array( __CLASS__, 'schedule_resync' ) );
		add_action( 'eru_usd_resync', array( __CLASS__, 'resync_batch' ) );
		add_action( Eru_USD_Rate::CRON, array( 'Eru_USD_Rate', 'cron_refresh' ) );
	}

	/** @return array{0:float,1:float}|null [usd regular, usd sale] or null when the product is not USD-priced. */
	private static function usd( $product ): ?array {
		if ( ! $product instanceof WC_Product || Eru_USD_Rate::effective() <= 0 ) {
			return null;
		}
		$regular = (float) $product->get_meta( '_eru_usd_price', true, 'edit' );
		if ( $regular <= 0 ) {
			return null;
		}
		$sale = (float) $product->get_meta( '_eru_usd_sale_price', true, 'edit' );
		return array( $regular, ( $sale > 0 && $sale < $regular ) ? $sale : 0.0 );
	}

	private static function to_try( float $usd, $product ): string {
		$rate = Eru_USD_Rate::effective();
		$try  = round( $usd * $rate, wc_get_price_decimals() );
		/**
		 * Filters the calculated store-currency price.
		 *
		 * @param float      $try     Calculated price.
		 * @param float      $usd     USD price.
		 * @param float      $rate    Effective rate (markup included).
		 * @param WC_Product $product Product.
		 */
		return (string) apply_filters( 'eru_usd_try_price', $try, $usd, $rate, $product );
	}

	public static function regular_price( $value, $product ) {
		$u = self::usd( $product );
		return $u ? self::to_try( $u[0], $product ) : $value;
	}

	public static function sale_price( $value, $product ) {
		$u = self::usd( $product );
		return $u ? ( $u[1] > 0 ? self::to_try( $u[1], $product ) : '' ) : $value;
	}

	public static function price( $value, $product ) {
		// A price set at runtime (gift line, bundle child…) wins.
		if ( $product instanceof WC_Product && array_key_exists( 'price', $product->get_changes() ) ) {
			return $value;
		}
		$u = self::usd( $product );
		return $u ? self::to_try( $u[1] > 0 ? $u[1] : $u[0], $product ) : $value;
	}

	public static function variation_price( $value, $variation ) {
		return self::price( $value, $variation );
	}

	public static function variation_regular( $value, $variation ) {
		return self::regular_price( $value, $variation );
	}

	public static function variation_sale( $value, $variation ) {
		return self::sale_price( $value, $variation );
	}

	/** Make WooCommerce's cached variation price ranges depend on the rate. */
	public static function prices_hash( $hash ) {
		$hash   = is_array( $hash ) ? $hash : array();
		$hash[] = 'eru_usd:' . Eru_USD_Rate::effective();
		return $hash;
	}

	public static function price_html( $html, $product ) {
		if ( ! get_option( 'eru_usd_show_usd' ) ) {
			return $html;
		}
		$u = self::usd( $product );
		if ( ! $u ) {
			return $html;
		}
		return $html . ' <small class="eru-usd-note">($' . esc_html( number_format_i18n( $u[1] > 0 ? $u[1] : $u[0], 2 ) ) . ')</small>';
	}

	/* ---------------------------------------------------------------- stored-price sync */

	public static function sync_after_save( $id ): void {
		self::sync( (int) $id );
	}

	/** Write the calculated prices into the stored meta so price sorting/filtering match. */
	public static function sync( int $id ): void {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return;
		}
		$u = self::usd( $product );
		if ( ! $u ) {
			// No USD price (any more): put back the TRY price saved before the first sync,
			// unless the merchant typed a new TRY price in the meantime.
			if ( ! metadata_exists( 'post', $id, '_eru_usd_orig_regular' ) || '' !== (string) $product->get_meta( '_eru_usd_price', true, 'edit' ) ) {
				return;
			}
			if ( (string) get_post_meta( $id, '_regular_price', true ) === (string) get_post_meta( $id, '_eru_usd_synced_regular', true ) ) {
				$regular = (string) get_post_meta( $id, '_eru_usd_orig_regular', true );
				$sale    = (string) get_post_meta( $id, '_eru_usd_orig_sale', true );
				update_post_meta( $id, '_regular_price', $regular );
				update_post_meta( $id, '_sale_price', $sale );
				update_post_meta( $id, '_price', '' !== $sale ? $sale : $regular );
			}
			foreach ( array( '_eru_usd_orig_regular', '_eru_usd_orig_sale', '_eru_usd_synced_regular' ) as $key ) {
				delete_post_meta( $id, $key );
			}
			self::after_sync( $id, $product );
			return;
		}
		if ( ! metadata_exists( 'post', $id, '_eru_usd_orig_regular' ) ) {
			update_post_meta( $id, '_eru_usd_orig_regular', (string) get_post_meta( $id, '_regular_price', true ) );
			update_post_meta( $id, '_eru_usd_orig_sale', (string) get_post_meta( $id, '_sale_price', true ) );
		}
		$regular = self::to_try( $u[0], $product );
		$sale    = $u[1] > 0 ? self::to_try( $u[1], $product ) : '';
		update_post_meta( $id, '_regular_price', $regular );
		update_post_meta( $id, '_sale_price', $sale );
		update_post_meta( $id, '_price', '' !== $sale ? $sale : $regular );
		update_post_meta( $id, '_eru_usd_synced_regular', $regular );
		self::after_sync( $id, $product );
	}

	private static function after_sync( int $id, WC_Product $product ): void {
		if ( $product->get_data_store() && method_exists( $product->get_data_store(), 'update_lookup_table' ) ) {
			$product->get_data_store()->update_lookup_table( $id, 'wc_product_meta_lookup' );
		}
		wc_delete_product_transients( $id );
		if ( $product->get_parent_id() ) {
			WC_Product_Variable::sync( $product->get_parent_id() );
		}
	}

	public static function schedule_resync(): void {
		if ( ! wp_next_scheduled( 'eru_usd_resync', array( 0 ) ) ) {
			wp_schedule_single_event( time() + 5, 'eru_usd_resync', array( 0 ) );
		}
	}

	/** Re-sync stored prices in chunks of 50 after the rate changed. */
	public static function resync_batch( $offset = 0 ): void {
		global $wpdb;
		$offset = max( 0, (int) $offset );
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id LIMIT 50 OFFSET %d", '_eru_usd_price', $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			self::sync( (int) $id );
		}
		if ( count( $ids ) === 50 ) {
			wp_schedule_single_event( time() + 5, 'eru_usd_resync', array( $offset + 50 ) );
		}
	}
}
