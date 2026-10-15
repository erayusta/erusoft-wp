<?php
/**
 * Dopigo → Woo product import: cursor-based, time-budgeted upsert of simple and variable products,
 * deterministic unique SKUs, category resolution under a parent, and two-phase image sideload with
 * hash de-duplication. Products are saved before any image is downloaded.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * State lives in one non-autoloaded option; each run continues from the stored page and position.
 */
class Eru_DPG_Importer {

	const STATE      = 'eru_dpg_import_state';
	const CAT_CACHE  = 'eru_dpg_category_names';
	const M_UPDATED  = '_eru_dpg_updated_at';
	const M_IMG_SIG  = '_eru_dpg_img_sig';
	const M_IMG_TODO = '_eru_dpg_img_todo';
	const M_SRC_HASH = '_eru_dpg_src_hash';

	/** Minimum seconds left before starting another image download. */
	const IMG_MIN_SECONDS = 6;

	/** @var self|null */
	private static $instance;

	/** @var Eru_DPG_API|null */
	private $api;

	/** @var float Deadline (unix, float). */
	private $deadline = 0.0;

	/** @return self */
	public static function instance() {
		return self::$instance ?: ( self::$instance = new self() );
	}

	/** @return Eru_DPG_API */
	private function api() {
		return $this->api ?: ( $this->api = new Eru_DPG_API() );
	}

	/** @return bool */
	private function expired() {
		return $this->deadline > 0 && microtime( true ) >= $this->deadline;
	}

