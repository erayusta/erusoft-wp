<?php
/**
 * Front-end review section for products with imported reviews.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On a product that has at least one imported Trendyol review, the reviews
 * template is replaced with a rating summary, a sortable/filterable paginated
 * card list and the regular review form (templates/single-product-reviews.php).
 * Products without imported reviews keep the theme template untouched.
 * Disable completely with: add_filter( 'eru_tyr_use_custom_template', '__return_false' );
 * then imported reviews are only decorated (source badge + photos).
 */
class Eru_TYR_Frontend {

	const PER_PAGE = 20;

	/** @var array<int,bool> */
	protected static array $has_imported = array();

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'comments_template', array( __CLASS__, 'reviews_template' ), 100 );
		add_filter( 'comment_text', array( __CLASS__, 'decorate_review' ), 20, 2 );
		add_action( 'wp_ajax_eru_tyr_reviews', array( __CLASS__, 'ajax_reviews' ) );
		add_action( 'wp_ajax_nopriv_eru_tyr_reviews', array( __CLASS__, 'ajax_reviews' ) );
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'structured_product' ), 20, 2 );
		add_filter( 'woocommerce_structured_data_review', array( __CLASS__, 'structured_review' ), 20, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Structured data: imported reviews are left out by default
	 * ------------------------------------------------------------------- */

	/**
	 * Rebuild aggregateRating and the review list from the store's own
	 * reviews only (Google's review-snippet guidelines exclude reviews
	 * collected on other sites). Setting "structured_data" turns this off.
	 */
	public static function structured_product( $markup, $product ) {
		if ( ! is_array( $markup ) || ! $product instanceof WC_Product || Eru_TYR_Settings::get( 'structured_data' ) || ! self::has_imported_reviews( $product->get_id() ) ) {
			return $markup;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, AVG(CAST(r.meta_value AS DECIMAL(4,2))) AS average
				 FROM {$wpdb->comments} c
				 INNER JOIN {$wpdb->commentmeta} r ON r.comment_id = c.comment_ID AND r.meta_key = 'rating'
				 LEFT JOIN {$wpdb->commentmeta} s ON s.comment_id = c.comment_ID AND s.meta_key = %s
				 WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND c.comment_parent = 0
				   AND s.meta_id IS NULL AND CAST(r.meta_value AS UNSIGNED) > 0",
				Eru_TYR_Importer::META_SOURCE,
				$product->get_id()
			)
		);

		unset( $markup['aggregateRating'], $markup['review'] );
		if ( $row && (int) $row->n > 0 ) {
			$markup['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) round( (float) $row->average, 2 ),
				'reviewCount' => (int) $row->n,
			);
			$own = get_comments(
				array(
					'number'     => 5,
					'post_id'    => $product->get_id(),
					'status'     => 'approve',
					'parent'     => 0,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array( 'key' => 'rating', 'type' => 'NUMERIC', 'compare' => '>', 'value' => 0 ),
						array( 'key' => Eru_TYR_Importer::META_SOURCE, 'compare' => 'NOT EXISTS' ),
					),
				)
			);
			foreach ( $own as $comment ) {
				$markup['review'][] = array(
					'@type'         => 'Review',
					'reviewRating'  => array(
						'@type'       => 'Rating',
						'bestRating'  => '5',
						'ratingValue' => get_comment_meta( $comment->comment_ID, 'rating', true ),
						'worstRating' => '1',
					),
					'author'        => array( '@type' => 'Person', 'name' => get_comment_author( $comment ) ),
					'reviewBody'    => get_comment_text( $comment ),
					'datePublished' => get_comment_date( 'c', $comment ),
				);
			}
		}
		return $markup;
	}

	/**
	 * Per-review markup: drop imported reviews unless the setting allows them.
	 */
	public static function structured_review( $markup, $comment ) {
		if ( ! Eru_TYR_Settings::get( 'structured_data' ) && $comment instanceof WP_Comment && 'trendyol' === get_comment_meta( $comment->comment_ID, Eru_TYR_Importer::META_SOURCE, true ) ) {
			return array();
		}
		return $markup;
	}

	public static function has_imported_reviews( int $product_id ): bool {
		global $wpdb;
		if ( ! isset( self::$has_imported[ $product_id ] ) ) {
			self::$has_imported[ $product_id ] = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
					 WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND cm.meta_key = %s LIMIT 1",
					$product_id,
					Eru_TYR_Importer::META_SOURCE
				)
			);
		}
		return self::$has_imported[ $product_id ];
	}

	protected static function active_for_current_product(): bool {
		return function_exists( 'is_product' ) && is_product() && self::has_imported_reviews( (int) get_queried_object_id() );
	}

	public static function enqueue(): void {
		if ( ! self::active_for_current_product() ) {
			return;
		}
		wp_enqueue_style( 'eru-tyr-reviews', ERU_TYR_URL . 'assets/frontend/reviews.css', array(), ERU_TYR_VERSION );
		if ( apply_filters( 'eru_tyr_use_custom_template', true ) ) {
			wp_enqueue_script( 'eru-tyr-reviews', ERU_TYR_URL . 'assets/frontend/reviews.js', array(), ERU_TYR_VERSION, true );
			wp_localize_script( 'eru-tyr-reviews', 'eruTyrFrontend', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
		}
	}

	public static function reviews_template( $template ) {
		if ( self::active_for_current_product() && apply_filters( 'eru_tyr_use_custom_template', true ) ) {
			return ERU_TYR_PATH . 'templates/single-product-reviews.php';
		}
		return $template;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	public static function render_summary( WC_Product $product ): void {
		if ( ! $product->get_review_count() ) {
			return;
		}
		$average = (float) $product->get_average_rating();
		$counts  = (array) $product->get_rating_counts();
		$total   = max( 1, array_sum( $counts ) );
		?>
		<div class="eru-tyr-summary">
			<div class="eru-tyr-summary-score">
				<div class="eru-tyr-summary-average"><?php echo esc_html( number_format_i18n( $average, 1 ) ); ?></div>
				<div class="eru-tyr-summary-stars" aria-hidden="true"><?php self::stars( $average ); ?></div>
				<div class="eru-tyr-summary-count"><?php self::count_label( $product->get_review_count() ); ?></div>
			</div>
			<div class="eru-tyr-summary-bars">
				<?php
				for ( $rate = 5; $rate >= 1; $rate-- ) :
					$count   = (int) ( $counts[ $rate ] ?? 0 );
					$percent = round( $count / $total * 100, 1 );
					?>
					<div class="eru-tyr-bar-row">
						<span class="eru-tyr-bar-label"><?php echo esc_html( $rate ); ?> <span class="eru-tyr-bar-star">★</span></span>
						<span class="eru-tyr-bar-track"><span class="eru-tyr-bar-fill" style="width:<?php echo esc_attr( $percent ); ?>%"></span></span>
						<span class="eru-tyr-bar-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					</div>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	}

	public static function render_toolbar( WC_Product $product ): void {
		?>
		<div class="eru-tyr-toolbar">
			<div class="eru-tyr-toolbar-count"><?php self::count_label( $product->get_review_count() ); ?></div>
			<div class="eru-tyr-toolbar-controls">
				<select class="eru-tyr-sort" aria-label="<?php esc_attr_e( 'Yorumları sırala', 'trendyol-reviews-for-woocommerce' ); ?>">
					<option value="date_desc"><?php esc_html_e( 'En yeni', 'trendyol-reviews-for-woocommerce' ); ?></option>
					<option value="date_asc"><?php esc_html_e( 'En eski', 'trendyol-reviews-for-woocommerce' ); ?></option>
					<option value="rating_desc"><?php esc_html_e( 'En yüksek puan', 'trendyol-reviews-for-woocommerce' ); ?></option>
					<option value="rating_asc"><?php esc_html_e( 'En düşük puan', 'trendyol-reviews-for-woocommerce' ); ?></option>
				</select>
				<select class="eru-tyr-star-filter" aria-label="<?php esc_attr_e( 'Puana göre filtrele', 'trendyol-reviews-for-woocommerce' ); ?>">
					<option value="0"><?php esc_html_e( 'Tüm puanlar', 'trendyol-reviews-for-woocommerce' ); ?></option>
					<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
						<option value="<?php echo esc_attr( $i ); ?>"><?php echo esc_html( $i ); ?> ★</option>
					<?php endfor; ?>
				</select>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array{comments: WP_Comment[], total: int, pages: int, page: int}
	 */
	public static function get_reviews( int $product_id, int $page = 1, string $orderby = 'date_desc', int $star = 0 ): array {
		$args = array(
			'post_id' => $product_id,
			'type'    => 'review',
			'status'  => 'approve',
			'parent'  => 0,
		);
		if ( $star >= 1 && $star <= 5 ) {
			$args['meta_query'] = array( array( 'key' => 'rating', 'value' => $star ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		switch ( $orderby ) {
			case 'date_asc':
				$args['orderby'] = 'comment_date_gmt';
				$args['order']   = 'ASC';
				break;
			case 'rating_desc':
			case 'rating_asc':
				$args['meta_key'] = 'rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'rating_asc' === $orderby ? 'ASC' : 'DESC';
				break;
			default:
				$args['orderby'] = 'comment_date_gmt';
				$args['order']   = 'DESC';
		}

		$total = (int) get_comments( array_merge( $args, array( 'count' => true ) ) );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( max( 1, $page ), $pages );

		return array(
			'comments' => get_comments( array_merge( $args, array( 'number' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE ) ) ),
			'total'    => $total,
			'pages'    => $pages,
			'page'     => $page,
		);
	}

	public static function render_list( array $comments ): void {
		if ( ! $comments ) {
			echo '<p class="eru-tyr-empty">' . esc_html__( 'Seçiminize uyan yorum bulunamadı.', 'trendyol-reviews-for-woocommerce' ) . '</p>';
			return;
		}
		foreach ( $comments as $comment ) {
			self::render_item( $comment );
		}
	}

	public static function render_item( WP_Comment $comment ): void {
		$rating      = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
		$is_trendyol = 'trendyol' === get_comment_meta( $comment->comment_ID, Eru_TYR_Importer::META_SOURCE, true );
		$photos      = get_comment_meta( $comment->comment_ID, Eru_TYR_Importer::META_PHOTOS, true );
		$author      = $comment->comment_author ? $comment->comment_author : __( 'Müşteri', 'trendyol-reviews-for-woocommerce' );
		?>
		<article class="eru-tyr-card">
			<header class="eru-tyr-card-head">
				<span class="eru-tyr-avatar" style="--eru-tyr-hue:<?php echo esc_attr( (string) ( crc32( $author ) % 360 ) ); ?>" aria-hidden="true"><?php echo esc_html( self::avatar_initials( $author ) ); ?></span>
				<div class="eru-tyr-card-meta">
					<div class="eru-tyr-card-row1">
						<span class="eru-tyr-card-author"><?php echo esc_html( $author ); ?></span>
						<?php
						if ( $is_trendyol ) {
							echo self::badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
						}
						?>
					</div>
					<div class="eru-tyr-card-row2">
						<?php if ( $rating ) : ?>
							<span class="eru-tyr-card-stars" role="img" aria-label="<?php echo esc_attr( sprintf( '%d/5', $rating ) ); ?>"><?php self::stars( $rating ); ?></span>
						<?php endif; ?>
						<time class="eru-tyr-card-date" datetime="<?php echo esc_attr( get_comment_date( 'c', $comment ) ); ?>"><?php echo esc_html( get_comment_date( wc_date_format(), $comment ) ); ?></time>
					</div>
				</div>
			</header>
			<div class="eru-tyr-card-body"><?php echo wp_kses_post( wpautop( $comment->comment_content ) ); ?></div>
			<?php echo self::gallery( $photos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in gallery(). ?>
		</article>
		<?php
	}

	public static function render_pagination( int $page, int $pages ): void {
		if ( $pages <= 1 ) {
			return;
		}
		?>
		<nav class="eru-tyr-pagination" data-page="<?php echo esc_attr( $page ); ?>" data-pages="<?php echo esc_attr( $pages ); ?>">
			<button type="button" class="eru-tyr-page-btn eru-tyr-prev" <?php disabled( $page <= 1 ); ?>>&larr; <?php esc_html_e( 'Önceki', 'trendyol-reviews-for-woocommerce' ); ?></button>
			<span class="eru-tyr-page-info">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Sayfa %1$s / %2$s', 'trendyol-reviews-for-woocommerce' ),
					esc_html( number_format_i18n( $page ) ),
					esc_html( number_format_i18n( $pages ) )
				);
				?>
			</span>
			<button type="button" class="eru-tyr-page-btn eru-tyr-next" <?php disabled( $page >= $pages ); ?>><?php esc_html_e( 'Sonraki', 'trendyol-reviews-for-woocommerce' ); ?> &rarr;</button>
		</nav>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX (public): paginated / sorted list
	 * ------------------------------------------------------------------- */

	public static function ajax_reviews(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only list of published reviews.
		$product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		$page       = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
		$orderby    = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'date_desc';
		$star       = isset( $_GET['star'] ) ? absint( $_GET['star'] ) : 0;
		// phpcs:enable

		if ( ! in_array( $orderby, array( 'date_desc', 'date_asc', 'rating_desc', 'rating_asc' ), true ) ) {
			$orderby = 'date_desc';
		}
		if ( 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) || post_password_required( $product_id ) ) {
			wp_send_json_error();
		}

		$result = self::get_reviews( $product_id, $page, $orderby, $star );
		ob_start();
		self::render_list( $result['comments'] );
		$list = ob_get_clean();
		ob_start();
		self::render_pagination( $result['page'], $result['pages'] );
		$pagination = ob_get_clean();

		wp_send_json_success(
			array(
				'list'       => $list,
				'pagination' => $pagination,
				'page'       => $result['page'],
				'pages'      => $result['pages'],
				'total'      => $result['total'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Theme template kept: decorate imported reviews only
	 * ------------------------------------------------------------------- */

	public static function decorate_review( $text, $comment = null ) {
		if ( is_admin() || apply_filters( 'eru_tyr_use_custom_template', true ) ) {
			return $text;
		}
		if ( ! $comment instanceof WP_Comment || 'review' !== $comment->comment_type ) {
			return $text;
		}
		if ( 'trendyol' !== get_comment_meta( $comment->comment_ID, Eru_TYR_Importer::META_SOURCE, true ) ) {
			return $text;
		}
		return '<div class="eru-tyr-badge-row">' . self::badge() . '</div>' . $text . self::gallery( get_comment_meta( $comment->comment_ID, Eru_TYR_Importer::META_PHOTOS, true ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	protected static function badge(): string {
		return '<span class="eru-tyr-badge" title="' . esc_attr__( 'Bu yorum, mağazanın Trendyol ürün sayfasından aktarılmıştır.', 'trendyol-reviews-for-woocommerce' ) . '">'
			. '<svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path fill="currentColor" d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16-4-4 1.41-1.41L10 14.17l6.59-6.58L18 9l-8 8z"/></svg>'
			. esc_html__( 'Trendyol\'dan aktarıldı', 'trendyol-reviews-for-woocommerce' )
			. '</span>';
	}

	/**
	 * @param mixed $photos
	 */
	protected static function gallery( $photos ): string {
		if ( ! is_array( $photos ) || ! $photos ) {
			return '';
		}
		$html = '';
		foreach ( $photos as $photo ) {
			if ( empty( $photo['url'] ) ) {
				continue;
			}
			$thumb = ! empty( $photo['thumb'] ) ? $photo['thumb'] : $photo['url'];
			$html .= '<a href="' . esc_url( $photo['url'] ) . '" class="eru-tyr-photo" data-eru-tyr-lightbox target="_blank" rel="nofollow noopener">'
				. '<img src="' . esc_url( $thumb ) . '" alt="' . esc_attr__( 'Müşteri yorum fotoğrafı', 'trendyol-reviews-for-woocommerce' ) . '" loading="lazy" /></a>';
		}
		return '' !== $html ? '<div class="eru-tyr-photos">' . $html . '</div>' : '';
	}

	protected static function count_label( int $count ): void {
		printf(
			/* translators: %s: number of reviews */
			esc_html( _n( '%s değerlendirme', '%s değerlendirme', $count, 'trendyol-reviews-for-woocommerce' ) ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	protected static function stars( float $value ): void {
		printf(
			'<span class="eru-tyr-stars"><span class="eru-tyr-stars-fill" style="width:%s%%"></span></span>',
			esc_attr( (string) round( max( 0, min( 5, $value ) ) / 5 * 100, 2 ) )
		);
	}

	protected static function avatar_initials( string $author ): string {
		$initials = '';
		foreach ( array_slice( preg_split( '/\s+/u', trim( $author ), -1, PREG_SPLIT_NO_EMPTY ), 0, 2 ) as $part ) {
			$first = mb_substr( $part, 0, 1, 'UTF-8' );
			if ( preg_match( '/\p{L}/u', $first ) ) {
				$initials .= mb_strtoupper( $first, 'UTF-8' );
			}
		}
		return '' !== $initials ? $initials : '★';
	}
}
