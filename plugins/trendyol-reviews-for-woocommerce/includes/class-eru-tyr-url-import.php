<?php
/**
 * Single-product (URL) and CSV import modes.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Both modes write to the manual mapping table first, so later store runs
 * and the daily sync keep the pairing.
 */
class Eru_TYR_Url_Import {

	const MAX_CSV_BYTES = 2 * MB_IN_BYTES;
	const MAX_CSV_ROWS  = 1000;

	/**
	 * Map one Trendyol product to one WooCommerce product and import now.
	 *
	 * @return array|WP_Error { imported: int, skipped: int, content_id: int }
	 */
	public static function import_url( string $trendyol, int $product_id ) {
		$content_id = Eru_TYR_Matcher::content_id_from( $trendyol );
		if ( ! $content_id ) {
			return new WP_Error( 'eru_tyr_url', __( 'Geçerli bir Trendyol ürün linki (…-p-123456) veya ürün numarası girin.', 'trendyol-reviews-for-woocommerce' ) );
		}
		if ( ! Eru_TYR_Matcher::set_mapping( $content_id, $product_id ) ) {
			return new WP_Error( 'eru_tyr_product', __( 'WooCommerce ürünü bulunamadı.', 'trendyol-reviews-for-woocommerce' ) );
		}
		Eru_TYR_Store_Import::forget_unmatched( $content_id );

		$importer = new Eru_TYR_Importer();
		$result   = $importer->import_reviews( $product_id, $content_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result + array( 'content_id' => $content_id );
	}

	/**
	 * Parse a two-column CSV: WooCommerce product (ID, URL or SKU) and
	 * Trendyol product (URL or content ID). Comma, semicolon or tab separated;
	 * a header row is ignored.
	 *
	 * @return array|WP_Error { rows: array<array{product_id:int,content_id:int}>, invalid: int[] line numbers }
	 */
	public static function parse_csv( string $path ) {
		if ( ! is_readable( $path ) || filesize( $path ) > self::MAX_CSV_BYTES ) {
			return new WP_Error( 'eru_tyr_csv', __( 'CSV dosyası okunamadı veya 2 MB sınırını aşıyor.', 'trendyol-reviews-for-woocommerce' ) );
		}
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'eru_tyr_csv', __( 'CSV dosyası okunamadı.', 'trendyol-reviews-for-woocommerce' ) );
		}

		$first     = (string) fgets( $handle );
		$delimiter = ',';
		foreach ( array( ';', "\t" ) as $candidate ) {
			if ( substr_count( $first, $candidate ) > substr_count( $first, $delimiter ) ) {
				$delimiter = $candidate;
			}
		}
		rewind( $handle );

		$rows    = array();
		$invalid = array();
		$line    = 0;
		while ( ( $data = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$line++;
			if ( count( $rows ) >= self::MAX_CSV_ROWS ) {
				$invalid[] = $line;
				continue;
			}
			$left  = isset( $data[0] ) ? trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $data[0] ) ) : '';
			$right = isset( $data[1] ) ? trim( (string) $data[1] ) : '';
			if ( '' === $left && '' === $right ) {
				continue;
			}
			$content_id = Eru_TYR_Matcher::content_id_from( $right );
			$product_id = self::resolve_product( $left );
			if ( ! $content_id || ! $product_id ) {
				if ( 1 !== $line ) { // The first line may be a header.
					$invalid[] = $line;
				}
				continue;
			}
			$rows[] = array( 'product_id' => $product_id, 'content_id' => $content_id );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array( 'rows' => $rows, 'invalid' => $invalid );
	}

	/**
	 * Save the mappings and queue one background import per row.
	 *
	 * @param array<array{product_id:int,content_id:int}> $rows
	 * @return int Queued rows.
	 */
	public static function queue_rows( array $rows ): int {
		$queued = 0;
		foreach ( $rows as $row ) {
			if ( Eru_TYR_Matcher::set_mapping( $row['content_id'], $row['product_id'] ) ) {
				Eru_TYR_Store_Import::forget_unmatched( $row['content_id'] );
				Eru_TYR_Store_Import::queue_product( $row['product_id'], $row['content_id'] );
				$queued++;
			}
		}
		return $queued;
	}

	/**
	 * WooCommerce product from an ID, a product URL or a SKU (variations resolve to the parent).
	 */
	public static function resolve_product( string $identifier ): int {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return 0;
		}
		$id = 0;
		if ( ctype_digit( $identifier ) ) {
			$id = (int) $identifier;
		} elseif ( preg_match( '#^https?://#i', $identifier ) ) {
			$id = url_to_postid( $identifier );
			if ( ! $id ) {
				$slug = basename( (string) wp_parse_url( $identifier, PHP_URL_PATH ) );
				$post = $slug ? get_page_by_path( $slug, OBJECT, 'product' ) : null;
				$id   = $post ? (int) $post->ID : 0;
			}
		} else {
			$id = (int) wc_get_product_id_by_sku( $identifier );
		}
		if ( $id && 'product_variation' === get_post_type( $id ) ) {
			$id = (int) wp_get_post_parent_id( $id );
		}
		return ( $id && 'product' === get_post_type( $id ) ) ? $id : 0;
	}
}
