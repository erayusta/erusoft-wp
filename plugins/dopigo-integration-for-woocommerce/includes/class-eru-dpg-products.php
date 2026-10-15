<?php
/**
 * Stock and price sync: Woo→Dopigo updates (queued per product), the budgeted Dopigo→Woo pull, VAT and SKU helpers.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matching: a WC unit (simple product or variation) is the Dopigo variant whose id is stored in
 * _eru_dpg_variant_id, else whose foreign_sku (then sku) equals the WC SKU.
 */
class Eru_DPG_Products {

	const META_VARIANT = '_eru_dpg_variant_id';
	const META_PRODUCT = '_eru_dpg_product_id';
	const PULL_STATE   = 'eru_dpg_pull_state';

	/** @var self|null */
	private static $instance;

	/** @var bool True while Dopigo data is written into WooCommerce (stops the echo back). */
	private static $suppress = false;

	/** @var Eru_DPG_API|null */
	private $api;

	/** @return self */
	public static function instance() {
		return self::$instance ?: ( self::$instance = new self() );
	}

	/** @return Eru_DPG_API */
	private function api() {
		return $this->api ?: ( $this->api = new Eru_DPG_API() );
	}

	/** Hooks (callbacks check the settings each time). */
	public function init() {
		add_action( 'woocommerce_update_product', array( $this, 'on_saved' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'on_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_saved' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_saved' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_saved' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_saved' ), 20, 1 );
	}

	/** @param callable $cb Work that writes Dopigo data into WooCommerce. @return mixed */
	public static function without_echo( callable $cb ) {
		$prev           = self::$suppress;
		self::$suppress = true;
		try {
			return $cb();
		} finally {
			self::$suppress = $prev;
		}
	}

	/** @return bool */
	public static function suppressed() {
		return self::$suppress;
	}

	/** @return bool Some Woo→Dopigo product direction is on. */
	public static function pushes() {
		return Eru_DPG_Settings::is_enabled() && ( Eru_DPG_Settings::allows( 'stock_direction', 'wc_to_dp' ) || Eru_DPG_Settings::allows( 'price_direction', 'wc_to_dp' ) );
	}

	/**
	 * Product/variation saved or stock changed: queue one push per product (or send now when the queue is off).
	 *
	 * @param int|WC_Product $product Product or id.
	 */
	public function on_saved( $product ) {
		if ( self::$suppress || ! self::pushes() ) {
			return;
		}
		$p = $product instanceof WC_Product ? $product : wc_get_product( $product );
		if ( ! $p || $p->is_type( 'variable' ) || '' === $p->get_sku() ) {
			return;
		}
		if ( Eru_DPG_Settings::on( 'queue_updates' ) ) {
			Eru_DPG_Cron::enqueue( 'eru_dpg_push_product', array( $p->get_id() ), 30 );
			return;
		}
		$r = $this->push_unit( $p );
		if ( Eru_DPG_API::is_retryable( $r ) ) {
			Eru_DPG_Cron::retry( 'eru_dpg_push_product', array( $p->get_id() ), $r, $p->get_sku() );
		}
	}

	/** Action Scheduler callback. @param int $id Product id. */
	public static function run_push_job( $id ) {
		$p = wc_get_product( (int) $id );
		if ( ! $p || $p->is_type( 'variable' ) ) {
			return;
		}
		$r = self::instance()->push_unit( $p );
		if ( Eru_DPG_API::is_retryable( $r ) ) {
			Eru_DPG_Cron::retry( 'eru_dpg_push_product', array( (int) $id ), $r, $p->get_sku() );
			return;
		}
		Eru_DPG_Cron::reset_attempts( 'eru_dpg_push_product', array( (int) $id ) );
	}

	/**
	 * Sends one unit's stock and/or price to Dopigo by foreign SKU (= WC SKU).
	 *
	 * @param WC_Product $p Unit.
	 * @return string|WP_Error updated | noop | dry-run, or the error.
	 */
	public function push_unit( WC_Product $p ) {
		$sku = (string) $p->get_sku();
		if ( ! self::pushes() || '' === $sku ) {
			return 'noop';
		}
		$payload = array();
		if ( Eru_DPG_Settings::allows( 'stock_direction', 'wc_to_dp' ) && $p->managing_stock() ) {
			$payload['stock'] = max( 0, (int) $p->get_stock_quantity() );
		}
		if ( Eru_DPG_Settings::allows( 'price_direction', 'wc_to_dp' ) ) {
			$vat   = self::vat_rate( $p );
			$price = '' !== $p->get_price() ? (float) $p->get_price() : (float) $p->get_regular_price();
			$reg   = '' !== $p->get_regular_price() ? (float) $p->get_regular_price() : $price;
			if ( $price > 0 ) {
				$payload['price']         = number_format( self::wc_to_dopigo( $price, $vat ), 2, '.', '' );
				$payload['listing_price'] = number_format( self::wc_to_dopigo( $reg, $vat ), 2, '.', '' );
			}
		}
		$payload = (array) apply_filters( 'eru_dpg_product_payload', $payload, $p );
		if ( ! $payload ) {
			return 'noop';
		}
		$r = $this->api()->update_by_foreign_sku( $sku, $payload );
		if ( is_wp_error( $r ) ) {
			Eru_DPG_Logger::log( 'product', Eru_DPG_API::is_retryable( $r ) ? 'warning' : 'failed', __( 'Woo → Dopigo güncelleme hatası: ', 'dopigo-integration-for-woocommerce' ) . $r->get_error_message(), array( 'payload' => $payload ), 'wc_to_dp', $sku );
			return $r;
		}
		if ( ! empty( $r['dry_run'] ) ) {
			Eru_DPG_Logger::log( 'product', 'info', __( 'Kuru çalışma: Dopigo\'ya gönderilmedi.', 'dopigo-integration-for-woocommerce' ), array( 'payload' => $payload ), 'wc_to_dp', $sku );
			return 'dry-run';
		}
		Eru_DPG_Logger::log( 'product', 'success', __( 'Woo → Dopigo stok/fiyat gönderildi.', 'dopigo-integration-for-woocommerce' ), array( 'payload' => $payload ), 'wc_to_dp', $sku );
		return 'updated';
	}

	// -------------------------------------------------------------------------------------------
	// Dopigo → Woo.
	// -------------------------------------------------------------------------------------------

	/** @return array Pull cursor state. */
	public static function pull_state() {
		$s = get_option( self::PULL_STATE, array() );
		return array_merge(
			array(
				'offset'    => 0,
				'last_run'  => 0,
				'last_done' => 0,
				'last'      => array(),
			),
			is_array( $s ) ? $s : array()
		);
	}

	/**
	 * Walks Dopigo catalogue pages from the stored cursor, within a time and page budget.
	 *
	 * @param int      $budget    Seconds.
	 * @param int      $page_size Meta products per page.
	 * @param int|null $max_pages Pages (default: setting).
	 * @return array Counters; done = the pass reached the end of the catalogue.
	 */
	public function pull_batch( $budget = 20, $page_size = 50, $max_pages = null ) {
		$res = array(
			'processed' => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'missing'   => 0,
			'pages'     => 0,
			'done'      => false,
		);
		if ( ! Eru_DPG_Settings::is_enabled() ) {
			return $res + array( 'error' => 'disabled' );
		}
		$stock = Eru_DPG_Settings::allows( 'stock_direction', 'dp_to_wc' );
		$price = Eru_DPG_Settings::allows( 'price_direction', 'dp_to_wc' );
		if ( ! $stock && ! $price ) {
			return $res;
		}
		$page_size = max( 1, min( 100, (int) $page_size ) );
		$max_pages = max( 1, (int) ( $max_pages ?? Eru_DPG_Settings::get( 'pull_pages' ) ) );
		$deadline  = microtime( true ) + max( 1, (int) $budget );
		$state     = self::pull_state();

		while ( $res['pages'] < $max_pages && microtime( true ) < $deadline ) {
			$batch = $this->api()->get_products( $page_size, (int) $state['offset'] );
			if ( is_wp_error( $batch ) ) {
				$res['error'] = $batch->get_error_message();
				Eru_DPG_Logger::log( 'product', 'warning', __( 'Dopigo ürün listesi alınamadı: ', 'dopigo-integration-for-woocommerce' ) . $batch->get_error_message(), array( 'offset' => $state['offset'] ), 'dp_to_wc' );
				break;
			}
			++$res['pages'];
			foreach ( $batch as $meta ) {
				if ( ! is_array( $meta ) ) {
					continue;
				}
				$variants = ( isset( $meta['products'] ) && is_array( $meta['products'] ) && $meta['products'] ) ? $meta['products'] : array( $meta );
				foreach ( $variants as $v ) {
					if ( is_array( $v ) ) {
						$this->apply_variant( $v, $meta, $stock, $price, $res );
					}
				}
			}
			if ( count( $batch ) < $page_size ) {
				$state['offset']    = 0;
				$state['last_done'] = time();
				$res['done']        = true;
				break;
			}
			$state['offset'] = (int) $state['offset'] + $page_size;
		}
		$state['last_run'] = time();
		$state['last']     = $res;
		update_option( self::PULL_STATE, $state, false );
		if ( $res['updated'] || $res['done'] ) {
			Eru_DPG_Logger::log( 'product', 'success', __( 'Dopigo → Woo stok/fiyat turu', 'dopigo-integration-for-woocommerce' ), $res, 'dp_to_wc' );
		}
		return $res;
	}

	/**
	 * @param string $variant_id Dopigo variant id.
	 * @return int WC product/variation id.
	 */
	public static function find_by_variant_id( $variant_id ) {
		global $wpdb;
		if ( '' === (string) $variant_id ) {
			return 0;
		}
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type IN ('product','product_variation') WHERE pm.meta_key = %s AND pm.meta_value = %s ORDER BY pm.post_id ASC LIMIT 1",
				self::META_VARIANT,
				(string) $variant_id
			)
		);
	}

