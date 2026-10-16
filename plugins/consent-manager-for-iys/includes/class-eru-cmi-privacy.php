<?php
/**
 * WordPress personal data exporter and eraser.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Covers the e-mail address, every row owned by the account with that address (earlier account e-mails and
 * phone numbers that are no longer on the account), the account's billing and shipping phone and the billing
 * phones of orders placed with that address. Exports include the evidence of each change (verified or not,
 * consent text and its hash, IP hash, admin note).
 *
 * Erasure anonymises instead of deleting. A consent record (status, date, source) is the proof the sender has
 * to keep under the İYS rules, and an opt-out must keep working after erasure. So:
 * - an erasure request is also a withdrawal: every active consent (ONAY) of the person is first changed to
 *   RET through the normal write path (history actor admin, source "privacy"), so the RET is queued for İYS
 *   while the address is still known (İYS's H175 rule skips it when İYS never held the ONAY). A consent that
 *   cannot be withdrawn now (the record is busy) is left untouched, not anonymised: the eraser answers
 *   done = false so WordPress calls it again, and after BUSY_RETRIES calls it says so in its messages;
 * - the address in the consent row is replaced by a placeholder; the salted hash stays, so the same address
 *   still resolves to its status (an opt-out keeps suppressing messages) without the address being stored;
 * - history rows lose their user id (they never held an address; IP and user agent are salted hashes), and
 *   one marker row (same status, source "privacy") records the erasure: confirmation links mailed before it
 *   become void, so an old link can never put the address back;
 * - queue rows: finished rows lose the address; an unsent ONAY is superseded and loses it too; a RET still on
 *   its way to İYS and rows awaiting İYS's answer keep it until they finish (the eraser reports them as
 *   retained); the daily retention job removes it as soon as they finish.
 */
class Eru_CMI_Privacy {

	const ID     = 'consent-manager-for-iys';
	const ORDERS = 200;

	/** Eraser calls (pages) that retry consents whose record was busy before reporting them. */
	const BUSY_RETRIES = 5;

	/** History source of the erasure marker row (voids confirmation links mailed before the erasure). */
	const SOURCE = 'privacy';

