<?php
/**
 * The only clock of the WooCommerce layer: "now" in the site's time zone for the engine, and UTC
 * database timestamps.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure;

use DateTimeImmutable;

final class Clock {

	public static function now(): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . time() ) )->setTimezone( wp_timezone() );
	}

	/** UTC "Y-m-d H:i:s" for the plugin's tables. */
	public static function mysql( ?int $ts = null ): string {
		return gmdate( 'Y-m-d H:i:s', $ts ?? time() );
	}

	/** Unix time of a UTC table value, or null. */
	public static function ts( $mysql ): ?int {
		if ( ! is_string( $mysql ) || '' === $mysql || 0 === strpos( $mysql, '0000' ) ) {
			return null;
		}

		$ts = strtotime( $mysql . ' UTC' );

		return false === $ts ? null : $ts;
	}
}