	/** @param array $v Dopigo variant. @return int WC unit id. */
	public static function find_unit( array $v ) {
		$id = self::find_by_variant_id( (string) ( $v['id'] ?? '' ) );
		foreach ( array( 'foreign_sku', 'sku' ) as $k ) {
			if ( ! $id && '' !== trim( (string) ( $v[ $k ] ?? '' ) ) ) {
				$id = (int) wc_get_product_id_by_sku( trim( (string) $v[ $k ] ) );
			}
		}
		return $id;
	}

	/**
	 * @param array $v     Variant.
	 * @param array $meta  Meta product.
	 * @param bool  $stock Apply stock.
	 * @param bool  $price Apply price.
	 * @param array $res   Counters.
	 */
	private function apply_variant( array $v, array $meta, $stock, $price, array &$res ) {
		++$res['processed'];
		$id = self::find_unit( $v );
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p || $p->is_type( 'variable' ) ) {
			++$res['missing'];
			return;
		}
		if ( Eru_DPG_Cron::pending( 'eru_dpg_push_product', array( $p->get_id() ) ) ) {
			++$res['skipped']; // A Woo→Dopigo push is queued: Woo holds the newer value, do not overwrite it.
			return;
		}
		$changed = false;
		$qty     = $v['available_stock'] ?? ( $v['stock'] ?? null );
		if ( $stock && null !== $qty && ( ! $p->managing_stock() || (int) $p->get_stock_quantity() !== (int) $qty ) ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( (int) $qty );
			$changed = true;
		}
		if ( $price && isset( $v['price'] ) && (float) $v['price'] > 0 ) {
			$vat    = isset( $meta['vat'] ) ? (int) $meta['vat'] : self::vat_rate( $p );
			$wc     = self::dopigo_to_wc( (float) $v['price'], $vat );
			$field  = 'sale' === Eru_DPG_Settings::get( 'price_field' ) ? $p->get_sale_price() : $p->get_regular_price();
			if ( round( (float) $field, 2 ) !== round( $wc, 2 ) ) {
				self::apply_wc_price( $p, $wc );
				$changed = true;
			}
		}
		if ( Eru_DPG_Settings::dry_run() ) {
			$changed ? ++$res['updated'] : ++$res['skipped'];
			return;
		}
		if ( ! empty( $v['id'] ) && (string) $p->get_meta( self::META_VARIANT ) !== (string) $v['id'] ) {
			$p->update_meta_data( self::META_VARIANT, (string) $v['id'] );
			$p->update_meta_data( self::META_PRODUCT, (string) ( $v['meta_id'] ?? ( $meta['meta_id'] ?? '' ) ) );
			$changed = true;
		}
		if ( $changed ) {
			self::without_echo( fn() => $p->save() );
			++$res['updated'];
		} else {
			++$res['skipped'];
		}
	}

	// -------------------------------------------------------------------------------------------
	// VAT and price helpers.
	// -------------------------------------------------------------------------------------------

	/** @param float $gross Gross. @param int $vat Rate %. @return float Net, 2 decimals. */
	public static function to_net( $gross, $vat ) {
		$vat = max( 0, (int) $vat );
		return (float) round( (float) $gross * 100 / ( 100 + $vat ), 2 );
	}

	/** @param float $net Net. @param int $vat Rate %. @return float Gross, 2 decimals. */
	public static function to_gross( $net, $vat ) {
		$vat = max( 0, (int) $vat );
		return (float) round( (float) $net * ( 100 + $vat ) / 100, 2 );
	}

	/** @param float $wc WC price. @param int $vat Rate. @return float Dopigo (gross) price. */
	public static function wc_to_dopigo( $wc, $vat ) {
		return 'net' === Eru_DPG_Settings::get( 'vat_mode' ) ? self::to_gross( $wc, $vat ) : (float) round( (float) $wc, 2 );
	}

	/** @param float $gross Dopigo price. @param int $vat Rate. @return float WC price. */
	public static function dopigo_to_wc( $gross, $vat ) {
		return 'net' === Eru_DPG_Settings::get( 'vat_mode' ) ? self::to_net( $gross, $vat ) : (float) round( (float) $gross, 2 );
	}

	/** @param WC_Product $p Product. @return int VAT rate from WC tax settings, else the default. */
	public static function vat_rate( WC_Product $p ) {
		if ( wc_tax_enabled() && class_exists( 'WC_Tax' ) ) {
			$rates = WC_Tax::get_base_tax_rates( $p->get_tax_class() );
			if ( $rates ) {
				$first = reset( $rates );
				if ( isset( $first['rate'] ) ) {
					return (int) round( (float) $first['rate'] );
				}
			}
		}
		return (int) Eru_DPG_Settings::get( 'default_vat' );
	}

	/** @param WC_Product $p Unit. @param float $value WC price for the configured field. */
	public static function apply_wc_price( WC_Product $p, $value ) {
		$value = wc_format_decimal( $value, 2 );
		if ( 'sale' === Eru_DPG_Settings::get( 'price_field' ) ) {
			$p->set_sale_price( $value );
			if ( '' === $p->get_regular_price() ) {
				$p->set_regular_price( $value );
			}
		} else {
			$p->set_regular_price( $value );
		}
		$sale = $p->get_sale_price();
		$p->set_price( '' !== $sale ? $sale : $value );
	}

	/**
	 * WC SKU for a Dopigo variant: a foreign_sku used by only one variant, else the Dopigo sku, else DP-<id>.
	 *
	 * @param array $v      Variant.
	 * @param array $counts foreign_sku => variants using it.
	 * @return string
	 */
	public static function pick_sku( array $v, array $counts = array() ) {
		$fs = trim( (string) ( $v['foreign_sku'] ?? '' ) );
		$sk = trim( (string) ( $v['sku'] ?? '' ) );
		if ( '' !== $fs && (int) ( $counts[ $fs ] ?? 1 ) <= 1 ) {
			return $fs;
		}
		if ( '' !== $sk ) {
			return $sk;
		}
		if ( '' !== $fs ) {
			return $fs;
		}
		$id = (string) ( $v['id'] ?? '' );
		return '' !== $id ? 'DP-' . $id : '';
	}

	/**
	 * Variation attributes from Dopigo custom_attributes; a synthetic "Varyant" attribute when there are none.
	 *
	 * @param array $v      Variant.
	 * @param array $counts foreign_sku counts.
	 * @return array<string,string> Name => value.
	 */
	public static function attr_pairs( array $v, array $counts = array() ) {
		$pairs = array();
		foreach ( (array) ( $v['custom_attributes'] ?? array() ) as $ca ) {
			$an = trim( (string) ( $ca['attribute']['name'] ?? '' ) );
			$av = trim( (string) ( $ca['value']['name'] ?? '' ) );
			if ( '' !== $an && '' !== $av ) {
				$pairs[ mb_convert_case( $an, MB_CASE_TITLE, 'UTF-8' ) ] = $av;
			}
		}
		if ( ! $pairs ) {
			$pairs[ __( 'Varyant', 'dopigo-integration-for-woocommerce' ) ] = self::pick_sku( $v, $counts );
		}
		return $pairs;
	}
}
