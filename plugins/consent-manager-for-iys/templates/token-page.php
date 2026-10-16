<?php
/**
 * Body of the signed link pages (opt-out and confirmation), shown through wp_die().
 *
 * The caller passes $eru_cmi_body, already escaped, and filters the result with Eru_CMI_Links::kses().
 *
 * @package consent-manager-for-iys
 * @var string $eru_cmi_body Escaped HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="eru-cmi-link-page">
<h1><?php esc_html_e( 'İletişim izinleri', 'consent-manager-for-iys' ); ?></h1>
<?php echo $eru_cmi_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts; filtered by the caller. ?>
<p class="eru-cmi-site"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></p>
</div>
