<?php
/**
 * Post card for listings. Args: heading (h2|h3).
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
$ufuk_heading = isset($args['heading']) && $args['heading'] === 'h3' ? 'h3' : 'h2';
?>
<article id="post-<?php the_ID(); ?>" <?php post_class('post-card reveal'); ?>>
	<?php if (has_post_thumbnail()) : ?>
		<div class="post-thumb">
			<?php the_post_thumbnail('ufuk-card', array('loading' => 'lazy', 'alt' => '')); ?>
		</div>
	<?php endif; ?>
	<div class="post-body">
		<p class="post-meta">
			<time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
			<?php
			$ufuk_cat = get_the_category();
			if ($ufuk_cat) {
				echo ' &middot; <span>' . esc_html($ufuk_cat[0]->name) . '</span>';
			}
			?>
		</p>
		<<?php echo $ufuk_heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></<?php echo $ufuk_heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="post-excerpt"><?php the_excerpt(); ?></div>
	</div>
</article>