	/** @return array State. */
	public static function state() {
		$s = get_option( self::STATE, array() );
		return array_merge(
			array(
				'offset'         => 0,
				'page_pos'       => 0,
				'last_pass_done' => 0,
				'last_run'       => 0,
				'last_error'     => '',
				'totals'         => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'images' => 0 ),
			),
			is_array( $s ) ? $s : array()
		);
	}

	/** Restart from the first page. */
	public static function reset_state() {
		delete_option( self::STATE );
	}

	/**
	 * One budgeted import step.
	 *
	 * @param int $budget       Seconds.
	 * @param int $page_size    Meta products per Dopigo page.
	 * @param int $image_budget Images to download in this run.
	 * @return array Counters; pass_done when the end of the catalogue was reached.
	 */
	public function run_batch( $budget = 20, $page_size = 10, $image_budget = 25 ) {
		$res = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'images' => 0, 'pass_done' => false );
		if ( ! Eru_DPG_Settings::is_enabled() ) {
			return $res + array( 'error' => 'disabled' );
		}
		$page_size      = max( 1, min( 100, (int) $page_size ) );
		$this->deadline = microtime( true ) + max( 1, (int) $budget );
		$state          = self::state();
		$img_left       = max( 0, (int) $image_budget );

		return Eru_DPG_Products::without_echo(
			function () use ( $res, $page_size, $state, $img_left ) {
				$this->flush_pending_images( $img_left, $res );
				if ( $this->expired() ) {
					$state['last_run'] = time();
					update_option( self::STATE, $state, false );
					return $res;
				}

				$batch = $this->api()->get_products( $page_size, (int) $state['offset'] );
				if ( is_wp_error( $batch ) ) {
					$state['last_error'] = $batch->get_error_message();
					$state['last_run']   = time();
					update_option( self::STATE, $state, false );
					Eru_DPG_Logger::log( 'import', 'failed', __( 'İçe aktarma: liste alınamadı: ', 'dopigo-integration-for-woocommerce' ) . $batch->get_error_message(), array( 'offset' => $state['offset'] ), 'dp_to_wc' );
					return $res + array( 'error' => $batch->get_error_message(), 'retryable' => Eru_DPG_API::is_retryable( $batch ) );
				}

				$count = count( $batch );
				$pos   = min( (int) $state['page_pos'], $count );
				for ( ; $pos < $count; $pos++ ) {
					if ( $this->expired() ) {
						break;
					}
					if ( ! is_array( $batch[ $pos ] ) ) {
						continue;
					}
					try {
						$this->upsert_meta( $batch[ $pos ], $res, $img_left );
					} catch ( Throwable $e ) {
						++$res['failed'];
						Eru_DPG_Logger::log( 'import', 'failed', __( 'İçe aktarma hatası: ', 'dopigo-integration-for-woocommerce' ) . $e->getMessage(), array( 'meta_id' => $batch[ $pos ]['meta_id'] ?? null ), 'dp_to_wc' );
					}
				}

				if ( $pos >= $count ) {
					$state['page_pos'] = 0;
					$state['offset']   = (int) $state['offset'] + $count;
					if ( $count < $page_size ) {
						$state['offset']         = 0;
						$state['last_pass_done'] = time();
						$res['pass_done']        = true;
					}
				} else {
					$state['page_pos'] = $pos;
				}
				foreach ( array( 'created', 'updated', 'skipped', 'failed', 'images' ) as $k ) {
					$state['totals'][ $k ] = (int) ( $state['totals'][ $k ] ?? 0 ) + $res[ $k ];
				}
				$state['last_run']   = time();
				$state['last_error'] = '';
				update_option( self::STATE, $state, false );
				if ( $res['created'] || $res['updated'] || $res['failed'] || $res['pass_done'] ) {
					Eru_DPG_Logger::log( 'import', $res['failed'] ? 'warning' : 'success', $res['pass_done'] ? __( 'İçe aktarma turu tamamlandı', 'dopigo-integration-for-woocommerce' ) : __( 'İçe aktarma adımı', 'dopigo-integration-for-woocommerce' ), $res, 'dp_to_wc' );
				}
				return $res;
			}
		);
	}

	/**
	 * @param array $meta     Meta product.
	 * @param array $res      Counters.
	 * @param int   $img_left Image budget left.
	 */
	private function upsert_meta( array $meta, array &$res, &$img_left ) {
		$variants = ( isset( $meta['products'] ) && is_array( $meta['products'] ) && $meta['products'] ) ? $meta['products'] : ( isset( $meta['id'] ) ? array( $meta ) : array() );
		$variants = array_values( array_filter( $variants, fn( $v ) => is_array( $v ) && '' !== Eru_DPG_Products::pick_sku( $v ) ) );
		if ( ! $variants ) {
			++$res['skipped'];
			return;
		}
		foreach ( $variants as $i => $v ) {
			if ( empty( $v['meta_id'] ) && ! empty( $meta['meta_id'] ) ) {
				$variants[ $i ]['meta_id'] = $meta['meta_id'];
			}
		}
		$counts = Eru_DPG_API::index_from_batch( array( array( 'products' => $variants ) ) )['foreign_sku_counts'];
		$global = (array) ( Eru_DPG_Tools::index()['foreign_sku_counts'] ?? array() );
		foreach ( $counts as $fs => $c ) {
			$counts[ $fs ] = max( $c, (int) ( $global[ $fs ] ?? 0 ) ); // A foreign_sku shared with another meta product is not unique either.
		}
		$existing = $this->find_existing( $variants, $counts );
		$stamp    = $this->max_updated_at( $variants );
		if ( $existing && '' !== $stamp && (string) get_post_meta( $existing, self::M_UPDATED, true ) === $stamp ) {
			++$res['skipped'];
			return;
		}
		if ( Eru_DPG_Settings::dry_run() ) {
			$existing ? ++$res['updated'] : ++$res['created'];
			return;
		}

		$name    = (string) ( $meta['name'] ?? '' );
		$name    = '' !== $name ? $name : __( 'Dopigo ürünü', 'dopigo-integration-for-woocommerce' );
		$desc    = wp_kses_post( (string) ( $meta['description'] ?? '' ) );
		$vat     = isset( $meta['vat'] ) ? (int) $meta['vat'] : (int) Eru_DPG_Settings::get( 'default_vat' );
		$cat     = $this->resolve_category( $meta );
		$product = $existing ? wc_get_product( $existing ) : null;
		$created = false;

		if ( 1 === count( $variants ) && ( ! $product || ! $product->is_type( 'variable' ) ) ) {
			$product = $this->upsert_simple( $product, $variants[0], $counts, $name, $desc, $vat, $cat, $created );
		} else {
			$product = $this->upsert_variable( $product, $variants, $counts, $name, $desc, $vat, $cat, $created );
		}
		if ( ! $product ) {
			++$res['failed'];
			return;
		}
		$pid = $product->get_id();
		update_post_meta( $pid, self::M_UPDATED, $stamp );
		$created ? ++$res['created'] : ++$res['updated'];

		$this->sync_images( $pid, $this->collect_urls( $variants ), $img_left, $res );
	}

	/** @param array $variants Variants. @return string Newest updated_at. */
	private function max_updated_at( array $variants ) {
		$max = '';
		foreach ( $variants as $v ) {
			$u = (string) ( $v['updated_at'] ?? '' );
			if ( $u > $max ) {
				$max = $u;
			}
		}
		return $max;
	}

	/**
	 * Existing WC product for a meta product: variant id meta → SKU → meta id on the parent.
	 *
	 * @param array $variants Variants.
	 * @param array $counts   foreign_sku counts.
	 * @return int Parent product id.
	 */
	private function find_existing( array $variants, array $counts ) {
		global $wpdb;
		foreach ( $variants as $v ) {
			$id = Eru_DPG_Products::find_by_variant_id( (string) ( $v['id'] ?? '' ) );
			if ( $id ) {
				return $this->parent_of( $id );
			}
		}
		foreach ( $variants as $v ) {
			$id = (int) wc_get_product_id_by_sku( Eru_DPG_Products::pick_sku( $v, $counts ) );
			if ( $id ) {
				return $this->parent_of( $id );
			}
		}
		$meta_id = (string) ( $variants[0]['meta_id'] ?? '' );
		if ( '' !== $meta_id ) {
			return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' WHERE pm.meta_key = %s AND pm.meta_value = %s LIMIT 1",
					Eru_DPG_Products::META_PRODUCT,
					$meta_id
				)
			);
		}
		return 0;
	}

	/** @param int $id Post id. @return int Parent for a variation, else itself. */
	private function parent_of( $id ) {
		$p = get_post( $id );
		return ( $p && 'product_variation' === $p->post_type && $p->post_parent ) ? (int) $p->post_parent : (int) $id;
	}

	/** @return WC_Product|null */
	private function upsert_simple( $product, array $v, array $counts, $name, $desc, $vat, $cat, &$created ) {
		if ( $product instanceof WC_Product ) {
			$p = $product;
		} else {
			$p = new WC_Product_Simple();
			$p->set_status( (string) Eru_DPG_Settings::get( 'import_status' ) );
			$created = true;
		}
		$p->set_name( $name );
		$p->set_description( $desc );
		$this->set_sku_safe( $p, Eru_DPG_Products::pick_sku( $v, $counts ), (string) ( $v['id'] ?? '' ) );
		$this->apply_price_stock( $p, $v, $vat );
		$this->apply_category( $p, $cat );
		$p->update_meta_data( Eru_DPG_Products::META_PRODUCT, (string) ( $v['meta_id'] ?? '' ) );
		if ( ! empty( $v['id'] ) ) {
			$p->update_meta_data( Eru_DPG_Products::META_VARIANT, (string) $v['id'] );
		}
		$p->save();
		return $p;
	}

	/** @return WC_Product|null */
	private function upsert_variable( $product, array $variants, array $counts, $name, $desc, $vat, $cat, &$created ) {
		if ( ! $product instanceof WC_Product_Variable ) {
			if ( $product instanceof WC_Product ) {
				wp_set_object_terms( $product->get_id(), 'variable', 'product_type' );
				$product = new WC_Product_Variable( $product->get_id() );
			} else {
				$product = new WC_Product_Variable();
				$product->set_status( (string) Eru_DPG_Settings::get( 'import_status' ) );
				$created = true;
			}
		}
		$per   = array();
		$attrs = array();
		foreach ( $variants as $i => $v ) {
			$per[ $i ] = Eru_DPG_Products::attr_pairs( $v, $counts );
			foreach ( $per[ $i ] as $an => $av ) {
				$attrs[ $an ][ $av ] = $av;
			}
		}
		$list = array();
		foreach ( $attrs as $an => $vals ) {
			$a = new WC_Product_Attribute();
			$a->set_id( 0 );
			$a->set_name( $an );
			$a->set_options( array_values( $vals ) );
			$a->set_visible( true );
			$a->set_variation( true );
			$list[] = $a;
		}
		$product->set_name( $name );
		$product->set_description( $desc );
		$product->set_attributes( $list );
		$this->apply_category( $product, $cat );
		$product->update_meta_data( Eru_DPG_Products::META_PRODUCT, (string) ( $variants[0]['meta_id'] ?? '' ) );
		$parent_id = $product->save();

		$known = array();
		foreach ( $product->get_children() as $cid ) {
			$dvid = (string) get_post_meta( $cid, Eru_DPG_Products::META_VARIANT, true );
			if ( '' !== $dvid ) {
				$known[ $dvid ] = $cid;
			}
		}
		foreach ( $variants as $i => $v ) {
			$dvid = (string) ( $v['id'] ?? '' );
			$var  = isset( $known[ $dvid ] ) ? new WC_Product_Variation( $known[ $dvid ] ) : new WC_Product_Variation();
			$var->set_parent_id( $parent_id );
			$var->set_status( 'publish' );
			$this->set_sku_safe( $var, Eru_DPG_Products::pick_sku( $v, $counts ), $dvid );
			$this->apply_price_stock( $var, $v, $vat );
			$va = array();
			foreach ( $per[ $i ] as $an => $av ) {
				$va[ sanitize_title( $an ) ] = $av;
			}
			$var->set_attributes( $va );
			$var->update_meta_data( Eru_DPG_Products::META_PRODUCT, (string) ( $v['meta_id'] ?? '' ) );
			if ( '' !== $dvid ) {
				$var->update_meta_data( Eru_DPG_Products::META_VARIANT, $dvid );
			}
			$var->save();
		}
		WC_Product_Variable::sync( $parent_id );
		return wc_get_product( $parent_id );
	}

	/**
	 * A SKU that does not collide, the same on every run: the preferred SKU, else SKU-<variant id>, else -2 … -20.
	 *
	 * @param int    $product_id Product being saved (0 = new).
	 * @param string $sku        Preferred SKU.
	 * @param string $dvid       Dopigo variant id.
	 * @return string '' when no candidate is free.
	 */
	public static function unique_sku_candidate( $product_id, $sku, $dvid = '' ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return '';
		}
		if ( wc_product_has_unique_sku( (int) $product_id, $sku ) ) {
			return $sku;
		}
		$base = '' !== (string) $dvid ? $sku . '-' . $dvid : $sku;
		if ( $base !== $sku && wc_product_has_unique_sku( (int) $product_id, $base ) ) {
			return $base;
		}
		for ( $i = 2; $i <= 20; $i++ ) {
			if ( wc_product_has_unique_sku( (int) $product_id, $base . '-' . $i ) ) {
				return $base . '-' . $i;
			}
		}
		return '';
	}

	/** @param WC_Product $p Product. @param string $sku SKU. @param string $dvid Variant id. */
	private function set_sku_safe( WC_Product $p, $sku, $dvid ) {
		$c = self::unique_sku_candidate( $p->get_id(), $sku, $dvid );
		if ( '' === $c ) {
			Eru_DPG_Logger::log( 'import', 'warning', __( 'Benzersiz SKU üretilemedi.', 'dopigo-integration-for-woocommerce' ), array( 'dopigo_variant_id' => $dvid ), 'dp_to_wc', $sku );
			return;
		}
		try {
			$p->set_sku( $c );
		} catch ( Throwable $e ) {
			Eru_DPG_Logger::log( 'import', 'warning', __( 'SKU atanamadı: ', 'dopigo-integration-for-woocommerce' ) . $e->getMessage(), array( 'dopigo_variant_id' => $dvid ), 'dp_to_wc', $c );
		}
	}

	/** @param WC_Product $p Product. @param array $v Variant. @param int $vat VAT. */
	private function apply_price_stock( WC_Product $p, array $v, $vat ) {
		if ( isset( $v['price'] ) && (float) $v['price'] > 0 ) {
			Eru_DPG_Products::apply_wc_price( $p, Eru_DPG_Products::dopigo_to_wc( (float) $v['price'], $vat ) );
		}
		$qty = $v['available_stock'] ?? ( $v['stock'] ?? null );
		if ( null !== $qty ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( (int) $qty );
		}
		if ( ! empty( $v['weight'] ) && is_numeric( $v['weight'] ) ) {
			$p->set_weight( (string) $v['weight'] );
		}
	}

	/** @param WC_Product $p Product. @param int $cat Term id (0 = leave categories alone). */
	private function apply_category( WC_Product $p, $cat ) {
		if ( $cat <= 0 ) {
			return;
		}
		if ( 'replace' === Eru_DPG_Settings::get( 'category_mode' ) ) {
			$p->set_category_ids( array( $cat ) );
			return;
		}
		$ids = array_map( 'intval', (array) $p->get_category_ids() );
		if ( ! in_array( $cat, $ids, true ) ) {
			$ids[] = $cat;
			$p->set_category_ids( $ids );
		}
	}

	/**
	 * Dopigo category → WC term under the configured parent (created when missing).
	 *
	 * @param array $meta Meta product.
	 * @return int Term id or 0.
	 */
	private function resolve_category( array $meta ) {
		$c    = $meta['category'] ?? null;
		$name = '';
		$cid  = 0;
		if ( is_array( $c ) ) {
			$cid  = (int) ( $c['id'] ?? 0 );
			$name = trim( (string) ( $c['name'] ?? '' ) );
		} elseif ( is_numeric( $c ) ) {
			$cid = (int) $c;
		} elseif ( is_string( $c ) ) {
			$name = trim( $c );
		}
		$cache = get_option( self::CAT_CACHE, array() );
		$cache = is_array( $cache ) ? $cache : array();
		if ( '' === $name && $cid > 0 ) {
			if ( array_key_exists( $cid, $cache ) ) {
				$name = (string) $cache[ $cid ];
			} elseif ( ! empty( $meta['meta_id'] ) ) {
				$detail = $this->api()->get_product_meta( (int) $meta['meta_id'] );
				if ( ! is_wp_error( $detail ) && is_array( $detail['category'] ?? null ) ) {
					$name = trim( (string) ( $detail['category']['name'] ?? '' ) );
				}
				if ( ! is_wp_error( $detail ) ) {
					$cache[ $cid ] = $name;
				}
			}
		} elseif ( $cid > 0 && '' !== $name ) {
			$cache[ $cid ] = $name;
		}
		if ( $cache !== get_option( self::CAT_CACHE, array() ) ) {
			update_option( self::CAT_CACHE, array_slice( $cache, -2000, null, true ), false );
		}
		if ( '' === $name ) {
			return 0;
		}
		$filtered = (int) apply_filters( 'eru_dpg_category_term', 0, $name, $meta );
		if ( $filtered > 0 ) {
			return $filtered;
		}
		$parent = (int) Eru_DPG_Settings::get( 'category_parent' );
		$terms  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'name'       => $name,
				'parent'     => $parent,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( ! is_wp_error( $terms ) && $terms ) {
			return (int) $terms[0]->term_id;
		}
		$new = wp_insert_term( $name, 'product_cat', $parent ? array( 'parent' => $parent ) : array() );
		if ( is_wp_error( $new ) ) {
			$id = (int) $new->get_error_data( 'term_exists' );
			if ( $id ) {
				return $id;
			}
			Eru_DPG_Logger::log( 'import', 'warning', __( 'Kategori oluşturulamadı: ', 'dopigo-integration-for-woocommerce' ) . $new->get_error_message(), array( 'category' => $name ), 'dp_to_wc' );
			return 0;
		}
		return (int) $new['term_id'];
	}

	// -------------------------------------------------------------------------------------------
	// Images.
	// -------------------------------------------------------------------------------------------

	/** @param array $variants Variants. @return string[] Unique image URLs in Dopigo order. */
	private function collect_urls( array $variants ) {
		$rows = array();
		foreach ( $variants as $v ) {
			foreach ( (array) ( $v['images'] ?? array() ) as $img ) {
				$u = (string) ( $img['absolute_url'] ?? ( $img['source_url'] ?? '' ) );
				if ( '' !== $u && wp_http_validate_url( $u ) ) {
					$rows[] = array( 'u' => $u, 'o' => (int) ( $img['order'] ?? 0 ) );
				}
			}
		}
		usort( $rows, fn( $a, $b ) => $a['o'] <=> $b['o'] );
		return array_values( array_unique( array_column( $rows, 'u' ) ) );
	}

	/** Downloads now if the budget allows, else queues the URLs. Skips when the URL list is unchanged. */
	private function sync_images( $pid, array $urls, &$img_left, array &$res ) {
		if ( ! $urls ) {
			return;
		}
		$sig = md5( implode( '|', $urls ) );
		if ( (string) get_post_meta( $pid, self::M_IMG_SIG, true ) === $sig ) {
			return;
		}
		if ( $img_left <= 0 ) {
			update_post_meta( $pid, self::M_IMG_TODO, $urls );
			return;
		}
		$done = $this->download_images( $pid, $urls, $img_left, $res );
		if ( $done >= count( $urls ) ) {
			update_post_meta( $pid, self::M_IMG_SIG, $sig );
			delete_post_meta( $pid, self::M_IMG_TODO );
		} else {
			update_post_meta( $pid, self::M_IMG_TODO, array_slice( $urls, $done ) );
		}
	}

	/** @return int URLs handled. */
	private function download_images( $pid, array $urls, &$img_left, array &$res ) {
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			return 0;
		}
		$ids  = array();
		$done = 0;
		foreach ( $urls as $url ) {
			if ( $img_left <= 0 || $this->expired() || $this->deadline - microtime( true ) < self::IMG_MIN_SECONDS ) {
				break;
			}
			$hash = md5( $url );
			$att  = $this->attachment_by_hash( $hash );
			if ( ! $att ) {
				$att = $this->sideload( $url, $pid );
				--$img_left;
				++$res['images'];
				if ( $att ) {
					update_post_meta( $att, self::M_SRC_HASH, $hash );
				}
			}
			if ( $att ) {
				$ids[] = $att;
			}
			++$done;
		}
		if ( $ids ) {
			if ( ! $p->get_image_id() ) {
				$p->set_image_id( array_shift( $ids ) );
			}
			if ( $ids ) {
				$p->set_gallery_image_ids( array_values( array_unique( array_merge( $p->get_gallery_image_ids(), $ids ) ) ) );
			}
			$p->save();
		}
		return $done;
	}

	/** @param string $hash URL hash. @return int Attachment id. */
	private function attachment_by_hash( $hash ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::M_SRC_HASH, $hash ) ); // phpcs:ignore
	}

	/** @return string[] Image sizes generated during import. */
	public static function image_sizes() {
		return (array) apply_filters( 'eru_dpg_import_image_sizes', array( 'thumbnail', 'medium', 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' ) );
	}

	/** @param string $url URL. @param int $pid Product. @return int Attachment id or 0. */
	private function sideload( $url, $pid ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 20 );
		if ( is_wp_error( $tmp ) ) {
			Eru_DPG_Logger::log( 'import', 'warning', __( 'Görsel indirilemedi: ', 'dopigo-integration-for-woocommerce' ) . $tmp->get_error_message(), array( 'url' => $url ), 'dp_to_wc' );
			return 0;
		}
		$limit = fn( $sizes ) => array_intersect_key( (array) $sizes, array_flip( self::image_sizes() ) );
		add_filter( 'intermediate_image_sizes_advanced', $limit, 999 );
		$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$id   = media_handle_sideload( array( 'name' => '' !== $name ? $name : 'dopigo.jpg', 'tmp_name' => $tmp ), $pid );
		remove_filter( 'intermediate_image_sizes_advanced', $limit, 999 );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			Eru_DPG_Logger::log( 'import', 'warning', __( 'Görsel eklenemedi: ', 'dopigo-integration-for-woocommerce' ) . $id->get_error_message(), array( 'url' => $url ), 'dp_to_wc' );
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Queued images in two phases: first one cover image for every product without one, then galleries.
	 *
	 * @param int   $img_left Budget.
	 * @param array $res      Counters.
	 */
	private function flush_pending_images( &$img_left, array &$res ) {
		global $wpdb;
		if ( $img_left <= 0 ) {
			return;
		}
		$coverless = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->postmeta} th ON th.post_id = pm.post_id AND th.meta_key = '_thumbnail_id' WHERE pm.meta_key = %s AND ( th.meta_id IS NULL OR th.meta_value IN ('', '0') ) LIMIT 200",
				self::M_IMG_TODO
			)
		);
		foreach ( $coverless as $pid ) {
			if ( $img_left <= 0 || $this->expired() ) {
				return;
			}
			$this->drain( (int) $pid, $img_left, $res, 1 );
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 20", self::M_IMG_TODO ) ); // phpcs:ignore
		foreach ( $ids as $pid ) {
			if ( $img_left <= 0 || $this->expired() ) {
				return;
			}
			$this->drain( (int) $pid, $img_left, $res, 0 );
		}
	}

	/** @param int $pid Product. @param int $img_left Budget. @param array $res Counters. @param int $max 0 = all. */
	private function drain( $pid, &$img_left, array &$res, $max ) {
		$urls = get_post_meta( $pid, self::M_IMG_TODO, true );
		if ( ! is_array( $urls ) || ! $urls ) {
			delete_post_meta( $pid, self::M_IMG_TODO );
			return;
		}
		$done = $this->download_images( $pid, $max > 0 ? array_slice( $urls, 0, $max ) : $urls, $img_left, $res );
		$rest = array_slice( $urls, $done );
		$rest ? update_post_meta( $pid, self::M_IMG_TODO, $rest ) : delete_post_meta( $pid, self::M_IMG_TODO );
	}

	/** @return int Products with queued images. */
	public static function pending_images() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::M_IMG_TODO ) ); // phpcs:ignore
	}
}
