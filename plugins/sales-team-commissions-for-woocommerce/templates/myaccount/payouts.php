<?php
/**
 * My Account: payout request and history.
 *
 * @package sales-team-commissions-for-woocommerce
 * @var array $eru_stc balance, currency, min, can_request, reason, needs_details, nonce, rows, page, pages,
 *                     base_url, details_url.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$eru_stc_c = array( 'currency' => $eru_stc['currency'] );
?>
<div class="eru-stc-account eru-stc-payouts">
	<ul class="eru-stc-tiles eru-stc-tiles--3">
		<li class="eru-stc-tile eru-stc-tile--accent"><span class="eru-stc-tile__label"><?php esc_html_e( 'Ödenebilir', 'sales-team-commissions-for-woocommerce' ); ?></span><strong class="eru-stc-tile__value"><?php echo wp_kses_post( wc_price( (float) $eru_stc['balance']['available'], $eru_stc_c ) ); ?></strong></li>
		<li class="eru-stc-tile"><span class="eru-stc-tile__label"><?php esc_html_e( 'Talep edildi', 'sales-team-commissions-for-woocommerce' ); ?></span><strong class="eru-stc-tile__value"><?php echo wp_kses_post( wc_price( (float) $eru_stc['balance']['requested'], $eru_stc_c ) ); ?></strong></li>
		<li class="eru-stc-tile"><span class="eru-stc-tile__label"><?php esc_html_e( 'Ödendi', 'sales-team-commissions-for-woocommerce' ); ?></span><strong class="eru-stc-tile__value"><?php echo wp_kses_post( wc_price( (float) $eru_stc['balance']['paid'], $eru_stc_c ) ); ?></strong></li>
	</ul>
	<?php if ( $eru_stc['can_request'] ) : ?>
		<form method="post" action="<?php echo esc_url( $eru_stc['base_url'] ); ?>" class="eru-stc-form">
			<input type="hidden" name="eru_stc_action" value="payout_request">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $eru_stc['nonce'] ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Ödenebilir bakiyenin ödenmesini talep et', 'sales-team-commissions-for-woocommerce' ); ?></button>
		</form>
	<?php else : ?>
		<p class="eru-stc-note"><?php echo esc_html( $eru_stc['reason'] ); ?>
		<?php if ( $eru_stc['needs_details'] ) : ?>
			<a href="<?php echo esc_url( $eru_stc['details_url'] ); ?>"><?php esc_html_e( 'Ödeme bilgileri', 'sales-team-commissions-for-woocommerce' ); ?></a>
		<?php endif; ?>
		</p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Ödeme geçmişi', 'sales-team-commissions-for-woocommerce' ); ?></h3>
	<?php if ( $eru_stc['rows'] ) : ?>
		<table class="eru-stc-table shop_table shop_table_responsive">
			<thead><tr><th><?php esc_html_e( 'No', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Talep', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Tutar', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Durum', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Ödeme', 'sales-team-commissions-for-woocommerce' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $eru_stc['rows'] as $eru_stc_p ) : ?>
				<tr>
					<td data-title="<?php esc_attr_e( 'No', 'sales-team-commissions-for-woocommerce' ); ?>">#<?php echo esc_html( (string) $eru_stc_p->id ); ?></td>
					<td data-title="<?php esc_attr_e( 'Talep', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( get_date_from_gmt( $eru_stc_p->requested_at, get_option( 'date_format' ) ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Tutar', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo wp_kses_post( wc_price( (float) $eru_stc_p->amount, array( 'currency' => $eru_stc_p->currency ) ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Durum', 'sales-team-commissions-for-woocommerce' ); ?>"><span class="eru-stc-badge eru-stc-badge--<?php echo esc_attr( $eru_stc_p->status ); ?>"><?php echo esc_html( Eru_STC_Labels::payout_status( $eru_stc_p->status ) ); ?></span></td>
					<td data-title="<?php esc_attr_e( 'Ödeme', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo $eru_stc_p->paid_at ? esc_html( get_date_from_gmt( $eru_stc_p->paid_at, get_option( 'date_format' ) ) . ( '' !== $eru_stc_p->reference ? ' · ' . $eru_stc_p->reference : '' ) ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php Eru_STC_Account::pager( $eru_stc['base_url'], $eru_stc['page'], $eru_stc['pages'] ); ?>
	<?php else : ?>
		<p class="eru-stc-muted"><?php esc_html_e( 'Henüz ödeme talebi yok.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php endif; ?>
</div>
