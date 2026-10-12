<?php
/**
 * Trendyol product -> WooCommerce product matching.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Priority: manual mapping table (`eru_tyr_map`, content ID => product ID)
 * → barcode in a product meta (default `_global_unique_id`, the WooCommerce
 * GTIN/EAN field) → SKU (the Trendyol stock codes, then the barcodes used as SKU).
 * Variations resolve to their parent product.
 */
class Eru_TYR_Matcher {

	const MAP_OPTION = 'eru_tyr_map';

	/**
	 * @param array $ty_product { content_id?: int, barcode?: string|string[], sku?: string|string[] }
	 * @return int|null WooCommerce product ID.
	 */
	public static function match( array $ty_product ): ?int {
		$content_id = (int) ( $ty_product['content_id'] ?? 0 );
		$barcodes   = array_values( array_filter( array_map( 'strval', (array) ( $ty_product['barcode'] ?? array() ) ), 'strlen' ) );
		$skus       = array_values( array_filter( array_map( 'strval', (array) ( $ty_product['sku'] ?? array() ) ), 'strlen' ) );
		$found      = null;

		if ( $content_id ) {
			$map = self::get_map();
			if ( isset( $map[ $content_id ] ) && self::is_product( $map[ $content_id ] ) ) {
				$found = (int) $map[ $content_id ];
			}
		}

		if ( null === $found && $barcodes ) {
			$found = self::find_by_meta( (string) Eru_TYR_Settings::get( 'barcode_meta' ), self::barcode_variants( $barcodes ) );
		}

		if ( null === $found ) {
			foreach ( array_merge( $skus, $barcodes ) as $code ) {
				$code = trim( $code );
				if ( '' === $code ) {
					continue;
				}
				$id = (int) wc_get_product_id_by_sku( $code );
				if ( $id ) {
					$found = self::parent_of( $id );
					break;
				}
			}
		}

		/**
		 * Filters the matched WooCommerce product ID (null = unmatched).
		 *
		 * @param int|null $found
		 * @param array    $ty_product
		 */
		$found = apply_filters( 'eru_tyr_match', $found, $ty_product );

		return $found ? (int) $found : null;
	}

	/* ---------------------------------------------------------------------
	 * Manual mapping table
	 * ------------------------------------------------------------------- */

	/**
	 * @return array<int,int> content ID => product ID
	 */
	public static function get_map(): array {
		$map = get_option( self::MAP_OPTION, array() );
		return is_array( $map ) ? array_map( 'intval', $map ) : array();
	}

	public static function set_mapping( int $content_id, int $product_id ): bool {
		if ( $content_id <= 0 || ! self::is_product( $product_id ) ) {
			return false;
		}
		$map                = self::get_map();
		$map[ $content_id ] = $product_id;
		update_option( self::MAP_OPTION, $map, false );
		return true;
	}

	public static function remove_mapping( int $content_id ): void {
		$map = self::get_map();
		unset( $map[ $content_id ] );
		update_option( self::MAP_OPTION, $map, false );
	}

	/**
	 * Content ID from a Trendyol product URL (`…-p-123456`) or a bare number.
	 */
	public static function content_id_from( string $value ): int {
		$value = trim( $value );
		if ( preg_match( '#^https?://(?:www\.|m\.)?trendyol\.com/.*?-p-(\d+)#i', $value, $m ) ) {
			return (int) $m[1];
		}
		return preg_match( '/^\d{1,15}$/', $value ) ? (int) $value : 0;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	protected static function is_product( int $id ): bool {
		return $id > 0 && 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id );
	}

	protected static function parent_of( int $id ): int {
		if ( 'product_variation' === get_post_type( $id ) ) {
			$parent = (int) wp_get_post_parent_id( $id );
			return $parent ? $parent : $id;
		}
		return $id;
	}

	/**
	 * A barcode may be stored with or without leading zeros (EAN-13 vs GTIN-14).
	 *
	 * @param string[] $barcodes
	 * @return string[]
	 */
	protected static function barcode_variants( array $barcodes ): array {
		$out = array();
		foreach ( $barcodes as $barcode ) {
			$barcode = trim( $barcode );
			$out[]   = $barcode;
			$digits  = preg_replace( '/\D/', '', $barcode );
			if ( strlen( $digits ) >= 8 && strlen( $digits ) <= 14 ) {
				$bare  = ltrim( $digits, '0' );
				$out[] = $digits;
				$out[] = $bare;
				$out[] = str_pad( $bare, 13, '0', STR_PAD_LEFT );
				$out[] = str_pad( $bare, 14, '0', STR_PAD_LEFT );
			}
		}
		return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
	}

	/**
	 * @param string[] $values
	 */
	protected static function find_by_meta( string $meta_key, array $values ): ?int {
		global $wpdb;

		if ( '' === $meta_key || ! $values ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value IN ({$placeholders})
				   AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft')
				 ORDER BY p.post_type = 'product' DESC, p.ID ASC LIMIT 1",
				array_merge( array( $meta_key ), $values )
			)
		);

		return $id ? self::parent_of( $id ) : null;
	}
}
