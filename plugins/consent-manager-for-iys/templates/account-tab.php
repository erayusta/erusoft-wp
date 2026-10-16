<?php
/**
 * Hesabım → İletişim tercihleri.
 *
 * Override by copying to yourtheme/consent-manager-for-iys/account-tab.php.
 *
 * @package consent-manager-for-iys
 * @var array      $eru_cmi_rows    type => {type, label, recipient, status, date, locked}.
 * @var string     $eru_cmi_action  Form URL.
 * @var array|null $eru_cmi_policy  [url, title] of the policy page, or null.
 * @var string     $eru_cmi_address Address edit URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="eru-cmi-account">
	<p><?php esc_html_e( 'Kampanya, indirim ve duyuruları hangi kanallardan almak istediğinizi seçin. Sipariş ve kargo bilgilendirmeleri bu tercihlerden etkilenmez.', 'consent-manager-for-iys' ); ?></p>
	<form method="post" action="<?php echo esc_url( $eru_cmi_action ); ?>" class="eru-cmi-account-form">
		<?php wp_nonce_field( Eru_CMI_Account::NONCE, '_eru_cmi_nonce' ); ?>
		<input type="hidden" name="eru_cmi_account_save" value="1">
		<ul class="eru-cmi-channels">
			<?php foreach ( $eru_cmi_rows as $eru_cmi_row ) : ?>
				<?php $eru_cmi_id = 'eru-cmi-ch-' . strtolower( $eru_cmi_row['type'] ); ?>
				<li class="eru-cmi-channel<?php echo '' === $eru_cmi_row['recipient'] ? ' is-disabled' : ''; ?>">
					<label for="<?php echo esc_attr( $eru_cmi_id ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $eru_cmi_id ); ?>" name="eru_cmi_channels[]" value="<?php echo esc_attr( $eru_cmi_row['type'] ); ?>"<?php checked( 'ONAY', $eru_cmi_row['status'] ); ?><?php disabled( '' === $eru_cmi_row['recipient'] || ! empty( $eru_cmi_row['locked'] ) ); ?>>
						<span class="eru-cmi-channel-name"><?php echo esc_html( $eru_cmi_row['label'] ); ?></span>
					</label>
					<span class="eru-cmi-channel-meta">
						<?php if ( '' === $eru_cmi_row['recipient'] ) : ?>
							<?php
							printf(
								/* translators: %s: link to the address page */
								esc_html__( 'Bu kanal için %s bölümüne bir cep telefonu numarası ekleyin.', 'consent-manager-for-iys' ),
								'<a href="' . esc_url( $eru_cmi_address ) . '">' . esc_html__( 'Adresler', 'consent-manager-for-iys' ) . '</a>'
							);
							?>
						<?php else : ?>
							<?php echo esc_html( Eru_CMI_Recipient::mask( $eru_cmi_row['recipient'] ) ); ?>
							<?php if ( 'ONAY' === $eru_cmi_row['status'] ) : ?>
								— <?php esc_html_e( 'izin verildi', 'consent-manager-for-iys' ); ?>
							<?php elseif ( 'RET' === $eru_cmi_row['status'] ) : ?>
								— <?php esc_html_e( 'izin yok', 'consent-manager-for-iys' ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $eru_cmi_row['locked'] ) ) : ?>
								<br><span class="eru-cmi-locked"><?php echo esc_html( Eru_CMI_Account::phone_notice() ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $eru_cmi_policy ) : ?>
			<p class="eru-cmi-policy"><a href="<?php echo esc_url( $eru_cmi_policy[0] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $eru_cmi_policy[1] ); ?></a></p>
		<?php endif; ?>
		<p><button type="submit" class="button woocommerce-Button eru-cmi-save"><?php esc_html_e( 'Tercihleri kaydet', 'consent-manager-for-iys' ); ?></button></p>
	</form>
	<?php if ( Eru_CMI_Settings::active_flag() ) : ?>
		<p class="eru-cmi-note"><?php esc_html_e( 'Değişiklikleriniz İleti Yönetim Sistemi\'ne (İYS) de bildirilir.', 'consent-manager-for-iys' ); ?></p>
	<?php endif; ?>
</div>
