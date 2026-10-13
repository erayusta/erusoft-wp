<?php
/** Settings, API keys (autoload=no, never echoed back) and the daily request counter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_APG_Settings {

	const OPTION = 'eru_apg_settings';
	const KEYS   = 'eru_apg_keys';
	const USAGE  = 'eru_apg_usage';

	public static function defaults(): array {
		return array(
			'provider'    => 'anthropic',
			'models'      => Eru_APG_Client::default_models(),
			'language'    => 'Türkçe',
			'max_tokens'  => 2000,
			'daily_cap'   => 20,
			'status'      => 'draft',
			'image'       => 0,
			'image_model' => 'gpt-image-1',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		$s['models'] = wp_parse_args( is_array( $s['models'] ) ? $s['models'] : array(), Eru_APG_Client::default_models() );
		return $s;
	}

	public static function model( string $provider ): string {
		$m = (string) ( self::get()['models'][ $provider ] ?? '' );
		return '' !== $m ? $m : (string) ( Eru_APG_Client::default_models()[ $provider ] ?? '' );
	}

	/** Raw key for one provider; '' when none. Never print this. */
	public static function key( string $provider ): string {
		$keys = get_option( self::KEYS, array() );
		return is_array( $keys ) && isset( $keys[ $provider ] ) && is_string( $keys[ $provider ] ) ? $keys[ $provider ] : '';
	}

	public static function save_key( string $provider, string $key ): void {
		if ( ! isset( Eru_APG_Client::providers()[ $provider ] ) ) {
			return;
		}
		$keys = get_option( self::KEYS, array() );
		$keys = is_array( $keys ) ? $keys : array();
		$key  = trim( preg_replace( '/[^\x21-\x7E]/', '', $key ) );
		if ( '' === $key ) {
			unset( $keys[ $provider ] );
		} else {
			$keys[ $provider ] = $key;
		}
		update_option( self::KEYS, $keys, false );
	}

	/** Display-safe hint such as "••••1234"; '' when no key is stored. */
	public static function masked( string $provider ): string {
		$k = self::key( $provider );
		if ( '' === $k ) {
			return '';
		}
		return '••••' . substr( $k, -4 );
	}

	/** @param mixed $raw Posted values (unslashed). Keys typed into the password fields are stored here too. */
	public static function sanitize( $raw ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$d      = self::defaults();
		$models = array();
		foreach ( Eru_APG_Client::providers() as $slug => $p ) {
			$m              = isset( $raw['models'][ $slug ] ) ? sanitize_text_field( (string) $raw['models'][ $slug ] ) : '';
			$models[ $slug ] = preg_match( '/^[A-Za-z0-9._:\-\/]{1,80}$/', $m ) ? $m : $d['models'][ $slug ];
			if ( ! empty( $raw['clear_key'][ $slug ] ) ) {
				self::save_key( $slug, '' );
			} elseif ( isset( $raw['new_key'][ $slug ] ) && '' !== trim( (string) $raw['new_key'][ $slug ] ) ) {
				self::save_key( $slug, (string) $raw['new_key'][ $slug ] );
			}
		}
		$prov   = isset( $raw['provider'] ) ? (string) $raw['provider'] : $d['provider'];
		$status = isset( $raw['status'] ) ? (string) $raw['status'] : 'draft';
		return array(
			'provider'    => isset( Eru_APG_Client::providers()[ $prov ] ) ? $prov : $d['provider'],
			'models'      => $models,
			'language'    => isset( $raw['language'] ) && '' !== trim( (string) $raw['language'] ) ? sanitize_text_field( (string) $raw['language'] ) : $d['language'],
			'max_tokens'  => isset( $raw['max_tokens'] ) ? max( 200, min( 8000, (int) $raw['max_tokens'] ) ) : $d['max_tokens'],
			'daily_cap'   => isset( $raw['daily_cap'] ) ? max( 1, min( 500, (int) $raw['daily_cap'] ) ) : $d['daily_cap'],
			'status'      => in_array( $status, array( 'draft', 'pending' ), true ) ? $status : 'draft',
			'image'       => ! empty( $raw['image'] ) ? 1 : 0,
			'image_model' => isset( $raw['image_model'] ) && preg_match( '/^[A-Za-z0-9._\-]{1,60}$/', (string) $raw['image_model'] ) ? (string) $raw['image_model'] : $d['image_model'],
		);
	}

	public static function usage_today(): int {
		$u = get_option( self::USAGE, array() );
		return is_array( $u ) && ( $u['date'] ?? '' ) === wp_date( 'Y-m-d' ) ? (int) ( $u['count'] ?? 0 ) : 0;
	}

	/** Count one request against today's cap; false when the cap is reached. */
	public static function take_request(): bool {
		$n = self::usage_today();
		if ( $n >= (int) self::get()['daily_cap'] ) {
			return false;
		}
		update_option( self::USAGE, array( 'date' => wp_date( 'Y-m-d' ), 'count' => $n + 1 ), false );
		return true;
	}
}
