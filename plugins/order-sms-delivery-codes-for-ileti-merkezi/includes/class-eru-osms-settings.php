<?php
/**
 * Settings, credentials (separate non-autoloaded option) and status rules.
 *
 * Rule: [ id, name, active, from ('*'|slug), to (slug), method ('*'|shipping method id), text, verify_target ].
 * Status slugs are stored without the "wc-" prefix. A rule whose text contains {teslimat_kodu}
 * is a "code rule": verify_target is the status staff can only reach by entering the code.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Settings {

	const OPTION       = 'eru_osms_settings';
	const CREDENTIALS  = 'eru_osms_credentials';
	const RULES_OPTION = 'eru_osms_rules';

	/** Defaults: master switch off, test mode on. Nothing is queued or sent until the merchant enables it. */
	public static function defaults(): array {
		return array(
			'enabled'       => 0,
			'test_mode'     => 1,
			'sender'        => '',
			'support_phone' => '',
			'support_email' => '',
			'max_attempts'  => 5,
		);
	}

	public static function get( ?string $key = null ) {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $s;
		}
		return $s[ $key ] ?? null;
	}

	public static function save( array $values ): void {
		$clean = self::get();
		foreach ( self::defaults() as $k => $default ) {
			if ( ! array_key_exists( $k, $values ) ) {
				continue;
			}
			$v = $values[ $k ];
			switch ( $k ) {
				case 'enabled':
				case 'test_mode':
					$clean[ $k ] = empty( $v ) ? 0 : 1;
					break;
				case 'sender':
					// İleti Merkezi sender titles are approved headers of up to 11 characters.
					$clean[ $k ] = mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, 11 );
					break;
				case 'support_email':
					$clean[ $k ] = sanitize_email( (string) $v );
					break;
				case 'max_attempts':
					$clean[ $k ] = max( 1, min( 10, (int) $v ) );
					break;
				default:
					$clean[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		update_option( self::OPTION, $clean );
	}

	/* ---- credentials: never autoloaded, never printed back ---- */

	public static function credentials(): array {
		$c = get_option( self::CREDENTIALS, array() );
		$c = is_array( $c ) ? $c : array();
		return array(
			'key'  => (string) ( $c['key'] ?? '' ),
			'hash' => (string) ( $c['hash'] ?? '' ),
		);
	}

	/** Opaque secrets: keep printable characters, strip control characters. */
	public static function clean_secret( $v ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $v ) );
	}

	/**
	 * Update credentials. An empty value keeps the stored one unless $clear is true.
	 */
	public static function set_credentials( string $key, string $hash, bool $clear = false ): void {
		$cur = self::credentials();
		$new = array(
			'key'  => $clear ? '' : ( '' !== self::clean_secret( $key ) ? self::clean_secret( $key ) : $cur['key'] ),
			'hash' => $clear ? '' : ( '' !== self::clean_secret( $hash ) ? self::clean_secret( $hash ) : $cur['hash'] ),
		);
		delete_option( self::CREDENTIALS );
		if ( '' !== $new['key'] || '' !== $new['hash'] ) {
			add_option( self::CREDENTIALS, $new, '', false );
		}
	}

	public static function has_credentials(): bool {
		$c = self::credentials();
		return '' !== $c['key'] && '' !== $c['hash'] && '' !== (string) self::get( 'sender' );
	}

	/** False while test mode is on: test mode only logs, it never sends. */
	public static function test_allows( ?string $phone = null ): bool {
		return Eru_OSMS_Decision::test_allows( (bool) (int) self::get( 'test_mode' ) );
	}

	/* ---- rules ---- */

	public static function rules(): array {
		$r = get_option( self::RULES_OPTION, array() );
		return is_array( $r ) ? array_values( array_filter( $r, 'is_array' ) ) : array();
	}

	public static function rule( string $id ): ?array {
		foreach ( self::rules() as $r ) {
			if ( isset( $r['id'] ) && (string) $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	public static function sanitize_rules( $in ): array {
		$out = array();
		$ids = array();
		foreach ( is_array( $in ) ? $in : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$text = sanitize_textarea_field( (string) ( $row['text'] ?? '' ) );
			$to   = Eru_OSMS_Decision::status( sanitize_key( (string) ( $row['to'] ?? '' ) ) );
			if ( '' === trim( $text ) || '' === $to ) {
				continue;
			}
			$id = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) ( $row['id'] ?? '' ) ) );
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'r' . substr( md5( wp_generate_uuid4() ), 0, 8 );
			}
			$ids[ $id ] = true;
			$from       = (string) ( $row['from'] ?? '*' );
			$method     = (string) ( $row['method'] ?? '*' );
			$out[]      = array(
				'id'            => $id,
				'name'          => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'active'        => empty( $row['active'] ) ? 0 : 1,
				'from'          => '*' === $from ? '*' : Eru_OSMS_Decision::status( sanitize_key( $from ) ),
				'to'            => $to,
				'method'        => '*' === $method ? '*' : sanitize_key( $method ),
				'text'          => $text,
				'verify_target' => Eru_OSMS_Decision::status( sanitize_key( (string) ( $row['verify_target'] ?? '' ) ) ),
			);
		}
		return $out;
	}

	public static function save_rules( array $rules ): void {
		update_option( self::RULES_OPTION, self::sanitize_rules( $rules ) );
	}
}
