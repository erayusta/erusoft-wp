<?php
/**
 * Kampanyalar → Durum: the Doctor report (the same checks as `wp eru-ce doctor`) and the stored data
 * the engine skipped.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use Erusoft\CampaignEngine\Infrastructure\ConfigErrors;

final class StatusScreen {

	public static function render(): void {
		if ( ! current_user_can( PostType::CAP ) ) {
			wp_die( esc_html__( 'Bu sayfayı görme yetkiniz yok.', 'campaign-engine-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap eru-ce-page"><h1>' . esc_html__( 'Kampanya motoru durumu', 'campaign-engine-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Aynı denetim komut satırında: wp eru-ce doctor', 'campaign-engine-for-woocommerce' ) . '</p>';
		echo '<table class="widefat striped eru-ce-status"><thead><tr><th>' . esc_html__( 'Denetim', 'campaign-engine-for-woocommerce' ) . '</th><th>' . esc_html__( 'Sonuç', 'campaign-engine-for-woocommerce' ) . '</th><th>' . esc_html__( 'Ayrıntı', 'campaign-engine-for-woocommerce' ) . '</th></tr></thead><tbody>';

		foreach ( Doctor::report() as $row ) {
			echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td><span class="eru-ce-state eru-ce-state--' . esc_attr( $row['status'] ) . '">' . esc_html( Doctor::statusLabel( $row['status'] ) ) . '</span></td><td>' . esc_html( $row['detail'] ) . '</td></tr>';
		}

		echo '</tbody></table>';

		$errors = ConfigErrors::all();

		if ( array() !== $errors ) {
			echo '<h2>' . esc_html__( 'Okunamayan kayıtlar', 'campaign-engine-for-woocommerce' ) . '</h2><ul class="ul-disc">';

			foreach ( $errors as $entry ) {
				$what = 'settings' === $entry['type']
					? __( 'Ayarlar', 'campaign-engine-for-woocommerce' )
					/* translators: %d: campaign id */
					: sprintf( __( 'Kampanya #%d', 'campaign-engine-for-woocommerce' ), (int) $entry['id'] );
				echo '<li>' . esc_html( $what . ' — ' . TraceText::error( WizardForm::formField( (string) $entry['field'] ), (string) $entry['reason'] ) ) . '</li>';
			}

			echo '</ul>';
		}

		echo '</div>';
	}
}
