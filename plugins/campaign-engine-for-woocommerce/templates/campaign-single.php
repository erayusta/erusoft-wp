<?php
/**
 * Campaign detail page. Override: campaign-engine-for-woocommerce/campaign-single.php in the theme.
 *
 * @package Erusoft\CampaignEngine
 */

use Erusoft\CampaignEngine\Presentation\Storefront\CampaignPages;
use Erusoft\CampaignEngine\Presentation\Storefront\Storefront;
use Erusoft\CampaignEngine\Presentation\Texts;

defined( 'ABSPATH' ) || exit;

$eru_ce_page     = CampaignPages::current();
$eru_ce_campaign = $eru_ce_page['campaign'];
$eru_ce_end      = $eru_ce_campaign->schedule->currentEnd( \Erusoft\CampaignEngine\Infrastructure\Clock::now() );
$eru_ce_products = CampaignPages::products( $eru_ce_campaign );

CampaignPages::header();
?>
<main class="eru-ce-pages wp-block-group has-global-padding is-layout-constrained">
	<p class="eru-ce-back"><a href="<?php echo esc_url( CampaignPages::url() ); ?>"><?php esc_html_e( '← Bütün kampanyalar', 'campaign-engine-for-woocommerce' ); ?></a></p>
	<h1 class="eru-ce-pages-title"><?php echo esc_html( $eru_ce_campaign->label ); ?></h1>
	<p class="eru-ce-card-gives"><?php echo esc_html( Texts::badge( $eru_ce_campaign ) ); ?></p>
	<?php if ( ! empty( $eru_ce_campaign->meta['description'] ) ) : ?>
		<?php echo wp_kses_post( wpautop( esc_html( (string) $eru_ce_campaign->meta['description'] ) ) ); ?>
	<?php endif; ?>
	<?php if ( null !== $eru_ce_end ) : ?>
		<p><?php echo Storefront::countdown( $eru_ce_campaign->id, $eru_ce_end->getTimestamp() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in countdown(). ?></p>
	<?php endif; ?>
	<?php if ( '' !== $eru_ce_products ) : ?>
		<div class="eru-ce-products"><?php echo $eru_ce_products; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce shortcode output. ?></div>
	<?php else : ?>
		<p><a class="wp-element-button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Mağazaya git', 'campaign-engine-for-woocommerce' ); ?></a></p>
	<?php endif; ?>
</main>
<?php
CampaignPages::footer();
