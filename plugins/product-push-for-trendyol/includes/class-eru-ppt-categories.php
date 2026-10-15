<?php
/**
 * Trendyol category tree and category attributes: bounded caches, search, and the WC → Trendyol
 * category resolution used by the item builder.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caches: one tree transient per environment (7 days) and one attribute transient per Trendyol leaf
 * category, at most MAX_ATTR_CACHES of them (oldest evicted). Attribute lookups are only made for ids that
 * exist as leaves in the cached tree, so arbitrary input cannot create cache entries.
 */
class Eru_PPT_Categories {

	const TTL             = 7 * DAY_IN_SECONDS;
	const INDEX           = 'eru_ppt_attr_index';
	const MAX_ATTR_CACHES = 300;
	const VALUE_PAGES     = 20;
	const NEG_TTL         = 10 * MINUTE_IN_SECONDS;
	const REFRESH_HOOK    = 'eru_ppt_refresh';
	const GROUP           = 'eru-ppt';

	/** Hooks. */
	public static function init() {
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh' ) );
	}

	/**
	 * Background refresh (Action Scheduler): the tree and, when given, one category's attributes.
	 * Page requests only read the caches and queue this job on a miss.
	 *
	 * @param int $cat_id Trendyol category (0 = tree only).
	 */
	public static function refresh( $cat_id = 0 ) {
		if ( ! Eru_PPT_Settings::is_enabled() ) {
			return;
		}
		if ( is_wp_error( self::tree() ) ) {
			return;
		}
		if ( absint( $cat_id ) ) {
			self::attributes( absint( $cat_id ) );
		}
	}

