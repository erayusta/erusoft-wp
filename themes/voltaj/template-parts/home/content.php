<?php
/**
 * Home: the static front page's own block content (shown only when it has content).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
if ('page' !== get_option('show_on_front') || !is_page()) {
	return;
}
while (have_posts()) :
	the_post();
	// A protected front page shows core's password form here (the_content() prints it).
	if (!post_password_required() && trim(get_the_content()) === '') {
		continue;
	}
	?>
	<section class="vj-section vj-home-content">
		<div class="vj-container vj-container--narrow vj-prose">
			<?php the_content(); ?>
		</div>
	</section>
	<?php
endwhile;
rewind_posts();
