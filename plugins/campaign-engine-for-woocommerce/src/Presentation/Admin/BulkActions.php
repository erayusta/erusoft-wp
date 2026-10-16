<?php
/**
 * Bulk "Duraklat" / "Devam ettir" on the campaign list. The handler checks the capability and the list's
 * nonce itself, then saves each campaign through the campaign writer (so the compiled row, the active
 * count and the page cache follow).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;

final class BulkActions {

	public const PAUSE  = 'eru_ce_pause';
	public const RESUME = 'eru_ce_resume';

	public static function hooks(): void {
		add_filter( 'bulk_actions-edit-' . PostType::NAME, array( self::class, 'actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . PostType::NAME, array( self::class, 'handle' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function actions( $actions ) {
		$actions                 = is_array( $actions ) ? $actions : array();
		$actions[ self::PAUSE ]  = __( 'Duraklat', 'campaign-engine-for-woocommerce' );
		$actions[ self::RESUME ] = __( 'Devam ettir', 'campaign-engine-for-woocommerce' );

		return $actions;
	}

	/**
	 * @param string $sendback redirect URL
	 * @param string $action   chosen bulk action
	 * @param int[]  $ids      post ids
	 */
	public static function handle( $sendback, $action, $ids ): string {
		$sendback = (string) $sendback;

		if ( self::PAUSE !== $action && self::RESUME !== $action ) {
			return $sendback;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! current_user_can( PostType::CAP ) || ! wp_verify_nonce( $nonce, 'bulk-posts' ) ) {
			return add_query_arg( 'eru_ce_bulk_refused', 1, remove_query_arg( array( 'eru_ce_paused', 'eru_ce_resumed' ), $sendback ) );
		}

		$pause = self::PAUSE === $action;
		$done  = 0;

		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			if ( PostType::NAME !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$def = get_post_meta( $id, CampaignWriter::META, true );

			if ( ! is_array( $def ) ) {
				continue;
			}

			$def['paused'] = $pause;

			if ( ! is_wp_error( CampaignWriter::update( $id, $def ) ) ) {
				++$done;
			}
		}

		return add_query_arg( $pause ? 'eru_ce_paused' : 'eru_ce_resumed', $done, remove_query_arg( array( 'eru_ce_paused', 'eru_ce_resumed', 'eru_ce_bulk_refused' ), $sendback ) );
	}

	public static function notice(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'edit-' . PostType::NAME !== $screen->id ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['eru_ce_bulk_refused'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Toplu işlem yapılmadı: yetkiniz yok ya da oturum süresi doldu.', 'campaign-engine-for-woocommerce' ) . '</p></div>';
		}

		foreach ( array( 'eru_ce_paused' => __( '%d kampanya duraklatıldı.', 'campaign-engine-for-woocommerce' ), 'eru_ce_resumed' => __( '%d kampanya yeniden çalışıyor.', 'campaign-engine-for-woocommerce' ) ) as $key => $text ) {
			if ( isset( $_GET[ $key ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $text, absint( $_GET[ $key ] ) ) ) . '</p></div>';
			}
		}
		// phpcs:enable
	}
}
