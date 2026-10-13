<?php
/** Front end: shortcode, archive/single templates and cards. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CP_Front {

	public static function init(): void {
		add_shortcode( 'eru_campaigns', array( __CLASS__, 'shortcode' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 20 );
		add_filter( 'document_title_parts', array( __CLASS__, 'title_parts' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/** Ended and upcoming campaign pages stay reachable but are kept out of search engines. */
	public static function robots( array $robots ): array {
		if ( ! is_singular( Eru_CP_Campaign::TYPE ) ) {
			return $robots;
		}
		$id      = (int) get_queried_object_id();
		$noindex = 'active' !== Eru_CP_Campaign::status( $id );
		/**
		 * Filters whether a campaign page gets noindex (default: when it is not active).
		 *
		 * @param bool $noindex Noindex.
		 * @param int  $id      Campaign ID.
		 */
		if ( apply_filters( 'eru_cp_noindex', $noindex, $id ) ) {
			$robots['noindex']  = true;
			$robots['follow']   = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	public static function title_parts( array $parts ): array {
		if ( is_post_type_archive( Eru_CP_Campaign::TYPE ) ) {
			$parts['title'] = Eru_CP_Settings::get()['archive_title'];
		}
		return $parts;
	}

	public static function style(): void {
		wp_enqueue_style( 'eru-cp', ERU_CP_URL . 'assets/campaigns.css', array(), ERU_CP_VERSION );
	}

	/** A theme can override: <theme>/eru-campaigns/archive.php and single.php. */
	public static function template( $template ) {
		$file = '';
		if ( is_post_type_archive( Eru_CP_Campaign::TYPE ) ) {
			$file = 'archive.php';
		} elseif ( is_singular( Eru_CP_Campaign::TYPE ) ) {
			$file = 'single.php';
		}
		if ( '' === $file ) {
			return $template;
		}
		$theme = locate_template( array( 'eru-campaigns/' . $file ) );
		return $theme ? $theme : ERU_CP_PATH . 'templates/' . $file;
	}

	/** Opens the page: classic themes use get_header(), block themes get the header template part. */
	public static function page_start(): void {
		self::style();
		if ( wp_is_block_theme() ) {
			?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>>
			<?php
			wp_body_open();
			echo '<div class="wp-site-blocks">';
			echo do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput -- block markup rendered by core.
			echo '<main class="wp-block-group eru-cp-main">';
			return;
		}
		get_header();
	}

	public static function page_end(): void {
		if ( wp_is_block_theme() ) {
			echo '</main>';
			echo do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput -- block markup rendered by core.
			echo '</div>';
			wp_footer();
			echo '</body></html>';
			return;
		}
		get_footer();
	}

	public static function card( WP_Post $post ): string {
		$id    = $post->ID;
		$dates = Eru_CP_Campaign::date_label( $id );
		$img   = has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, 'large', array( 'loading' => 'lazy' ) ) : '<span class="eru-cp-card__noimg"></span>';
		$exc   = has_excerpt( $id ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 22 );
		$out   = '<article class="eru-cp-card"><a class="eru-cp-card__img" href="' . esc_url( get_permalink( $id ) ) . '">' . $img . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core image markup.
		$out  .= '<div class="eru-cp-card__body"><h3 class="eru-cp-card__title"><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>';
		if ( '' !== $dates ) {
			$out .= '<p class="eru-cp-card__dates">' . esc_html( $dates ) . '</p>';
		}
		if ( '' !== $exc ) {
			$out .= '<p class="eru-cp-card__text">' . esc_html( $exc ) . '</p>';
		}
		$out .= '<a class="eru-cp-card__more" href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html__( 'Detayları gör', 'campaign-pages-for-wordpress' ) . '</a></div></article>';
		return $out;
	}

	/** @param WP_Post[] $posts Campaigns. */
	public static function grid( array $posts, int $columns ): string {
		if ( ! $posts ) {
			return '';
		}
		$out = '<div class="eru-cp-grid eru-cp-cols-' . (int) $columns . '">';
		foreach ( $posts as $p ) {
			$out .= self::card( $p );
		}
		return $out . '</div>';
	}

	/**
	 * [eru_campaigns limit="" columns="" status="active|upcoming|ended"]
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$s    = Eru_CP_Settings::get();
		$atts = shortcode_atts( array( 'limit' => 0, 'columns' => $s['columns'], 'status' => 'active' ), (array) $atts, 'eru_campaigns' );
		$st   = in_array( $atts['status'], array( 'active', 'upcoming', 'ended' ), true ) ? $atts['status'] : 'active';
		self::style();
		$html = self::grid( Eru_CP_Campaign::query( $st, (int) $atts['limit'] ), max( 1, min( 4, (int) $atts['columns'] ) ) );
		if ( '' === $html ) {
			return '<p class="eru-cp-empty">' . esc_html__( 'Şu anda aktif kampanya bulunmuyor.', 'campaign-pages-for-wordpress' ) . '</p>';
		}
		return '<div class="eru-cp" style="--eru-cp-accent:' . esc_attr( $s['accent'] ) . '">' . $html . '</div>';
	}
}
