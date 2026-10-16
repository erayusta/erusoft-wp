<?php
/**
 * My Account: payout details. Stored values are shown masked only; the inputs are always empty (a blank
 * field keeps the stored value). Without the site's encryption key the IBAN and TCKN fields are hidden.
 *
 * @package sales-team-commissions-for-woocommerce
 * @var array $eru_stc enabled, available, masked, nonce, delete_nonce, action_url.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$eru_stc_m = $eru_stc['masked'];
?>
<div class="eru-stc-account eru-stc-details">
	<?php if ( ! $eru_stc['enabled'] ) : ?>
		<p class="eru-stc-note"><?php esc_html_e( 'Ödeme bilgileri mağaza tarafından alınıyor; bu form kapalı.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php else : ?>
		<?php if ( 'empty' !== $eru_stc_m['state'] ) : ?>
			<h3><?php esc_html_e( 'Kayıtlı bilgiler', 'sales-team-commissions-for-woocommerce' ); ?></h3>
			<dl class="eru-stc-masked">
				<dt><?php esc_html_e( 'Hesap sahibi', 'sales-team-commissions-for-woocommerce' ); ?></dt><dd><?php echo esc_html( '' !== $eru_stc_m['holder'] ? $eru_stc_m['holder'] : '—' ); ?></dd>
				<dt><?php esc_html_e( 'IBAN', 'sales-team-commissions-for-woocommerce' ); ?></dt><dd><code><?php echo esc_html( '' !== $eru_stc_m['iban'] ? $eru_stc_m['iban'] : '—' ); ?></code></dd>
				<dt><?php esc_html_e( 'T.C. kimlik no', 'sales-team-commissions-for-woocommerce' ); ?></dt><dd><code><?php echo esc_html( '' !== $eru_stc_m['tckn'] ? $eru_stc_m['tckn'] : '—' ); ?></code></dd>
			</dl>
		<?php endif; ?>

		<?php if ( ! $eru_stc['available'] ) : ?>
			<p class="eru-stc-note eru-stc-nokey"><?php esc_html_e( 'Ödeme bilgileri şu anda kaydedilemiyor: mağazada şifreleme anahtarı tanımlı değil. Ödeme için mağazayla iletişime geçin.', 'sales-team-commissions-for-woocommerce' ); ?></p>
		<?php else : ?>
			<h3><?php echo esc_html( 'empty' === $eru_stc_m['state'] ? __( 'Ödeme bilgilerinizi girin', 'sales-team-commissions-for-woocommerce' ) : __( 'Bilgileri değiştir', 'sales-team-commissions-for-woocommerce' ) ); ?></h3>
			<p class="eru-stc-muted"><?php esc_html_e( 'Bilgileriniz şifreli saklanır ve kaydedildikten sonra yalnızca maskeli gösterilir. Değiştirmek istemediğiniz alanı boş bırakın.', 'sales-team-commissions-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( $eru_stc['action_url'] ); ?>" class="eru-stc-form eru-stc-details-form" autocomplete="off">
				<input type="hidden" name="eru_stc_action" value="details_save">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $eru_stc['nonce'] ); ?>">
				<p class="form-row form-row-wide">
					<label for="eru-stc-holder"><?php esc_html_e( 'Hesap sahibinin adı soyadı', 'sales-team-commissions-for-woocommerce' ); ?></label>
					<input type="text" class="input-text" name="eru_stc_holder" id="eru-stc-holder" value="" maxlength="100" autocomplete="off">
				</p>
				<p class="form-row form-row-wide">
					<label for="eru-stc-iban"><?php esc_html_e( 'IBAN', 'sales-team-commissions-for-woocommerce' ); ?></label>
					<input type="text" class="input-text" name="eru_stc_iban" id="eru-stc-iban" value="" maxlength="34" inputmode="text" autocomplete="off" placeholder="<?php esc_attr_e( 'TR ile başlayan 26 karakter', 'sales-team-commissions-for-woocommerce' ); ?>">
				</p>
				<p class="form-row form-row-wide">
					<label for="eru-stc-tckn"><?php esc_html_e( 'T.C. kimlik no (isteğe bağlı)', 'sales-team-commissions-for-woocommerce' ); ?></label>
					<input type="text" class="input-text" name="eru_stc_tckn" id="eru-stc-tckn" value="" maxlength="11" inputmode="numeric" autocomplete="off">
				</p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Kaydet', 'sales-team-commissions-for-woocommerce' ); ?></button></p>
			</form>
		<?php endif; ?>

		<?php if ( 'empty' !== $eru_stc_m['state'] ) : ?>
			<form method="post" action="<?php echo esc_url( $eru_stc['action_url'] ); ?>" class="eru-stc-form eru-stc-delete">
				<input type="hidden" name="eru_stc_action" value="details_delete">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $eru_stc['delete_nonce'] ); ?>">
				<button type="submit" class="button eru-stc-link-button"><?php esc_html_e( 'Kayıtlı ödeme bilgilerimi sil', 'sales-team-commissions-for-woocommerce' ); ?></button>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</div>
