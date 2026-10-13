<?php
/**
 * Settings access, slide selection, rendering, shortcode and archive hook.
 *
 * @package category-banner-slider-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CBS {

	/** Has a slider on this page already used eager loading / high fetch priority? */
	private static $eager_used = false;

	const OPTION        = 'eru_cbs_settings';
	const OPTION_GLOBAL = 'eru_cbs_global_slides';
	const META_ENABLED  = '_eru_cbs_enabled';
	const META_SLIDES   = '_eru_cbs_slides';

	/** Archive hook positions offered in settings. Default: none (shortcode only). */
	public static function positions() {
		return array(
			''                                  => __( 'Yok (yalnızca kısa kod)', 'category-banner-slider-for-woocommerce' ),
			'woocommerce_before_main_content'   => __( 'İçeriğin başında', 'category-banner-slider-for-woocommerce' ),
			'woocommerce_archive_description'   => __( 'Kategori açıklamasının yanında', 'category-banner-slider-for-woocommerce' ),
			'woocommerce_before_shop_loop'      => __( 'Ürün listesinden önce', 'category-banner-slider-for-woocommerce' ),
		);
	}

	public static function defaults() {
		return array(
			'position'          => '',
			'autoplay'          => 1,
			'autoplay_speed_ms' => 5000,
			'show_arrows'       => 1,
			'show_dots'         => 1,
			'loop'              => 1,
			'height_desktop'    => '',
			'height_tablet'     => '',
			'height_mobile'     => '',
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function sanitize_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$d   = self::defaults();
		$pos = isset( $in['position'] ) ? sanitize_key( $in['position'] ) : '';
		$out = array(
			'position'    => array_key_exists( $pos, self::positions() ) ? $pos : '',
			'autoplay'    => empty( $in['autoplay'] ) ? 0 : 1,
			'show_arrows' => empty( $in['show_arrows'] ) ? 0 : 1,
			'show_dots'   => empty( $in['show_dots'] ) ? 0 : 1,
			'loop'        => empty( $in['loop'] ) ? 0 : 1,
		);
		$speed                    = isset( $in['autoplay_speed_ms'] ) ? absint( $in['autoplay_speed_ms'] ) : $d['autoplay_speed_ms'];
		$out['autoplay_speed_ms'] = max( 1000, $speed );
		foreach ( array( 'height_desktop', 'height_tablet', 'height_mobile' ) as $k ) {
			$v       = isset( $in[ $k ] ) ? absint( $in[ $k ] ) : 0;
			$out[ $k ] = 0 === $v ? '' : $v;
		}
		return $out;
	}

	/** Keeps slides that have a desktop image; order is preserved. */
	public static function sanitize_slides( $slides ) {
		$clean = array();
		if ( ! is_array( $slides ) ) {
			return $clean;
		}
		foreach ( $slides as $slide ) {
			if ( ! is_array( $slide ) ) {
				continue;
			}
			$desktop = isset( $slide['image_desktop'] ) ? absint( $slide['image_desktop'] ) : 0;
			if ( $desktop <= 0 ) {
				continue;
			}
			$clean[] = array(
				'image_desktop' => $desktop,
				'image_tablet'  => isset( $slide['image_tablet'] ) ? absint( $slide['image_tablet'] ) : 0,
				'image_mobile'  => isset( $slide['image_mobile'] ) ? absint( $slide['image_mobile'] ) : 0,
				'link_url'      => isset( $slide['link_url'] ) ? esc_url_raw( trim( (string) $slide['link_url'] ) ) : '',
				'alt'           => isset( $slide['alt'] ) ? sanitize_text_field( (string) $slide['alt'] ) : '',
			);
		}
		return $clean;
	}

	public static function init() {
		add_shortcode( 'eru_category_slider', array( __CLASS__, 'shortcode' ) );
		$pos = self::get_settings()['position'];
		if ( '' !== $pos && array_key_exists( $pos, self::positions() ) ) {
			add_action( $pos, array( __CLASS__, 'render_archive' ), 5 );
		}
	}

	/** Archive output: category slides if enabled, else the global slides, else nothing. */
	public static function render_archive() {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() || is_paged() ) {
			return;
		}
		$term   = get_queried_object();
		$slides = array();
		if ( $term && ! empty( $term->term_id ) && get_term_meta( $term->term_id, self::META_ENABLED, true ) ) {
			$slides = self::sanitize_slides( get_term_meta( $term->term_id, self::META_SLIDES, true ) );
		}
		if ( ! $slides ) {
			$slides = self::sanitize_slides( get_option( self::OPTION_GLOBAL, array() ) );
		}
		if ( $slides ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in build_html().
			echo self::build_html( $slides, self::get_settings() );
		}
	}

	/** [eru_category_slider term="slug"] — uses that category's slides regardless of the archive toggle. */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'term' => '' ), $atts, 'eru_category_slider' );
		$term = null;
		if ( '' !== $atts['term'] ) {
			$term = get_term_by( 'slug', sanitize_title( $atts['term'] ), 'product_cat' );
		} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
		}
		if ( ! $term || is_wp_error( $term ) || empty( $term->term_id ) ) {
			return '';
		}
		$slides = self::sanitize_slides( get_term_meta( $term->term_id, self::META_SLIDES, true ) );
		return $slides ? self::build_html( $slides, self::get_settings() ) : '';
	}

	private static function build_html( $slides, $s ) {
		wp_enqueue_style( 'eru-cbs', ERU_CBS_URL . 'assets/css/slider.css', array(), ERU_CBS_VERSION );
		wp_enqueue_script( 'eru-cbs', ERU_CBS_URL . 'assets/js/slider.js', array(), ERU_CBS_VERSION, true );
		$config = array(
			'autoplay' => (bool) $s['autoplay'],
			'speed'    => (int) $s['autoplay_speed_ms'],
			'loop'     => (bool) $s['loop'],
		);
		$items  = '';
		// Only the first slider on the page competes for the LCP: later sliders load lazily.
		$first  = ! self::$eager_used;
		foreach ( $slides as $slide ) {
			$one = self::build_slide( $slide, $first );
			if ( '' !== $one ) {
				if ( $first ) {
					self::$eager_used = true;
				}
				$first  = false;
				$items .= $one;
			}
		}
		if ( '' === $items ) {
			return '';
		}
		$multi = count( $slides ) > 1;
		$vars  = array();
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $d ) {
			if ( '' !== $s[ 'height_' . $d ] ) {
				$vars[] = '--eru-cbs-h-' . $d . ':' . absint( $s[ 'height_' . $d ] ) . 'px';
			}
		}
		$html  = '<div class="eru-cbs" data-eru-cbs="' . esc_attr( wp_json_encode( $config ) ) . '"' . ( $vars ? ' style="' . esc_attr( implode( ';', $vars ) ) . '"' : '' ) . '>';
		$html .= '<div class="eru-cbs__viewport"><div class="eru-cbs__track">' . $items . '</div></div>';
		if ( $multi && ! empty( $s['show_arrows'] ) ) {
			$html .= '<button type="button" class="eru-cbs__arrow eru-cbs__arrow--prev" aria-label="' . esc_attr__( 'Önceki', 'category-banner-slider-for-woocommerce' ) . '">&#10094;</button>';
			$html .= '<button type="button" class="eru-cbs__arrow eru-cbs__arrow--next" aria-label="' . esc_attr__( 'Sonraki', 'category-banner-slider-for-woocommerce' ) . '">&#10095;</button>';
		}
		if ( $multi && ! empty( $s['show_dots'] ) ) {
			$html .= '<div class="eru-cbs__dots"></div>';
		}
		return $html . '</div>';
	}

	private static function build_slide( $slide, $is_first = false ) {
		$desktop = wp_get_attachment_image_url( $slide['image_desktop'], 'full' );
		if ( ! $desktop ) {
			return '';
		}
		$srcset = function ( $id ) {
			$set = $id ? wp_get_attachment_image_srcset( $id, 'full' ) : false;
			if ( $set ) {
				return $set;
			}
			$url = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
			return $url ? esc_url( $url ) : '';
		};
		$tablet = $srcset( $slide['image_tablet'] );
		$mobile = $srcset( $slide['image_mobile'] );
		$tablet = $tablet ? $tablet : '';
		$mobile = $mobile ? $mobile : $tablet;

		$attr = array(
			'class'    => 'eru-cbs__img',
			'sizes'    => '100vw',
			'loading'  => $is_first ? 'eager' : 'lazy',
			'decoding' => 'async',
		);
		if ( $is_first ) {
			$attr['fetchpriority'] = 'high';
		}
		if ( '' !== $slide['alt'] ) {
			$attr['alt'] = $slide['alt'];
		}
		$img = wp_get_attachment_image( $slide['image_desktop'], 'full', false, $attr );
		if ( '' === $img ) {
			return '';
		}
		$pic = '<picture class="eru-cbs__picture">';
		if ( $mobile ) {
			$pic .= '<source media="(max-width: 767px)" srcset="' . esc_attr( $mobile ) . '" sizes="100vw">';
		}
		if ( $tablet ) {
			$pic .= '<source media="(max-width: 1024px)" srcset="' . esc_attr( $tablet ) . '" sizes="100vw">';
		}
		$pic .= $img . '</picture>';
		if ( ! empty( $slide['link_url'] ) ) {
			$pic = '<a class="eru-cbs__link" href="' . esc_url( $slide['link_url'] ) . '">' . $pic . '</a>';
		}
		return '<div class="eru-cbs__slide">' . $pic . '</div>';
	}
}
