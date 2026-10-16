<?php
/**
 * Kampanyalar → Ödüller: a read-only list of spend-and-earn rewards, newest first, filterable by status.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Presentation\Texts;

final class RewardsScreen {

	private const PER_PAGE = 30;

	/** @return array<string,string> */
	public static function statuses(): array {
		return array(
			RewardRepository::PENDING   => __( 'Bekliyor', 'campaign-engine-for-woocommerce' ),
			RewardRepository::AVAILABLE => __( 'Kullanılabilir', 'campaign-engine-for-woocommerce' ),
			RewardRepository::USED      => __( 'Kullanıldı', 'campaign-engine-for-woocommerce' ),
			RewardRepository::EXPIRED   => __( 'Süresi doldu', 'campaign-engine-for-woocommerce' ),
			RewardRepository::REVERSED  => __( 'İptal edildi', 'campaign-engine-for-woocommerce' ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( PostType::CAP ) ) {
			wp_die( esc_html__( 'Bu sayfayı görme yetkiniz yok.', 'campaign-engine-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$labels = self::statuses();
		$status = isset( $labels[ $status ] ) ? $status : '';
		$page   = RewardRepository::page( $status, self::PER_PAGE, $paged );

		echo '<div class="wrap eru-ce-page"><h1>' . esc_html__( 'Ödüller', 'campaign-engine-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Harca kazan kampanyalarının ödülleri. Ödül, sipariş ödendikten ve bekleme süresi geçtikten sonra kupona dönüşür ve müşteriye e-postayla gönderilir; iade ve iptallerde ayarlara göre azalır ya da iptal edilir.', 'campaign-engine-for-woocommerce' ) . '</p>';
		echo '<ul class="subsubsub">';
		echo '<li><a href="' . esc_url( Menu::url( 'eru-ce-rewards' ) ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'Tümü', 'campaign-engine-for-woocommerce' ) . '</a> | </li>';

		foreach ( $labels as $key => $label ) {
			echo '<li><a href="' . esc_url( Menu::url( 'eru-ce-rewards', array( 'status' => $key ) ) ) . '"' . ( $key === $status ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a> </li>';
		}

		echo '</ul><table class="widefat striped"><thead><tr>';

		foreach ( array( '#', __( 'Müşteri', 'campaign-engine-for-woocommerce' ), __( 'Kampanya', 'campaign-engine-for-woocommerce' ), __( 'Tutar', 'campaign-engine-for-woocommerce' ), __( 'Durum', 'campaign-engine-for-woocommerce' ), __( 'Kupon', 'campaign-engine-for-woocommerce' ), __( 'Kaynak sipariş', 'campaign-engine-for-woocommerce' ), __( 'Tarih', 'campaign-engine-for-woocommerce' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		if ( array() === $page['rows'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Henüz ödül yok.', 'campaign-engine-for-woocommerce' ) . '</td></tr>';
		}

		foreach ( $page['rows'] as $row ) {
			$order = wc_get_order( (int) $row['source_order_id'] );
			$when  = Clock::ts( 'available' === $row['status'] ? ( $row['expires_at'] ?? null ) : ( $row['available_at'] ?? null ) );
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) $row['id'] ) . '</td>';
			echo '<td>' . esc_html( '' === (string) $row['email'] ? __( '(silindi)', 'campaign-engine-for-woocommerce' ) : (string) $row['email'] ) . '</td>';
			echo '<td>' . esc_html( get_the_title( (int) $row['campaign_id'] ) ? get_the_title( (int) $row['campaign_id'] ) : '#' . (int) $row['campaign_id'] ) . '</td>';
			echo '<td>' . esc_html( Texts::money( (int) $row['amount_minor'] ) ) . '</td>';
			echo '<td>' . esc_html( $labels[ $row['status'] ] ?? (string) $row['status'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['code'] ) . '</td>';
			echo '<td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : esc_html( '#' . (int) $row['source_order_id'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $when ? '—' : Texts::dateTime( $when ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $page['total'] / self::PER_PAGE );

		if ( $pages > 1 ) {
			echo '<p class="tablenav-pages">';
			for ( $i = 1; $i <= min( $pages, 50 ); $i++ ) {
				echo $i === $paged ? '<strong>' . esc_html( (string) $i ) . '</strong> ' : '<a href="' . esc_url( Menu::url( 'eru-ce-rewards', array( 'status' => $status, 'paged' => $i ) ) ) . '">' . esc_html( (string) $i ) . '</a> ';
			}
			echo '</p>';
		}

		echo '</div>';
	}
}
