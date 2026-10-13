<?php
/**
 * Campaign archive. Override in a theme: eru-campaigns/archive.php.
 *
 * @package campaign-pages-for-wordpress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s = Eru_CP_Settings::get();
Eru_CP_Front::page_start();
?>
<div class="eru-cp eru-cp-archive" style="--eru-cp-accent:<?php echo esc_attr( $s['accent'] ); ?>">
	<header class="eru-cp-head">
		<h1><?php echo esc_html( $s['archive_title'] ); ?></h1>
		<?php if ( '' !== $s['archive_intro'] ) : ?>
			<p><?php echo esc_html( $s['archive_intro'] ); ?></p>
		<?php endif; ?>
	</header>
	<?php
	$active = Eru_CP_Campaign::query( 'active' );
	if ( $active ) {
		echo Eru_CP_Front::grid( $active, (int) $s['columns'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the card renderer.
	} else {
		echo '<p class="eru-cp-empty">' . esc_html__( 'Şu anda aktif kampanya bulunmuyor.', 'campaign-pages-for-wordpress' ) . '</p>';
	}
	if ( ! empty( $s['show_upcoming'] ) ) {
		$up = Eru_CP_Campaign::query( 'upcoming' );
		if ( $up ) {
			echo '<h2 class="eru-cp-sub">' . esc_html__( 'Yaklaşan kampanyalar', 'campaign-pages-for-wordpress' ) . '</h2>';
			echo Eru_CP_Front::grid( $up, (int) $s['columns'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	if ( ! empty( $s['show_ended'] ) ) {
		$ended = Eru_CP_Campaign::query( 'ended' );
		if ( $ended ) {
			echo '<h2 class="eru-cp-sub">' . esc_html__( 'Sona eren kampanyalar', 'campaign-pages-for-wordpress' ) . '</h2>';
			echo '<div class="eru-cp-ended">' . Eru_CP_Front::grid( $ended, (int) $s['columns'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	?>
</div>
<?php
Eru_CP_Front::page_end();
