<?php
/**
 * One rewrite flush after a change that needs new rules (a campaign page slug, the account endpoint, or
 * turning those modules on or off). The flag lives in the autoloaded storefront option, so requests
 * without a pending change cost nothing.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;

final class Rewrite {

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'maybeFlush' ), 99 );
	}

	public static function maybeFlush(): void {
		if ( 1 !== (int) StorefrontOptions::get( 'rewrite_pending' ) ) {
			return;
		}

		StorefrontOptions::update( array( 'rewrite_pending' => 0 ) );
		flush_rewrite_rules( false );
	}
}
