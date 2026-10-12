<?php
/**
 * Product reviews template used for products that have imported Trendyol reviews.
 *
 * Rating summary + sortable, filterable, paginated review cards, followed by
 * the regular review form (same markup as WooCommerce's own template so the
 * core star-rating script and themes keep working).
 * Disable via: add_filter( 'eru_tyr_use_custom_template', '__return_false' );
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $product;

if ( ! $product instanceof WC_Product || ( ! comments_open() && ! $product->get_review_count() ) ) {
	return;
}

$eru_tyr_result = Eru_TYR_Frontend::get_reviews( $product->get_id() );
?>
<div id="reviews" class="woocommerce-Reviews eru-tyr-reviews" data-product="<?php echo esc_attr( $product->get_id() ); ?>">

	<?php Eru_TYR_Frontend::render_summary( $product ); ?>

	<div id="comments" class="eru-tyr-comments">
		<?php if ( $product->get_review_count() ) : ?>
			<?php Eru_TYR_Frontend::render_toolbar( $product ); ?>
			<div class="eru-tyr-list" aria-live="polite">
				<?php Eru_TYR_Frontend::render_list( $eru_tyr_result['comments'] ); ?>
			</div>
			<div class="eru-tyr-pagination-wrap">
				<?php Eru_TYR_Frontend::render_pagination( $eru_tyr_result['page'], $eru_tyr_result['pages'] ); ?>
			</div>
		<?php else : ?>
			<p class="woocommerce-noreviews eru-tyr-empty"><?php esc_html_e( 'Henüz değerlendirme yok.', 'trendyol-reviews-for-woocommerce' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( get_option( 'woocommerce_review_rating_verification_required' ) === 'no' || wc_customer_bought_product( '', get_current_user_id(), $product->get_id() ) ) : ?>
		<div id="review_form_wrapper">
			<div id="review_form">
				<?php
				$eru_tyr_commenter = wp_get_current_commenter();
				$eru_tyr_form      = array(
					/* translators: %s: product title */
					'title_reply'         => $product->get_review_count() ? __( 'Değerlendirme ekle', 'trendyol-reviews-for-woocommerce' ) : sprintf( __( '&ldquo;%s&rdquo; için ilk değerlendirmeyi siz yazın', 'trendyol-reviews-for-woocommerce' ), esc_html( get_the_title() ) ),
					/* translators: %s: reviewer name */
					'title_reply_to'      => __( '%s yorumuna yanıt yazın', 'trendyol-reviews-for-woocommerce' ),
					'title_reply_before'  => '<span id="reply-title" class="comment-reply-title">',
					'title_reply_after'   => '</span>',
					'comment_notes_after' => '',
					'label_submit'        => __( 'Gönder', 'trendyol-reviews-for-woocommerce' ),
					'logged_in_as'        => '',
					'comment_field'       => '',
					'fields'              => array(),
				);

				$eru_tyr_required = (bool) get_option( 'require_name_email', 1 );
				$eru_tyr_fields   = array(
					'author' => array( __( 'Ad', 'trendyol-reviews-for-woocommerce' ), 'text', $eru_tyr_commenter['comment_author'] ),
					'email'  => array( __( 'E-posta', 'trendyol-reviews-for-woocommerce' ), 'email', $eru_tyr_commenter['comment_author_email'] ),
				);
				foreach ( $eru_tyr_fields as $eru_tyr_key => $eru_tyr_field ) {
					$eru_tyr_form['fields'][ $eru_tyr_key ] = '<p class="comment-form-' . esc_attr( $eru_tyr_key ) . '"><label for="' . esc_attr( $eru_tyr_key ) . '">' . esc_html( $eru_tyr_field[0] )
						. ( $eru_tyr_required ? '&nbsp;<span class="required">*</span>' : '' )
						. '</label><input id="' . esc_attr( $eru_tyr_key ) . '" name="' . esc_attr( $eru_tyr_key ) . '" type="' . esc_attr( $eru_tyr_field[1] ) . '" value="' . esc_attr( $eru_tyr_field[2] ) . '" size="30" ' . ( $eru_tyr_required ? 'required' : '' ) . ' /></p>';
				}

				$eru_tyr_account = wc_get_page_permalink( 'myaccount' );
				if ( $eru_tyr_account ) {
					/* translators: 1: opening link tag, 2: closing link tag */
					$eru_tyr_form['must_log_in'] = '<p class="must-log-in">' . sprintf( esc_html__( 'Değerlendirme yazmak için %1$sgiriş yapmalısınız%2$s.', 'trendyol-reviews-for-woocommerce' ), '<a href="' . esc_url( $eru_tyr_account ) . '">', '</a>' ) . '</p>';
				}

				if ( wc_review_ratings_enabled() ) {
					$eru_tyr_form['comment_field'] = '<div class="comment-form-rating"><label for="rating">' . esc_html__( 'Puanınız', 'trendyol-reviews-for-woocommerce' ) . ( wc_review_ratings_required() ? '&nbsp;<span class="required">*</span>' : '' ) . '</label><select name="rating" id="rating" required>
						<option value="">' . esc_html__( 'Puan verin…', 'trendyol-reviews-for-woocommerce' ) . '</option>
						<option value="5">' . esc_html__( 'Mükemmel', 'trendyol-reviews-for-woocommerce' ) . '</option>
						<option value="4">' . esc_html__( 'İyi', 'trendyol-reviews-for-woocommerce' ) . '</option>
						<option value="3">' . esc_html__( 'Orta', 'trendyol-reviews-for-woocommerce' ) . '</option>
						<option value="2">' . esc_html__( 'Fena değil', 'trendyol-reviews-for-woocommerce' ) . '</option>
						<option value="1">' . esc_html__( 'Çok kötü', 'trendyol-reviews-for-woocommerce' ) . '</option>
					</select></div>';
				}
				$eru_tyr_form['comment_field'] .= '<p class="comment-form-comment"><label for="comment">' . esc_html__( 'Yorumunuz', 'trendyol-reviews-for-woocommerce' ) . '&nbsp;<span class="required">*</span></label><textarea id="comment" name="comment" cols="45" rows="8" required></textarea></p>';

				comment_form( apply_filters( 'woocommerce_product_review_comment_form_args', $eru_tyr_form ) );
				?>
			</div>
		</div>
	<?php else : ?>
		<p class="woocommerce-verification-required"><?php esc_html_e( 'Yalnızca bu ürünü satın almış ve giriş yapmış müşteriler değerlendirme yazabilir.', 'trendyol-reviews-for-woocommerce' ); ?></p>
	<?php endif; ?>

	<div class="clear"></div>
</div>
