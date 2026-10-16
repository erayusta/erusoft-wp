<?php
/**
 * Signed, expiring per-recipient tokens for opt-out and confirmation links.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token = base64url( "<recipient hash>.<channel>.<expiry>" ) . "." . base64url( HMAC-SHA256 ).
 *
 * The payload carries the salted recipient hash, never the address. The HMAC key is a random per-site secret
 * (option eru_cmi_token_secret, not autoloaded) and the signed message starts with the purpose, so an opt-out
 * token is never accepted as a confirmation token and the other way round. Anything that is not exactly this
 * shape with a valid signature (a plain e-mail address, an unsigned payload) is rejected.
 *
 * Confirmation tokens carry a third part: the address, encrypted with a key derived from the same secret
 * (libsodium secretbox, or AES-256-GCM through OpenSSL) and covered by the HMAC. A row whose address is
 * known only by hash (opt-out link for an address without a record, privacy erasure) can then be restored
 * when its owner confirms; the decrypted address must hash to the signed recipient hash.
 */
class Eru_CMI_Tokens {

	const SECRET_OPTION = 'eru_cmi_token_secret';
	const PURPOSES      = array( 'optout', 'confirm' );

	/** Confirmation links (re-consent after an opt-out) live 48 hours. */
	const CONFIRM_TTL = 2 * DAY_IN_SECONDS;

	/** @return string Raw 32-byte secret, created once. */
	private static function secret() {
		$hex = get_option( self::SECRET_OPTION, '' );
		if ( ! is_string( $hex ) || ! preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
			$hex = bin2hex( random_bytes( 32 ) );
			if ( ! add_option( self::SECRET_OPTION, $hex, '', false ) ) {
				$stored = get_option( self::SECRET_OPTION, '' );
				$hex    = is_string( $stored ) && preg_match( '/^[0-9a-f]{64}$/', $stored ) ? $stored : $hex;
				if ( $stored !== $hex ) {
					update_option( self::SECRET_OPTION, $hex, false );
				}
			}
		}
		return (string) hex2bin( $hex );
	}

