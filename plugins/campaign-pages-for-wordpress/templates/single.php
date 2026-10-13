<?php
/**
 * Single campaign. Override in a theme: eru-campaigns/single.php.
 *
 * @package campaign-pages-for-wordpress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s = Eru_CP_Settings::get();
Eru_CP_Front::page_start();
while ( have_posts() ) :
	the_post();
	$cid    = get_the_ID();
	$status = Eru_CP_Campaign::status( $cid );
	$dates  = Eru_CP_Campaign::date_label( $cid );
	$cta    = Eru_CP_Campaign::cta_url( $cid );
	?>
<article class="eru-cp eru-cp-single" style="--eru-cp-accent:<?php echo esc_attr( $s['accent'] ); ?>">
	<?php if ( has_post_thumbnail() ) : ?>
		<div class="eru-cp-banner"><?php the_post_thumbnail( 'full' ); ?></div>
	<?php endif; ?>
	<header class="eru-cp-head">
		<h1><?php the_title(); ?></h1>
		<?php if ( '' !== $dates ) : ?>
			<p class="eru-cp-dates"><?php echo esc_html( $dates ); ?></p>
		<?php endif; ?>
	</header>
	<?php if ( 'active' !== $status ) : ?>
		<p class="eru-cp-notice">
			<?php
			echo esc_html(
				'upcoming' === $status
					? __( 'Bu kampanya henüz başlamadı.', 'campaign-pages-for-wordpress' )
					: __( 'Bu kampanya sona erdi.', 'campaign-pages-for-wordpress' )
			);
			?>
		</p>
	<?php endif; ?>
	<div class="eru-cp-content"><?php the_content(); ?></div>
	<?php if ( 'active' === $status && '' !== $cta ) : ?>
		<p class="eru-cp-cta"><a class="eru-cp-button" href="<?php echo esc_url( $cta ); ?>"><?php echo esc_html( Eru_CP_Campaign::cta_text( $cid ) ); ?></a></p>
	<?php endif; ?>
	<p class="eru-cp-back"><a href="<?php echo esc_url( get_post_type_archive_link( Eru_CP_Campaign::TYPE ) ); ?>">&larr; <?php echo esc_html( $s['archive_title'] ); ?></a></p>
</article>
	<?php
endwhile;
Eru_CP_Front::page_end();