	/**
	 * Queues one refresh job per category (no duplicates while one is pending).
	 *
	 * @param int $cat_id Category (0 = tree).
	 */
	public static function queue( $cat_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! Eru_PPT_Settings::is_enabled() ) {
			return;
		}
		$args = array( absint( $cat_id ) );
		$have = as_get_scheduled_actions(
			array(
				'hook'     => self::REFRESH_HOOK,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( ! $have ) {
			as_enqueue_async_action( self::REFRESH_HOOK, $args, self::GROUP );
		}
	}

	/** @return WP_Error Cache miss in a page request. */
	private static function loading() {
		return new WP_Error( 'eru_ppt_loading', __( 'Trendyol kategori verisi yükleniyor (arka planda alınıyor); birkaç dakika sonra tekrar doğrulayın.', 'product-push-for-trendyol' ) );
	}

	/**
	 * Negative cache: failed fetches are not repeated for NEG_TTL. One transient per environment for
	 * the tree and one (an array of leaf ids) for attributes, so the number of rows stays fixed.
	 *
	 * @param string   $what tree|attr.
	 * @param int|null $cat  Category for attr.
	 * @return string|null Stored error message.
	 */
	private static function neg_get( $what, $cat = null ) {
		$v = get_transient( 'eru_ppt_neg_' . $what . '_' . Eru_PPT_Settings::environment() );
		if ( 'tree' === $what ) {
			return is_string( $v ) && '' !== $v ? $v : null;
		}
		$row = is_array( $v ) ? ( $v[ (int) $cat ] ?? null ) : null;
		return is_array( $row ) && (int) $row[0] > time() ? (string) $row[1] : null;
	}

	/** @param string $what tree|attr. @param string $msg Error. @param int|null $cat Category. */
	private static function neg_set( $what, $msg, $cat = null ) {
		$key = 'eru_ppt_neg_' . $what . '_' . Eru_PPT_Settings::environment();
		if ( 'tree' === $what ) {
			set_transient( $key, (string) $msg, self::NEG_TTL );
			return;
		}
		$v = get_transient( $key );
		$v = is_array( $v ) ? array_filter( $v, fn( $r ) => is_array( $r ) && (int) $r[0] > time() ) : array();
		$v[ (int) $cat ] = array( time() + self::NEG_TTL, (string) $msg );
		set_transient( $key, $v, self::NEG_TTL );
	}

	/** @return string Tree transient key. */
	private static function tree_key() {
		return 'eru_ppt_cat_' . Eru_PPT_Settings::environment();
	}

	/** @param int $id Category. @return string Attribute transient key. */
	private static function attr_key( $id ) {
		return 'eru_ppt_attr_' . Eru_PPT_Settings::environment() . '_' . absint( $id );
	}

	/**
	 * Flat tree: id => [n => name, p => path, l => leaf].
	 *
	 * @param bool $force  Ignore the cache (and the negative cache).
	 * @param bool $remote False in page requests: never fetch, queue a refresh job on a miss.
	 * @return array|WP_Error
	 */
	public static function tree( $force = false, $remote = true ) {
		$cached = $force ? false : get_transient( self::tree_key() );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		$neg = $force ? null : self::neg_get( 'tree' );
		if ( null !== $neg ) {
			return new WP_Error( 'eru_ppt_categories', $neg ); // A recent failure (e.g. bad credentials) is reported, not "loading".
		}
		if ( ! $remote ) {
			self::queue( 0 );
			return self::loading();
		}
		$r = Eru_PPT_Client::call( 'categories' );
		if ( ! $r['ok'] ) {
			if ( $r['sent'] ) {
				self::neg_set( 'tree', $r['error'] );
			}
			return new WP_Error( 'eru_ppt_categories', $r['error'] );
		}
		$flat = array();
		$walk = function ( $nodes, $path, $depth ) use ( &$walk, &$flat ) {
			if ( $depth > 12 || ! is_array( $nodes ) ) {
				return;
			}
			foreach ( $nodes as $n ) {
				if ( ! is_array( $n ) || empty( $n['id'] ) ) {
					continue;
				}
				$name = sanitize_text_field( (string) ( $n['name'] ?? '' ) );
				$p    = '' === $path ? $name : $path . ' > ' . $name;
				$kids = is_array( $n['subCategories'] ?? null ) ? $n['subCategories'] : array();
				$flat[ (int) $n['id'] ] = array(
					'n' => $name,
					'p' => $p,
					'l' => empty( $kids ),
				);
				if ( $kids ) {
					$walk( $kids, $p, $depth + 1 );
				}
			}
		};
		$walk( $r['data']['categories'] ?? array(), '', 0 );
		if ( ! $flat ) {
			self::neg_set( 'tree', __( 'Trendyol kategori ağacı boş döndü.', 'product-push-for-trendyol' ) );
			return new WP_Error( 'eru_ppt_categories', __( 'Trendyol kategori ağacı boş döndü.', 'product-push-for-trendyol' ) );
		}
		set_transient( self::tree_key(), $flat, self::TTL );
		return $flat;
	}

	/** @param string $s Text. @return string Turkish-aware lower case. */
	public static function lower( $s ) {
		return mb_strtolower( str_replace( array( 'İ', 'I' ), array( 'i', 'ı' ), (string) $s ), 'UTF-8' );
	}

	/**
	 * Leaf categories whose path contains every word of the query.
	 *
	 * @param string $q     Query (min 2 chars).
	 * @param int    $limit Max results.
	 * @return array|WP_Error List of [id, name, path].
	 */
	public static function search( $q, $limit = 30 ) {
		$q = trim( self::lower( $q ) );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		if ( ctype_digit( $q ) ) {
			$tree = self::tree();
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
			return isset( $tree[ (int) $q ] ) ? array(
				array(
					'id'   => (int) $q,
					'name' => $tree[ (int) $q ]['n'],
					'path' => $tree[ (int) $q ]['p'],
					'leaf' => (bool) $tree[ (int) $q ]['l'],
				),
			) : array();
		}
		$tree = self::tree();
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$words = array_filter( preg_split( '/\s+/u', $q ) );
		$out   = array();
		foreach ( $tree as $id => $c ) {
			if ( empty( $c['l'] ) ) {
				continue;
			}
			$hay = self::lower( $c['p'] );
			foreach ( $words as $w ) {
				if ( false === mb_strpos( $hay, $w ) ) {
					continue 2;
				}
			}
			$out[] = array(
				'id'   => (int) $id,
				'name' => $c['n'],
				'path' => $c['p'],
				'leaf' => true,
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Attributes of a leaf category: list of [id, name, required, allowCustom, varianter, complete,
	 * values[id,name]]. A failed value page caches nothing (negative cache instead); a value list longer
	 * than VALUE_PAGES pages is cached with complete=false and validation does not call its values invalid.
	 *
	 * @param int  $cat_id Trendyol category.
	 * @param bool $force  Ignore the cache (and the negative cache).
	 * @param bool $remote False in page requests: never fetch, queue a refresh job on a miss.
	 * @return array|WP_Error
	 */
	public static function attributes( $cat_id, $force = false, $remote = true ) {
		$cat_id = absint( $cat_id );
		$key    = self::attr_key( $cat_id );
		$cached = $force ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $remote ) {
			$tree = get_transient( self::tree_key() );
			$neg  = self::neg_get( 'tree' );
			if ( ( ! is_array( $tree ) || ! $tree ) && null !== $neg ) {
				return new WP_Error( 'eru_ppt_categories', $neg );
			}
			if ( ! is_array( $tree ) || ! $tree ) {
				self::queue( $cat_id ); // The job fetches the tree, then this category.
				return self::loading();
			}
		} else {
			$tree = self::tree();
		}
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		if ( ! isset( $tree[ $cat_id ] ) ) {
			/* translators: %d: category id */
			return new WP_Error( 'eru_ppt_category', sprintf( __( 'Trendyol kategori ağacında %d bulunamadı; ağacı yenileyin.', 'product-push-for-trendyol' ), $cat_id ) );
		}
		if ( empty( $tree[ $cat_id ]['l'] ) ) {
			return new WP_Error( 'eru_ppt_category', __( 'Seçilen Trendyol kategorisi en alt seviye değil; alt kategorilerinden birini seçin.', 'product-push-for-trendyol' ) );
		}
		$neg = $force ? null : self::neg_get( 'attr', $cat_id );
		if ( null !== $neg ) {
			return new WP_Error( 'eru_ppt_attributes', $neg );
		}
		if ( ! $remote ) {
			self::queue( $cat_id );
			return self::loading();
		}
		$r = Eru_PPT_Client::call( 'category_attributes', null, array( 'id' => $cat_id ) );
		if ( ! $r['ok'] ) {
			if ( $r['sent'] ) {
				self::neg_set( 'attr', $r['error'], $cat_id );
			}
			return new WP_Error( 'eru_ppt_attributes', $r['error'] );
		}
		$out = array();
		foreach ( (array) ( $r['data']['categoryAttributes'] ?? array() ) as $a ) {
			$aid = absint( $a['attribute']['id'] ?? 0 );
			if ( ! $aid ) {
				continue;
			}
			$values = array();
			foreach ( (array) ( $a['attributeValues'] ?? array() ) as $v ) {
				if ( isset( $v['id'] ) ) {
					$values[] = array(
						'id'   => (int) $v['id'],
						'name' => sanitize_text_field( (string) ( $v['name'] ?? '' ) ),
					);
				}
			}
			$complete = true;
			if ( ! $values && empty( $a['allowCustom'] ) ) {
				// Values on a separate, paged endpoint. Every page must succeed or nothing is cached.
				$total = 1;
				for ( $pg = 0; $pg < $total; $pg++ ) {
					if ( $pg >= self::VALUE_PAGES ) {
						$complete = false; // Cap reached: cached, but flagged so validation does not trust it.
						break;
					}
					$vr = Eru_PPT_Client::call(
						'attribute_values',
						null,
						array(
							'id'   => $cat_id,
							'attr' => $aid,
						),
						array(
							'page' => $pg,
							'size' => 100,
						)
					);
					if ( ! $vr['ok'] ) {
						/* translators: 1: attribute, 2: error */
						$msg = sprintf( __( '"%1$s" değer listesi alınamadı: %2$s', 'product-push-for-trendyol' ), sanitize_text_field( (string) ( $a['attribute']['name'] ?? $aid ) ), $vr['error'] );
						if ( $vr['sent'] ) {
							self::neg_set( 'attr', $msg, $cat_id );
						}
						return new WP_Error( 'eru_ppt_attributes', $msg );
					}
					foreach ( (array) ( $vr['data']['content'] ?? array() ) as $v ) {
						if ( isset( $v['attributeValueId'] ) ) {
							$values[] = array(
								'id'   => (int) $v['attributeValueId'],
								'name' => sanitize_text_field( (string) ( $v['attributeValue'] ?? '' ) ),
							);
						}
					}
					$total = max( 1, (int) ( $vr['data']['totalPages'] ?? 1 ) );
				}
			}
			$out[] = array(
				'id'          => $aid,
				'name'        => sanitize_text_field( (string) ( $a['attribute']['name'] ?? '' ) ),
				'required'    => ! empty( $a['required'] ),
				'allowCustom' => ! empty( $a['allowCustom'] ),
				'varianter'   => ! empty( $a['varianter'] ),
				'complete'    => $complete,
				'values'      => $values,
			);
		}
		self::remember( $key );
		set_transient( $key, $out, self::TTL );
		return $out;
	}

	/** @param string $key Attribute transient key; evicts the oldest beyond the cap. */
	private static function remember( $key ) {
		$idx         = get_option( self::INDEX, array() );
		$idx         = is_array( $idx ) ? $idx : array();
		$idx[ $key ] = time();
		asort( $idx );
		while ( count( $idx ) > self::MAX_ATTR_CACHES ) {
			$old = array_key_first( $idx );
			delete_transient( $old );
			unset( $idx[ $old ] );
		}
		if ( false === get_option( self::INDEX, false ) ) {
			add_option( self::INDEX, $idx, '', false );
		} else {
			update_option( self::INDEX, $idx, false );
		}
	}

	/** Drops the tree and every attribute cache (refresh button, tests). */
	public static function flush() {
		foreach ( array( 'live', 'stage' ) as $env ) {
			foreach ( array( 'eru_ppt_cat_', 'eru_ppt_neg_tree_', 'eru_ppt_neg_attr_' ) as $k ) {
				delete_transient( $k . $env );
			}
		}
		$idx = get_option( self::INDEX, array() );
		foreach ( array_keys( is_array( $idx ) ? $idx : array() ) as $key ) {
			delete_transient( $key );
		}
		delete_option( self::INDEX );
	}

	/** @return array<int,array> WC term id => map row. */
	public static function map() {
		$out = array();
		foreach ( (array) Eru_PPT_Settings::get( 'category_map' ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['wc'] ) && ! empty( $row['ty'] ) ) {
				$out[ (int) $row['wc'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Trendyol category for a product: the product's override, else the deepest mapped WC category among
	 * the product's categories and their ancestors.
	 *
	 * @param WC_Product $product Product (a variation resolves through its parent).
	 * @return array{id:int,name:string,source:string}
	 */
	public static function resolve( $product ) {
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
			if ( ! $product ) {
				return array(
					'id'     => 0,
					'name'   => '',
					'source' => 'none',
				);
			}
		}
		$over = absint( $product->get_meta( '_eru_ppt_category_id' ) );
		if ( $over ) {
			return array(
				'id'     => $over,
				'name'   => (string) $product->get_meta( '_eru_ppt_category_name' ),
				'source' => 'override',
			);
		}
		$map  = self::map();
		$best = null;
		$bd   = -1;
		foreach ( $product->get_category_ids() as $term_id ) {
			foreach ( array_merge( array( (int) $term_id ), array_map( 'intval', get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) ) ) as $cand ) {
				if ( ! isset( $map[ $cand ] ) ) {
					continue;
				}
				$depth = count( get_ancestors( $cand, 'product_cat', 'taxonomy' ) );
				if ( $depth > $bd || ( $depth === $bd && $cand < $best ) ) {
					$best = $cand;
					$bd   = $depth;
				}
			}
		}
		if ( null === $best ) {
			return array(
				'id'     => 0,
				'name'   => '',
				'source' => 'none',
			);
		}
		return array(
			'id'     => (int) $map[ $best ]['ty'],
			'name'   => (string) ( $map[ $best ]['name'] ?? '' ),
			'source' => 'map',
		);
	}
}
