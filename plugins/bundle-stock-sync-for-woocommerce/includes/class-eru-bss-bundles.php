<?php
/**
 * Bundle definitions and availability maths.
 *
 * A bundle is a product that carries `_eru_bss_components` (list of SKU + quantity).
 * Components are resolved by SKU. A component cannot itself be a bundle (no nesting).
 *
 * @package bundle-stock-sync-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BSS_Bundles {

	const META_COMPONENTS = '_eru_bss_components';
	const META_INDEX      = '_eru_bss_comp_id';

	/**
	 * Raw component definition of a product: list of array( sku, qty ).
	 */
	public static function get_components( $product_id ) {
		$raw = get_post_meta( (int) $product_id, self::META_COMPONENTS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['sku'] ) ) {
				continue;
			}
			$out[] = array(
				'sku' => (string) $row['sku'],
				'qty' => max( 1, (int) ( $row['qty'] ?? 1 ) ),
			);
		}
		return $out;
	}

	public static function is_bundle( $product_id ) {
		return (bool) self::get_components( $product_id );
	}

	/**
	 * Normalise user input rows (sku/qty), dropping empties and merging duplicates.
	 */
	public static function sanitize_components( $rows ) {
		$merged = array();
		foreach ( (array) $rows as $row ) {
			$sku = isset( $row['sku'] ) ? trim( sanitize_text_field( wp_unslash( (string) $row['sku'] ) ) ) : '';
			if ( '' === $sku ) {
				continue;
			}
			$qty            = max( 1, min( 9999, (int) ( $row['qty'] ?? 1 ) ) );
			$merged[ $sku ] = ( $merged[ $sku ] ?? 0 ) + $qty;
		}
		$out = array();
		foreach ( $merged as $sku => $qty ) {
			$out[] = array(
				'sku' => (string) $sku,
				'qty' => $qty,
			);
		}
		return $out;
	}

	/**
	 * Resolve components to products. Returns list of array( sku, qty, id, product|null, issue|'' ).
	 */
	public static function resolve( $bundle_id, $components = null ) {
		$components = null === $components ? self::get_components( $bundle_id ) : $components;
		$out        = array();
		foreach ( $components as $c ) {
			$id    = (int) wc_get_product_id_by_sku( $c['sku'] );
			$prod  = $id ? wc_get_product( $id ) : null;
			$issue = '';
			if ( ! $prod ) {
				$issue = 'missing';
			} elseif ( $id === (int) $bundle_id ) {
				$issue = 'self';
			} elseif ( self::is_bundle( $id ) ) {
				$issue = 'nested';
			}
			$out[] = array(
				'sku'     => $c['sku'],
				'qty'     => $c['qty'],
				'id'      => $id,
				'product' => $prod,
				'issue'   => $issue,
			);
		}
		return $out;
	}

	/**
	 * Validate a definition before it is stored. Returns true or WP_Error.
	 */
	public static function validate( $bundle_id, $components ) {
		if ( ! $components ) {
			return true;
		}
		foreach ( self::resolve( $bundle_id, $components ) as $r ) {
			if ( 'self' === $r['issue'] ) {
				return new WP_Error( 'eru_bss_self', __( 'Bir paket kendi içeriğinde yer alamaz.', 'bundle-stock-sync-for-woocommerce' ) );
			}
			if ( 'nested' === $r['issue'] ) {
				/* translators: %s: SKU */
				return new WP_Error( 'eru_bss_nested', sprintf( __( '"%s" başka bir paket; iç içe paketler desteklenmez.', 'bundle-stock-sync-for-woocommerce' ), $r['sku'] ) );
			}
		}
		// This product must not already be a component of another bundle.
		if ( self::bundles_containing( array( (int) $bundle_id ) ) ) {
			return new WP_Error( 'eru_bss_is_component', __( 'Bu ürün başka bir paketin bileşeni; iç içe paketler desteklenmez.', 'bundle-stock-sync-for-woocommerce' ) );
		}
		return true;
	}

	/**
	 * Store a definition (validated), rebuild the reverse index and recalculate stock.
	 *
	 * @return true|WP_Error
	 */
	public static function set_components( $bundle_id, $components ) {
		$bundle_id  = (int) $bundle_id;
		$components = self::sanitize_components( $components );
		$ok         = self::validate( $bundle_id, $components );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		delete_post_meta( $bundle_id, self::META_INDEX );
		if ( ! $components ) {
			delete_post_meta( $bundle_id, self::META_COMPONENTS );
			return true;
		}
		update_post_meta( $bundle_id, self::META_COMPONENTS, $components );
		foreach ( self::resolve( $bundle_id, $components ) as $r ) {
			if ( $r['id'] ) {
				add_post_meta( $bundle_id, self::META_INDEX, $r['id'] );
			}
		}
		Eru_BSS_Sync::recalc( $bundle_id );
		return true;
	}

	/**
	 * Bundle ids that contain any of the given component product ids.
	 */
	public static function bundles_containing( array $component_ids ) {
		global $wpdb;
		$component_ids = array_values( array_filter( array_map( 'intval', $component_ids ) ) );
		if ( ! $component_ids ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $component_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ($in)", array_merge( array( self::META_INDEX ), $component_ids ) ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Availability of one component, in bundle units.
	 *
	 * @return int|null Whole bundles the component can supply; null = not limited.
	 */
	private static function component_supply( array $r ) {
		if ( $r['issue'] || ! $r['product'] ) {
			return 0;
		}
		$p = $r['product'];
		if ( 'publish' !== $p->get_status() ) {
			return 0;
		}
		if ( $p->is_type( 'variation' ) ) {
			$parent = get_post_status( $p->get_parent_id() );
			if ( 'publish' !== $parent ) {
				return 0;
			}
		}
		if ( $p->managing_stock() ) {
			if ( $p->get_stock_quantity() <= 0 && $p->backorders_allowed() ) {
				return null; // Sold on backorder: does not limit the bundle.
			}
			return (int) floor( max( 0, (float) $p->get_stock_quantity() ) / $r['qty'] );
		}
		return 'outofstock' === $p->get_stock_status() ? 0 : null;
	}

	/**
	 * Calculate bundle availability.
	 *
	 * @return array|null null when the product is not a bundle; else array( qty => int|null, issues => list ).
	 */
	public static function availability( $bundle_id, $fresh = false ) {
		$resolved = self::resolve( $bundle_id );
		if ( $fresh ) {
			foreach ( $resolved as $i => $r ) {
				if ( $r['id'] ) {
					wp_cache_delete( $r['id'], 'post_meta' );
					wp_cache_delete( 'product-' . $r['id'], 'products' );
					$resolved[ $i ]['product'] = wc_get_product( $r['id'] );
				}
			}
		}
		if ( ! $resolved ) {
			return null;
		}
		$qty    = null;
		$issues = array();
		foreach ( $resolved as $r ) {
			if ( $r['issue'] ) {
				$issues[] = $r;
			}
			$s = self::component_supply( $r );
			if ( null !== $s ) {
				$qty = null === $qty ? $s : min( $qty, $s );
			}
		}
		return array(
			'qty'    => $qty,
			'issues' => $issues,
		);
	}
}
