<?php
/**
 * My Account: status of a pending or suspended agent, or the application form.
 *
 * @package sales-team-commissions-for-woocommerce
 * @var array $eru_stc agent, panel_url, can_apply, nonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$eru_stc_agent = $eru_stc['agent'];
?>
<div class="eru-stc-account eru-stc-status">
	<?php if ( $eru_stc_agent && 'pending' === $eru_stc_agent->status ) : ?>
		<p class="eru-stc-note"><?php esc_html_e( 'Temsilci başvurunuz inceleniyor. Mağaza onayladığında paneliniz burada açılır.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php elseif ( $eru_stc_agent ) : ?>
		<p class="eru-stc-note"><?php esc_html_e( 'Temsilci hesabınız askıya alındı. Ayrıntılar için mağazayla iletişime geçin.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php elseif ( $eru_stc['can_apply'] ) : ?>
		<p><?php esc_html_e( 'Mağazanın satış ekibine katılmak için başvurabilirsiniz. Başvurunuz onaylandığında size bir davet linki tanımlanır; bu linkle gelen müşterilerin siparişlerinden komisyon kazanırsınız.', 'sales-team-commissions-for-woocommerce' ); ?></p>
		<form method="post" action="<?php echo esc_url( $eru_stc['panel_url'] ); ?>" class="eru-stc-form">
			<input type="hidden" name="eru_stc_action" value="apply">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $eru_stc['nonce'] ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Temsilci olmak için başvur', 'sales-team-commissions-for-woocommerce' ); ?></button>
		</form>
	<?php else : ?>
		<p class="eru-stc-note"><?php esc_html_e( 'Bu sayfa satış temsilcileri içindir.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php endif; ?>
</div>
