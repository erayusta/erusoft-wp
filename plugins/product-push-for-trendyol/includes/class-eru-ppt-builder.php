<?php
/**
 * Builds Trendyol Product V2 items from WooCommerce products and validates them before anything is sent.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple product → one item. Variable product → one item per published variation, all with the parent's
 * productMainId (parent SKU or ppt-<parent id>) and their own barcode; variant attributes come from the
 * variation's attributes through the attribute map. Validation returns Turkish messages.
 */
class Eru_PPT_Builder {

	const MAX_IMAGES = 8;
	const TITLE_MAX  = 100;
	const DESC_MAX   = 30000;

	/**
	 * JSON with the shortest exact float representation (194.35 stays 194.35 whatever the server's
	 * serialize_precision); the previous ini value is restored.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function encode( $data ) {
		$old = ini_get( 'serialize_precision' );
		ini_set( 'serialize_precision', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			$json = (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} finally {
			if ( false !== $old ) {
				ini_set( 'serialize_precision', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			}
		}
		return $json;
	}

	/** @param mixed $v Amount. @return int|float Rounded to 2 decimals, whole numbers as int. */
	public static function num( $v ) {
		$f = round( (float) $v, 2 );
		return ( floor( $f ) === $f && abs( $f ) < 1e12 ) ? (int) $f : $f;
	}

	/**
	 * Barcode of a simple product or variation: per-object override, then the configured source, then
	 * prefix + id when a prefix is set.
	 *
	 * @param WC_Product $obj Object.
	 * @return string '' when none.
	 */
	public static function barcode( $obj ) {
		$b = trim( (string) $obj->get_meta( '_eru_ppt_barcode' ) );
		if ( '' !== $b ) {
			return $b;
		}
		switch ( (string) Eru_PPT_Settings::get( 'barcode_source' ) ) {
			case 'gtin':
				$b = method_exists( $obj, 'get_global_unique_id' ) ? (string) $obj->get_global_unique_id() : '';
				break;
			case 'meta':
				$key = (string) Eru_PPT_Settings::get( 'barcode_meta_key' );
				$b   = '' !== $key ? (string) $obj->get_meta( $key ) : '';
				break;
			default:
				$b = (string) $obj->get_sku();
		}
		$b = trim( $b );
		if ( '' === $b && '' !== (string) Eru_PPT_Settings::get( 'barcode_prefix' ) ) {
			$b = Eru_PPT_Settings::get( 'barcode_prefix' ) . $obj->get_id();
		}
		return $b;
	}

	/** @param WC_Product $product Parent. @return string productMainId. */
	public static function main_id( $product ) {
		$sku = trim( (string) $product->get_sku() );
		return '' !== $sku ? substr( $sku, 0, 40 ) : 'ppt-' . $product->get_id();
	}

