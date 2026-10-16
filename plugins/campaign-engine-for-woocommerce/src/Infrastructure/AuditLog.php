<?php
/**
 * Append-only log of changes worth explaining later: campaign saves, refused reservations, uses over
 * the limit, reward transitions and reversals. Kept on uninstall unless ERU_CE_REMOVE_DATA is true.
 * No personal data is written here (order and reward ids only).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure;

use Erusoft\CampaignEngine\Infrastructure\Database\Schema;

final class AuditLog {

	private const MAX_JSON = 65535;

	/** @param array<string,mixed> $data */
	public static function record( string $object_type, int $object_id, string $action, array $data = array() ): void {
		global $wpdb;

		$json = (string) wp_json_encode( $data );

		if ( strlen( $json ) > self::MAX_JSON ) {
			$json = (string) wp_json_encode( array( 'truncated' => true ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( 'audit_log' ),
			array(
				'user_id'     => get_current_user_id(),
				'object_type' => substr( $object_type, 0, 30 ),
				'object_id'   => max( 0, $object_id ),
				'action'      => substr( $action, 0, 40 ),
				'data'        => $json,
				'created_at'  => Clock::mysql(),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/** @return array<int,array<string,mixed>> newest first */
	public static function forObject( string $object_type, int $object_id, int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'audit_log' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d', $object_type, $object_id, $limit ), ARRAY_A );
	}
}
