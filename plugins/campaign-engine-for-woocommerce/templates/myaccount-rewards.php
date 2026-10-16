<?php
/**
 * Hesabım → Ödüllerim. Override: campaign-engine-for-woocommerce/myaccount-rewards.php in the theme.
 *
 * Variables: $rewards (rows of the logged-in customer, newest first).
 *
 * @package Erusoft\CampaignEngine
 */

use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Presentation\Texts;

defined( 'ABSPATH' ) || exit;

$eru_ce_labels = array(
	RewardRepository::PENDING   => __( 'Hazırlanıyor', 'campaign-engine-for-woocommerce' ),
	RewardRepository::AVAILABLE => __( 'Kullanılabilir', 'campaign-engine-for-woocommerce' ),
	RewardRepository::USED      => __( 'Kullanıldı', 'campaign-engine-for-woocommerce' ),
	RewardRepository::EXPIRED   => __( 'Süresi doldu', 'campaign-engine-for-woocommerce' ),
	RewardRepository::REVERSED  => __( 'İptal edildi', 'campaign-engine-for-woocommerce' ),
);
?>
<div class="eru-ce-rewards">
	<p><?php esc_html_e( 'Harca kazan kampanyalarından kazandığınız kuponlar. Kupon, siparişiniz ödendikten sonra hazırlanır ve e-postanıza da gönderilir.', 'campaign-engine-for-woocommerce' ); ?></p>
	<?php if ( empty( $rewards ) ) : ?>
		<p class="woocommerce-info"><?php esc_html_e( 'Henüz ödülünüz yok.', 'campaign-engine-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="shop_table shop_table_responsive eru-ce-rewards-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ödül', 'campaign-engine-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Durum', 'campaign-engine-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Kupon kodu', 'campaign-engine-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Tarih', 'campaign-engine-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rewards as $eru_ce_row ) : ?>
					<?php
					$eru_ce_status = (string) $eru_ce_row['status'];
					$eru_ce_when   = RewardRepository::AVAILABLE === $eru_ce_status ? Clock::ts( $eru_ce_row['expires_at'] ?? null ) : Clock::ts( $eru_ce_row['available_at'] ?? null );
					$eru_ce_order  = wc_get_order( (int) $eru_ce_row['source_order_id'] );
					?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Ödül', 'campaign-engine-for-woocommerce' ); ?>">
							<?php echo esc_html( Texts::money( (int) $eru_ce_row['amount_minor'] ) ); ?>
							<?php if ( $eru_ce_order && (int) $eru_ce_order->get_customer_id() === get_current_user_id() ) : ?>
								<br><small>
								<?php
								/* translators: %s: order number */
								echo esc_html( sprintf( __( 'Sipariş #%s', 'campaign-engine-for-woocommerce' ), $eru_ce_order->get_order_number() ) );
								?>
								</small>
							<?php endif; ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Durum', 'campaign-engine-for-woocommerce' ); ?>"><?php echo esc_html( $eru_ce_labels[ $eru_ce_status ] ?? $eru_ce_status ); ?></td>
						<td data-title="<?php esc_attr_e( 'Kupon kodu', 'campaign-engine-for-woocommerce' ); ?>">
							<?php if ( RewardRepository::AVAILABLE === $eru_ce_status && '' !== (string) $eru_ce_row['code'] ) : ?>
								<code class="eru-ce-code"><?php echo esc_html( (string) $eru_ce_row['code'] ); ?></code>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Tarih', 'campaign-engine-for-woocommerce' ); ?>">
							<?php
							if ( null === $eru_ce_when ) {
								echo '—';
							} elseif ( RewardRepository::AVAILABLE === $eru_ce_status ) {
								/* translators: %s: date */
								echo esc_html( sprintf( __( 'Son kullanım: %s', 'campaign-engine-for-woocommerce' ), Texts::dateTime( $eru_ce_when ) ) );
							} elseif ( RewardRepository::PENDING === $eru_ce_status ) {
								/* translators: %s: date */
								echo esc_html( sprintf( __( 'Hazır olacağı tarih: %s', 'campaign-engine-for-woocommerce' ), Texts::dateTime( $eru_ce_when ) ) );
							} else {
								echo esc_html( Texts::dateTime( $eru_ce_when ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
