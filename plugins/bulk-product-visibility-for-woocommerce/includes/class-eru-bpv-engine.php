<?php
/**
 * Filtering, dry-run preview and batched apply.
 *
 * @package bulk-product-visibility-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BPV_Engine {

	const VISIBILITY = array( 'visible', 'catalog', 'search', 'hidden' );
	const STATUSES   = array( 'publish', 'draft', 'private' );
	const STOCK      = array( '', 'instock', 'outofstock', 'onbackorder' );

	public static function sanitize_filters( $f ) {
		$f = is_array( $f ) ? $f : array();
		$s = isset( $f['stock'] ) ? sanitize_key( $f['stock'] ) : '';
		return array(
			'category'  => isset( $f['category'] ) ? absint( $f['category'] ) : 0,
			'stock'     => in_array( $s, self::STOCK, true ) ? $s : '',
			'price_min' => ( isset( $f['price_min'] ) && '' !== trim( (string) $f['price_min'] ) ) ? (string) wc_format_decimal( $f['price_min'] ) : '',
			'price_max' => ( isset( $f['price_max'] ) && '' !== trim( (string) $f['price_max'] ) ) ? (string) wc_format_decimal( $f['price_max'] ) : '',
		);
	}

	/**
	 * @return array{visibility:string,status:string}|WP_Error
	 */
	public static function sanitize_changes( $c ) {
		$c   = is_array( $c ) ? $c : array();
		$vis = isset( $c['visibility'] ) ? sanitize_key( $c['visibility'] ) : '';
		$st  = isset( $c['status'] ) ? sanitize_key( $c['status'] ) : '';
		if ( '' !== $vis && ! in_array( $vis, self::VISIBILITY, true ) ) {
			return new WP_Error( 'eru_bpv_visibility', __( 'Geçersiz görünürlük değeri.', 'bulk-product-visibility-for-woocommerce' ) );
		}
		if ( '' !== $st && ! in_array( $st, self::STATUSES, true ) ) {
			return new WP_Error( 'eru_bpv_status', __( 'Geçersiz durum değeri.', 'bulk-product-visibility-for-woocommerce' ) );
		}
		if ( '' === $vis && '' === $st ) {
			return new WP_Error( 'eru_bpv_empty', __( 'Uygulanacak bir değişiklik seçin.', 'bulk-product-visibility-for-woocommerce' ) );
		}
		return array( 'visibility' => $vis, 'status' => $st );
	}

	/**
	 * Product IDs matching the filters (parent products only, not variations).
	 *
	 * @return int[]
	 */
	/** Most products one bulk change may touch (filter `eru_bpv_max_products`, default 5000). */
	public static function max_products(): int {
		return max( 1, (int) apply_filters( 'eru_bpv_max_products', 5000 ) );
	}

	public static function find_ids( $filters ) {
		$f    = self::sanitize_filters( $filters );
		$args = array(
			'post_type'              => 'product',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => self::max_products() + 1, // One extra row tells preview() the filter is too broad.
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( $f['category'] ) {
			$args['tax_query'] = array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => array( $f['category'] ),
					'include_children' => true,
				),
			);
		}
		$meta = array();
		if ( '' !== $f['stock'] ) {
			$meta[] = array( 'key' => '_stock_status', 'value' => $f['stock'] );
		}
		if ( '' !== $f['price_min'] ) {
			$meta[] = array( 'key' => '_price', 'value' => $f['price_min'], 'compare' => '>=', 'type' => 'DECIMAL(12,4)' );
		}
		if ( '' !== $f['price_max'] ) {
			$meta[] = array( 'key' => '_price', 'value' => $f['price_max'], 'compare' => '<=', 'type' => 'DECIMAL(12,4)' );
		}
		if ( $meta ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta );
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Dry run: counts the matches, changes nothing and returns a one-hour token that unlocks the apply step.
	 *
	 * @param array $filters   Filters.
	 * @param array $changes   Wanted changes.
	 * @param bool  $check_cap Enforce manage_woocommerce (AJAX handlers pass true).
	 * @return array{token:string,count:int,sample:string[]}|WP_Error
	 */
	public static function preview( $filters, $changes, $check_cap = false ) {
		if ( $check_cap && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'eru_bpv_cap', __( 'Yetkiniz yok.', 'bulk-product-visibility-for-woocommerce' ) );
		}
		$changes = self::sanitize_changes( $changes );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$ids = self::find_ids( $filters );
		if ( count( $ids ) > self::max_products() ) {
			/* translators: %d: maximum number of products */
			return new WP_Error( 'eru_bpv_many', sprintf( __( 'Filtre çok fazla ürünle eşleşiyor (bir işlemde en fazla %d ürün). Filtreyi daraltın.', 'bulk-product-visibility-for-woocommerce' ), self::max_products() ) );
		}
		if ( ! $ids ) {
			return new WP_Error( 'eru_bpv_none', __( 'Filtreye uyan ürün yok.', 'bulk-product-visibility-for-woocommerce' ) );
		}
		$token = wp_generate_password( 24, false );
		set_transient(
			'eru_bpv_' . $token,
			array( 'ids' => $ids, 'changes' => $changes, 'user' => get_current_user_id() ),
			HOUR_IN_SECONDS
		);
		return array(
			'token'  => $token,
			'count'  => count( $ids ),
			'sample' => array_map( 'get_the_title', array_slice( $ids, 0, 8 ) ),
		);
	}

	public static function batch_size() {
		return max( 1, (int) apply_filters( 'eru_bpv_batch_size', 50 ) );
	}

	/**
	 * Applies one batch of a previewed job.
	 *
	 * @return array{done:bool,next:int,processed:int,total:int}|WP_Error
	 */
	public static function run_batch( $token, $offset, $check_cap = false ) {
		if ( $check_cap && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'eru_bpv_cap', __( 'Yetkiniz yok.', 'bulk-product-visibility-for-woocommerce' ) );
		}
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		$job   = '' === $token ? false : get_transient( 'eru_bpv_' . $token );
		if ( ! is_array( $job ) || (int) $job['user'] !== get_current_user_id() ) {
			return new WP_Error( 'eru_bpv_token', __( 'Önce önizleme yapın; işlem süresi dolmuş olabilir.', 'bulk-product-visibility-for-woocommerce' ) );
		}
		$offset = max( 0, (int) $offset );
		$total  = count( $job['ids'] );
		$size   = self::batch_size();
		foreach ( array_slice( $job['ids'], $offset, $size ) as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			if ( '' !== $job['changes']['visibility'] ) {
				$p->set_catalog_visibility( $job['changes']['visibility'] );
			}
			if ( '' !== $job['changes']['status'] ) {
				$p->set_status( $job['changes']['status'] );
			}
			$p->save();
		}
		$next = $offset + $size;
		$done = $next >= $total;
		if ( $done ) {
			delete_transient( 'eru_bpv_' . $token );
		}
		return array( 'done' => $done, 'next' => $next, 'processed' => min( $next, $total ), 'total' => $total );
	}
}
