<?php
/**
 * Wires the admin screens, the shop-front modules, the clock route, Hesabım → Ödüllerim, the campaign
 * pages and WP-CLI. Called from Plugin::boot() (WooCommerce active).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation;

use Erusoft\CampaignEngine\Cli\Commands;
use Erusoft\CampaignEngine\Presentation\Account\RewardsEndpoint;
use Erusoft\CampaignEngine\Presentation\Admin\BulkActions;
use Erusoft\CampaignEngine\Presentation\Admin\CampaignScreen;
use Erusoft\CampaignEngine\Presentation\Admin\ConflictNotice;
use Erusoft\CampaignEngine\Presentation\Admin\Menu;
use Erusoft\CampaignEngine\Presentation\Admin\PreviewController;
use Erusoft\CampaignEngine\Presentation\Admin\ScreenCleanup;
use Erusoft\CampaignEngine\Presentation\Admin\SettingsScreen;
use Erusoft\CampaignEngine\Presentation\Admin\TemplateGallery;
use Erusoft\CampaignEngine\Presentation\Rest\ClockController;
use Erusoft\CampaignEngine\Presentation\Storefront\CampaignPages;
use Erusoft\CampaignEngine\Presentation\Storefront\Rewrite;
use Erusoft\CampaignEngine\Presentation\Storefront\Storefront;

final class Presentation {

	public static function hooks(): void {
		CampaignScreen::hooks();

		if ( is_admin() ) {
			Menu::hooks();
			BulkActions::hooks();
			PreviewController::hooks();
			TemplateGallery::hooks();
			SettingsScreen::hooks();
			ConflictNotice::hooks();
			ScreenCleanup::hooks();
		}

		ClockController::hooks();
		Storefront::hooks();
		CampaignPages::hooks();
		RewardsEndpoint::hooks();
		Rewrite::hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}
	}
}
