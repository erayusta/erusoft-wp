<?php
/**
 * Campaign archive page. Override: campaign-engine-for-woocommerce/campaign-archive.php in the theme.
 *
 * @package Erusoft\CampaignEngine
 */

use Erusoft\CampaignEngine\Presentation\Storefront\CampaignPages;
use Erusoft\CampaignEngine\Presentation\Storefront\Storefront;
use Erusoft\CampaignEngine\Presentation\Texts;

defined( 'ABSPATH' ) || exit;

$eru_ce_page      = CampaignPages::current();
$eru_ce_campaigns = (array) ( $eru_ce_page['campaigns'] ?? array() );
$eru_ce_now       = \Erusoft\CampaignEngine\Infrastructure\Clock::now();

CampaignPages::header();
?>
<main class="eru-ce-pages wp-block-group has-global-padding is-layout-constrained">
	<h1 class="eru-ce-pages-title"><?php esc_html_e( 'Kampanyalar', 'campaign-engine-for-woocommerce' ); ?></h1>
	<?php if ( array() === $eru_ce_campaigns ) : ?>
		<p><?php esc_html_e( 'Şu an yayında olan kampanya yok.', 'campaign-engine-for-woocommerce' ); ?></p>
	<?php else : ?>
		<ul class="eru-ce-cards">
			<?php foreach ( $eru_ce_campaigns as $eru_ce_campaign ) : ?>
				<?php $eru_ce_end = $eru_ce_campaign->schedule->currentEnd( $eru_ce_now ); ?>
				<li class="eru-ce-card">
					<h2><a href="<?php echo esc_url( CampaignPages::url( $eru_ce_campaign ) ); ?>"><?php echo esc_html( $eru_ce_campaign->label ); ?></a></h2>
					<p class="eru-ce-card-gives"><?php echo esc_html( Texts::badge( $eru_ce_campaign ) ); ?></p>
					<?php if ( ! empty( $eru_ce_campaign->meta['description'] ) ) : ?>
						<p><?php echo esc_html( wp_trim_words( (string) $eru_ce_campaign->meta['description'], 30 ) ); ?></p>
					<?php endif; ?>
					<?php if ( null !== $eru_ce_end ) : ?>
						<p><?php echo Storefront::countdown( $eru_ce_campaign->id, $eru_ce_end->getTimestamp() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in countdown(). ?></p>
					<?php endif; ?>
					<p><a class="wp-element-button" href="<?php echo esc_url( CampaignPages::url( $eru_ce_campaign ) ); ?>"><?php esc_html_e( 'Kampanyayı incele', 'campaign-engine-for-woocommerce' ); ?></a></p>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</main>
<?php
CampaignPages::footer();
