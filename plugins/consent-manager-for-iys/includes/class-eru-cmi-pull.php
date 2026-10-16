<?php
/**
 * İYS → site: reads one page of the İYS change feed and applies it locally (actor "iys", never queued).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cursor is İYS's own opaque "after" value and is stored only after a page was read successfully.
 * When İYS refuses a stored cursor (H509) it is cleared, so the next page starts over; applying the same
 * change twice is a no-op. A change older than the local record, or as old while a local change is still
 * queued or sent, is ignored (the local change is on its way to İYS). A change without recipientType
 * keeps the stored type.
 */
class Eru_CMI_Pull {

	/** @return string Cursor option for the selected environment. */
	public static function cursor_option() {
		return 'eru_cmi_pull_cursor_' . Eru_CMI_Settings::env();
	}

	/**
	 * Reads and applies one page.
	 *
	 * @param int $limit Page size (clamped to 1–999 by the client).
	 * @return array{ok:bool,applied:int,ignored:int,more:bool,error:string}
	 */
	public static function page( $limit = Eru_CMI_Client::MAX_PULL ) {
		$out = array( 'ok' => false, 'applied' => 0, 'ignored' => 0, 'more' => false, 'error' => '' );
		if ( ! Eru_CMI_Settings::enabled() ) {
			$out['error'] = __( 'İYS bağlantısı kapalı.', 'consent-manager-for-iys' );
			return $out;
		}
		$owner = Eru_CMI_Lock::acquire( 'pull', 10 * MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			$out['error'] = __( 'Başka bir çekme işlemi sürüyor.', 'consent-manager-for-iys' );
			return $out;
		}
		try {
			$cursor = (string) get_option( self::cursor_option(), '' );
			$res    = ( new Eru_CMI_Client() )->pull_changes( $cursor, $limit );
			if ( ! $res['ok'] ) {
				if ( '' !== $cursor && false !== strpos( (string) wp_json_encode( $res['data'] ), 'H509' ) ) {
					delete_option( self::cursor_option() );
				}
				$out['error'] = $res['error'];
				return $out;
			}
			foreach ( $res['items'] as $item ) {
				$out[ self::apply( $item ) ? 'applied' : 'ignored' ]++;
			}
			if ( '' !== $res['cursor'] ) {
				update_option( self::cursor_option(), $res['cursor'], false );
			}
			$out['ok']   = true;
			$out['more'] = '' !== $res['cursor'] && $res['cursor'] !== $cursor && count( $res['items'] ) > 0;
			return $out;
		} finally {
			Eru_CMI_Lock::release( 'pull', $owner );
		}
	}

	/** @param object $consent Consent. @return bool No local row of this environment is still queued or sent. */
	private static function accepted_only( $consent ) {
		$last = Eru_CMI_Queue::last_row( (int) $consent->id );
		return ! $last || ! in_array( $last->status, array( 'queued', 'sent' ), true );
	}

	/**
	 * Applies one İYS change (also used by the reconcile when İYS holds a newer, different status).
	 *
	 * @param array $item recipient, type, status, recipientType, consentDate.
	 * @return bool Applied.
	 */
	public static function apply( array $item ) {
		$type   = Eru_CMI_Recipient::type( $item['type'] ?? '' );
		$status = strtoupper( (string) ( $item['status'] ?? '' ) );
		if ( '' === $type || ! in_array( $status, Eru_CMI_Recipient::STATUSES, true ) ) {
			return false;
		}
		$ts    = Eru_CMI_Rules::parse_date( (string) ( $item['consentDate'] ?? '' ) ) ?? time();
		$local = Eru_CMI_Repository::get( (string) ( $item['recipient'] ?? '' ), $type );
		if ( $local ) {
			$local_ts = strtotime( $local->consent_date . ' UTC' );
			// Older than ours, or as old while our change is still on its way: ours wins.
			if ( $local_ts > $ts || ( $local_ts === $ts && ! self::accepted_only( $local ) ) ) {
				return false;
			}
		}
		$context = array(
			'source'       => 'iys',
			'actor'        => 'iys',
			'actor_id'     => 0,
			'consent_date' => $ts,
		);
		if ( '' !== (string) ( $item['recipientType'] ?? '' ) ) {
			$context['recipient_type'] = (string) $item['recipientType'];
		}
		$r = Eru_CMI_Repository::set( (string) ( $item['recipient'] ?? '' ), $type, $status, $context );
		return ! is_wp_error( $r );
	}
}
