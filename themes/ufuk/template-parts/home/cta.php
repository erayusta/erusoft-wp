<?php
/**
 * Home: call-to-action band. Hidden while the title is empty.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!ufuk_setting('show_cta')) {
	return;
}

$ufuk_title = ufuk_setting('cta_title');
if ($ufuk_title === '') {
	ufuk_admin_hint(__('Çağrı bandı için bir başlık girin ya da bandı kapatın.', 'ufuk'), 'ufuk_cta');
	return;
}
$ufuk_text = ufuk_setting('cta_text');
?>
<section class="section section-tight" aria-labelledby="cta-title">
	<div class="container">
		<div class="cta-banner reveal">
			<h2 id="cta-title"><?php echo esc_html($ufuk_title); ?></h2>
			<?php if ($ufuk_text) : ?>
				<p><?php echo esc_html($ufuk_text); ?></p>
			<?php endif; ?>
			<?php ufuk_button(ufuk_setting('cta_button_text'), ufuk_setting('cta_button_url'), 'btn-light btn-lg', true); ?>
		</div>
	</div>
</section>
