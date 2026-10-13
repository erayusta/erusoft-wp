<?php
/**
 * Home: highlights strip from the Customizer (one item per line, "Title | text"). Hidden when empty.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
$narin_items = narin_setting_lines('highlights');
if (!$narin_items) {
	return;
}
?>
<section class="section home-highlights" aria-label="<?php esc_attr_e('Öne çıkan bilgiler', 'narin'); ?>">
	<div class="container">
		<ul class="highlights">
			<?php foreach (array_slice($narin_items, 0, 4) as $narin_item) : ?>
				<li class="highlight">
					<?php narin_icon('check'); ?>
					<strong><?php echo esc_html($narin_item['title']); ?></strong>
					<?php if ($narin_item['text'] !== '') : ?>
						<span><?php echo esc_html($narin_item['text']); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
