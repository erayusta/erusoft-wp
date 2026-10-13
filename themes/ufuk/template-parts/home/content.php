<?php
/**
 * Home: the static front page's own content (block editor), when it has any.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}

$ufuk_front = get_post((int) get_option('page_on_front'));
if (!$ufuk_front || trim(wp_strip_all_tags((string) $ufuk_front->post_content, true)) === '' && !has_blocks($ufuk_front)) {
	return;
}
?>
<section class="section home-content">
	<div class="container">
		<div class="entry-content">
			<?php
			while (have_posts()) {
				the_post();
				the_content();
			}
			?>
		</div>
	</div>
</section>
