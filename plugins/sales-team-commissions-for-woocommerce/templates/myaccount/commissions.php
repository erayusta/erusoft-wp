<?php
/**
 * My Account: the agent's commission rows.
 *
 * @package sales-team-commissions-for-woocommerce
 * @var array $eru_stc rows, status, total, page, pages, base_url.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$eru_stc_filters = array(
	''         => __( 'Tümü', 'sales-team-commissions-for-woocommerce' ),
	'pending'  => __( 'Beklemede', 'sales-team-commissions-for-woocommerce' ),
	'approved' => __( 'Onaylı', 'sales-team-commissions-for-woocommerce' ),
	'paid'     => __( 'Ödendi', 'sales-team-commissions-for-woocommerce' ),
	'void'     => __( 'İptal', 'sales-team-commissions-for-woocommerce' ),
);
?>
<div class="eru-stc-account eru-stc-commissions">
	<ul class="eru-stc-filter">
		<?php foreach ( $eru_stc_filters as $eru_stc_k => $eru_stc_l ) : ?>
			<li><a href="<?php echo esc_url( '' === $eru_stc_k ? $eru_stc['base_url'] : add_query_arg( 'durum', $eru_stc_k, $eru_stc['base_url'] ) ); ?>"<?php echo $eru_stc_k === $eru_stc['status'] ? ' aria-current="page" class="is-current"' : ''; ?>><?php echo esc_html( $eru_stc_l ); ?></a></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $eru_stc['rows'] ) : ?>
		<table class="eru-stc-table shop_table shop_table_responsive">
			<thead><tr><th><?php esc_html_e( 'Tarih', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Sipariş', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Seviye', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Matrah', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Komisyon', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Durum', 'sales-team-commissions-for-woocommerce' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $eru_stc['rows'] as $eru_stc_row ) : ?>
				<?php $eru_stc_cur = array( 'currency' => $eru_stc_row->currency ); ?>
				<tr>
					<td data-title="<?php esc_attr_e( 'Tarih', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( get_date_from_gmt( $eru_stc_row->earned_at, get_option( 'date_format' ) ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Sipariş', 'sales-team-commissions-for-woocommerce' ); ?>">#<?php echo esc_html( (string) $eru_stc_row->order_id ); ?><?php echo '' !== $eru_stc_row->entry_key ? ' <small>' . esc_html__( '(düzeltme)', 'sales-team-commissions-for-woocommerce' ) . '</small>' : ''; ?></td>
					<td data-title="<?php esc_attr_e( 'Seviye', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( (string) $eru_stc_row->level ); ?></td>
					<td data-title="<?php esc_attr_e( 'Matrah', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo '' === $eru_stc_row->entry_key ? wp_kses_post( wc_price( (float) $eru_stc_row->basis, $eru_stc_cur ) ) : '—'; ?></td>
					<td data-title="<?php esc_attr_e( 'Komisyon', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo wp_kses_post( wc_price( (float) $eru_stc_row->amount, $eru_stc_cur ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Durum', 'sales-team-commissions-for-woocommerce' ); ?>"><span class="eru-stc-badge eru-stc-badge--<?php echo esc_attr( $eru_stc_row->status ); ?>"><?php echo esc_html( Eru_STC_Labels::commission_status( $eru_stc_row->status ) ); ?></span><?php echo 'void' === $eru_stc_row->status && '' !== $eru_stc_row->void_reason ? ' <small>(' . esc_html( Eru_STC_Labels::void_reason( $eru_stc_row->void_reason ) ) . ')</small>' : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php Eru_STC_Account::pager( '' === $eru_stc['status'] ? $eru_stc['base_url'] : add_query_arg( 'durum', $eru_stc['status'], $eru_stc['base_url'] ), $eru_stc['page'], $eru_stc['pages'] ); ?>
	<?php else : ?>
		<p class="eru-stc-muted"><?php esc_html_e( 'Kayıt yok.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php endif; ?>
</div>
