<?php
/**
 * An agent's payout details: account holder, IBAN and (optionally) TCKN, each encrypted with
 * Eru_STC_Crypto into the agents table (payout_holder / payout_iban / payout_tckn). The UI, exports and
 * REST only ever see masks; a payout request stores a snapshot of the ciphertexts so a later change does
 * not redirect money that is already requested.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Payout_Details {

	const FIELDS = array(
		'holder' => 'payout_holder',
		'iban'   => 'payout_iban',
		'tckn'   => 'payout_tckn',
	);

	/**
	 * Validates, encrypts and stores. A blank field keeps the stored value (the form never shows the
	 * full value back).
	 *
	 * @param int   $agent_id Agent.
	 * @param array $in       holder, iban, tckn (plain text).
	 * @return true|WP_Error
	 */
	public static function save( $agent_id, array $in ) {
		global $wpdb;
		$agent = Eru_STC_Agents::get( $agent_id );
		if ( ! $agent ) {
			return new WP_Error( 'eru_stc_agent_missing', __( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! Eru_STC_Crypto::available() ) {
			return new WP_Error( 'eru_stc_no_key', __( 'Ödeme bilgileri şu anda kaydedilemiyor: şifreleme anahtarı tanımlı değil. Mağaza yöneticisiyle iletişime geçin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$holder = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $in['holder'] ?? '' ) ) ) );
		$iban   = Eru_STC_Crypto::normalize_iban( (string) ( $in['iban'] ?? '' ) );
		$tckn   = preg_replace( '/\s+/', '', (string) ( $in['tckn'] ?? '' ) );
		if ( '' !== $holder && ( mb_strlen( $holder ) < 3 || mb_strlen( $holder ) > 100 ) ) {
			return new WP_Error( 'eru_stc_holder', __( 'Hesap sahibinin adını 3–100 karakter olarak girin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( '' !== $iban && ! Eru_STC_Crypto::valid_iban( $iban ) ) {
			return new WP_Error( 'eru_stc_iban', __( 'IBAN geçersiz: TR ile başlayan 26 karakterlik IBAN girin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( '' !== $tckn && ! Eru_STC_Crypto::valid_tckn( $tckn ) ) {
			return new WP_Error( 'eru_stc_tckn', __( 'T.C. kimlik numarası geçersiz.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$data = array();
		foreach ( array(
			'holder' => $holder,
			'iban'   => $iban,
			'tckn'   => $tckn,
		) as $field => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$sealed = Eru_STC_Crypto::encrypt( $value );
			if ( is_wp_error( $sealed ) ) {
				return $sealed;
			}
			$data[ self::FIELDS[ $field ] ] = $sealed;
		}
		if ( ! $data ) {
			return true;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		if ( false === $wpdb->update( Eru_STC_Installer::table( 'agents' ), $data, array( 'id' => (int) $agent->id ) ) ) {
			return new WP_Error( 'eru_stc_details_db', __( 'Ödeme bilgileri kaydedilemedi.', 'sales-team-commissions-for-woocommerce' ) );
		}
		/**
		 * Fires after an agent's payout details changed (no values are passed).
		 *
		 * @param int      $agent_id Agent.
		 * @param string[] $fields   Changed fields (holder, iban, tckn).
		 */
		do_action( 'eru_stc_payout_details_saved', (int) $agent->id, array_keys( array_filter( array( 'holder' => $holder, 'iban' => $iban, 'tckn' => $tckn ) ) ) );
		self::notify( Eru_STC_Agents::get( (int) $agent->id ), false );
		return true;
	}

	/**
	 * Tells the agent's account address that the payout details changed (masked values only), so a
	 * takeover of the account cannot redirect payouts unnoticed.
	 *
	 * @param object|null $agent   Agent row after the change.
	 * @param bool        $deleted The details were deleted.
	 */
	public static function notify( $agent, $deleted ) {
		$user = $agent ? get_userdata( (int) $agent->user_id ) : false;
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = $deleted ? __( 'Ödeme bilgileriniz silindi', 'sales-team-commissions-for-woocommerce' ) : __( 'Ödeme bilgileriniz güncellendi', 'sales-team-commissions-for-woocommerce' );
		$body  = '<p>' . esc_html( $deleted ? __( 'Temsilci hesabınızdaki kayıtlı ödeme bilgileri silindi.', 'sales-team-commissions-for-woocommerce' ) : __( 'Temsilci hesabınızdaki ödeme bilgileri değiştirildi. Kayıtlı bilgiler (maskeli):', 'sales-team-commissions-for-woocommerce' ) ) . '</p>';
		if ( ! $deleted ) {
			$m     = self::masked( $agent );
			$body .= '<ul><li>' . esc_html__( 'Hesap sahibi', 'sales-team-commissions-for-woocommerce' ) . ': ' . esc_html( '' !== $m['holder'] ? $m['holder'] : '—' ) . '</li><li>IBAN: ' . esc_html( '' !== $m['iban'] ? $m['iban'] : '—' ) . '</li><li>' . esc_html__( 'T.C. kimlik no', 'sales-team-commissions-for-woocommerce' ) . ': ' . esc_html( '' !== $m['tckn'] ? $m['tckn'] : '—' ) . '</li></ul>';
		}
		$body   .= '<p>' . esc_html__( 'Bu değişikliği siz yapmadıysanız hemen şifrenizi değiştirin ve mağazayla iletişime geçin.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		$message = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer()->wrap_message( $title, $body ) : $body;
		wp_mail( $user->user_email, $site . ': ' . $title, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Wipes the stored details.
	 *
	 * @param int $agent_id Agent.
	 * @return bool Whether anything was stored.
	 */
	public static function wipe( $agent_id ) {
		global $wpdb;
		$agent = Eru_STC_Agents::get( $agent_id );
		if ( ! $agent || ( null === $agent->payout_holder && null === $agent->payout_iban && null === $agent->payout_tckn ) ) {
			return false;
		}
		$t = Eru_STC_Installer::table( 'agents' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET payout_holder = NULL, payout_iban = NULL, payout_tckn = NULL, updated_at = %s WHERE id = %d", current_time( 'mysql', true ), $agent->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return true;
	}

	/**
	 * Masked view of an agent's stored details.
	 *
	 * @param int|object $agent Agent id or row.
	 * @return array{state:string,holder:string,iban:string,tckn:string,complete:bool} state: empty (nothing
	 *         stored) | ok | nokey (stored, no key on this site: "anahtar yok") | unreadable (another key).
	 */
	public static function masked( $agent ) {
		$row = is_object( $agent ) ? $agent : Eru_STC_Agents::get( (int) $agent );
		$out = self::open(
			array(
				'holder' => $row ? $row->payout_holder : null,
				'iban'   => $row ? $row->payout_iban : null,
				'tckn'   => $row ? $row->payout_tckn : null,
			),
			false
		);
		return $out;
	}

	/**
	 * Ciphertext snapshot for a payout request (JSON), or null when nothing is stored.
	 *
	 * @param int|object $agent Agent id or row.
	 * @return string|null
	 */
	public static function snapshot( $agent ) {
		$row = is_object( $agent ) ? $agent : Eru_STC_Agents::get( (int) $agent );
		if ( ! $row || ( null === $row->payout_holder && null === $row->payout_iban && null === $row->payout_tckn ) ) {
			return null;
		}
		return (string) wp_json_encode(
			array(
				'holder' => $row->payout_holder,
				'iban'   => $row->payout_iban,
				'tckn'   => $row->payout_tckn,
			)
		);
	}

	/**
	 * Reads a payout's snapshot.
	 *
	 * @param string|null $json      Snapshot.
	 * @param bool        $full_iban Return the full IBAN and holder (explicit "tam IBAN" export only);
	 *                               the TCKN is always masked.
	 * @return array{state:string,holder:string,iban:string,tckn:string,complete:bool}
	 */
	public static function from_snapshot( $json, $full_iban = false ) {
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		$data = is_array( $data ) ? $data : array();
		return self::open(
			array(
				'holder' => $data['holder'] ?? null,
				'iban'   => $data['iban'] ?? null,
				'tckn'   => $data['tckn'] ?? null,
			),
			(bool) $full_iban
		);
	}

	/** Decrypts and masks a set of stored values. */
	private static function open( array $stored, $full ) {
		$out = array(
			'state'    => 'empty',
			'holder'   => '',
			'iban'     => '',
			'tckn'     => '',
			'complete' => false,
		);
		$any = false;
		foreach ( $stored as $k => $v ) {
			if ( is_string( $v ) && '' !== $v ) {
				$any = true;
			}
		}
		if ( ! $any ) {
			return $out;
		}
		if ( ! Eru_STC_Crypto::available() ) {
			$label = __( 'anahtar yok', 'sales-team-commissions-for-woocommerce' );
			foreach ( $stored as $k => $v ) {
				$out[ $k ] = is_string( $v ) && '' !== $v ? $label : '';
			}
			$out['state'] = 'nokey';
			return $out;
		}
		$out['state'] = 'ok';
		foreach ( $stored as $k => $v ) {
			if ( ! is_string( $v ) || '' === $v ) {
				continue;
			}
			$plain = Eru_STC_Crypto::decrypt( $v );
			if ( null === $plain ) {
				$out[ $k ]    = __( 'okunamıyor', 'sales-team-commissions-for-woocommerce' );
				$out['state'] = 'unreadable';
				continue;
			}
			if ( 'iban' === $k ) {
				$out[ $k ] = $full ? $plain : Eru_STC_Crypto::mask_iban( $plain );
			} elseif ( 'tckn' === $k ) {
				$out[ $k ] = Eru_STC_Crypto::mask_tckn( $plain );
			} else {
				$out[ $k ] = $full ? $plain : Eru_STC_Crypto::mask_name( $plain );
			}
		}
		$out['complete'] = 'ok' === $out['state'] && '' !== $out['holder'] && '' !== $out['iban'];
		return $out;
	}
}