	/** Hooks. */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/** @param array $e Exporters. @return array */
	public static function register_exporter( $e ) {
		$e[ self::ID ] = array(
			'exporter_friendly_name' => __( 'İYS iletişim izinleri', 'consent-manager-for-iys' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $e;
	}

	/** @param array $e Erasers. @return array */
	public static function register_eraser( $e ) {
		$e[ self::ID ] = array(
			'eraser_friendly_name' => __( 'İYS iletişim izinleri', 'consent-manager-for-iys' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $e;
	}

	/**
	 * Phone numbers linked to an e-mail address.
	 *
	 * @param string $email E-mail.
	 * @return string[] Raw numbers.
	 */
	private static function phones( $email ) {
		$out  = array();
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			foreach ( array( 'billing_phone', 'shipping_phone' ) as $k ) {
				$out[] = (string) get_user_meta( $user->ID, $k, true );
			}
		}
		foreach ( wc_get_orders( array( 'billing_email' => $email, 'limit' => self::ORDERS, 'orderby' => 'date', 'order' => 'DESC', 'status' => 'any' ) ) as $order ) {
			$out[] = (string) $order->get_billing_phone();
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Consent rows of an e-mail address and its phones.
	 *
	 * @param string $email E-mail.
	 * @return object[] Rows keyed by id.
	 */
	private static function rows( $email ) {
		$rows = array();
		$add  = function ( $r ) use ( &$rows ) {
			if ( $r ) {
				$rows[ (int) $r->id ] = $r;
			}
		};
		$add( Eru_CMI_Repository::get( $email, 'EPOSTA' ) );
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			global $wpdb;
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Eru_CMI_Installer::table( 'consents' ) . ' WHERE user_id = %d LIMIT 500', (int) $user->ID ) ) ?: array() as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$add( $r );
			}
		}
		foreach ( self::phones( $email ) as $p ) {
			$add( Eru_CMI_Repository::get( $p, 'MESAJ' ) );
			$add( Eru_CMI_Repository::get( $p, 'ARAMA' ) );
		}
		ksort( $rows );
		return $rows;
	}

	/**
	 * Exporter.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page (everything fits on one).
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$data  = array();
		$texts = get_option( Eru_CMI_Capture_Checkout::TEXTS, array() );
		$texts = is_array( $texts ) ? $texts : array();
		foreach ( self::rows( (string) $email ) as $row ) {
			$history = array();
			foreach ( Eru_CMI_History::for_consent( (int) $row->id ) as $h ) {
				$line = $h->created_at . ' UTC: ' . ( '' === $h->previous_status ? '—' : $h->previous_status ) . ' → ' . $h->new_status . ' (' . $h->actor . ', ' . $h->source . '; '
					. ( ! empty( $h->verified ) ? __( 'adres sahibi bağlantıyla doğruladı', 'consent-manager-for-iys' ) : __( 'doğrulanmadı', 'consent-manager-for-iys' ) ) . ')';
				if ( ! empty( $h->text_hash ) ) {
					$line .= ' — ' . __( 'İzin metni', 'consent-manager-for-iys' ) . ': "' . ( $texts[ $h->text_hash ]['t'] ?? '?' ) . '" (' . __( 'metin özeti', 'consent-manager-for-iys' ) . ' ' . $h->text_hash . ')';
				}
				if ( ! empty( $h->ip_hash ) ) {
					$line .= ' — ' . __( 'IP özeti', 'consent-manager-for-iys' ) . ' ' . $h->ip_hash;
				}
				if ( ! empty( $h->note ) ) {
					$line .= ' — ' . __( 'Not', 'consent-manager-for-iys' ) . ': ' . $h->note;
				}
				$history[] = $line;
			}
			$data[] = array(
				'group_id'    => 'eru-cmi-consents',
				'group_label' => __( 'İYS iletişim izinleri', 'consent-manager-for-iys' ),
				'item_id'     => 'eru-cmi-' . (int) $row->id,
				'data'        => array(
					array( 'name' => __( 'Kanal', 'consent-manager-for-iys' ), 'value' => $row->type ),
					array( 'name' => __( 'Alıcı', 'consent-manager-for-iys' ), 'value' => Eru_CMI_Repository::is_placeholder( $row ) ? __( '(anonimleştirildi)', 'consent-manager-for-iys' ) : $row->recipient ),
					array( 'name' => __( 'Durum', 'consent-manager-for-iys' ), 'value' => $row->status ),
					array( 'name' => __( 'Alıcı tipi', 'consent-manager-for-iys' ), 'value' => $row->recipient_type ),
					array( 'name' => __( 'Kaynak', 'consent-manager-for-iys' ), 'value' => $row->source ),
					array( 'name' => __( 'İzin tarihi (UTC)', 'consent-manager-for-iys' ), 'value' => $row->consent_date ),
					array( 'name' => __( 'Geçmiş', 'consent-manager-for-iys' ), 'value' => implode( "\n", $history ) ),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser (anonymises; see the class doc).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Call number (WordPress raises it while done is false; used to bound busy retries).
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$consents = Eru_CMI_Installer::table( 'consents' );
		$history  = Eru_CMI_Installer::table( 'history' );
		$queue    = Eru_CMI_Installer::table( 'queue' );
		$removed  = false;
		$kept     = 0;
		$busy     = 0;
		$rows     = self::rows( (string) $email );
		foreach ( $rows as $id => $row ) {
			if ( 'ONAY' === $row->status && ! Eru_CMI_Repository::is_placeholder( $row ) ) {
				// Withdraw first, while the address is known: the RET must reach İYS.
				$w = Eru_CMI_Repository::set(
					$row->recipient,
					$row->type,
					'RET',
					array(
						'actor'          => 'admin',
						'actor_id'       => 0,
						'source'         => self::SOURCE,
						'recipient_type' => $row->recipient_type,
						'note'           => __( 'Kişisel veri silme talebi: izin geri çekildi.', 'consent-manager-for-iys' ),
					)
				);
				if ( is_wp_error( $w ) ) {
					++$busy; // Not withdrawn: never anonymise it (İYS and the opt-out check would keep the ONAY).
					continue;
				}
				$row         = Eru_CMI_Repository::by_id( (int) $row->id ) ?? $row;
				$rows[ $id ] = $row;
			}
			$ph = Eru_CMI_Repository::placeholder( $row->recipient_hash );
			if ( $row->recipient !== $ph || (int) $row->user_id ) {
				$wpdb->update( $consents, array( 'recipient' => $ph, 'user_id' => 0 ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Eru_CMI_History::add(
					(int) $row->id,
					$row->status,
					$row->status,
					array(
						'actor'    => 'admin',
						'actor_id' => 0,
						'source'   => self::SOURCE,
						'note'     => __( 'Kişisel veri silme talebi: alıcı anonimleştirildi.', 'consent-manager-for-iys' ),
					)
				);
				$removed = true;
			}
			if ( $wpdb->query( $wpdb->prepare( "UPDATE {$history} SET actor_id = 0 WHERE consent_id = %d AND actor_id <> 0", (int) $row->id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$removed = true;
			}
			$q = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, batch_key, payload FROM {$queue} WHERE consent_id = %d ORDER BY id ASC LIMIT 500", (int) $row->id ) ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			foreach ( $q as $item ) {
				$p = json_decode( (string) $item->payload, true );
				if ( ! is_array( $p ) || $ph === ( $p['recipient'] ?? '' ) ) {
					continue;
				}
				$unsent_onay = 'queued' === $item->status && null === $item->batch_key && 'ONAY' === ( $p['status'] ?? '' );
				if ( in_array( $item->status, array( 'queued', 'sent' ), true ) && ! $unsent_onay ) {
					++$kept; // A RET on its way to İYS, or a row awaiting İYS's answer: kept until it finishes.
					continue;
				}
				$p['recipient'] = $ph;
				$cols           = array( 'payload' => (string) wp_json_encode( $p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
				if ( $unsent_onay ) {
					$cols += array(
						'status'      => 'superseded',
						'next_try_at' => null,
						'last_error'  => __( 'Kişisel veri silme talebiyle gönderilmeden kapatıldı.', 'consent-manager-for-iys' ),
						'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
					);
				}
				$wpdb->update( $queue, $cols, array( 'id' => (int) $item->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$removed = true;
			}
		}
		if ( $kept ) {
			Eru_CMI_Scheduler::arm_purge(); // The daily purge clears those addresses once the rows finish.
		}
		$retry    = $busy && (int) $page < self::BUSY_RETRIES;
		$messages = array();
		if ( $busy && ! $retry ) {
			/* translators: %d: number of consent records */
			$messages[] = sprintf( _n( '%d izin kaydı şu anda başka bir işlem tarafından kullanıldığı için geri çekilemedi ve anonimleştirilmedi; silme talebini biraz sonra yeniden çalıştırın.', '%d izin kaydı şu anda başka bir işlem tarafından kullanıldığı için geri çekilemedi ve anonimleştirilmedi; silme talebini biraz sonra yeniden çalıştırın.', $busy, 'consent-manager-for-iys' ), $busy );
		}
		if ( $rows ) {
			$messages[] = __( 'İYS izin kayıtları yasal kayıt olarak saklandı; alıcı bilgisi anonimleştirildi ve yalnızca tuzlanmış özeti tutuluyor.', 'consent-manager-for-iys' );
		}
		if ( $kept ) {
			/* translators: %d: number of queue rows */
			$messages[] = sprintf( _n( '%d kayıt İYS\'ye iletilmeyi ya da İYS yanıtını bekliyor; tamamlandığında adres günlük temizlikle kendiliğinden silinir.', '%d kayıt İYS\'ye iletilmeyi ya da İYS yanıtını bekliyor; tamamlandığında adres günlük temizlikle kendiliğinden silinir.', $kept, 'consent-manager-for-iys' ), $kept );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => (bool) $rows,
			'messages'       => $messages,
			'done'           => ! $retry,
		);
	}
}
