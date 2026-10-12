<?php
/**
 * Inserts Trendyol reviews as native WooCommerce product reviews.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every imported review is a regular `review` comment with `rating` meta, so
 * star averages, review counts, structured data and other plugins keep
 * working. Extra comment meta:
 *   _eru_tyr_review_id  Trendyol review ID (site-wide duplicate guard)
 *   _eru_tyr_source     'trendyol'
 *   _eru_tyr_photos     array of { url, thumb } (optional)
 * Reviewer names are always masked before they are stored ("A*** B***").
 */
class Eru_TYR_Importer {

	const META_REVIEW_ID = '_eru_tyr_review_id';
	const META_SOURCE    = '_eru_tyr_source';
	const META_PHOTOS    = '_eru_tyr_photos';
	const PHOTO_DIR      = 'trendyol-reviews';
	const PAGE_SIZE      = 30;
	const MAX_PAGES      = 40;

	protected Eru_TYR_Client $client;

	public function __construct( ?Eru_TYR_Client $client = null ) {
		$this->client = $client ?? new Eru_TYR_Client();
	}

	/**
	 * Import the reviews of one Trendyol content ID into one product.
	 *
	 * Reviews arrive newest first; paging stops at the first page that holds
	 * an already imported review but nothing new, or at the per-product cap.
	 *
	 * @return array|WP_Error { imported: int, skipped: int }
	 */
	public function import_reviews( int $product_id, int $content_id ) {
		if ( 'product' !== get_post_type( $product_id ) || $content_id <= 0 ) {
			return new WP_Error( 'eru_tyr_bad_target', __( 'Geçersiz ürün veya Trendyol ürün numarası.', 'trendyol-reviews-for-woocommerce' ) );
		}

		$settings   = Eru_TYR_Settings::all();
		$seller     = (string) $settings['seller_id'];
		$max        = (int) $settings['max_reviews'];
		$min_rating = (int) $settings['min_rating'];
		$stored     = self::count_imported( $product_id );
		$imported   = 0;
		$skipped    = 0;
		$page       = 0;
		$seen       = array();

		do {
			$result = $this->client->get_reviews_page( $content_id, $page, self::PAGE_SIZE );
			if ( is_wp_error( $result ) ) {
				if ( $imported || $skipped ) {
					break; // Keep what was imported before the failure.
				}
				return $result;
			}
			if ( ! $result['reviews'] ) {
				break;
			}

			$known     = self::existing_review_ids( array_column( $result['reviews'], 'id' ) );
			$had_known = false;
			$new       = 0;

			foreach ( $result['reviews'] as $review ) {
				if ( isset( $known[ $review['id'] ] ) || isset( $seen[ $review['id'] ] ) ) {
					$had_known = true;
					continue;
				}
				$seen[ $review['id'] ] = true;

				// A product page can list reviews of purchases from other sellers: keep only the merchant's own
				// when the payload says which seller it belongs to.
				$other_seller = '' !== $seller && '' !== ( $review['seller_id'] ?? '' ) && (string) $review['seller_id'] !== $seller;
				if ( $other_seller || '' === trim( $review['comment'] ) || $review['rating'] < $min_rating ) {
					$skipped++;
					continue;
				}
				if ( $stored + $imported >= $max ) {
					break 2;
				}
				if ( $this->insert_review( $product_id, $review, ! empty( $settings['import_photos'] ) ) ) {
					$imported++;
					$new++;
				}
			}

			$page++;
			if ( $had_known && ! $new ) {
				break;
			}
		} while ( $page < $result['total_pages'] && $page < self::MAX_PAGES );

		if ( $imported ) {
			WC_Comments::clear_transients( $product_id );
			clean_post_cache( $product_id );
		}

		return array( 'imported' => $imported, 'skipped' => $skipped );
	}

