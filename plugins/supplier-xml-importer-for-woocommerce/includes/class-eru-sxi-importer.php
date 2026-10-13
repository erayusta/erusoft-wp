<?php
/**
 * Turns one mapped feed row into a created / updated product (or, in a dry run, into a plan).
 *
 * @package supplier-xml-importer-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SXI_Importer {

	const META_FEED = '_eru_sxi_feed';
	const META_RUN  = '_eru_sxi_run';
	const META_SRC  = '_eru_sxi_src';
	const META_IMG_TRY = '_eru_sxi_img_try';

	/* ---- numbers ---- */

	/**
	 * Parse "1.234,56", "1,234.56", "1234.5", "₺ 99" into a float; null when not a number.
	 */
	public static function parse_number( $raw ) {
		$s = preg_replace( '/[^0-9,.\-]/', '', (string) $raw );
		if ( '' === $s || ! preg_match( '/\d/', $s ) ) {
			return null;
		}
		$comma = strrpos( $s, ',' );
		$dot   = strrpos( $s, '.' );
		if ( false !== $comma && false !== $dot ) {
			$dec = $comma > $dot ? ',' : '.';
		} elseif ( false !== $comma ) {
			$dec = ( strlen( $s ) - $comma - 1 ) <= 2 && substr_count( $s, ',' ) === 1 ? ',' : '';
		} elseif ( false !== $dot ) {
			$dec = ( strlen( $s ) - $dot - 1 ) <= 2 || substr_count( $s, '.' ) === 1 && ( strlen( $s ) - $dot - 1 ) !== 3 ? '.' : '';
		} else {
			$dec = '';
		}
		if ( '' === $dec ) {
			$s = str_replace( array( ',', '.' ), '', $s );
		} else {
			$thousands = ',' === $dec ? '.' : ',';
			$s         = str_replace( $thousands, '', $s );
			$s         = str_replace( $dec, '.', $s );
		}
		return is_numeric( $s ) ? (float) $s : null;
	}

	/**
	 * Apply fixed exchange rate, percentage markup and rounding.
	 */
	public static function apply_price_rules( $price, array $feed ) {
		$v = (float) $price * (float) $feed['rate'] * ( 1 + (float) $feed['markup'] / 100 );
		if ( $v <= 0 ) {
			return 0.0;
		}
		switch ( $feed['rounding'] ) {
			case '90':
				$v = round( $v - 0.90 ) + 0.90;
				break;
			case '99':
				$v = round( $v - 0.99 ) + 0.99;
				break;
			case 'int':
				$v = round( $v );
				break;
			default:
				$v = round( $v, 2 );
		}
		return (float) apply_filters( 'eru_sxi_price', max( 0.0, $v ), $price, $feed );
	}

	private static function money( $v ) {
		return wc_format_decimal( $v, wc_get_price_decimals() );
	}

	/* ---- categories ---- */

	/** "Source = Target" lines -> map. */
	private static function cat_map( array $feed ) {
		$map = array();
		foreach ( preg_split( '/\R/', (string) $feed['cat_map'] ) as $line ) {
			$p = explode( '=', $line, 2 );
			if ( 2 === count( $p ) && '' !== trim( $p[0] ) ) {
				$map[ mb_strtolower( trim( $p[0] ) ) ] = trim( $p[1] );
			}
		}
		return $map;
	}

	/**
	 * Resolve category path strings to term ids.
	 *
	 * @param bool $create Create missing terms (live) or only look them up (dry run).
	 * @return array{ids:int[],new:bool}
	 */
	public static function resolve_categories( array $paths, array $feed, $create ) {
		$map = self::cat_map( $feed );
		$ids = array();
		$new = false;
		foreach ( $paths as $path ) {
			$path = $map[ mb_strtolower( $path ) ] ?? $path;
			$sep  = '' !== $feed['cat_sep'] ? $feed['cat_sep'] : '>';
			$parent = 0;
			foreach ( array_filter( array_map( 'trim', explode( $sep, $path ) ), 'strlen' ) as $name ) {
				$name  = sanitize_text_field( $name );
				$found = self::find_term( $name, $parent );
				if ( ! $found ) {
					$new = true;
					if ( ! $create ) {
						$parent = 0;
						break;
					}
					$t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
					if ( is_wp_error( $t ) ) {
						$found = (int) ( $t->get_error_data( 'term_exists' ) ?: 0 );
						if ( ! $found ) {
							break;
						}
					} else {
						$found = (int) $t['term_id'];
					}
				}
				$parent = $found;
			}
			if ( $parent ) {
				$ids[] = $parent;
			}
		}
		return array(
			'ids' => array_values( array_unique( $ids ) ),
			'new' => $new,
		);
	}

	private static function find_term( $name, $parent ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'name'       => $name,
				'parent'     => $parent,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		return ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0] : 0;
	}

	/* ---- images ---- */

	private static function attachment_for( $url ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::META_SRC, $url ) );
	}

	private static function clean_urls( array $urls ) {
		$out = array();
		foreach ( $urls as $u ) {
			$u = esc_url_raw( trim( $u ), array( 'http', 'https' ) );
			if ( $u ) {
				$out[] = $u;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, max( 1, (int) apply_filters( 'eru_sxi_max_images', 10 ) ) );
	}

	/**
	 * Do the feed's image URLs still need to be applied? Not when they are already attached, nor when
	 * the same URL set was tried recently (failed downloads are retried after N days, default 7).
	 */
	public static function images_pending( WC_Product $p, array $urls ): bool {
		if ( array_slice( self::current_sources( $p ), 0, count( $urls ) ) === $urls ) {
			return false;
		}
		$try  = explode( '|', (string) $p->get_meta( self::META_IMG_TRY ) );
		$days = max( 0, (int) apply_filters( 'eru_sxi_image_retry_days', 7 ) );
		$when = isset( $try[1] ) ? (int) $try[1] : 0; // Older records carry no time: retry them.
		return ! ( md5( implode( "\n", $urls ) ) === $try[0] && $when > 0 && time() - $when < $days * DAY_IN_SECONDS );
	}

	/** Source URLs currently attached to a product (featured first). */
	private static function current_sources( WC_Product $p ) {
		$ids = array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) );
		return array_map( fn( $id ) => (string) get_post_meta( $id, self::META_SRC, true ), $ids );
	}

	/**
	 * Attach images, downloading only URLs that appear in the feed and are not already in the library.
	 */
	private static function apply_images( WC_Product $p, array $urls ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids = array();
		foreach ( $urls as $url ) {
			$att = self::attachment_for( $url );
			if ( ! $att ) {
				$att = media_sideload_image( $url, $p->get_id() ?: 0, null, 'id' );
				if ( is_wp_error( $att ) ) {
					continue;
				}
				update_post_meta( (int) $att, self::META_SRC, $url );
			}
			$ids[] = (int) $att;
		}
		// Remember the attempt (URL set + time) so a failing download is not retried on every run,
		// but is retried after eru_sxi_image_retry_days.
		$p->update_meta_data( self::META_IMG_TRY, md5( implode( "\n", $urls ) ) . '|' . time() );
		if ( $ids ) {
			$p->set_image_id( array_shift( $ids ) );
			$p->set_gallery_image_ids( $ids );
		}
	}

	/* ---- attributes ---- */

	private static function simple_attributes( WC_Product $p, array $attrs ) {
		$existing = $p->get_attributes();
		foreach ( $attrs as $name => $values ) {
			$key              = sanitize_title( $name );
			$a                = new WC_Product_Attribute();
			$a->set_id( 0 );
			$a->set_name( $name );
			$a->set_options( array_values( array_unique( $values ) ) );
			$a->set_visible( true );
			$a->set_variation( false );
			$existing[ $key ] = $a;
		}
		$p->set_attributes( $existing );
	}

	private static function attrs_differ( WC_Product $p, array $attrs ) {
		$have = $p->get_attributes();
		foreach ( $attrs as $name => $values ) {
			$key = sanitize_title( $name );
			if ( ! isset( $have[ $key ] ) ) {
				return true;
			}
			$o = $have[ $key ]->get_options();
			$w = array_values( array_unique( $values ) );
			sort( $o );
			sort( $w );
			if ( array_map( 'strval', $o ) !== array_map( 'strval', $w ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---- the row ---- */

	/**
	 * Process one row.
	 *
	 * @return array Preview line: sku, name, action (create|update|unchanged|skip|error), price_old/new, stock_old/new, note, id.
	 */
	public static function process_row( array $feed, array $row, $dry, $run_id ) {
		$sku  = trim( (string) $row['sku'] );
		$line = array(
			'sku'       => $sku,
			'name'      => (string) $row['name'],
			'action'    => 'skip',
			'price_old' => null,
			'price_new' => null,
			'stock_old' => null,
			'stock_new' => null,
			'note'      => '',
			'id'        => 0,
		);
		if ( '' === $sku ) {
			$line['note'] = __( 'SKU yok', 'supplier-xml-importer-for-woocommerce' );
			return $line;
		}
		$row = apply_filters( 'eru_sxi_row', $row, $feed );
		if ( ! is_array( $row ) ) {
			$line['note'] = __( 'Filtre tarafından atlandı', 'supplier-xml-importer-for-woocommerce' );
			return $line;
		}

		$price = self::parse_number( $row['price'] );
		$price = null === $price ? null : self::apply_price_rules( $price, $feed );
		$sale  = self::parse_number( $row['sale_price'] );
		$sale  = null === $sale || $sale <= 0 ? null : self::apply_price_rules( $sale, $feed );
		$stock = self::parse_number( $row['stock'] );
		$stock = null === $stock ? null : max( 0, (int) round( $stock ) );
		$stock = apply_filters( 'eru_sxi_stock', $stock, $row, $feed );
		$line['price_new'] = null === $price ? null : self::money( $price );
		$line['stock_new'] = $stock;

		$parent_sku  = trim( (string) ( $row['parent_sku'] ?? '' ) );
		$is_variation = '' !== $parent_sku && $parent_sku !== $sku;
		$existing_id = (int) wc_get_product_id_by_sku( $sku );
		$existing    = $existing_id ? wc_get_product( $existing_id ) : null;

		if ( ! $existing && 'update_only' === $feed['mode'] ) {
			$line['note'] = __( 'Mağazada yok (yalnızca güncelleme modu)', 'supplier-xml-importer-for-woocommerce' );
			return $line;
		}
		if ( $existing && 'create_only' === $feed['mode'] ) {
			$line['note'] = __( 'Zaten var (yalnızca oluşturma modu)', 'supplier-xml-importer-for-woocommerce' );
			$line['id']   = $existing_id;
			return $line;
		}
		if ( ! $existing && null === $price ) {
			$line['action'] = 'error';
			$line['note']   = __( 'Yeni ürün için geçerli fiyat yok', 'supplier-xml-importer-for-woocommerce' );
			return $line;
		}

		$fields = $feed['update_fields'];
		$use    = fn( $f ) => ! $existing || in_array( $f, $fields, true ); // New products always take every mapped field.

		$cats = array(
			'ids' => array(),
			'new' => false,
		);
		if ( $use( 'categories' ) && $row['categories'] && ! $is_variation ) {
			$cats = self::resolve_categories( $row['categories'], $feed, false );
		}
		$urls = $feed['images'] && $use( 'images' ) ? self::clean_urls( $row['images'] ) : array();

		if ( $existing ) {
			$line['id']        = $existing_id;
			$line['name']      = $existing->get_name();
			$line['price_old'] = $existing->get_regular_price();
			$line['stock_old'] = $existing->managing_stock() ? $existing->get_stock_quantity() : null;
		}

		// Work out what changes.
		$change = array();
		if ( $existing ) {
			if ( $use( 'name' ) && '' !== $row['name'] && ! $is_variation && sanitize_text_field( $row['name'] ) !== $existing->get_name() ) {
				$change[] = 'name';
			}
			if ( $use( 'description' ) && '' !== $row['description'] && ! $is_variation && wp_kses_post( $row['description'] ) !== $existing->get_description() ) {
				$change[] = 'description';
			}
			if ( $use( 'price' ) && null !== $price && self::money( $price ) !== self::money( (float) $existing->get_regular_price() ) ) {
				$change[] = 'price';
			}
			if ( $use( 'price' ) && '' !== $feed['map']['sale_price'] ) {
				$want_sale = null === $sale ? '' : self::money( $sale );
				if ( $want_sale !== (string) $existing->get_sale_price() && ! ( '' !== $want_sale && self::money( (float) $existing->get_sale_price() ) === $want_sale ) ) {
					$change[] = 'sale';
				}
			}
			if ( $use( 'stock' ) && null !== $stock && ( ! $existing->managing_stock() || (int) $existing->get_stock_quantity() !== $stock ) ) {
				$change[] = 'stock';
			}
			if ( ( $cats['ids'] || $cats['new'] ) && ( $cats['new'] || self::sorted( $existing->get_category_ids() ) !== self::sorted( $cats['ids'] ) ) ) {
				$change[] = 'categories';
			}
			if ( $urls && self::images_pending( $existing, $urls ) ) {
				$change[] = 'images';
			}
			if ( $use( 'attributes' ) && $row['attrs'] && ! $is_variation && self::attrs_differ( $existing, $row['attrs'] ) ) {
				$change[] = 'attributes';
			}
			if ( ! $change ) {
				$line['action'] = 'unchanged';
				if ( ! $dry ) {
					self::mark( $existing_id, $feed['id'], $run_id );
					if ( $existing->is_type( 'variation' ) ) {
						self::mark( $existing->get_parent_id(), $feed['id'], $run_id );
					}
				}
				return $line;
			}
			$line['action'] = 'update';
			$line['note']   = implode( ', ', $change );
		} else {
			$line['action'] = 'create';
			$line['note']   = $is_variation ? __( 'varyasyon', 'supplier-xml-importer-for-woocommerce' ) : '';
		}

		if ( $dry ) {
			return $line;
		}

		// Apply.
		try {
			$saved = $is_variation
				? self::write_variation( $feed, $row, $existing, $sku, $parent_sku, $price, $sale, $stock, $urls, $change, $run_id )
				: self::write_simple( $feed, $row, $existing, $sku, $price, $sale, $stock, $urls, $change, $run_id );
		} catch ( Exception $e ) {
			$line['action'] = 'error';
			$line['note']   = $e->getMessage();
			return $line;
		}
		if ( is_wp_error( $saved ) ) {
			$line['action'] = 'error';
			$line['note']   = $saved->get_error_message();
			return $line;
		}
		$line['id'] = (int) $saved;
		return $line;
	}

	private static function sorted( array $ids ) {
		$ids = array_map( 'intval', $ids );
		sort( $ids );
		return $ids;
	}

	private static function mark( $id, $feed_id, $run_id ) {
		if ( $id ) {
			update_post_meta( $id, self::META_FEED, $feed_id );
			update_post_meta( $id, self::META_RUN, $run_id );
		}
	}

	private static function set_prices( WC_Product $p, $price, $sale, array $feed, $apply ) {
		if ( ! $apply ) {
			return;
		}
		if ( null !== $price ) {
			$p->set_regular_price( self::money( $price ) );
		}
		if ( '' !== $feed['map']['sale_price'] ) {
			$p->set_sale_price( null === $sale ? '' : self::money( $sale ) );
		}
	}

	private static function set_stock( WC_Product $p, $stock, $apply ) {
		if ( ! $apply || null === $stock ) {
			return;
		}
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
		$p->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
	}

	private static function write_simple( array $feed, array $row, $existing, $sku, $price, $sale, $stock, array $urls, array $change, $run_id ) {
		$p      = $existing ? $existing : new WC_Product_Simple();
		$create = ! $existing;
		$has    = fn( $k ) => $create || in_array( $k, $change, true );
		if ( $create ) {
			$p->set_sku( $sku );
			$p->set_status( $feed['new_status'] );
			$p->set_name( '' !== $row['name'] ? sanitize_text_field( $row['name'] ) : $sku );
		}
		if ( $has( 'name' ) && '' !== $row['name'] ) {
			$p->set_name( sanitize_text_field( $row['name'] ) );
		}
		if ( $has( 'description' ) && '' !== $row['description'] ) {
			$p->set_description( wp_kses_post( $row['description'] ) );
		}
		self::set_prices( $p, $price, $sale, $feed, $has( 'price' ) || $has( 'sale' ) );
		self::set_stock( $p, $stock, $create || $has( 'stock' ) );
		if ( ( $has( 'categories' ) ) && $row['categories'] ) {
			$cats = self::resolve_categories( $row['categories'], $feed, true );
			if ( $cats['ids'] ) {
				$p->set_category_ids( $cats['ids'] );
			}
		}
		if ( ( $has( 'attributes' ) ) && $row['attrs'] ) {
			self::simple_attributes( $p, $row['attrs'] );
		}
		if ( $urls && ( $create || $has( 'images' ) ) ) {
			self::apply_images( $p, $urls );
		}
		$id = $p->save();
		if ( ! $id ) {
			return new WP_Error( 'eru_sxi_save', __( 'Ürün kaydedilemedi.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		self::mark( $id, $feed['id'], $run_id );
		return $id;
	}

	private static function write_variation( array $feed, array $row, $existing, $sku, $parent_sku, $price, $sale, $stock, array $urls, array $change, $run_id ) {
		$parent_id = (int) wc_get_product_id_by_sku( $parent_sku );
		$parent    = $parent_id ? wc_get_product( $parent_id ) : null;
		if ( $parent && ! $parent->is_type( 'variable' ) ) {
			return new WP_Error( 'eru_sxi_parent', __( 'Ana ürün varyasyonlu bir ürün değil.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		if ( ! $parent ) {
			$parent = new WC_Product_Variable();
			$parent->set_sku( $parent_sku );
			$parent->set_name( '' !== $row['name'] ? sanitize_text_field( $row['name'] ) : $parent_sku );
			$parent->set_status( $feed['new_status'] );
			if ( $row['categories'] ) {
				$cats = self::resolve_categories( $row['categories'], $feed, true );
				$parent->set_category_ids( $cats['ids'] );
			}
		}
		// Parent attributes (variation = true) collect every value seen.
		$attrs = $parent->get_attributes();
		$vattr = array();
		foreach ( $row['attrs'] as $name => $values ) {
			$key = sanitize_title( $name );
			$val = $values[0];
			if ( isset( $attrs[ $key ] ) ) {
				$a = $attrs[ $key ];
			} else {
				$a = new WC_Product_Attribute();
				$a->set_id( 0 );
				$a->set_name( $name );
				$a->set_visible( true );
				$a->set_variation( true );
			}
			$a->set_options( array_values( array_unique( array_merge( $a->get_options(), array( $val ) ) ) ) );
			$attrs[ $key ] = $a;
			$vattr[ $key ] = $val;
		}
		$parent->set_attributes( $attrs );
		if ( $urls && ! $parent->get_image_id() ) {
			self::apply_images( $parent, $urls );
		}
		$pid = $parent->save();

		$v      = $existing ? $existing : new WC_Product_Variation();
		$create = ! $existing;
		if ( $create ) {
			$v->set_parent_id( $pid );
			$v->set_sku( $sku );
			$v->set_status( 'publish' === $feed['new_status'] ? 'publish' : 'private' );
		}
		if ( $vattr ) {
			$v->set_attributes( $vattr );
		}
		$has = fn( $k ) => $create || in_array( $k, $change, true );
		self::set_prices( $v, $price, $sale, $feed, $has( 'price' ) || $has( 'sale' ) );
		self::set_stock( $v, $stock, $create || $has( 'stock' ) );
		$vid = $v->save();
		if ( ! $vid ) {
			return new WP_Error( 'eru_sxi_save', __( 'Varyasyon kaydedilemedi.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		WC_Product_Variable::sync( $pid );
		wc_delete_product_transients( $pid );
		self::mark( $vid, $feed['id'], $run_id );
		self::mark( $pid, $feed['id'], $run_id );
		return $vid;
	}

	/**
	 * Apply the "missing from feed" policy to one product.
	 */
	public static function handle_missing( $product_id, $action ) {
		$p = wc_get_product( $product_id );
		if ( ! $p ) {
			return false;
		}
		if ( 'draft' === $action && ! $p->is_type( 'variation' ) ) {
			$p->set_status( 'draft' );
		} else {
			if ( $p->is_type( 'variable' ) ) {
				return true;
			}
			$p->set_manage_stock( true );
			$p->set_stock_quantity( 0 );
			$p->set_stock_status( 'outofstock' );
		}
		$p->save();
		return true;
	}
}
