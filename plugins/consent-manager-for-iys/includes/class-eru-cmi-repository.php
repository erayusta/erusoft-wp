<?php
/**
 * Consent store: the one write path (set) and the reads.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * set() upserts one (recipient, type) row under a per-recipient MySQL lock, appends a history row when the
 * status changes, passes the İYS record through the `eru_cmi_record` filter and queues it, then fires
 * `eru_cmi_consent_changed`.
 *
 * Context keys (all optional):
 * - source         string  Where the change came from on this site (checkout, register, account, link,
 *                          admin, import, api, iys …). Default "api".
 * - actor          string  user | admin | api | iys | import. Default "api". "iys" changes are never queued.
 * - actor_id       int     WP user who made the change. Default: current user.
 * - user_id        int     WP user who owns the recipient (0 keeps the stored owner).
 * - consent_date   int|string  Unix time, or "Y-m-d H:i:s" in Turkish time. Default: now.
 * - recipient_type string  BIREYSEL | TACIR (wins over company).
 * - company        string  Billing company; with the TACIR setting on, a non-empty value means TACIR and
 *                          an empty one BIREYSEL. Without either key (or with the setting off) an existing
 *                          record keeps its stored type; a new one is BIREYSEL.
 * - iys_source     string  İYS source code (HS_WEB, HS_FIZIKSEL_ORTAM …). Default HS_WEB.
 * - ip, ua         string  Stored only as salted hashes in the history.
 * - skip_queue     bool    Store without queuing (forced for actor "iys").
 * - verified       bool    The address owner proved it (e-mail confirmation link). Never true for phones.
 * - text_hash      string  SHA-256 of the consent text shown (checkout, registration); kept in the history.
 * - note           string  Admin's reason; required for an admin to lift a RET.
 *
 * Lifting a RET (RET → ONAY) is refused with WP_Error "eru_cmi_reconsent_required" unless it comes from
 * İYS (actor iys), from the e-mail owner through a confirmation link (actor user, verified, EPOSTA) or from an
 * admin with a note. A logged-in account is no proof: WooCommerce does not verify account e-mails, and the
 * billing phone is free text.
 * - mark_accepted  bool    The change is already registered at İYS (used by the pull).
 *
 * Sending the same ONAY again after İYS never received the earlier one (last row skipped or failed, no
 * record at İYS in the selected environment) queues it again and writes a history row: from actor "user"
 * with this call's date (a fresh consent), from any other actor with the stored date, and only when the
 * H467 rule (with the restamp_old setting) would let it through.
 * Returns WP_Error "eru_cmi_busy" when the per-recipient lock is not obtained within 5 s.
 */
class Eru_CMI_Repository {

	/** @return string Table. */
	public static function table() {
		return Eru_CMI_Installer::table( 'consents' );
	}

