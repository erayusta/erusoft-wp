<?php
/**
 * Home: info cards from the Customizer ("Başlık | açıklama" per line). Hidden when empty.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!voltaj_setting('show_info')) {
	return;
}
$voltaj_cards = voltaj_setting_lines('info_cards');
if (!$voltaj_cards) {
	echo '<div class="vj-container">';
	voltaj_admin_hint(__('Bilgi kartları boş. Teslimat, fatura veya teknik destek gibi gerçek koşullarınızı ekleyin.', 'voltaj'), 'voltaj_home');
	echo '</div>';
	return;
}
$voltaj_icons = (array) apply_filters('voltaj_info_icons', array('truck', 'file-text', 'headphones', 'shield', 'package', 'tool'));
?>
<section class="vj-section vj-home-info" aria-label="<?php esc_attr_e('Alışveriş bilgileri', 'voltaj'); ?>">
	<div class="vj-container">
		<ul class="vj-info-grid">
			<?php foreach ($voltaj_cards as $voltaj_i => $voltaj_card) : ?>
				<li class="vj-info-card">
					<span class="vj-info-card__icon"><?php voltaj_icon($voltaj_icons[$voltaj_i % max(1, count($voltaj_icons))]); ?></span>
					<span class="vj-info-card__body">
						<strong class="vj-info-card__title"><?php echo esc_html($voltaj_card['title']); ?></strong>
						<?php if ($voltaj_card['text'] !== '') : ?>
							<span class="vj-info-card__text"><?php echo esc_html($voltaj_card['text']); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
