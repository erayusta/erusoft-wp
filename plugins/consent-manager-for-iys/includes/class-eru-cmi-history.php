<?php
/**
 * Append-only consent audit trail: who changed which status, when and from where.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows are never updated. IP address and user agent are stored only as salted hashes, so a disputed
 * change can be matched against a known value without keeping the value itself.
 */
class Eru_CMI_History {

	const ACTORS = array( 'user', 'admin', 'api', 'iys', 'import' );

	/**
	 * Appends a row.
	 *
	 * @param int         $consent_id Consent row.
	 * @param string|null $previous   Previous status (null for a new record).
	 * @param string      $new        New status.
	 * @param array       $context    actor, actor_id, source, ip, ua, verified, text_hash, note.
	 * @return int Row id.
	 */
	public static function add( $consent_id, $previous, $new, array $context ) {
		global $wpdb;
		$actor = in_array( $context['actor'] ?? '', self::ACTORS, true ) ? $context['actor'] : 'api';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Eru_CMI_Installer::table( 'history' ),
			array(
				'consent_id'      => (int) $consent_id,
				'previous_status' => (string) $previous,
				'new_status'      => (string) $new,
				'actor'           => $actor,
				'actor_id'        => max( 0, (int) ( $context['actor_id'] ?? 0 ) ),
				'source'          => substr( sanitize_key( (string) ( $context['source'] ?? '' ) ), 0, 40 ),
				'ip_hash'         => Eru_CMI_Recipient::hash_value( (string) ( $context['ip'] ?? '' ) ),
				'ua_hash'         => Eru_CMI_Recipient::hash_value( (string) ( $context['ua'] ?? '' ) ),
				'verified'        => empty( $context['verified'] ) ? 0 : 1,
				'text_hash'       => preg_match( '/^[0-9a-f]{64}$/', (string) ( $context['text_hash'] ?? '' ) ) ? (string) $context['text_hash'] : '',
				'note'            => mb_substr( sanitize_text_field( (string) ( $context['note'] ?? '' ) ), 0, 255 ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @param int $consent_id Consent row. @return object[] Rows, oldest first. */
	public static function for_consent( $consent_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Eru_CMI_Installer::table( 'history' ) . ' WHERE consent_id = %d ORDER BY id ASC', (int) $consent_id ) ) ?: array(); // phpcs:ignore
	}
}
