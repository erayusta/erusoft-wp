<?php
/**
 * Recipient helpers: normalisation (lower-case e-mail, E.164 phone), salted hashes and masks.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * İYS channels: EPOSTA (e-mail), MESAJ (SMS) and ARAMA (call). Phones are Turkish numbers stored as
 * +90XXXXXXXXXX; MESAJ needs a mobile number (5xx).
 */
class Eru_CMI_Recipient {

	const TYPES          = array( 'EPOSTA', 'MESAJ', 'ARAMA' );
	const STATUSES       = array( 'ONAY', 'RET' );
	const RECIPIENT_TYPES = array( 'BIREYSEL', 'TACIR' );
	const SALT_OPTION    = 'eru_cmi_hash_salt';

	/** @param string $type Channel. @return bool */
	public static function is_phone_type( $type ) {
		return in_array( $type, array( 'MESAJ', 'ARAMA' ), true );
	}

	/** @param mixed $type Raw. @return string Upper-case channel or ''. */
	public static function type( $type ) {
		$type = strtoupper( trim( (string) $type ) );
		return in_array( $type, self::TYPES, true ) ? $type : '';
	}

	/**
	 * Normalises a recipient for a channel.
	 *
	 * @param string $recipient Raw value.
	 * @param string $type      EPOSTA | MESAJ | ARAMA.
	 * @return string|WP_Error
	 */
	public static function normalize( $recipient, $type ) {
		$type      = self::type( $type );
		$recipient = trim( (string) $recipient );
		if ( '' === $type ) {
			return new WP_Error( 'eru_cmi_type', __( 'Geçersiz izin tipi.', 'consent-manager-for-iys' ) );
		}
		if ( 'EPOSTA' === $type ) {
			$email = strtolower( $recipient );
			if ( strlen( $email ) > 190 || ! is_email( $email ) ) {
				return new WP_Error( 'eru_cmi_recipient', __( 'Geçersiz e-posta adresi.', 'consent-manager-for-iys' ) );
			}
			return $email;
		}
		$digits = preg_replace( '/\D+/', '', $recipient );
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '90' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 11 === strlen( $digits ) && '0' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		$pattern = 'MESAJ' === $type ? '/^5\d{9}$/' : '/^[2-5]\d{9}$/';
		if ( ! preg_match( $pattern, $digits ) ) {
			return new WP_Error( 'eru_cmi_recipient', __( 'Geçersiz telefon numarası.', 'consent-manager-for-iys' ) );
		}
		return '+90' . $digits;
	}

	/** @return string Per-site salt (random, created once, not autoloaded). */
	public static function salt() {
		$salt = get_option( self::SALT_OPTION, '' );
		if ( ! is_string( $salt ) || strlen( $salt ) < 32 ) {
			$salt = bin2hex( random_bytes( 32 ) );
			if ( ! add_option( self::SALT_OPTION, $salt, '', false ) ) {
				$salt = (string) get_option( self::SALT_OPTION, $salt );
			}
		}
		return $salt;
	}

	/** @param string $value Any value. @return string Salted SHA-256 hex, or '' for an empty value. */
	public static function hash_value( $value ) {
		$value = (string) $value;
		return '' === $value ? '' : hash_hmac( 'sha256', $value, self::salt() );
	}

	/** @param string $normalized Normalised recipient. @return string Hash used for look-ups and erasure. */
	public static function hash( $normalized ) {
		return self::hash_value( 'r:' . $normalized );
	}

	/**
	 * Mask for screens, CLI and logs: "or***@example.com", "+90 5** *** ** 00".
	 *
	 * @param string $recipient Recipient.
	 * @return string
	 */
	public static function mask( $recipient ) {
		$recipient = (string) $recipient;
		$at        = strrpos( $recipient, '@' );
		if ( false !== $at ) {
			$local = substr( $recipient, 0, $at );
			return mb_substr( $local, 0, min( 2, max( 1, mb_strlen( $local ) - 1 ) ) ) . '***' . substr( $recipient, $at );
		}
		$digits = preg_replace( '/\D+/', '', $recipient );
		if ( strlen( $digits ) >= 6 ) {
			return '+' . substr( $digits, 0, 2 ) . ' ' . substr( $digits, 2, 1 ) . '** *** ** ' . substr( $digits, -2 );
		}
		return '***';
	}
}
