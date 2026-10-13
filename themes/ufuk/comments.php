<?php
/**
 * Comments.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (post_password_required()) {
	return;
}
?>
<section id="comments" class="comments-area">
	<?php if (have_comments()) : ?>
		<h2 class="comments-title">
			<?php
			$ufuk_count = get_comments_number();
			/* translators: %s: number of comments */
			printf(esc_html(_n('%s yorum', '%s yorum', $ufuk_count, 'ufuk')), esc_html(number_format_i18n($ufuk_count)));
			?>
		</h2>
		<ol class="comment-list">
			<?php
			wp_list_comments(array(
				'style'       => 'ol',
				'short_ping'  => true,
				'avatar_size' => 40,
			));
			?>
		</ol>
		<?php the_comments_navigation(); ?>
	<?php endif; ?>

	<?php if (!comments_open() && get_comments_number()) : ?>
		<p class="no-comments"><?php esc_html_e('Yorumlar kapalı.', 'ufuk'); ?></p>
	<?php endif; ?>

	<?php comment_form(array('class_submit' => 'btn btn-primary')); ?>
</section>