	/**
	 * Objects that become items: the product itself (simple) or its published variations.
	 *
	 * @param WC_Product $product Product.
	 * @return WC_Product[]
	 */
	public static function objects( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$out = array();
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v && 'publish' === $v->get_status() ) {
					$out[] = $v;
				}
			}
			return $out;
		}
		return array( $product );
	}

	/**
	 * @param int    $cat Trendyol category.
	 * @param string $wc  WC attribute key (pa_x or sanitised custom name).
	 * @return array|null Mapping row (category-specific first).
	 */
	public static function mapping( $cat, $wc ) {
		$any = null;
		foreach ( (array) Eru_PPT_Settings::get( 'attribute_map' ) as $row ) {
			if ( ! is_array( $row ) || ( $row['wc'] ?? '' ) !== $wc ) {
				continue;
			}
			if ( (int) ( $row['cat'] ?? 0 ) === (int) $cat ) {
				return $row;
			}
			if ( 0 === (int) ( $row['cat'] ?? 0 ) && null === $any ) {
				$any = $row;
			}
		}
		return $any;
	}

	/**
	 * Builds and validates.
	 *
	 * @param WC_Product $product Simple or variable product.
	 * @param bool       $remote  False in page requests: category data from the cache only (a miss queues a
	 *                            background refresh and reports "yükleniyor").
	 * @return array{items:array,errors:string[],objects:array<string,int>,main_id:string,category:int,variant_attrs:array<string,int[]>}
	 */
	public static function build( $product, $remote = true ) {
		$out = array(
			'items'         => array(),
			'errors'        => array(),
			'objects'       => array(),
			'main_id'       => '',
			'category'      => 0,
			'variant_attrs' => array(),
			'warnings'      => array(),
		);
		if ( $product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product || ! ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) ) {
			$out['errors'][] = __( 'Yalnızca basit ve varyantlı ürünler gönderilebilir.', 'product-push-for-trendyol' );
			return $out;
		}
		$s      = Eru_PPT_Settings::all();
		$errors   = array();
		$warnings = array();

		$cat             = Eru_PPT_Categories::resolve( $product );
		$out['category'] = $cat['id'];
		$defs            = null;
		if ( ! $cat['id'] ) {
			$errors[] = __( 'Trendyol kategorisi seçilmemiş: ürün ekranından seçin veya kategori eşleme tablosuna ekleyin.', 'product-push-for-trendyol' );
		} else {
			$defs = Eru_PPT_Categories::attributes( $cat['id'], false, $remote );
			if ( is_wp_error( $defs ) && 'eru_ppt_loading' === $defs->get_error_code() ) {
				$errors[] = $defs->get_error_message();
				$defs     = null;
			} elseif ( is_wp_error( $defs ) ) {
				/* translators: %s: error */
				$errors[] = sprintf( __( 'Trendyol kategori özellikleri alınamadı: %s', 'product-push-for-trendyol' ), $defs->get_error_message() );
				$defs     = null;
			}
		}
		$def_by_id = array();
		foreach ( (array) $defs as $d ) {
			$def_by_id[ (int) $d['id'] ] = $d;
		}

		$brand = absint( $product->get_meta( '_eru_ppt_brand_id' ) );
		$brand = $brand ? $brand : absint( $s['brand_id'] );
		if ( ! $brand ) {
			$errors[] = __( 'Trendyol markası seçilmemiş: ayarlardan varsayılan markayı seçin veya ürün ekranında girin.', 'product-push-for-trendyol' );
		}
		if ( ! absint( $s['cargo_company_id'] ) ) {
			$errors[] = __( 'Kargo firması kimliği ayarlanmamış (WooCommerce → Trendyol Ürün Gönderimi).', 'product-push-for-trendyol' );
		}

		$objects = self::objects( $product );
		if ( ! $objects ) {
			$errors[] = __( 'Varyantlı ürünün yayında varyasyonu yok.', 'product-push-for-trendyol' );
		}
		$main_id        = self::main_id( $product );
		$out['main_id'] = $main_id;

		// Product-level attributes: the metabox form wins over mapped WC attributes.
		$base = array();
		foreach ( (array) $product->get_meta( '_eru_ppt_attributes' ) as $aid => $v ) {
			if ( is_array( $v ) && isset( $v['v'] ) && absint( $v['v'] ) ) {
				$base[ absint( $aid ) ] = array( 'v' => absint( $v['v'] ) );
			} elseif ( is_array( $v ) && isset( $v['c'] ) && '' !== trim( (string) $v['c'] ) ) {
				$base[ absint( $aid ) ] = array( 'c' => trim( (string) $v['c'] ) );
			}
		}
		foreach ( $product->get_attributes() as $wa ) {
			if ( ! $wa instanceof WC_Product_Attribute || ( $wa->get_variation() && $product->is_type( 'variable' ) ) ) {
				continue;
			}
			$key = sanitize_title( $wa->get_name() );
			$row = $cat['id'] ? self::mapping( $cat['id'], $key ) : null;
			if ( ! $row || isset( $base[ (int) $row['ty'] ] ) ) {
				continue;
			}
			if ( $wa->is_taxonomy() ) {
				$terms = $wa->get_terms();
				$first = $terms ? reset( $terms ) : null;
				$slug  = $first ? $first->slug : '';
				$label = $first ? $first->name : '';
			} else {
				$opts  = $wa->get_options();
				$label = $opts ? (string) reset( $opts ) : '';
				$slug  = sanitize_title( $label );
			}
			$mapped = self::map_value( $row, $slug, $label, $def_by_id );
			if ( null !== $mapped ) {
				$base[ (int) $row['ty'] ] = $mapped;
			}
		}

		$images_parent = array();
		foreach ( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) as $iid ) {
			if ( $iid ) {
				$images_parent[] = (int) $iid;
			}
		}

		$title_over = trim( (string) $product->get_meta( '_eru_ppt_title' ) );
		$desc_over  = trim( (string) $product->get_meta( '_eru_ppt_description' ) );
		$desc       = '' !== $desc_over ? $desc_over : (string) $product->get_description();
		if ( '' === trim( wp_strip_all_tags( $desc ) ) ) {
			$desc = (string) $product->get_short_description();
		}
		$prefix = (string) $s['description_prefix'];
		$desc   = trim( ( '' !== trim( $prefix ) ? $prefix . "\n" : '' ) . $desc );
		if ( '' === trim( wp_strip_all_tags( $desc ) ) ) {
			$errors[] = __( 'Açıklama boş: ürün açıklaması veya Trendyol açıklaması girin.', 'product-push-for-trendyol' );
		} elseif ( mb_strlen( $desc ) > self::DESC_MAX ) {
			$errors[] = __( 'Açıklama 30.000 karakteri aşıyor.', 'product-push-for-trendyol' );
		}

		$seen = array();
		foreach ( $objects as $obj ) {
			$is_var = $obj->is_type( 'variation' );
			$label  = $is_var ? sprintf( /* translators: 1: variation id, 2: attribute summary */ __( 'Varyasyon #%1$d (%2$s): ', 'product-push-for-trendyol' ), $obj->get_id(), self::summary( $obj ) ) : '';
			$errs   = array();

			$barcode = self::barcode( $obj );
			if ( '' === $barcode ) {
				$errs[] = __( 'Barkod eksik: barkod kaynağını (SKU / GTIN / meta) doldurun, ürün ekranında barkod girin veya ayarlarda barkod öneki tanımlayın.', 'product-push-for-trendyol' );
			} elseif ( ! preg_match( '/^[A-Za-z0-9._\-]{1,40}$/', $barcode ) ) {
				/* translators: %s: barcode */
				$errs[] = sprintf( __( 'Barkod geçersiz: "%s" (en fazla 40 karakter; harf, rakam, nokta, tire, alt çizgi).', 'product-push-for-trendyol' ), $barcode );
			} elseif ( isset( $seen[ $barcode ] ) ) {
				/* translators: %s: barcode */
				$errs[] = sprintf( __( 'Barkod "%s" bu üründe birden fazla kez kullanılıyor.', 'product-push-for-trendyol' ), $barcode );
			}
			$seen[ $barcode ] = true;

			// Attributes: product level, then the variation's own mapped values.
			$attrs    = $base;
			$variant  = array();
			$reported = array();
			if ( $is_var ) {
				foreach ( $obj->get_attributes() as $key => $value ) {
					$key = (string) $key;
					$row = $cat['id'] ? self::mapping( $cat['id'], sanitize_title( $key ) ) : null;
					if ( ! $row ) {
						continue;
					}
					$tyname = $def_by_id[ (int) $row['ty'] ]['name'] ?? (string) $row['ty'];
					if ( '' === (string) $value ) {
						/* translators: %s: attribute */
						$errs[]                     = sprintf( __( '"%s" değeri seçilmemiş (herhangi bir değer olamaz).', 'product-push-for-trendyol' ), $tyname );
						$reported[ (int) $row['ty'] ] = true;
						continue;
					}
					if ( taxonomy_exists( $key ) ) {
						$term = get_term_by( 'slug', (string) $value, $key );
						$name = $term ? $term->name : (string) $value;
						$slug = (string) $value;
					} else {
						$name = (string) $value;
						$slug = sanitize_title( $name );
					}
					$mapped = self::map_value( $row, $slug, $name, $def_by_id );
					if ( null === $mapped ) {
						/* translators: 1: Trendyol attribute, 2: value */
						$errs[]                     = sprintf( __( '"%1$s" için "%2$s" değeri eşlenmemiş (Özellik eşleme tablosu).', 'product-push-for-trendyol' ), $tyname, $name );
						$reported[ (int) $row['ty'] ] = true;
						continue;
					}
					$attrs[ (int) $row['ty'] ] = $mapped;
					$variant[]                 = (int) $row['ty'];
				}
			}

			$list = array();
			if ( null !== $defs ) {
				foreach ( $attrs as $aid => $v ) {
					if ( ! isset( $def_by_id[ $aid ] ) ) {
						continue; // Not an attribute of this category (e.g. left over from another category).
					}
					$d = $def_by_id[ $aid ];
					if ( isset( $v['c'] ) ) {
						if ( empty( $d['allowCustom'] ) ) {
							/* translators: %s: attribute */
							$errs[] = sprintf( __( '"%s" serbest değer kabul etmiyor; listeden seçin.', 'product-push-for-trendyol' ), $d['name'] );
							continue;
						}
						$list[] = array(
							'attributeId'          => $aid,
							'customAttributeValue' => mb_substr( (string) $v['c'], 0, 200 ),
						);
						continue;
					}
					$known = in_array( (int) $v['v'], array_map( 'intval', array_column( $d['values'], 'id' ) ), true );
					if ( $d['values'] && empty( $d['complete'] ) && ! $known ) {
						/* translators: %s: attribute */
						$warnings[] = $label . sprintf( __( '"%s": değer doğrulanamadı (Trendyol değer listesi eksik); Trendyol sonucu kesinleştirecek.', 'product-push-for-trendyol' ), $d['name'] );
					}
					if ( $d['values'] && ! empty( $d['complete'] ) && ! $known ) {
						/* translators: %s: attribute */
						$errs[] = sprintf( __( '"%s" için geçersiz değer; listeden yeniden seçin.', 'product-push-for-trendyol' ), $d['name'] );
						continue;
					}
					$list[] = array(
						'attributeId'      => $aid,
						'attributeValueId' => (int) $v['v'],
					);
				}
				foreach ( $defs as $d ) {
					if ( ! empty( $d['required'] ) && ! isset( $attrs[ (int) $d['id'] ] ) && ! isset( $reported[ (int) $d['id'] ] ) ) {
						/* translators: %s: attribute */
						$errs[] = sprintf( __( 'Zorunlu özellik eksik: %s', 'product-push-for-trendyol' ), $d['name'] );
					}
				}
			}

			// Title.
			$title = '' !== $title_over ? $title_over : self::title( (string) $s['title_template'], $product, $obj );
			if ( '' === $title ) {
				$errs[] = __( 'Başlık boş.', 'product-push-for-trendyol' );
			} elseif ( mb_strlen( $title ) > self::TITLE_MAX ) {
				$errs[] = __( 'Başlık 100 karakteri aşıyor; Trendyol başlığını kısaltın.', 'product-push-for-trendyol' );
			}

			// Prices.
			$sale = self::num( $obj->get_price() );
			if ( $sale <= 0 ) {
				$errs[] = __( 'Fiyat 0\'dan büyük olmalı.', 'product-push-for-trendyol' );
			}
			$list_over = Eru_PPT_Settings::decimal( $obj->get_meta( '_eru_ppt_list_price' ) );
			if ( '' !== $list_over && (float) $list_over > 0 ) {
				$lp = self::num( $list_over );
			} else {
				$reg = (float) $obj->get_regular_price();
				$lp  = self::num( ( $reg > 0 ? $reg : (float) $sale ) * (float) $s['list_multiplier'] );
			}
			if ( $lp < $sale ) {
				$lp = $sale;
			}

			// Images.
			$ids = $images_parent;
			if ( $is_var && $obj->get_image_id() ) {
				array_unshift( $ids, (int) $obj->get_image_id() );
			}
			$images = array();
			foreach ( array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_IMAGES ) as $iid ) {
				$url = (string) wp_get_attachment_url( $iid );
				if ( '' !== $url ) {
					$images[] = array( 'url' => $url );
				}
			}
			if ( ! $images ) {
				$errs[] = __( 'En az bir ürün görseli gerekli.', 'product-push-for-trendyol' );
			}
			foreach ( $images as $im ) {
				if ( 0 !== strpos( $im['url'], 'https://' ) ) {
					/* translators: %s: image URL */
					$errs[] = sprintf( __( 'Görsel adresi https ile başlamalı ve herkese açık olmalı: %s', 'product-push-for-trendyol' ), $im['url'] );
					break;
				}
			}

			$qty = 0;
			if ( 'stock' === $s['push_quantity'] && $obj->managing_stock() ) {
				$qty = max( 0, (int) $obj->get_stock_quantity() );
			}

			$item = array(
				'barcode'           => $barcode,
				'title'             => $title,
				'productMainId'     => $main_id,
				'brandId'           => $brand,
				'categoryId'        => (int) $cat['id'],
				'quantity'          => $qty,
				'stockCode'         => substr( '' !== (string) $obj->get_sku() ? (string) $obj->get_sku() : $barcode, 0, 100 ),
				'dimensionalWeight' => self::num( $s['dimensional_weight'] ),
				'description'       => $desc,
				'currencyType'      => 'TRY',
				'listPrice'         => $lp,
				'salePrice'         => $sale,
				'vatRate'           => (int) $s['vat_rate'],
				'cargoCompanyId'    => absint( $s['cargo_company_id'] ),
				'deliveryOption'    => array( 'deliveryDuration' => (int) $s['delivery_duration'] ),
				'images'            => $images,
				'attributes'        => $list,
			);
			if ( absint( $s['shipment_address_id'] ) ) {
				$item['shipmentAddressId'] = absint( $s['shipment_address_id'] );
			}
			if ( absint( $s['returning_address_id'] ) ) {
				$item['returningAddressId'] = absint( $s['returning_address_id'] );
			}

			/**
			 * Last chance to change an item (e.g. wording rules of your store).
			 *
			 * @param array      $item    Trendyol item.
			 * @param WC_Product $obj     Simple product or variation.
			 * @param WC_Product $product Parent product.
			 */
			$item = apply_filters( 'eru_ppt_item', $item, $obj, $product );
			if ( ! is_array( $item ) ) {
				$errs[] = __( 'eru_ppt_item filtresi geçersiz bir öğe döndürdü.', 'product-push-for-trendyol' );
				$item   = array();
			}

			foreach ( $errs as $e ) {
				$errors[] = $label . $e;
			}
			if ( '' !== $barcode && $item ) {
				$out['items'][]                     = $item;
				$out['objects'][ $barcode ]         = $obj->get_id();
				$out['variant_attrs'][ $barcode ]   = $variant;
			}
		}
		$out['errors']   = array_values( array_unique( $errors ) );
		$out['warnings'] = array_values( array_unique( $warnings ) );
		return $out;
	}

	/**
	 * @param array  $row       Mapping row.
	 * @param string $slug      WC value slug.
	 * @param string $name      WC value label.
	 * @param array  $def_by_id Category attribute definitions.
	 * @return array|null ['v' => id] | ['c' => text] | null when it cannot be mapped.
	 */
	private static function map_value( array $row, $slug, $name, array $def_by_id ) {
		$values = (array) ( $row['values'] ?? array() );
		if ( isset( $values[ $slug ] ) ) {
			return array( 'v' => (int) $values[ $slug ] );
		}
		$san = sanitize_title( $name );
		if ( isset( $values[ $san ] ) ) {
			return array( 'v' => (int) $values[ $san ] );
		}
		$d = $def_by_id[ (int) $row['ty'] ] ?? null;
		if ( $d ) {
			foreach ( (array) $d['values'] as $v ) {
				if ( Eru_PPT_Categories::lower( $v['name'] ) === Eru_PPT_Categories::lower( $name ) ) {
					return array( 'v' => (int) $v['id'] ); // Same label on both sides.
				}
			}
			if ( ! empty( $d['allowCustom'] ) && '' !== $name ) {
				return array( 'c' => $name );
			}
		}
		return null;
	}

	/** @param WC_Product $obj Variation. @return string "M, Siyah". */
	public static function summary( $obj ) {
		$parts = array();
		foreach ( $obj->get_attributes() as $key => $value ) {
			if ( taxonomy_exists( (string) $key ) ) {
				$t       = get_term_by( 'slug', (string) $value, (string) $key );
				$parts[] = $t ? $t->name : (string) $value;
			} else {
				$parts[] = (string) $value;
			}
		}
		return implode( ', ', array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Title from the template: {name}, {sku}, {attribute:pa_x}.
	 *
	 * @param string     $tpl     Template.
	 * @param WC_Product $product Parent.
	 * @param WC_Product $obj     Object.
	 * @return string
	 */
	public static function title( $tpl, $product, $obj ) {
		$t = str_replace( array( '{name}', '{sku}' ), array( $product->get_name(), (string) $obj->get_sku() ), $tpl );
		$t = (string) preg_replace_callback(
			'/\{attribute:([a-z0-9_\-]+)\}/i',
			function ( $m ) use ( $product, $obj ) {
				$key = sanitize_title( $m[1] );
				if ( $obj->is_type( 'variation' ) ) {
					$atts = $obj->get_attributes();
					if ( isset( $atts[ $key ] ) && '' !== (string) $atts[ $key ] ) {
						if ( taxonomy_exists( $key ) ) {
							$term = get_term_by( 'slug', (string) $atts[ $key ], $key );
							return $term ? $term->name : (string) $atts[ $key ];
						}
						return (string) $atts[ $key ];
					}
				}
				$val = (string) $product->get_attribute( $key );
				$val = explode( ',', $val );
				return trim( (string) reset( $val ) );
			},
			$t
		);
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $t ) ) );
	}
}
