<?php
/**
 * Home: the static front page's own block content (shown only when it has content).
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
while (have_posts()) :
	the_post();
	if (trim(get_the_content()) === '') {
		continue;
	}
	?>
	<section class="section home-content">
		<div class="container container--narrow entry-content">
			<?php the_content(); ?>
		</div>
	</section>
	<?php
endwhile;
rewind_posts();