	/**
	 * "Ayşe Yılmaz" → "A*** Y***". Parts Trendyol already hid ("****") get a
	 * deterministic invented initial seeded by the review ID.
	 */
	public static function mask_name( string $name, int $seed = 0 ): string {
		$alphabet = 'ABCDEFGHIKLMNOPRSTUYZ';
		$out      = array();
		foreach ( preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) as $index => $part ) {
			$first = mb_substr( $part, 0, 1, 'UTF-8' );
			if ( preg_match( '/\p{L}/u', $first ) ) {
				// Turkish casing: i → İ, ı → I (mb_strtoupper alone maps i → I).
				$first = mb_strtoupper( strtr( $first, array( 'i' => 'İ', 'ı' => 'I' ) ), 'UTF-8' );
			} else {
				$first = $alphabet[ absint( crc32( $seed . '-' . $index ) ) % strlen( $alphabet ) ];
			}
			$out[] = $first . '***';
		}
		if ( ! $out ) {
			$out = array( $alphabet[ absint( crc32( $seed . '-0' ) ) % strlen( $alphabet ) ] . '***' );
		}
		return implode( ' ', array_slice( $out, 0, 3 ) );
	}

	/**
	 * Photos are only stored/downloaded from Trendyol's CDN (filter `eru_tyr_photo_hosts`).
	 */
	public static function photo_allowed( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		foreach ( (array) apply_filters( 'eru_tyr_photo_hosts', array( 'dsmcdn.com' ) ) as $allowed ) {
			$allowed = strtolower( (string) $allowed );
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete every imported review and the local photo folder.
	 *
	 * @return int Deleted review count.
	 */
	public static function delete_all_imported(): int {
		$comments = get_comments(
			array(
				'type'       => 'review',
				'status'     => 'all',
				'meta_key'   => self::META_SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'trendyol', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$products = array();
		foreach ( $comments as $comment ) {
			$products[ (int) $comment->comment_post_ID ] = true;
			wp_delete_comment( $comment, true );
		}
		foreach ( array_keys( $products ) as $product_id ) {
			WC_Comments::clear_transients( $product_id );
		}
		self::delete_photo_dir();
		return count( $comments );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	protected function insert_review( int $product_id, array $review, bool $photos ): int {
		$timestamp  = $review['timestamp'] ? $review['timestamp'] : time();
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => self::mask_name( $review['author'], $review['id'] ),
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
				'comment_content'      => sanitize_textarea_field( $review['comment'] ),
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_agent'        => 'trendyol-reviews-for-woocommerce',
				'user_id'              => 0,
				'comment_date'         => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
				'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $timestamp ),
			)
		);
		if ( ! $comment_id ) {
			return 0;
		}

		update_comment_meta( $comment_id, 'rating', max( 1, min( 5, (int) $review['rating'] ) ) );
		update_comment_meta( $comment_id, self::META_REVIEW_ID, (int) $review['id'] );
		update_comment_meta( $comment_id, self::META_SOURCE, 'trendyol' );
		if ( $photos && ! empty( $review['photos'] ) ) {
			update_comment_meta( $comment_id, self::META_PHOTOS, self::localize_photos( (int) $review['id'], $review['photos'] ) );
		}

		return (int) $comment_id;
	}

	/**
	 * @param int[] $ids
	 * @return array<int,true> Review IDs that already exist anywhere on the site.
	 */
	protected static function existing_review_ids( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value IN ({$placeholders})", array_merge( array( self::META_REVIEW_ID ), $ids ) ) );
		return array_fill_keys( array_map( 'intval', $found ), true );
	}

	protected static function count_imported( int $product_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
				 WHERE c.comment_post_ID = %d AND cm.meta_key = %s",
				$product_id,
				self::META_REVIEW_ID
			)
		);
	}

	/**
	 * Keep only Trendyol CDN photos and download them into
	 * uploads/trendyol-reviews/; a photo that fails to download keeps its
	 * original (CDN) URL.
	 *
	 * @param array<array{url:string,thumb:string}> $photos
	 */
	protected static function localize_photos( int $review_id, array $photos ): array {
		$clean = array();
		foreach ( array_slice( $photos, 0, 10 ) as $photo ) {
			$url = (string) ( $photo['url'] ?? '' );
			if ( ! self::photo_allowed( $url ) ) {
				continue;
			}
			$thumb   = (string) ( $photo['thumb'] ?? '' );
			$clean[] = array(
				'url'   => $url,
				'thumb' => self::photo_allowed( $thumb ) ? $thumb : $url,
			);
		}

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::PHOTO_DIR;
		$base   = trailingslashit( $upload['baseurl'] ) . self::PHOTO_DIR;
		if ( ! $clean || ! wp_mkdir_p( $dir ) ) {
			return $clean;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$out = array();
		foreach ( $clean as $i => $local ) {
			foreach ( array( 'url' => '', 'thumb' => '-thumb' ) as $key => $suffix ) {
				$file = self::download_image( $local[ $key ], $dir, $review_id . '-' . $i . $suffix );
				if ( $file ) {
					$local[ $key ] = $base . '/' . $file;
				}
			}
			$out[] = $local;
		}
		return $out;
	}

	protected static function download_image( string $url, string $dir, string $name ): string {
		foreach ( array( 'jpg', 'png', 'webp' ) as $ext ) {
			if ( file_exists( $dir . '/' . $name . '.' . $ext ) ) {
				return $name . '.' . $ext;
			}
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return '';
		}
		$info = wp_getimagesize( $tmp );
		$exts = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		$ext  = ( $info && isset( $exts[ $info['mime'] ] ) ) ? $exts[ $info['mime'] ] : '';
		if ( '' === $ext || filesize( $tmp ) > 5 * MB_IN_BYTES ) {
			wp_delete_file( $tmp );
			return '';
		}
		$filename = sanitize_file_name( $name . '.' . $ext );
		if ( ! copy( $tmp, $dir . '/' . $filename ) ) {
			wp_delete_file( $tmp );
			return '';
		}
		wp_delete_file( $tmp );
		return $filename;
	}

	protected static function delete_photo_dir(): void {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::PHOTO_DIR;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( '.' !== $file && '..' !== $file && is_file( $dir . '/' . $file ) ) {
				wp_delete_file( $dir . '/' . $file );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
