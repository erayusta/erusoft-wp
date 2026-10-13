<?php
/** Post type, meta and date logic. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CP_Campaign {

	const TYPE  = 'eru_campaign';
	const START = '_eru_cp_start';
	const END   = '_eru_cp_end';
	const OFF   = '_eru_cp_off';
	const LINK  = '_eru_cp_link_type'; // none | product | category | url.
	const REF   = '_eru_cp_link_ref';
	const CTA   = '_eru_cp_cta_text';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		// Rewrite rules are flushed only when the archive slug changed (flag set by Eru_CP_Settings::save()).
		add_action(
			'init',
			function () {
				if ( get_option( 'eru_cp_flush' ) ) {
					delete_option( 'eru_cp_flush' );
					flush_rewrite_rules();
				}
			},
			20
		);
	}

	public static function register(): void {
		$slug = Eru_CP_Settings::get()['archive_slug'];
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Kampanyalar', 'campaign-pages-for-wordpress' ),
					'singular_name'      => __( 'Kampanya', 'campaign-pages-for-wordpress' ),
					'menu_name'          => __( 'Kampanyalar', 'campaign-pages-for-wordpress' ),
					'add_new'            => __( 'Yeni ekle', 'campaign-pages-for-wordpress' ),
					'add_new_item'       => __( 'Yeni kampanya ekle', 'campaign-pages-for-wordpress' ),
					'edit_item'          => __( 'Kampanyayı düzenle', 'campaign-pages-for-wordpress' ),
					'new_item'           => __( 'Yeni kampanya', 'campaign-pages-for-wordpress' ),
					'view_item'          => __( 'Kampanyayı görüntüle', 'campaign-pages-for-wordpress' ),
					'search_items'       => __( 'Kampanya ara', 'campaign-pages-for-wordpress' ),
					'all_items'          => __( 'Tüm kampanyalar', 'campaign-pages-for-wordpress' ),
					'not_found'          => __( 'Kampanya bulunamadı.', 'campaign-pages-for-wordpress' ),
					'not_found_in_trash' => __( 'Çöpte kampanya yok.', 'campaign-pages-for-wordpress' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => $slug,
				'rewrite'      => array( 'slug' => $slug, 'with_front' => false ),
				'menu_icon'    => 'dashicons-megaphone',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			)
		);
	}

	/** Valid Y-m-d or ''. */
	public static function clean_date( $raw ): string {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $raw;
	}

	/** @return string 'active' | 'upcoming' | 'ended' | 'off' */
	public static function status( int $post_id ): string {
		if ( '1' === (string) get_post_meta( $post_id, self::OFF, true ) ) {
			return 'off';
		}
		$today = wp_date( 'Y-m-d' );
		$start = (string) get_post_meta( $post_id, self::START, true );
		$end   = (string) get_post_meta( $post_id, self::END, true );
		if ( '' !== $start && $today < $start ) {
			return 'upcoming';
		}
		if ( '' !== $end && $today > $end ) {
			return 'ended';
		}
		return 'active';
	}

	/**
	 * Published campaigns with the given status, newest first.
	 *
	 * @param string $status active | upcoming | ended.
	 * @return WP_Post[]
	 */
	public static function query( string $status, int $limit = -1 ): array {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			if ( self::status( $p->ID ) === $status ) {
				$out[] = $p;
			}
		}
		return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
	}

	public static function date_label( int $post_id ): string {
		$fmt   = get_option( 'date_format' );
		$start = (string) get_post_meta( $post_id, self::START, true );
		$end   = (string) get_post_meta( $post_id, self::END, true );
		$f     = fn( $d ) => date_i18n( $fmt, strtotime( $d . ' 12:00:00' ) );
		if ( '' !== $start && '' !== $end ) {
			return $f( $start ) . ' – ' . $f( $end );
		}
		if ( '' !== $end ) {
			/* translators: %s: end date. */
			return sprintf( __( 'Son gün: %s', 'campaign-pages-for-wordpress' ), $f( $end ) );
		}
		if ( '' !== $start ) {
			/* translators: %s: start date. */
			return sprintf( __( 'Başlangıç: %s', 'campaign-pages-for-wordpress' ), $f( $start ) );
		}
		return '';
	}

	/** Call-to-action URL resolved from the link type (product, category, custom URL). */
	public static function cta_url( int $post_id ): string {
		$type = (string) get_post_meta( $post_id, self::LINK, true );
		$ref  = (string) get_post_meta( $post_id, self::REF, true );
		$url  = '';
		if ( 'product' === $type && (int) $ref > 0 && 'publish' === get_post_status( (int) $ref ) ) {
			$url = (string) get_permalink( (int) $ref );
		} elseif ( 'category' === $type && (int) $ref > 0 && taxonomy_exists( 'product_cat' ) ) {
			$link = get_term_link( (int) $ref, 'product_cat' );
			$url  = is_wp_error( $link ) ? '' : (string) $link;
		} elseif ( 'url' === $type ) {
			$url = $ref;
		}
		return (string) apply_filters( 'eru_cp_cta_url', $url, $post_id );
	}

	public static function cta_text( int $post_id ): string {
		$text = (string) get_post_meta( $post_id, self::CTA, true );
		return '' !== $text ? $text : Eru_CP_Settings::get()['cta_default'];
	}
}
