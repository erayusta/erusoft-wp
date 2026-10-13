<?php
/** Front-end wiring: core `content_pagination` filter plus TOC and prev/next in `the_content`. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_HP_Front {

	public static function init(): void {
		add_filter( 'content_pagination', array( __CLASS__, 'paginate' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'decorate' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'allow_section_urls' ), 10, 2 );
	}

	/**
	 * Core answers /post/2/ with a 404 (and then a redirect to the first page) unless the content has
	 * <!--nextpage-->. Let the section URLs this plugin creates through.
	 *
	 * @param bool|mixed    $preempt  Short-circuit value.
	 * @param WP_Query|null $wp_query Main query.
	 * @return bool|mixed
	 */
	public static function allow_section_urls( $preempt, $wp_query = null ) {
		if ( ! $wp_query instanceof WP_Query || ! $wp_query->is_singular() ) {
			return $preempt;
		}
		$post = $wp_query->post ?? null;
		$page = (int) $wp_query->get( 'page' );
		if ( $page < 2 || ! $post instanceof WP_Post || ! self::applies( $post ) || false !== strpos( $post->post_content, '<!--nextpage-->' ) ) {
			return $preempt;
		}
		$s = Eru_HP_Settings::get();
		$sections = count( Eru_HP_Splitter::split( $post->post_content, (int) $s['level'], (int) $s['min_sections'], (int) $s['min_words'] ) );
		if ( $sections < 2 ) {
			return $preempt; // Not paginated by this plugin: core behaviour.
		}
		if ( $page <= $sections ) {
			return true;
		}
		// Beyond the last section: a real 404 instead of core's redirect to a guessed URL.
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		// Core would strip the page number and redirect a 404 to the post; keep the 404.
		add_filter( 'redirect_canonical', '__return_false' );
		return true;
	}

	public static function register_assets(): void {
		wp_register_style( 'eru-hp', ERU_HP_URL . 'assets/pagination.css', array(), ERU_HP_VERSION );
	}

	private static function applies( ?WP_Post $post ): bool {
		if ( ! $post || is_admin() || is_feed() || ! is_singular() ) {
			return false;
		}
		$s = Eru_HP_Settings::get();
		return (bool) apply_filters( 'eru_hp_applies', in_array( $post->post_type, (array) $s['post_types'], true ), $post );
	}

	/**
	 * @param string[] $pages Pages produced by core from <!--nextpage-->.
	 * @param WP_Post  $post
	 * @return string[]
	 */
	public static function paginate( $pages, $post ) {
		if ( ! is_array( $pages ) || 1 !== count( $pages ) || ! self::applies( $post instanceof WP_Post ? $post : null ) ) {
			return $pages;
		}
		$s     = Eru_HP_Settings::get();
		$split = Eru_HP_Splitter::split( (string) $pages[0], (int) $s['level'], (int) $s['min_sections'], (int) $s['min_words'] );
		return count( $split ) > 1 ? $split : $pages;
	}

	public static function decorate( $content ) {
		global $page, $numpages, $multipage, $post;
		if ( ! $multipage || $numpages < 2 || ! in_the_loop() || ! self::applies( $post ) || false !== strpos( $post->post_content, '<!--nextpage-->' ) ) {
			return $content;
		}
		$s      = Eru_HP_Settings::get();
		$titles = Eru_HP_Splitter::titles( $post->post_content, (int) $s['level'], (int) $s['min_sections'], (int) $s['min_words'] );
		if ( count( $titles ) !== (int) $numpages ) {
			return $content;
		}
		wp_enqueue_style( 'eru-hp' );
		$toc = '';
		if ( $s['show_toc'] ) {
			$toc  = '<nav class="eru-hp-toc" aria-label="' . esc_attr( $s['toc_title'] ) . '"><p class="eru-hp-toc__title">' . esc_html( $s['toc_title'] ) . '</p><ol>';
			foreach ( $titles as $i => $title ) {
				$n    = $i + 1;
				$toc .= '<li' . ( $n === (int) $page ? ' class="is-current"' : '' ) . '><a href="' . esc_url( self::page_url( $n ) ) . '"' . ( $n === (int) $page ? ' aria-current="page"' : '' ) . '>' . esc_html( $title ) . '</a></li>';
			}
			$toc .= '</ol></nav>';
		}
		$nav = '';
		if ( $s['show_nav'] ) {
			$nav = '<nav class="eru-hp-nav" aria-label="' . esc_attr__( 'Sayfa gezintisi', 'heading-pagination-for-wordpress' ) . '">';
			if ( $page > 1 ) {
				$nav .= '<a class="eru-hp-nav__prev" rel="prev" href="' . esc_url( self::page_url( $page - 1 ) ) . '"><span>' . esc_html__( 'Önceki', 'heading-pagination-for-wordpress' ) . '</span> ' . esc_html( $titles[ $page - 2 ] ) . '</a>';
			}
			if ( $page < $numpages ) {
				$nav .= '<a class="eru-hp-nav__next" rel="next" href="' . esc_url( self::page_url( $page + 1 ) ) . '"><span>' . esc_html__( 'Sonraki', 'heading-pagination-for-wordpress' ) . '</span> ' . esc_html( $titles[ $page ] ) . '</a>';
			}
			$nav .= '</nav>';
		}
		return $toc . $content . $nav;
	}

	/** Permalink of page $n of the current post (core logic: pretty or ?page=). */
	private static function page_url( int $n ): string {
		global $post;
		if ( $n <= 1 ) {
			return get_permalink( $post );
		}
		if ( ! get_option( 'permalink_structure' ) || in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			return add_query_arg( 'page', $n, get_permalink( $post ) );
		}
		return trailingslashit( get_permalink( $post ) ) . user_trailingslashit( (string) $n, 'single_paged' );
	}
}
