<?php
/**
 * My Account: the agent's customers and downline.
 *
 * @package sales-team-commissions-for-woocommerce
 * @var array $eru_stc customers, total, page, pages, team, max, base_url.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="eru-stc-account eru-stc-team">
	<h3>
		<?php
		/* translators: %d: customer count */
		printf( esc_html__( 'Müşterilerim (%d)', 'sales-team-commissions-for-woocommerce' ), (int) $eru_stc['total'] );
		?>
	</h3>
	<?php if ( $eru_stc['customers'] ) : ?>
		<table class="eru-stc-table shop_table shop_table_responsive">
			<thead><tr><th><?php esc_html_e( 'Müşteri', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'E-posta', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Bağlantı', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Tarih', 'sales-team-commissions-for-woocommerce' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $eru_stc['customers'] as $eru_stc_c ) : ?>
				<tr>
					<td data-title="<?php esc_attr_e( 'Müşteri', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( '' !== $eru_stc_c['name'] ? $eru_stc_c['name'] : '—' ); ?></td>
					<td data-title="<?php esc_attr_e( 'E-posta', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( $eru_stc_c['email'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Bağlantı', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( $eru_stc_c['type'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Tarih', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( $eru_stc_c['since'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php Eru_STC_Account::pager( $eru_stc['base_url'], $eru_stc['page'], $eru_stc['pages'] ); ?>
	<?php else : ?>
		<p class="eru-stc-muted"><?php esc_html_e( 'Henüz size bağlı müşteri yok. Davet linkinizi paylaşın.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Alt temsilcilerim', 'sales-team-commissions-for-woocommerce' ); ?></h3>
	<?php if ( $eru_stc['team'] ) : ?>
		<table class="eru-stc-table shop_table shop_table_responsive">
			<thead><tr><th><?php esc_html_e( 'Temsilci', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Kademe', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Durum', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Müşteri', 'sales-team-commissions-for-woocommerce' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $eru_stc['team'] as $eru_stc_t ) : ?>
				<tr>
					<td data-title="<?php esc_attr_e( 'Temsilci', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( $eru_stc_t['name'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Kademe', 'sales-team-commissions-for-woocommerce' ); ?>">
						<?php
						echo esc_html( (string) $eru_stc_t['depth'] );
						if ( ! $eru_stc_t['earns'] ) {
							echo ' <small>(' . esc_html__( 'seviye sınırının dışında', 'sales-team-commissions-for-woocommerce' ) . ')</small>';
						}
						?>
					</td>
					<td data-title="<?php esc_attr_e( 'Durum', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( $eru_stc_t['status'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Müşteri', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( (string) $eru_stc_t['customers'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="eru-stc-muted">
			<?php
			/* translators: %d: level cap */
			printf( esc_html__( 'Bir satıştan en fazla %d kademe komisyon alır; 1. kademe müşterinin temsilcisidir.', 'sales-team-commissions-for-woocommerce' ), (int) $eru_stc['max'] );
			?>
		</p>
	<?php else : ?>
		<p class="eru-stc-muted"><?php esc_html_e( 'Size bağlı alt temsilci yok.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php endif; ?>
</div>
