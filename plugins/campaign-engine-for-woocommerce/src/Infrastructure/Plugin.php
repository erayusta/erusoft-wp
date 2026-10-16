<?php
/**
 * Wires the WooCommerce layer and the admin and shop-front screens (on `eru_ce_loaded`, i.e. WooCommerce
 * active) and handles activation.
 *
 * Inert default: with no active campaign the cart pass returns at once, the price filters find nothing
 * to change, no Action Scheduler job exists and nothing is sent anywhere. Jobs appear only when a
 * reservation or a reward does.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure;

use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use Erusoft\CampaignEngine\Infrastructure\Database\Schema;
use Erusoft\CampaignEngine\Infrastructure\Privacy\Privacy;
use Erusoft\CampaignEngine\Infrastructure\Reward\RewardService;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CartApplier;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CheckoutGuard;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\OrderEvents;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\OrderWriter;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\PaymentBinding;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\ReservationGate;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\ShippingApplier;
use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;
use Erusoft\CampaignEngine\Presentation\Presentation;

final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		Schema::maybeUpgrade();

		PostType::hooks();
		CampaignWriter::hooks();
		ConfigErrors::hooks();
		CartApplier::hooks();
		ShippingApplier::hooks();
		CheckoutGuard::hooks();
		OrderWriter::hooks();
		ReservationGate::hooks();
		PaymentBinding::hooks();
		OrderEvents::hooks();
		RewardService::hooks();
		Jobs::hooks();
		Privacy::hooks();
		Presentation::hooks();
	}

	/** Tables, and open work re-armed after a deactivation. */
	public static function activate(): void {
		Schema::install();
		StorefrontOptions::ensure();

		// Campaign pages or the account endpoint left on before a deactivation need their rules again.
		if ( StorefrontOptions::on( 'pages' ) || StorefrontOptions::on( 'account' ) ) {
			StorefrontOptions::requestFlush();
		}

		if ( Jobs::available() && Jobs::workRemains() ) {
			Jobs::ensureDailySweep();
			RewardService::rearm();
		}
	}

	/** Every job of the plugin is removed; data stays. The campaign page rules go with the plugin. */
	public static function deactivate(): void {
		Jobs::clearAll();

		if ( StorefrontOptions::on( 'pages' ) || StorefrontOptions::on( 'account' ) ) {
			// Rebuilt on the next request, without this plugin's rules.
			delete_option( 'rewrite_rules' );
		}
	}
}