	/**
	 * Records a consent.
	 *
	 * @param string $recipient E-mail or phone.
	 * @param string $type      EPOSTA | MESAJ | ARAMA.
	 * @param string $status    ONAY | RET.
	 * @param array  $context   See class doc.
	 * @return array|WP_Error {id, recipient, type, status, previous_status, changed, recipient_type,
	 *                        queue: queued|skipped|none, queue_reason, queue_id}
	 */
	public static function set( $recipient, $type, $status, array $context = array() ) {
		global $wpdb;
		$type   = Eru_CMI_Recipient::type( $type );
		$status = strtoupper( trim( (string) $status ) );
		if ( ! in_array( $status, Eru_CMI_Recipient::STATUSES, true ) ) {
			return new WP_Error( 'eru_cmi_status', __( 'Geçersiz izin durumu.', 'consent-manager-for-iys' ) );
		}
		$recipient = Eru_CMI_Recipient::normalize( $recipient, $type );
		if ( is_wp_error( $recipient ) ) {
			return $recipient;
		}
		$context['actor']    = in_array( $context['actor'] ?? '', Eru_CMI_History::ACTORS, true ) ? $context['actor'] : 'api';
		$context['source']   = substr( sanitize_key( (string) ( $context['source'] ?? 'api' ) ), 0, 40 );
		$context['actor_id'] = isset( $context['actor_id'] ) ? (int) $context['actor_id'] : get_current_user_id();
		$context['verified'] = ! empty( $context['verified'] ) && 'EPOSTA' === $type && 'user' === $context['actor'];
		$consent_ts          = Eru_CMI_Rules::parse_date( $context['consent_date'] ?? null ) ?? time();
		$consent_ts          = min( $consent_ts, time() );
		// Type information: a valid explicit recipient_type, or the company key while the TACIR setting is on.
		$explicit_rt         = strtoupper( trim( (string) ( $context['recipient_type'] ?? '' ) ) );
		$use_company         = Eru_CMI_Settings::on( 'tacir_from_company' ) && array_key_exists( 'company', $context );
		$has_type            = in_array( $explicit_rt, Eru_CMI_Recipient::RECIPIENT_TYPES, true ) || $use_company;
		$rtype               = Eru_CMI_Rules::recipient_type( $explicit_rt, (string) ( $context['company'] ?? '' ), $use_company );
		$hash                = Eru_CMI_Recipient::hash( $recipient );
		$lock                = 'eru_cmi_' . substr( $hash, 0, 40 ) . '_' . $type;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'eru_cmi_busy', __( 'Bu alıcı için başka bir değişiklik sürüyor; tekrar deneyin.', 'consent-manager-for-iys' ) );
		}

		try {
			$row      = self::row( $recipient, $type );
			$now      = gmdate( 'Y-m-d H:i:s' );
			// A row found by hash may hold a placeholder (erased, or an opt-out by link for an unknown address).
			// A change made by a person or an admin with the address restores it; İYS pulls and imports do not.
			$restore  = $row && $row->recipient !== $recipient && in_array( $context['actor'], array( 'user', 'admin', 'api' ), true );
			if ( $row && ! $has_type ) {
				$rtype = $row->recipient_type; // No type information in this call: never re-type the recipient.
			}
			if ( $row && 'RET' === $row->status && 'ONAY' === $status && ! self::may_lift( $context ) ) {
				return new WP_Error( 'eru_cmi_reconsent_required', __( 'Geri çekilmiş bir izin ancak İYS üzerinden, adres sahibinin onay bağlantısıyla ya da gerekçe yazan bir yöneticiyle yeniden verilebilir.', 'consent-manager-for-iys' ) );
			}
			$previous = $row ? $row->status : null;
			$changed  = ! $row || $row->status !== $status;
			$rt_moved = $row && $row->recipient_type !== $rtype;
			// Same ONAY again after İYS never got the earlier one (last row skipped or failed). From the user
			// it is a fresh consent (new date). From any other source the stored date stays, and the row is
			// queued only when the rules would send it (H467 and the restamp_old setting decide).
			$again       = false;
			$fresh_again = false;
			if ( $row && ! $changed && ! $rt_moved && 'ONAY' === $status && 'iys' !== $context['actor'] && empty( $context['skip_queue'] ) ) {
				$last = Eru_CMI_Queue::last_row( (int) $row->id );
				if ( $last && in_array( $last->status, array( 'skipped', 'failed' ), true ) && ! Eru_CMI_Queue::known_at_iys( $row ) ) {
					if ( 'user' === $context['actor'] ) {
						$again       = true;
						$fresh_again = true;
					} else {
						$stored_ts = (int) strtotime( $row->consent_date . ' UTC' );
						$again     = ! Eru_CMI_Rules::is_too_old( $stored_ts, time(), Eru_CMI_Rules::holidays() )
							|| ( Eru_CMI_Settings::on( 'restamp_old' ) && Eru_CMI_Rules::is_local( $context['actor'] ) );
					}
				}
			}
			if ( ! $row ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					self::table(),
					array(
						'recipient'      => $recipient,
						'recipient_hash' => $hash,
						'type'           => $type,
						'status'         => $status,
						'recipient_type' => $rtype,
						'source'         => $context['source'],
						'consent_date'   => gmdate( 'Y-m-d H:i:s', $consent_ts ),
						'user_id'        => max( 0, (int) ( $context['user_id'] ?? 0 ) ),
						'iys_status'     => '',
						'updated_at'     => $now,
					)
				);
				$id = (int) $wpdb->insert_id;
				if ( ! $id ) {
					return new WP_Error( 'eru_cmi_db', __( 'İzin kaydı yazılamadı.', 'consent-manager-for-iys' ) );
				}
			} else {
				$id   = (int) $row->id;
				$data = array();
				if ( $changed ) {
					$data = array(
						'status'       => $status,
						'source'       => $context['source'],
						'consent_date' => gmdate( 'Y-m-d H:i:s', $consent_ts ),
					);
				}
				if ( $fresh_again ) {
					$data['consent_date'] = gmdate( 'Y-m-d H:i:s', $consent_ts );
				}
				if ( $rt_moved ) {
					$data['recipient_type'] = $rtype;
				}
				if ( $restore ) {
					$data['recipient'] = $recipient;
				}
				if ( ! empty( $context['user_id'] ) && (int) $row->user_id !== (int) $context['user_id'] ) {
					$data['user_id'] = (int) $context['user_id'];
				}
				if ( $data ) {
					$data['updated_at'] = $now;
					$wpdb->update( self::table(), $data, array( 'id' => $id ) ); // phpcs:ignore
				}
			}
			if ( $changed || $again ) {
				Eru_CMI_History::add( $id, $previous, $status, $context ); // A re-queued repeat is audited too.
			}

			$consent = self::by_id( $id );
			$queue   = array( 'status' => 'none', 'reason' => '', 'id' => 0 );
			if ( 'iys' === $context['actor'] || ! empty( $context['mark_accepted'] ) ) {
				if ( $changed || $rt_moved ) {
					Eru_CMI_Queue::supersede( $id, __( 'İYS\'deki daha yeni bir değişiklikle geçersiz kaldı.', 'consent-manager-for-iys' ) );
				}
				Eru_CMI_Queue::mark_known( $id );
			} elseif ( ( $changed || $rt_moved || $again ) && empty( $context['skip_queue'] ) ) {
				$record = array(
					'consent_id'      => $id,
					'recipient'       => $recipient,
					'type'            => $type,
					'status'          => $status,
					'previous_status' => $previous,
					'recipient_type'  => $rtype,
					'consent_ts'      => strtotime( $consent->consent_date . ' UTC' ),
					'iys_source'      => Eru_CMI_Rules::iys_source( (string) ( $context['iys_source'] ?? 'HS_WEB' ) ),
				);
				/**
				 * Filters the İYS record before it is queued. Return false to keep the change local only.
				 *
				 * @param array $record  consent_id, recipient, type, status, previous_status, recipient_type,
				 *                       consent_ts, iys_source.
				 * @param array $context Context passed to set().
				 */
				$record = apply_filters( 'eru_cmi_record', $record, $context );
				if ( is_array( $record ) ) {
					$queue = Eru_CMI_Queue::enqueue( $record, $consent, $context );
				}
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$result = array(
			'id'              => $id,
			'recipient'       => $recipient,
			'type'            => $type,
			'status'          => $status,
			'previous_status' => $previous,
			'changed'         => $changed,
			'recipient_type'  => $rtype,
			'queue'           => $queue['status'],
			'queue_reason'    => $queue['reason'],
			'queue_id'        => (int) $queue['id'],
		);
		if ( $changed ) {
			/**
			 * A consent status was created or changed.
			 *
			 * @param array       $result   See set().
			 * @param string|null $previous Previous status (null for a new record).
			 * @param array       $context  Context passed to set().
			 */
			do_action( 'eru_cmi_consent_changed', $result, $previous, $context );
		}
		return $result;
	}

	/**
	 * Looked up by hash, so a row whose address was replaced by a placeholder (privacy erasure, opt-out link
	 * for an address without a record) still answers for that address.
	 *
	 * @param string $recipient Normalised. @param string $type Type. @return object|null
	 */
	private static function row( $recipient, $type ) {
		return self::by_hash( Eru_CMI_Recipient::hash( $recipient ), $type );
	}

	/**
	 * Who may turn a RET back into ONAY.
	 *
	 * @param array $context Normalised context of set().
	 * @return bool
	 */
	private static function may_lift( array $context ) {
		if ( 'iys' === $context['actor'] ) {
			return true; // The person re-consented at İYS itself.
		}
		if ( 'admin' === $context['actor'] ) {
			return '' !== trim( (string) ( $context['note'] ?? '' ) );
		}
		return ! empty( $context['verified'] ); // E-mail owner through a confirmation link.
	}

	/**
	 * Takes the per-recipient lock that set() uses, for other read-check-write paths (reconcile).
	 *
	 * @param string $hash Recipient hash. @param string $type Channel.
	 * @return bool Obtained within 5 s.
	 */
	public static function lock( $hash, $type ) {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'eru_cmi_' . substr( (string) $hash, 0, 40 ) . '_' . $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @param string $hash Recipient hash. @param string $type Channel. Releases lock(). */
	public static function unlock( $hash, $type ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'eru_cmi_' . substr( (string) $hash, 0, 40 ) . '_' . $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @param string $hash Recipient hash. @param string $type Channel. @return object|null */
	public static function by_hash( $hash, $type ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE recipient_hash = %s AND type = %s ORDER BY id ASC LIMIT 1', (string) $hash, (string) $type ) ); // phpcs:ignore
	}

	/** @param string $hash Recipient hash. @return string Placeholder stored instead of an address. */
	public static function placeholder( $hash ) {
		return '#' . substr( (string) $hash, 0, 64 );
	}

	/** @param object $row Consent row. @return bool The row holds a placeholder, not an address. */
	public static function is_placeholder( $row ) {
		return $row && 0 === strpos( (string) $row->recipient, '#' );
	}

	/**
	 * Withdrawal known only by recipient hash (signed opt-out link). With an address on record this is a
	 * normal set(); otherwise a RET row keyed by the hash is stored (or updated) locally. It cannot be sent to
	 * İYS without the address, and İYS would skip a first RET anyway (H175).
	 *
	 * @param string $hash    Recipient hash.
	 * @param string $type    Channel.
	 * @param array  $context Context (see class doc).
	 * @return array|WP_Error Same shape as set().
	 */
	public static function withdraw_hash( $hash, $type, array $context ) {
		global $wpdb;
		$type = Eru_CMI_Recipient::type( $type );
		if ( '' === $type || ! preg_match( '/^[0-9a-f]{64}$/', (string) $hash ) ) {
			return new WP_Error( 'eru_cmi_recipient', __( 'Geçersiz alıcı.', 'consent-manager-for-iys' ) );
		}
		$row = self::by_hash( $hash, $type );
		if ( $row && ! self::is_placeholder( $row ) ) {
			return self::set( $row->recipient, $type, 'RET', $context );
		}
		$context['actor']    = in_array( $context['actor'] ?? '', Eru_CMI_History::ACTORS, true ) ? $context['actor'] : 'api';
		$context['actor_id'] = isset( $context['actor_id'] ) ? (int) $context['actor_id'] : get_current_user_id();
		$context['verified'] = false;
		$lock                = 'eru_cmi_' . substr( $hash, 0, 40 ) . '_' . $type;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'eru_cmi_busy', __( 'Bu alıcı için başka bir değişiklik sürüyor; tekrar deneyin.', 'consent-manager-for-iys' ) );
		}
		try {
			$row      = self::by_hash( $hash, $type );
			$previous = $row ? $row->status : null;
			$changed  = ! $row || 'RET' !== $row->status;
			$now      = gmdate( 'Y-m-d H:i:s' );
			if ( ! $row ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					self::table(),
					array(
						'recipient'      => self::placeholder( $hash ),
						'recipient_hash' => $hash,
						'type'           => $type,
						'status'         => 'RET',
						'recipient_type' => 'BIREYSEL',
						'source'         => substr( sanitize_key( (string) ( $context['source'] ?? 'link' ) ), 0, 40 ),
						'consent_date'   => $now,
						'user_id'        => 0,
						'iys_status'     => '',
						'updated_at'     => $now,
					)
				);
				$id = (int) $wpdb->insert_id;
				if ( ! $id ) {
					return new WP_Error( 'eru_cmi_db', __( 'İzin kaydı yazılamadı.', 'consent-manager-for-iys' ) );
				}
			} else {
				$id = (int) $row->id;
				if ( $changed ) {
					$wpdb->update( self::table(), array( 'status' => 'RET', 'source' => substr( sanitize_key( (string) ( $context['source'] ?? 'link' ) ), 0, 40 ), 'consent_date' => $now, 'updated_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore
				}
			}
			if ( $changed ) {
				Eru_CMI_History::add( $id, $previous, 'RET', $context );
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$result = array(
			'id'              => $id,
			'recipient'       => self::placeholder( $hash ),
			'type'            => $type,
			'status'          => 'RET',
			'previous_status' => $previous,
			'changed'         => $changed,
			'recipient_type'  => $row ? $row->recipient_type : 'BIREYSEL',
			'queue'           => 'none',
			'queue_reason'    => '',
			'queue_id'        => 0,
		);
		if ( $changed ) {
			/** This action is documented in set(). The recipient is a placeholder here. */
			do_action( 'eru_cmi_consent_changed', $result, $previous, $context );
		}
		return $result;
	}

	/**
	 * @param string $recipient Any format.
	 * @param string $type      Channel.
	 * @return object|null Consent row.
	 */
	public static function get( $recipient, $type ) {
		$type = Eru_CMI_Recipient::type( $type );
		$norm = Eru_CMI_Recipient::normalize( $recipient, $type );
		return is_wp_error( $norm ) ? null : self::row( $norm, $type );
	}

	/** @param int $id Row id. @return object|null */
	public static function by_id( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Every channel row of a recipient (an e-mail, or a phone for MESAJ and ARAMA).
	 *
	 * @param string $recipient Any format.
	 * @return object[]
	 */
	public static function for_recipient( $recipient ) {
		global $wpdb;
		$hashes = array();
		foreach ( Eru_CMI_Recipient::TYPES as $t ) {
			$n = Eru_CMI_Recipient::normalize( $recipient, $t );
			if ( ! is_wp_error( $n ) ) {
				$hashes[ Eru_CMI_Recipient::hash( $n ) ] = true;
			}
		}
		if ( ! $hashes ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE recipient_hash IN ({$in}) ORDER BY type ASC", ...array_keys( $hashes ) ) ) ?: array(); // phpcs:ignore
	}
}
