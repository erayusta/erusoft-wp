<?php
/**
 * The "Kampanyalar" top-level menu (the campaign post type's own menu) and its pages: Tüm kampanyalar,
 * Yeni kampanya (template gallery), Ödüller, Ayarlar, Durum. WordPress's own "add new" entry is replaced
 * by the gallery, which links to an empty campaign too.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use WP_Screen;

final class Menu {

	public const PARENT       = 'edit.php?post_type=eru_ce_campaign';
	public const SETTINGS_CAP = 'manage_eru_ce_settings';
	public const PAGES        = array( 'eru-ce-new', 'eru-ce-rewards', 'eru-ce-settings', 'eru-ce-status' );

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register' ), 20 );
		add_filter( 'submenu_file', array( self::class, 'submenuFile' ), 10, 2 );
	}

	public static function register(): void {
		add_submenu_page( self::PARENT, __( 'Yeni kampanya', 'campaign-engine-for-woocommerce' ), __( 'Yeni kampanya', 'campaign-engine-for-woocommerce' ), PostType::CAP, 'eru-ce-new', array( TemplateGallery::class, 'render' ) );
		add_submenu_page( self::PARENT, __( 'Ödüller', 'campaign-engine-for-woocommerce' ), __( 'Ödüller', 'campaign-engine-for-woocommerce' ), PostType::CAP, 'eru-ce-rewards', array( RewardsScreen::class, 'render' ) );
		add_submenu_page( self::PARENT, __( 'Kampanya ayarları', 'campaign-engine-for-woocommerce' ), __( 'Ayarlar', 'campaign-engine-for-woocommerce' ), self::SETTINGS_CAP, 'eru-ce-settings', array( SettingsScreen::class, 'render' ) );
		add_submenu_page( self::PARENT, __( 'Kampanya motoru durumu', 'campaign-engine-for-woocommerce' ), __( 'Durum', 'campaign-engine-for-woocommerce' ), PostType::CAP, 'eru-ce-status', array( StatusScreen::class, 'render' ) );

		// The gallery replaces WordPress's "add new" entry (it offers an empty campaign too).
		remove_submenu_page( self::PARENT, 'post-new.php?post_type=' . PostType::NAME );
	}

	/** Highlights "Yeni kampanya" while an empty campaign is being created. */
	public static function submenuFile( $submenu_file, $parent_file ) {
		global $pagenow, $typenow;

		if ( 'post-new.php' === $pagenow && PostType::NAME === $typenow ) {
			return 'eru-ce-new';
		}

		return $submenu_file;
	}

	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( self::PARENT ) );
	}

	/** The campaign list and editor, and the plugin's own pages. */
	public static function isPluginScreen( WP_Screen $screen ): bool {
		if ( PostType::NAME === $screen->post_type ) {
			return true;
		}

		foreach ( self::PAGES as $page ) {
			if ( false !== strpos( (string) $screen->id, $page ) ) {
				return true;
			}
		}

		return false;
	}
}
