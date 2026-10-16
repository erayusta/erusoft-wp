<?php
/**
 * "Yeni kampanya": the template gallery. Choosing a template posts a form (capability + nonce) that
 * creates a draft campaign from the template's definition and opens it in the wizard at the step
 * where the template needs attention. "Boş kampanya" opens an empty wizard.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use Erusoft\CampaignEngine\Presentation\Texts;
use Erusoft\CampaignEngine\Templates\Definitions;
use WP_Error;

final class TemplateGallery {

	public const ACTION = 'eru_ce_template';
	public const NONCE  = 'eru_ce_template';

	public static function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( PostType::CAP ) ) {
			wp_die( esc_html__( 'Bu sayfayı görme yetkiniz yok.', 'campaign-engine-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap eru-ce-page"><h1>' . esc_html__( 'Yeni kampanya', 'campaign-engine-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Bir şablon seçin: kampanya taslak olarak oluşturulur ve sihirbazda açılır. Örnek değerleri, hedef ürünleri ve tarihleri kendinize göre değiştirip yayınlayın.', 'campaign-engine-for-woocommerce' ) . '</p>';
		echo '<div class="eru-ce-templates">';

		foreach ( Definitions::all() as $id => $template ) {
			if ( ! is_array( $template ) || is_wp_error( CampaignWriter::validate( (array) ( $template['definition'] ?? array() ) ) ) ) {
				continue;
			}

			$preview = Campaign::fromArray( array_merge( (array) $template['definition'], array( 'id' => 1 ) ) );

			echo '<form class="eru-ce-template" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<h2>' . esc_html( (string) $template['title'] ) . '</h2>';
			echo '<p class="eru-ce-template-gives">' . esc_html( Texts::badge( $preview ) ) . '</p>';
			echo '<p>' . esc_html( (string) $template['summary'] ) . '</p>';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			echo '<input type="hidden" name="template" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( self::NONCE );
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Bu şablonla başla', 'campaign-engine-for-woocommerce' ) . '</button></p>';
			echo '</form>';
		}

		echo '<div class="eru-ce-template eru-ce-template--blank"><h2>' . esc_html__( 'Boş kampanya', 'campaign-engine-for-woocommerce' ) . '</h2><p>' . esc_html__( 'Bütün adımları kendiniz doldurun.', 'campaign-engine-for-woocommerce' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . PostType::NAME ) ) . '">' . esc_html__( 'Boş kampanya oluştur', 'campaign-engine-for-woocommerce' ) . '</a></p></div>';
		echo '</div></div>';
	}

	/** admin-post handler. */
	public static function handle(): void {
		$nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$template = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : '';
		$id       = self::createFrom( $template, $nonce );

		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ), '', array( 'response' => 403 ) );
		}

		$step = (int) ( Definitions::get( $template )['step'] ?? 2 );
		wp_safe_redirect( add_query_arg( array( 'eru_ce_step' => $step ), get_edit_post_link( $id, 'url' ) ) );
		exit;
	}

	/**
	 * Creates a draft campaign from a template.
	 *
	 * @return int|WP_Error post id
	 */
	public static function createFrom( string $template, string $nonce ) {
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( PostType::CAP ) ) {
			return new WP_Error( 'eru_ce_forbidden', __( 'Bu işlem için yetkiniz yok ya da oturum süresi doldu.', 'campaign-engine-for-woocommerce' ) );
		}

		$found = Definitions::get( $template );

		if ( null === $found ) {
			return new WP_Error( 'eru_ce_not_found', __( 'Şablon bulunamadı.', 'campaign-engine-for-woocommerce' ) );
		}

		return CampaignWriter::create( (array) $found['definition'], (string) $found['title'], 'draft' );
	}
}