	/** @param string $bin Bytes. @return string base64url without padding. */
	private static function b64( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/** @param string $txt base64url. @return string|false Bytes. */
	private static function unb64( $txt ) {
		if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $txt ) ) {
			return false;
		}
		return base64_decode( strtr( $txt, '-_', '+/' ), true );
	}

	/**
	 * @param string $purpose optout | confirm.
	 * @param string $payload Payload.
	 * @param string $blob    Encrypted-address part ('' when absent).
	 * @return string Raw MAC.
	 */
	private static function mac( $purpose, $payload, $blob = '' ) {
		return hash_hmac( 'sha256', $purpose . '|' . $payload . ( '' === $blob ? '' : '|' . $blob ), self::secret(), true );
	}

	/** @return string 32-byte encryption key (separate from the MAC key). */
	private static function enc_key() {
		return hash_hmac( 'sha256', 'eru-cmi-enc', self::secret(), true );
	}

	/** @return bool Confirmation tokens can carry the address (libsodium or OpenSSL GCM available). */
	public static function can_carry() {
		return function_exists( 'sodium_crypto_secretbox' ) || ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) );
	}

	/** @param string $plain Address. @return string base64url blob ('' when no cipher is available). */
	private static function seal( $plain ) {
		$key = self::enc_key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return self::b64( "\x01" . $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
		}
		if ( self::can_carry() ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $ct ? '' : self::b64( "\x02" . $iv . $tag . $ct );
		}
		return '';
	}

	/** @param string $blob base64url blob. @return string|false Address. */
	private static function open( $blob ) {
		$raw = self::unb64( $blob );
		if ( false === $raw || strlen( $raw ) < 30 ) {
			return false;
		}
		$key = self::enc_key();
		if ( "\x01" === $raw[0] && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			return sodium_crypto_secretbox_open( substr( $raw, 1 + $n ), substr( $raw, 1, $n ), $key );
		}
		if ( "\x02" === $raw[0] && function_exists( 'openssl_decrypt' ) ) {
			return openssl_decrypt( substr( $raw, 29 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 1, 12 ), substr( $raw, 13, 16 ) );
		}
		return false;
	}

	/**
	 * Token for a recipient hash.
	 *
	 * @param string $hash    Recipient hash (Eru_CMI_Recipient::hash()).
	 * @param string $channel EPOSTA | MESAJ | ARAMA.
	 * @param int    $exp     Expiry (Unix).
	 * @param string      $purpose   optout | confirm.
	 * @param string|null $recipient Confirm tokens: the address to carry (encrypted).
	 * @return string
	 */
	public static function mint_hash( $hash, $channel, $exp, $purpose = 'optout', $recipient = null ) {
		$payload = $hash . '.' . $channel . '.' . (int) $exp;
		$blob    = ( 'confirm' === $purpose && null !== $recipient ) ? self::seal( (string) $recipient ) : '';
		return self::b64( $payload ) . '.' . self::b64( self::mac( $purpose, $payload, $blob ) ) . ( '' === $blob ? '' : '.' . $blob );
	}

	/**
	 * Token for an address.
	 *
	 * @param string   $recipient E-mail or phone.
	 * @param string   $channel   EPOSTA | MESAJ | ARAMA.
	 * @param string   $purpose   optout | confirm.
	 * @param int|null $ttl       Seconds (default: the setting for opt-out, 48 h for confirm).
	 * @return string Token, or '' for an invalid recipient or channel.
	 */
	public static function mint( $recipient, $channel, $purpose = 'optout', $ttl = null ) {
		$channel = Eru_CMI_Recipient::type( $channel );
		$norm    = Eru_CMI_Recipient::normalize( $recipient, $channel );
		if ( is_wp_error( $norm ) || ! in_array( $purpose, self::PURPOSES, true ) ) {
			return '';
		}
		if ( null === $ttl ) {
			$ttl = 'confirm' === $purpose ? self::CONFIRM_TTL : Eru_CMI_Settings::token_ttl();
		}
		return self::mint_hash( Eru_CMI_Recipient::hash( $norm ), $channel, time() + (int) $ttl, $purpose, 'confirm' === $purpose ? $norm : null );
	}

	/**
	 * Checks a token. The signature is checked before the expiry, so only a genuine token is ever reported
	 * as expired.
	 *
	 * @param mixed  $token   Token.
	 * @param string $purpose optout | confirm.
	 * @return array{hash:string,channel:string,exp:int,recipient:string}|WP_Error eru_cmi_token_invalid |
	 *         eru_cmi_token_expired (the expired error carries the payload as data). "recipient" is the carried
	 *         address of a confirmation token (checked against the hash), '' otherwise.
	 */
	public static function verify( $token, $purpose = 'optout' ) {
		$invalid = new WP_Error( 'eru_cmi_token_invalid', __( 'Bağlantı geçersiz.', 'consent-manager-for-iys' ) );
		$dots = is_string( $token ) ? substr_count( $token, '.' ) : 0;
		if ( ! is_string( $token ) || strlen( $token ) > 800 || ! in_array( $purpose, self::PURPOSES, true ) || ! ( 1 === $dots || ( 2 === $dots && 'confirm' === $purpose ) ) ) {
			return $invalid;
		}
		$parts = explode( '.', $token );
		$p64   = $parts[0];
		$s64   = $parts[1];
		$blob  = $parts[2] ?? '';
		$payload           = self::unb64( $p64 );
		$sig               = self::unb64( $s64 );
		if ( false === $payload || false === $sig || ! preg_match( '/^([0-9a-f]{64})\.(EPOSTA|MESAJ|ARAMA)\.(\d{1,12})$/', $payload, $m ) ) {
			return $invalid;
		}
		if ( ! hash_equals( self::mac( $purpose, $payload, $blob ), $sig ) ) {
			return $invalid;
		}
		$data = array(
			'hash'      => $m[1],
			'channel'   => $m[2],
			'exp'       => (int) $m[3],
			'recipient' => '',
		);
		if ( '' !== $blob ) {
			$plain = self::open( $blob );
			$norm  = false === $plain ? new WP_Error( 'eru_cmi_token_invalid' ) : Eru_CMI_Recipient::normalize( $plain, $m[2] );
			if ( is_wp_error( $norm ) || ! hash_equals( $m[1], Eru_CMI_Recipient::hash( $norm ) ) ) {
				return $invalid; // The carried address must be the signed recipient.
			}
			$data['recipient'] = $norm;
		}
		if ( $data['exp'] < time() ) {
			return new WP_Error( 'eru_cmi_token_expired', __( 'Bağlantının süresi doldu.', 'consent-manager-for-iys' ), $data );
		}
		return $data;
	}
}
