<?php
/**
 * My Account: agent panel (balance, referral link, customer invitation, recent commissions).
 *
 * Override in a theme: sales-team-commissions-for-woocommerce/myaccount/panel.php.
 *
 * @package sales-team-commissions-for-woocommerce
 * @var array $eru_stc agent, label, balance, currency, referral_url, customers, team, invites,
 *                     invite_nonce, recent, hold_days, urls.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$eru_stc_b = $eru_stc['balance'];
$eru_stc_c = array( 'currency' => $eru_stc['currency'] );
?>
<div class="eru-stc-account eru-stc-panel">
	<p class="eru-stc-hello">
		<?php
		/* translators: %s: agent name */
		printf( esc_html__( 'Merhaba %s. Davet kodunuz:', 'sales-team-commissions-for-woocommerce' ), esc_html( $eru_stc['label'] ) );
		?>
		<code class="eru-stc-code"><?php echo esc_html( $eru_stc['agent']->referral_code ); ?></code>
	</p>

	<ul class="eru-stc-tiles">
		<li class="eru-stc-tile"><span class="eru-stc-tile__label"><?php esc_html_e( 'Beklemede', 'sales-team-commissions-for-woocommerce' ); ?></span><strong class="eru-stc-tile__value"><?php echo wp_kses_post( wc_price( (float) $eru_stc_b['pending'], $eru_stc_c ) ); ?></strong></li>
		<li class="eru-stc-tile eru-stc-tile--accent"><span class="eru-stc-tile__label"><?php esc_html_e( 'Ödenebilir', 'sales-team-commissions-for-woocommerce' ); ?></span><strong class="eru-stc-tile__value"><?php echo wp_kses_post( wc_price( (float) $eru_stc_b['available'], $eru_stc_c ) ); ?></strong></li>
		<li class="eru-stc-tile"><span class="eru-stc-tile__label"><?php esc_html_e( 'Talep edildi', 'sales-team-commissions-for-woocommerce' ); ?></span><strong class="eru-stc-tile__value"><?php echo wp_kses_post( wc_price( (float) $eru_stc_b['requested'], $eru_stc_c ) ); ?></strong></li>
		<li class="eru-stc-tile"><span class="eru-stc-tile__label"><?php esc_html_e( 'Ödendi', 'sales-team-commissions-for-woocommerce' ); ?></span><strong class="eru-stc-tile__value"><?php echo wp_kses_post( wc_price( (float) $eru_stc_b['paid'], $eru_stc_c ) ); ?></strong></li>
	</ul>
	<p class="eru-stc-muted">
		<?php
		/* translators: %d: days */
		printf( esc_html( _n( 'Komisyonlar siparişten sonra %d gün bekler, sonra ödenebilir olur.', 'Komisyonlar siparişten sonra %d gün bekler, sonra ödenebilir olur.', max( 1, $eru_stc['hold_days'] ), 'sales-team-commissions-for-woocommerce' ) ), (int) $eru_stc['hold_days'] );
		?>
		<a href="<?php echo esc_url( $eru_stc['urls']['payouts'] ); ?>"><?php esc_html_e( 'Ödeme talebi', 'sales-team-commissions-for-woocommerce' ); ?></a>
	</p>

	<h3><?php esc_html_e( 'Davet linkiniz', 'sales-team-commissions-for-woocommerce' ); ?></h3>
	<div class="eru-stc-copy">
		<input type="text" readonly value="<?php echo esc_attr( $eru_stc['referral_url'] ); ?>" id="eru-stc-ref-url" aria-label="<?php esc_attr_e( 'Davet linkiniz', 'sales-team-commissions-for-woocommerce' ); ?>">
		<button type="button" class="button" data-eru-stc-copy="eru-stc-ref-url" data-done="<?php esc_attr_e( 'Kopyalandı', 'sales-team-commissions-for-woocommerce' ); ?>"><?php esc_html_e( 'Kopyala', 'sales-team-commissions-for-woocommerce' ); ?></button>
	</div>
	<p class="eru-stc-muted">
		<?php
		printf(
			/* translators: 1: customer count, 2: team size */
			esc_html__( 'Müşteri: %1$d · Ekip: %2$d', 'sales-team-commissions-for-woocommerce' ),
			(int) $eru_stc['customers'],
			(int) $eru_stc['team']
		);
		?>
		· <a href="<?php echo esc_url( $eru_stc['urls']['team'] ); ?>"><?php esc_html_e( 'Ekibim', 'sales-team-commissions-for-woocommerce' ); ?></a>
	</p>

	<?php if ( $eru_stc['invites'] ) : ?>
		<h3><?php esc_html_e( 'Müşteri davet et', 'sales-team-commissions-for-woocommerce' ); ?></h3>
		<p class="eru-stc-muted"><?php esc_html_e( 'Kayıtlı bir müşteriye onay bağlantısı gönderilir; müşteri onaylayınca listenize eklenir. Yeni müşteriler için davet linkinizi paylaşın.', 'sales-team-commissions-for-woocommerce' ); ?></p>
		<form method="post" action="<?php echo esc_url( $eru_stc['urls']['panel'] ); ?>" class="eru-stc-form eru-stc-inline">
			<input type="hidden" name="eru_stc_action" value="invite">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $eru_stc['invite_nonce'] ); ?>">
			<label class="screen-reader-text" for="eru-stc-invite-email"><?php esc_html_e( 'Müşterinin e-posta adresi', 'sales-team-commissions-for-woocommerce' ); ?></label>
			<input type="email" name="eru_stc_email" id="eru-stc-invite-email" required placeholder="<?php esc_attr_e( 'ornek@example.com', 'sales-team-commissions-for-woocommerce' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Davet gönder', 'sales-team-commissions-for-woocommerce' ); ?></button>
		</form>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Son komisyonlar', 'sales-team-commissions-for-woocommerce' ); ?></h3>
	<?php if ( $eru_stc['recent'] ) : ?>
		<table class="eru-stc-table shop_table shop_table_responsive">
			<thead><tr><th><?php esc_html_e( 'Tarih', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Sipariş', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Seviye', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Komisyon', 'sales-team-commissions-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Durum', 'sales-team-commissions-for-woocommerce' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $eru_stc['recent'] as $eru_stc_row ) : ?>
				<tr>
					<td data-title="<?php esc_attr_e( 'Tarih', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( get_date_from_gmt( $eru_stc_row->earned_at, get_option( 'date_format' ) ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Sipariş', 'sales-team-commissions-for-woocommerce' ); ?>">#<?php echo esc_html( (string) $eru_stc_row->order_id ); ?></td>
					<td data-title="<?php esc_attr_e( 'Seviye', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo esc_html( (string) $eru_stc_row->level ); ?></td>
					<td data-title="<?php esc_attr_e( 'Komisyon', 'sales-team-commissions-for-woocommerce' ); ?>"><?php echo wp_kses_post( wc_price( (float) $eru_stc_row->amount, array( 'currency' => $eru_stc_row->currency ) ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Durum', 'sales-team-commissions-for-woocommerce' ); ?>"><span class="eru-stc-badge eru-stc-badge--<?php echo esc_attr( $eru_stc_row->status ); ?>"><?php echo esc_html( Eru_STC_Labels::commission_status( $eru_stc_row->status ) ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><a href="<?php echo esc_url( $eru_stc['urls']['commissions'] ); ?>"><?php esc_html_e( 'Tüm komisyonlar', 'sales-team-commissions-for-woocommerce' ); ?></a></p>
	<?php else : ?>
		<p class="eru-stc-muted"><?php esc_html_e( 'Henüz komisyon yok.', 'sales-team-commissions-for-woocommerce' ); ?></p>
	<?php endif; ?>
</div>
