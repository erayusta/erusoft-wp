<?php
/**
 * Home: figures band. Only value + label pairs that are both filled are shown.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!ufuk_setting('show_stats')) {
	return;
}

$ufuk_stats = array();
for ($ufuk_i = 1; $ufuk_i <= 4; $ufuk_i++) {
	$ufuk_value = ufuk_setting('stat_' . $ufuk_i . '_value');
	$ufuk_label = ufuk_setting('stat_' . $ufuk_i . '_label');
	if ($ufuk_value !== '' && $ufuk_label !== '') {
		$ufuk_stats[] = array($ufuk_value, $ufuk_label);
	}
}

if (!$ufuk_stats) {
	ufuk_admin_hint(__('Rakamlar bandı boş: gerçek bilgilerinizi (ör. hizmet alanı sayısı, çalışma saatleri) girin ya da bandı kapatın.', 'ufuk'), 'ufuk_stats');
	return;
}
?>
<section class="stats-bar" aria-label="<?php esc_attr_e('Kısaca', 'ufuk'); ?>">
	<div class="container">
		<dl class="stats-grid stats-<?php echo count($ufuk_stats); ?>">
			<?php foreach ($ufuk_stats as $ufuk_n => $ufuk_stat) : ?>
				<div class="stat reveal" data-reveal-delay="<?php echo esc_attr($ufuk_n * 80); ?>">
					<dt><?php echo esc_html($ufuk_stat[1]); ?></dt>
					<dd><?php echo esc_html($ufuk_stat[0]); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>
	</div>
</section>
